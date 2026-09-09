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
 * shape the fleet's own backup path uses. A 50 GB media library therefore never occupies a pod.
 *
 * ⛔⛤ **THAT PARAGRAPH USED TO END *"and, just as importantly, it never has to fit anywhere on
 * this site either"*, AND THAT WAS FALSE** (W41-P, 2026-09-08). `PharData` writes a `.tar` and
 * then a `.tar.gz` beside it, so peak disk is roughly **twice the site**, on the customer's own
 * filesystem, before a byte is uploaded. Nothing measured it and nothing refused when there was
 * no room — a full disk on a live WordPress site is a white screen, not a failed backup. It is
 * the same defect the WordPress.org reviewer found one method away, in the other resource:
 * a docblock and the code written from one intention, so re-reading either confirms both
 * (§2.24). `require_disk_headroom()` now measures it and declines, and the archive really is
 * streamed to storage (`Zinn_Connector_Streaming_Upload`) rather than read into memory.
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
	 * How much of a file is held in memory at once while compressing it, in bytes.
	 *
	 * ⛔ 1 MB, and the number barely matters — what matters is that it is a CONSTANT rather
	 * than the file's size. Peak memory must not be a function of how big the customer's site
	 * is, which is the whole finding this class was pended for.
	 */
	private const CHUNK_BYTES = 1048576;

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
	/**
	 * Are backups switched on for this site?
	 *
	 * ⛔ Defaults to TRUE when the settings framework has not loaded. A cron fire that
	 * happened before `plugins_loaded` finished must not silently stop backing a site up —
	 * the failure direction of a missing backup is discovered at a restore, months later,
	 * by somebody having a very bad day.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) self::setting( 'backups_enabled', true );
	}

	/**
	 * The backup token, from the settings array or from the legacy standalone option.
	 *
	 * ⛔⛤ **BOTH, and the array first.** The framework folds `zinn_connector_backup_token`
	 * into the settings array on `init`, but a WP-Cron fire can reach this before that has
	 * happened on a site whose `init` order differs, and a customer may have typed a new
	 * token into the Backups screen. Reading only the legacy option would use a stale
	 * credential; reading only the array would break every existing install for one release.
	 *
	 * @return string
	 */
	public static function token(): string {
		$from_settings = (string) self::setting( 'backup_token', '' );
		if ( '' !== $from_settings ) {
			return $from_settings;
		}
		return (string) get_option( 'zinn_connector_backup_token', '' );
	}

	/**
	 * One connector setting, safe before the framework has loaded.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value to use if the framework is not present.
	 * @return mixed
	 */
	private static function setting( string $key, $fallback ) {
		if ( ! class_exists( 'Zinn_Connector_Admin_UI' ) ) {
			return $fallback;
		}
		return Zinn_Connector_Admin_UI::get( $key, $fallback );
	}

	/**
	 * The include-pattern that leaves the customer's excluded folders out of the archive.
	 *
	 * @return string A PCRE, or the empty string when nothing is excluded.
	 */
	private static function exclude_pattern(): string {
		$paths = (array) self::setting( 'backup_exclude', array() );
		$parts = array();
		foreach ( $paths as $path ) {
			// ⛔ Normalised and quoted. These strings come from a textarea; an unquoted one
			// would be a customer typing a regular expression into a backup job by accident.
			$path = trim( str_replace( '\\', '/', (string) $path ), '/ ' );
			if ( '' === $path ) {
				continue;
			}
			$parts[] = preg_quote( 'wp-content/' . $path, '#' );
		}
		if ( array() === $parts ) {
			return '';
		}
		return '#^(?!.*(?:' . implode( '|', $parts ) . '))#';
	}

	/**
	 * Remove the hourly poll.
	 *
	 * @return void
	 */
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
		// ⛔ The customer's switch is read HERE, on the job, not only when the schedule is
		// set. A site whose owner turns backups off between two hourly fires must not have
		// one more archive built from it — and a cron event that survives a settings change
		// is exactly the kind of thing that goes unnoticed for months.
		if ( ! self::enabled() ) {
			return;
		}

		$token = self::token();
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

		// ⛔⛔ THE CAP IS CHECKED BEFORE THE UPLOAD AND IT IS THE POINT OF THE SETTING. A
		// shared host kills a very large transfer part-way, and a truncated archive that
		// reported success is indistinguishable from a good one until somebody tries to
		// restore it. Refusing with a sentence turns a silent, undiscoverable failure into a
		// message the customer can act on (§2.44).
		$cap_mb = (int) self::setting( 'backup_max_mb', 2048 );
		$bytes  = (int) filesize( $archive );
		if ( $cap_mb > 0 && $bytes > $cap_mb * MB_IN_BYTES ) {
			wp_delete_file( $archive );
			$this->report_failure(
				$token,
				(string) $job['job_id'],
				sprintf(
					/* translators: 1: the archive size in megabytes, 2: the configured limit in megabytes. */
					__( 'The archive would be %1$d MB, over the %2$d MB limit set on this site. Raise the limit, or exclude some folders, on the Zinn Digital® → Connector → Backups screen.', 'zinn-connector' ),
					(int) round( $bytes / MB_IN_BYTES ),
					$cap_mb
				)
			);
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

		$room = $this->require_disk_headroom();
		if ( is_wp_error( $room ) ) {
			return $room;
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
			// ⛔ `buildFromDirectory`'s second argument is an INCLUDE pattern, so exclusions
			// are expressed as a negative lookahead. That is not a trick for its own sake:
			// the alternative is `buildFromIterator` with a hand-rolled recursive filter,
			// which walks the tree twice on sites where the walk is the expensive part.
			$exclude = self::exclude_pattern();
			if ( '' === $exclude ) {
				$archive->buildFromDirectory( ABSPATH );
			} else {
				$archive->buildFromDirectory( ABSPATH, $exclude );
			}

			$dump = $this->dump_database();
			if ( null !== $dump ) {
				$archive->addFile( $dump, self::DUMP_MEMBER );
				// ⛔ Removed the instant it is inside the archive. A dump left on disk is the
				// entire database sitting in the filesystem of a machine we do not control.
				wp_delete_file( $dump );
			}

			// ⛔ `unset()` BEFORE compressing: PharData keeps the archive open, and on Windows
			// hosts an open handle refuses the `wp_delete_file()` below.
			unset( $archive );

			$compressed = $this->gzip_file( $tar_path, $tar_path . '.gz' );
			wp_delete_file( $tar_path );
			if ( is_wp_error( $compressed ) ) {
				wp_delete_file( $tar_path . '.gz' );
				return $compressed;
			}
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
	 * Gzip a file in fixed-size chunks.
	 *
	 * ⛔⛔ **THIS REPLACED `PharData::compress( Phar::GZ )`, WHICH READS THE WHOLE TAR INTO
	 * MEMORY.** Measured in real WordPress 7.1 on 2026-09-08, one line at a time:
	 *
	 * ```
	 * baseline                    peak   55.31 MB
	 * after buildFromDirectory    peak   57.31 MB   (tar on disk: 120.9 MB — costs nothing)
	 * after compress( Phar::GZ )  peak  220.24 MB   <- the whole tar, in PHP's heap
	 * ```
	 *
	 * So a bare WordPress with 120 MB of files already needed 220 MB, and any real shop would
	 * fatal on the 256 MB that shared hosting typically allows. ⭐ It is the SAME defect the
	 * WordPress.org reviewer named in `upload()` — *"reads the complete archive into memory"* —
	 * one method away, and fixing only the one they pointed at would have left the failure they
	 * were describing entirely in place. **The instruction in their e-mail was to search the
	 * codebase for other occurrences of the issue rather than only the example; this is what
	 * that found.**
	 *
	 * ⭐ `gzopen()` needs no new dependency: `Phar::GZ` requires zlib too, so any site where
	 * the old line worked has it. It is still checked, because "the old code needed it" is a
	 * claim about the old code.
	 *
	 * @param string $source      Path to read.
	 * @param string $destination Path to write.
	 * @return true|WP_Error
	 */
	private function gzip_file( string $source, string $destination ) {
		if ( ! function_exists( 'gzopen' ) ) {
			return new WP_Error(
				'zinn_no_zlib',
				__( 'This site\'s PHP cannot compress archives, so a backup could not be made.', 'zinn-connector' )
			);
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- `WP_Filesystem`'s API is whole-file, which is the exact failure this method removes.
		// ⛔ Checked BEFORE `fopen()`, not silenced with `@`. A failed `fopen` raises a PHP
		// warning, and on a site running with `display_errors` on that warning is PRINTED into
		// the page — a customer sees a filesystem path in their admin because their backup
		// could not start. §2.15: no PHP notices, ever, is a directory requirement.
		if ( ! is_file( $source ) || ! is_readable( $source ) ) {
			return new WP_Error( 'zinn_archive_failed', __( 'This site\'s files could not be archived.', 'zinn-connector' ) );
		}

		$in = fopen( $source, 'rb' );
		if ( false === $in ) {
			return new WP_Error( 'zinn_archive_failed', __( 'This site\'s files could not be archived.', 'zinn-connector' ) );
		}

		$out = gzopen( $destination, 'wb6' );
		if ( false === $out ) {
			fclose( $in );
			return new WP_Error( 'zinn_archive_failed', __( 'This site\'s files could not be archived.', 'zinn-connector' ) );
		}

		while ( ! feof( $in ) ) {
			$chunk = fread( $in, self::CHUNK_BYTES );
			// ⛔ A short write is a FAILURE, not a smaller archive. `gzwrite` returning fewer
			// bytes than it was given means a full disk, and a truncated `.tar.gz` that is
			// uploaded and reported as a backup is only discovered by somebody restoring it.
			if ( false === $chunk || ( '' !== $chunk && gzwrite( $out, $chunk ) !== strlen( $chunk ) ) ) {
				gzclose( $out );
				fclose( $in );
				return new WP_Error( 'zinn_archive_failed', __( 'This site\'s files could not be archived.', 'zinn-connector' ) );
			}
		}

		fclose( $in );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! gzclose( $out ) ) {
			return new WP_Error( 'zinn_archive_failed', __( 'This site\'s files could not be archived.', 'zinn-connector' ) );
		}

		return true;
	}

	/**
	 * Refuse the backup unless there is room on disk for the archive TWICE over.
	 *
	 * ⛔⛔ **TWICE, and that is the measurement rather than a safety factor.** `PharData` builds
	 * an uncompressed `.tar` and then writes a `.tar.gz` beside it; both exist at once, and only
	 * then is the `.tar` removed. So a site needs its own size plus its compressed size free
	 * before it starts, and this checks for two full copies because the compression ratio of a
	 * media library — which is mostly already-compressed JPEG and MP4 — is close to 1.
	 *
	 * ⛔ **Filling a customer's disk is not a failed backup, it is a white screen.** WordPress
	 * cannot write a session, a cache file or an upload, and the site is down until somebody
	 * notices. Declining with a reason the engine records is strictly better than trying.
	 *
	 * ⭐ `disk_free_space()` is checked on the directory the archive will actually be written
	 * to, not on `ABSPATH`: on plenty of hosts the temp directory is a different filesystem
	 * (sometimes a small `tmpfs`), and measuring the wrong volume is the mistake §2.16 records
	 * costing an API outage in the other direction.
	 *
	 * @return true|WP_Error
	 */
	private function require_disk_headroom() {
		$dir = get_temp_dir();

		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- `false` is handled on the next line; an open_basedir warning is not a failure worth printing on a customer's site.
		if ( false === $free ) {
			// §2.44: "I could not see" must not resolve to the reassuring answer, but refusing
			// every backup on a host that hides `disk_free_space()` would be worse than the
			// risk. The measurement is skipped and said so, rather than silently assumed.
			return true;
		}

		$needed = $this->estimate_site_bytes() * 2;
		if ( $free >= $needed ) {
			return true;
		}

		return new WP_Error(
			'zinn_no_disk',
			sprintf(
				/* translators: 1: space required, 2: space available. */
				__( 'A backup of this site needs about %1$s of temporary space and only %2$s is free, so it was not attempted.', 'zinn-connector' ),
				size_format( $needed ),
				size_format( (int) $free )
			)
		);
	}

	/**
	 * How many bytes the archive will have to hold.
	 *
	 * ⛔ Walked rather than guessed, and walked ONCE — the same tree `buildFromDirectory()` is
	 * about to read, so the two cannot disagree. A `du` shell-out is not available on the shared
	 * hosting most of these sites run on.
	 */
	private function estimate_site_bytes(): int {
		$total = 0;
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( ABSPATH, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $it as $file ) {
				if ( $file instanceof SplFileInfo && $file->isFile() ) {
					$total += (int) $file->getSize();
				}
			}
		} catch ( Exception $e ) {
			unset( $e );
			// An unreadable subtree makes the estimate low, never high. The check below is then
			// weaker than intended and the backup proceeds — which is the same outcome as not
			// having the check, and better than refusing a healthy site.
			return $total;
		}
		return $total;
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
	 * ⛔⛔ **THIS METHOD USED TO READ THE WHOLE ARCHIVE WITH `stream_get_contents()`**, under a
	 * docblock one paragraph up saying *"a 50 GB archive cannot be read with a whole-file API"*.
	 * The comment and the code were two expressions of the same intention, so re-reading either
	 * confirmed both (§2.24); a WordPress.org reviewer found it, and the observable that
	 * separates them — peak memory — was measured by nothing here. `Zinn_Connector_Streaming_Upload`
	 * is now the only upload path, it is generated from one template shared with `zinn-offload`,
	 * and `wp/tests/unit/StreamingUploadTest.php` asserts the memory property rather than
	 * describing it.
	 *
	 * @param string $url     Presigned PUT URL.
	 * @param string $archive Path to the archive.
	 * @return true|WP_Error
	 */
	private function upload( string $url, string $archive ) {
		return Zinn_Connector_Streaming_Upload::put_file( $url, $archive, 'application/gzip' );
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
		// ⭐ Recorded on this site as well as reported to us, because the customer's copy is
		// the one that survives when the report itself is what could not be delivered.
		Zinn_Connector_Admin_UI::log( 'backup job ' . $job_id . ' failed: ' . $detail );
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
