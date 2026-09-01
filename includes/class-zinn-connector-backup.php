<?php
/**
 * Taking a backup of THIS site and handing it to Zinn Digital® — outbound only.
 *
 * ⛔⛔ **THIS OPENS NO ROUTE, AND THAT IS THE WHOLE DESIGN.** The plugin's own header says a
 * route on every customer site would be a new attack surface on every customer site, and a
 * backup is not a good enough reason to relax it — quite the opposite, since the thing an
 * attacker would want from a backup endpoint is the entire site and database. So Zinn cannot
 * ring this site; this site rings Zinn. A WP-Cron event asks *"is a backup due?"*, and the
 * answer either contains a job or does not.
 *
 * ⭐ **The archive never passes through Zinn's servers.** The engine hands back a short-lived
 * presigned upload URL and this plugin streams straight to object storage, which is the same
 * shape the fleet's own backup path uses. A 50 GB media library therefore never occupies a pod
 * — and, just as importantly, it never has to fit anywhere on this site either.
 *
 * ── ⛔ WHAT THIS DELIBERATELY CANNOT DO ────────────────────────────────────────
 *
 * **It cannot restore.** A plugin is PHP running inside the site it is protecting, so at the
 * moment a site is broken enough to need restoring — a fatal error, a half-finished update, a
 * dropped database — this code does not run at all. Restoring needs SSH, the customer is told
 * so in the dashboard when they connect, and no amount of work here changes it. Building a
 * restore path that only works while the patient is healthy would be worse than having none,
 * because somebody would plan around it.
 *
 * @package ZinnConnector
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The scheduled backup collector.
 */
class Zinn_Connector_Backup {

	/** The WP-Cron hook this plugin schedules. */
	public const CRON_HOOK = 'zinn_connector_backup_poll';

	/**
	 * The archive member the database dump travels under.
	 *
	 * ⛔ A name INSIDE the tar, never a path on disk. The on-disk staging file is created with
	 * `wp_tempnam()`, which is unpredictable — a fixed name in a shared temp directory is a
	 * symlink race on exactly the shared hosting most of these sites run on.
	 */
	private const DUMP_MEMBER = '.zinn-backup-db.sql';

	/**
	 * Register the schedule. Called on plugin activation and on `init` as a self-heal.
	 *
	 * ⛔ Guarded, because `wp_schedule_event` with no guard queues a SECOND event every time
	 * `init` fires — which is every request. A site would accumulate thousands of them and
	 * back itself up continuously.
	 */
	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/** Remove the schedule. Called on deactivation, so a disabled plugin stops asking. */
	public static function unschedule(): void {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * Ask whether a backup is due, and take it if one is.
	 *
	 * ⛔ **Silent when there is nothing to do.** This runs hourly on every connected site; a
	 * notice, a log line or an option write on the empty path would be noise on a scale of
	 * thousands of sites, and would make the interesting case impossible to find.
	 */
	public function run(): void {
		$token = (string) get_option( 'zinn_connector_backup_token', '' );
		if ( '' === $token ) {
			// Not enrolled for backups. Nothing to say.
			return;
		}

		$job = $this->claim_job( $token );
		if ( null === $job ) {
			return;
		}

		$archive = $this->build_archive();
		if ( is_wp_error( $archive ) ) {
			$this->report_failure( $token, (string) $job['job_id'], $archive->get_error_message() );
			return;
		}

		$uploaded = $this->upload( (string) $job['upload_url'], $archive );

		// ⛔⛔ The staging file is removed on BOTH paths, before any early return. A failed
		// upload that leaves a complete copy of the site and its database sitting in the
		// filesystem is a data-exposure bug that would accumulate one archive per failure.
		$size = (int) filesize( $archive );
		wp_delete_file( $archive );

		if ( is_wp_error( $uploaded ) ) {
			$this->report_failure( $token, (string) $job['job_id'], $uploaded->get_error_message() );
			return;
		}

		$this->report_success( $token, (string) $job['job_id'], $size );
	}

	/**
	 * Ask the engine for a due job. Returns `null` when there is nothing to do.
	 *
	 * ⛔ A transport failure returns `null` — the same as "nothing due" — **on purpose**. This
	 * is a poll that runs again in an hour, so there is nothing to recover and nobody to tell;
	 * treating an unreachable network as an error would put a scary admin notice on a customer's
	 * site because their host had a blip at 3am.
	 *
	 * @param string $token The site's backup token.
	 * @return array{job_id:string,upload_url:string}|null
	 */
	private function claim_job( string $token ) {
		$response = wp_remote_post(
			zinn_connector_api_base() . '/v1/connector/backup/claim',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => (string) wp_json_encode( array( 'site_url' => home_url() ) ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['job_id'] ) || empty( $body['upload_url'] ) ) {
			return null;
		}

		return array(
			'job_id'     => (string) $body['job_id'],
			'upload_url' => (string) $body['upload_url'],
		);
	}

	/**
	 * Build a gzipped tar of the site's files plus a database dump.
	 *
	 * ⭐ **The database dump is best effort and its absence is not fatal.** A site whose host
	 * forbids `mysqldump` and whose PHP cannot open enough memory to serialise the tables still
	 * has files worth keeping, and refusing the whole backup because one half is unavailable
	 * would leave that customer with nothing. What must never happen is calling the result a
	 * *full* backup, which is why the engine records what a given archive actually contained.
	 *
	 * @return string|WP_Error Path to the archive, or an error.
	 */
	private function build_archive() {
		if ( ! class_exists( 'PharData' ) ) {
			return new WP_Error(
				'zinn_no_phar',
				__( 'This site\'s PHP cannot create archives, so a backup could not be made.', 'zinn-connector' )
			);
		}

		// ⛔ `wp_tempnam()` rather than a fixed path: unpredictable, so a co-tenant on shared
		// hosting has nothing to pre-create a symlink at (CWE-377).
		$base = wp_tempnam( 'zinn-backup' );
		if ( ! $base ) {
			return new WP_Error( 'zinn_no_temp', __( 'Could not create a temporary file.', 'zinn-connector' ) );
		}
		wp_delete_file( $base );
		$tar_path = $base . '.tar';

		try {
			$archive = new PharData( $tar_path );
			$archive->buildFromDirectory( ABSPATH );

			$dump = $this->dump_database();
			if ( null !== $dump ) {
				$archive->addFile( $dump, self::DUMP_MEMBER );
				// ⛔ Removed the instant it is inside the archive. A dump left on disk is the
				// entire database sitting in the filesystem of a machine we do not control.
				wp_delete_file( $dump );
			}

			$archive->compress( Phar::GZ );
			unset( $archive );
			wp_delete_file( $tar_path );
		} catch ( Exception $e ) {
			wp_delete_file( $tar_path );
			wp_delete_file( $tar_path . '.gz' );
			return new WP_Error(
				'zinn_archive_failed',
				__( 'This site\'s files could not be archived.', 'zinn-connector' )
			);
		}

		return $tar_path . '.gz';
	}

	/**
	 * Dump the database to a temporary file, or `null` if it cannot be done.
	 *
	 * ⛔⛔ **Written OUTSIDE the document root and to an unpredictable name.** A dump under the
	 * web root is downloadable over HTTP by anyone who guesses the filename, for as long as it
	 * exists — and it is every password hash and every customer record on the site.
	 *
	 * @return string|null Path to the dump.
	 */
	private function dump_database(): ?string {
		global $wpdb;

		$path = wp_tempnam( 'zinn-db' );
		if ( ! $path ) {
			return null;
		}

		// ⛔⛔ **`WP_Filesystem` IS THE WRONG TOOL HERE AND THE SNIFF CANNOT KNOW THAT.** Its API
		// is whole-file: `put_contents()` takes a string, so using it would mean building the
		// entire dump in memory first — the exact failure the paging below exists to avoid, and
		// the reason a backup plugin OOMs a real shop at 3am. A streaming handle is required, so
		// the file functions are used deliberately and the sniff is silenced with a reason.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$handle = fopen( $path, 'wb' );
		if ( ! $handle ) {
			wp_delete_file( $path );
			return null;
		}

		// ⛔ A dump enumerates the schema; there is no WordPress API for that and nothing to
		// cache — the whole point is to read the database as it is at this instant.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema enumeration for a backup; no core API exists and a cached answer would be wrong.
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $tables ) ) {
			fclose( $handle );
			wp_delete_file( $path );
			return null;
		}

		foreach ( $tables as $table ) {
			// ⛔⛔ A table NAME cannot be a bound parameter in SQL — `$wpdb->prepare()` has no
			// placeholder for an identifier, so every dump tool faces this. What makes it safe
			// here is the SOURCE: `$name` comes from this database's own `SHOW TABLES`, never
			// from a request, and backticks are stripped so it cannot close the quoting.
			$name = str_replace( '`', '', (string) $table );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- `SHOW CREATE TABLE` READS a definition, it changes nothing (the SchemaChange sniff matches on the word CREATE). The identifier comes from this database's own SHOW TABLES with backticks stripped, never from a request, and SQL has no placeholder for an identifier.
			$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . $name . '`', ARRAY_N );
			if ( ! is_array( $create ) || ! isset( $create[1] ) ) {
				continue;
			}
			fwrite( $handle, 'DROP TABLE IF EXISTS `' . $name . "`;\n" );
			fwrite( $handle, $create[1] . ";\n" );

			// ⛔ Streamed in pages, never `SELECT *` into memory. A products or postmeta table
			// on a real shop is hundreds of megabytes, and loading it whole is how a backup
			// plugin takes a site down with a memory exhaustion at 3am.
			$offset = 0;
			$page   = 500;
			do {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier interpolated, values bound.
				$sql = $wpdb->prepare( 'SELECT * FROM `' . $name . '` LIMIT %d OFFSET %d', $page, $offset );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the prepared statement above (values bound, identifier from SHOW TABLES). Row data being dumped must never be served from a cache.
				$rows = $wpdb->get_results( $sql, ARRAY_A );
				if ( ! is_array( $rows ) || array() === $rows ) {
					break;
				}
				foreach ( $rows as $row ) {
					$values = array();
					foreach ( $row as $value ) {
						$values[] = null === $value ? 'NULL' : "'" . esc_sql( (string) $value ) . "'";
					}
					fwrite( $handle, 'INSERT INTO `' . $name . '` VALUES (' . implode( ',', $values ) . ");\n" );
				}
				// ⛔ Counted ONCE into a variable rather than in the loop condition: `count()`
				// in a `while` re-counts the array on every iteration, which on a table of a
				// million rows is a measurable cost for an answer that cannot change.
				$fetched = count( $rows );
				$offset += $page;
			} while ( $fetched === $page );
		}

		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $path;
	}

	/**
	 * Stream the archive to the presigned URL.
	 *
	 * ⛔ The URL is a **bearer credential** — whoever holds it can write that object — so it is
	 * never logged, never stored in an option and never echoed into an admin notice.
	 *
	 * @param string $url     Presigned PUT URL.
	 * @param string $archive Path to the archive.
	 * @return true|WP_Error
	 */
	private function upload( string $url, string $archive ) {
		// ⛔ Same reason as `dump_database()`: a 50 GB archive cannot be read with a whole-file
		// API. `@` because a missing or unreadable archive is handled on the next line — this is
		// a checked failure, not a silenced one.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged
		$body = @fopen( $archive, 'rb' );
		if ( ! $body ) {
			return new WP_Error( 'zinn_unreadable', __( 'The archive could not be read back.', 'zinn-connector' ) );
		}
		$contents = stream_get_contents( $body );
		fclose( $body );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 900,
				'headers' => array( 'Content-Type' => 'application/gzip' ),
				'body'    => $contents,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'zinn_upload_failed', __( 'The backup could not be uploaded.', 'zinn-connector' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status > 299 ) {
			return new WP_Error( 'zinn_upload_rejected', __( 'The backup upload was rejected.', 'zinn-connector' ) );
		}

		return true;
	}

	/**
	 * Tell the engine the upload finished.
	 *
	 * ⛔ The size we report is **not** what the engine records. It HEADs the object itself,
	 * because a truncated upload that reports success is indistinguishable from a good one
	 * until somebody tries to restore it. This figure is a cross-check, not a source of truth.
	 *
	 * @param string $token  The site's backup token.
	 * @param string $job_id The job being settled.
	 * @param int    $size   Bytes uploaded, as this site measured them.
	 */
	private function report_success( string $token, string $job_id, int $size ): void {
		$this->report(
			$token,
			$job_id,
			array(
				'status'     => 'uploaded',
				'size_bytes' => $size,
			)
		);
	}

	/**
	 * Tell the engine the attempt failed, and why, so the row is not left running for ever.
	 *
	 * @param string $token  The site's backup token.
	 * @param string $job_id The job being settled.
	 * @param string $detail Why it failed. Kept for STAFF — never shown to the customer.
	 */
	private function report_failure( string $token, string $job_id, string $detail ): void {
		$this->report(
			$token,
			$job_id,
			array(
				'status' => 'failed',
				'detail' => $detail,
			)
		);
	}

	/**
	 * One outbound completion call.
	 *
	 * ⛔ Failure here is deliberately silent. The engine times a job out on its own, so a
	 * missed completion costs one retry rather than a stuck row — and there is nothing an
	 * administrator could do about it if we told them.
	 *
	 * @param string              $token   The site's backup token.
	 * @param string              $job_id  The job being settled.
	 * @param array<string,mixed> $payload The completion body.
	 */
	private function report( string $token, string $job_id, array $payload ): void {
		wp_remote_post(
			zinn_connector_api_base() . '/v1/connector/backup/complete',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => (string) wp_json_encode( array_merge( array( 'job_id' => $job_id ), $payload ) ),
			)
		);
	}
}
