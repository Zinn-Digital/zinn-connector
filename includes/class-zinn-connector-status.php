<?php
/**
 * Is this site actually connected to Zinn Digital®, and if not, why not?
 *
 * ⚖️ **Owner, 2026-09-08:** *"including the bridge one between wp and our panel etc. And it
 * needs to show status of ocnnetion and all those things properly."* This is the bridge, and
 * this class is the "properly".
 *
 * ⛔⛔ **THE STATUS IS COMPUTED FROM WHAT IS TRUE, NOT FROM WHAT WE WROTE DOWN WHEN WE
 * CONNECTED.** `zinn_connector_state` records that a connection was once made. It says
 * nothing about whether the credential still exists, whether the user still has the
 * capability the credential inherits, or whether our end still recognises the site — and each
 * of those fails independently, in the field, without touching that option. A screen that
 * reads the option and prints "Connected" is a screen that will say "Connected" for ever on a
 * site that has not worked since the day somebody tidied up their application passwords.
 *
 * ⭐ **Three local legs before any network call.** The commonest real failure — the
 * application password revoked from the profile screen — is answerable on this server, for
 * free, with certainty, and it is the one a remote check would be worst at diagnosing.
 *
 * @package ZinnConnector
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The connector's connection status, and the disconnect that makes it honest.
 */
class Zinn_Connector_Status {

	/**
	 * Transient holding the last remote check, so opening the screen is not a network wait.
	 */
	private const CACHE_KEY = 'zinn_connector_remote_status';

	/**
	 * How long a remote answer is trusted. Fifteen minutes: long enough that clicking
	 * between tabs costs nothing, short enough that a customer who has just fixed their
	 * billing does not sit looking at a stale complaint.
	 */
	private const CACHE_TTL = 900;

	/**
	 * The status, in the shape the shared connection card renders.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$state = get_option( 'zinn_connector_state', array() );
		$state = is_array( $state ) ? $state : array();

		if ( empty( $state['connected_at'] ) ) {
			// ⛔ Not "paste it below": this card also renders on the Zinn Digital® overview page,
			// where nothing is below it (D24633).
			return array(
				'state'   => 'disconnected',
				'summary' => __( 'This site is not connected to Zinn Digital®.', 'zinn-connector' ),
				'reason'  => __( 'Nothing is wrong — it has simply not been paired yet. Get a pairing code from your Zinn® dashboard and paste it into this plugin’s settings.', 'zinn-connector' ),
				'action'  => array(
					'label' => __( 'Open my Zinn® dashboard', 'zinn-connector' ),
					'url'   => 'https://app.zinndigital.com/content/other-sites',
					'style' => 'primary',
				),
			);
		}

		$login   = (string) ( $state['user_login'] ?? '' );
		$uuid    = (string) ( $state['password_uuid'] ?? '' );
		$user    = '' === $login ? false : get_user_by( 'login', $login );
		$details = self::details( $state );

		// ── Leg 1: does the WordPress user still exist? ──────────────────────────────────
		if ( ! $user instanceof WP_User ) {
			return array(
				'state'      => 'disconnected',
				'summary'    => __( 'The connection is broken.', 'zinn-connector' ),
				'reason'     => sprintf(
					/* translators: %s: the WordPress username the credential belonged to. */
					__( 'The WordPress user the credential belonged to (%s) no longer exists on this site, so nothing we send can be published. Connect again as a user who can publish.', 'zinn-connector' ),
					$login
				),
				'action'     => self::reconnect_action(),
				'details'    => $details,
				'checked_at' => time(),
			);
		}

		// ── Leg 2: does the application password still exist? ────────────────────────────
		// ⛔ This is the commonest real failure and the one a remote check diagnoses worst:
		// from our side a revoked credential looks like every other 401.
		if ( '' !== $uuid && ! self::password_exists( $user->ID, $uuid ) ) {
			return array(
				'state'      => 'disconnected',
				'summary'    => __( 'The connection is broken.', 'zinn-connector' ),
				'reason'     => __( 'The “Zinn Digital® (publishing)” application password has been deleted from this site, so we can no longer sign in. Connect again to create a new one — nothing else was lost.', 'zinn-connector' ),
				'actions'    => array(
					self::reconnect_action(),
					array(
						'label' => __( 'See this site’s application passwords', 'zinn-connector' ),
						'url'   => admin_url( 'profile.php#application-passwords-section' ),
					),
				),
				'details'    => $details,
				'checked_at' => time(),
			);
		}

		// ── Leg 3: can that user still publish? ──────────────────────────────────────────
		// An application password inherits its user's capabilities, so a demoted user is a
		// connection that authenticates perfectly and then fails at the first post.
		if ( ! user_can( $user, 'publish_posts' ) ) {
			return array(
				'state'      => 'degraded',
				'summary'    => __( 'Connected, but publishing would fail.', 'zinn-connector' ),
				'reason'     => sprintf(
					/* translators: %s: the WordPress username the credential belongs to. */
					__( 'The credential belongs to %s, and that user can no longer publish posts. Sign-in still works, so nothing looks wrong until an article is due. Give that user the ability to publish, or connect again as somebody who can.', 'zinn-connector' ),
					$login
				),
				'actions'    => array(
					array(
						'label' => __( 'Edit that user', 'zinn-connector' ),
						'url'   => get_edit_user_link( $user->ID ),
					),
					self::reconnect_action(),
				),
				'details'    => $details,
				'checked_at' => time(),
			);
		}

		// ── Leg 4: what does OUR end say? ────────────────────────────────────────────────
		$remote = self::remote();

		if ( 'unreachable' === $remote['state'] ) {
			return array(
				'state'      => 'degraded',
				'summary'    => __( 'Connected here — we could not reach Zinn Digital® to confirm.', 'zinn-connector' ),
				'reason'     => $remote['reason'],
				'action'     => self::recheck_action(),
				'details'    => $details,
				'checked_at' => (int) $remote['checked_at'],
			);
		}

		if ( 'refused' === $remote['state'] ) {
			return array(
				'state'      => 'degraded',
				'summary'    => __( 'Connected, but your Zinn® account needs attention.', 'zinn-connector' ),
				'reason'     => $remote['reason'],
				'actions'    => array(
					array(
						'label' => __( 'Open my Zinn® dashboard', 'zinn-connector' ),
						'url'   => 'https://app.zinndigital.com/billing',
						'style' => 'primary',
					),
					self::recheck_action(),
				),
				'details'    => $details,
				'checked_at' => (int) $remote['checked_at'],
			);
		}

		$summary = '' === (string) $remote['org']
			? __( 'Connected to Zinn Digital®.', 'zinn-connector' )
			: sprintf(
				/* translators: %s: the Zinn Digital organisation this site belongs to. */
				__( 'Connected to Zinn Digital® as %s.', 'zinn-connector' ),
				(string) $remote['org']
			);

		return array(
			'state'      => 'connected',
			'summary'    => $summary,
			'details'    => $details,
			'action'     => self::recheck_action(),
			'checked_at' => (int) $remote['checked_at'],
		);
	}

	/**
	 * The rows under the headline: who, when, and which site we think this is.
	 *
	 * ⛔ No token, no uuid fragment, nothing that is a credential or half of one. This block
	 * is the single most screenshot-and-pasted-into-a-ticket part of any settings screen.
	 *
	 * @param array<string, mixed> $state The stored connection state.
	 * @return array<int, array<string, string>>
	 */
	private static function details( array $state ): array {
		$rows = array(
			array(
				'label' => __( 'Publishing as', 'zinn-connector' ),
				'value' => (string) ( $state['user_login'] ?? '' ),
			),
			array(
				'label' => __( 'Connected', 'zinn-connector' ),
				'value' => empty( $state['connected_at'] )
					? ''
					: wp_date( (string) get_option( 'date_format', 'Y-m-d' ), (int) $state['connected_at'] ),
			),
		);
		if ( ! empty( $state['site_id'] ) ) {
			$rows[] = array(
				'label' => __( 'Zinn® site ID', 'zinn-connector' ),
				'value' => (string) $state['site_id'],
			);
		}
		return $rows;
	}

	/**
	 * Does an application password with this uuid still exist for this user?
	 *
	 * ⛔ Returns TRUE when WordPress cannot tell us — application passwords being unavailable
	 * is not evidence that ours was revoked, and reporting "disconnected" on a site where the
	 * feature is merely switched off would send the customer to fix the wrong thing (§2.44:
	 * the instrument that cannot see must not return a verdict).
	 *
	 * @param int    $user_id The credential's owner.
	 * @param string $uuid    The credential's uuid.
	 * @return bool
	 */
	private static function password_exists( int $user_id, string $uuid ): bool {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return true;
		}
		$found = WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
		return is_array( $found ) && array() !== $found;
	}

	/**
	 * Ask our end what it thinks, with the answer cached.
	 *
	 * ⛔⛔ **AN UNREACHABLE SERVER AND A REFUSED ACCOUNT ARE DIFFERENT ANSWERS (§2.57).**
	 * Reporting "we could not reach Zinn" for a suspended account sends the customer to
	 * check their firewall; reporting "your account has a problem" for our own outage blames
	 * them for us. Neither can be guessed from a status code alone, which is why the engine
	 * returns a `reason` and this reads it.
	 *
	 * @param bool $fresh Ignore the cache.
	 * @return array{state:string,org:string,reason:string,checked_at:int}
	 */
	public static function remote( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['state'] ) ) {
				return array(
					'state'      => (string) $cached['state'],
					'org'        => (string) ( $cached['org'] ?? '' ),
					'reason'     => (string) ( $cached['reason'] ?? '' ),
					'checked_at' => (int) ( $cached['checked_at'] ?? 0 ),
				);
			}
		}

		$token  = (string) get_option( 'zinn_connector_backup_token', '' );
		$result = array(
			'state'      => 'ok',
			'org'        => '',
			'reason'     => '',
			'checked_at' => time(),
		);

		if ( '' === $token ) {
			// ⛔ No token is NOT a failure. The token is minted for the backup half; a site
			// connected for publishing only has none, and painting that amber would report a
			// fault on a correctly configured site.
			set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
			return $result;
		}

		$response = wp_remote_get(
			zinn_connector_api_base() . '/v1/connector/status',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['state']  = 'unreachable';
			$result['reason'] = sprintf(
				/* translators: %s: the transport error WordPress reported. */
				__( 'This server could not reach api.zinndigital.com (%s). That is usually a firewall or an outbound-connection rule on your host, not a problem with your Zinn® account — publishing may still be working.', 'zinn-connector' ),
				$response->get_error_message()
			);
			set_transient( self::CACHE_KEY, $result, 300 );
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();

		if ( $code >= 500 ) {
			$result['state']  = 'unreachable';
			$result['reason'] = __( 'Zinn Digital® answered with an error of its own — that is our end, not yours. Publishing is unaffected while this clears.', 'zinn-connector' );
			set_transient( self::CACHE_KEY, $result, 300 );
			return $result;
		}

		if ( ! empty( $body['refused'] ) ) {
			$result['state']  = 'refused';
			$result['reason'] = (string) ( $body['reason'] ?? __( 'Your Zinn® account is not currently able to publish to this site. Your dashboard says why.', 'zinn-connector' ) );
			$result['org']    = (string) ( $body['org'] ?? '' );
			set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
			return $result;
		}

		if ( $code < 200 || $code >= 300 ) {
			$result['state']  = 'unreachable';
			$result['reason'] = sprintf(
				/* translators: %d: the HTTP status code returned. */
				__( 'Zinn Digital® answered HTTP %d, which we do not recognise. Send a diagnostics report from the Support tab and we will look — do not change anything here first.', 'zinn-connector' ),
				$code
			);
			set_transient( self::CACHE_KEY, $result, 300 );
			return $result;
		}

		$result['org'] = (string) ( $body['org'] ?? '' );
		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * The "check again" button.
	 *
	 * @return array<string, string>
	 */
	private static function recheck_action(): array {
		return array(
			'label'  => __( 'Check again now', 'zinn-connector' ),
			'action' => 'zinn_connector_recheck',
		);
	}

	/**
	 * The "connect again" button.
	 *
	 * @return array<string, string>
	 */
	private static function reconnect_action(): array {
		return array(
			'label' => __( 'Connect this site again', 'zinn-connector' ),
			'url'   => Zinn_Connector_Admin_UI::page_url( 'connection' ),
			'style' => 'primary',
		);
	}

	/**
	 * Forget the remote answer, so the next read asks again.
	 *
	 * @return array<string, string>
	 */
	public static function recheck(): array {
		delete_transient( self::CACHE_KEY );
		$remote = self::remote( true );
		if ( 'ok' === $remote['state'] ) {
			return array(
				'kind'    => 'success',
				'message' => __( 'Checked. The connection is live.', 'zinn-connector' ),
			);
		}
		return array(
			'kind'    => 'warning',
			'message' => $remote['reason'],
		);
	}

	/**
	 * Disconnect this site.
	 *
	 * ⚖️ **Owner ruling, 2026-09-08**, asked directly: *"Yes — a real Disconnect button"*.
	 *
	 * ⛔⛔ **A DISCONNECT THAT ONLY FORGETS IS A LIE.** Clearing our own option while leaving
	 * the application password in place would give the customer a screen saying "not
	 * connected" over a live credential that still publishes. The revocation is the act; the
	 * option is bookkeeping.
	 *
	 * ⛔ **Revoked FIRST, forgotten second.** If the revocation fails we still hold the uuid,
	 * so the customer can be told precisely what is left and where to remove it by hand. The
	 * other order loses the only handle on the credential we were trying to destroy.
	 *
	 * @return array<string, string>
	 */
	public static function disconnect(): array {
		$state = get_option( 'zinn_connector_state', array() );
		$state = is_array( $state ) ? $state : array();
		$login = (string) ( $state['user_login'] ?? '' );
		$uuid  = (string) ( $state['password_uuid'] ?? '' );
		$user  = '' === $login ? false : get_user_by( 'login', $login );

		$revoked = true;
		if ( $user instanceof WP_User && '' !== $uuid && class_exists( 'WP_Application_Passwords' ) ) {
			$result  = WP_Application_Passwords::delete_application_password( $user->ID, $uuid );
			$revoked = true === $result;
		}

		if ( ! $revoked ) {
			return array(
				'kind'    => 'error',
				'message' => __( 'We could not remove the application password, so this site is still connected. Delete “Zinn Digital® (publishing)” on your profile screen and try again — we have changed nothing.', 'zinn-connector' ),
			);
		}

		delete_option( 'zinn_connector_state' );
		delete_option( 'zinn_connector_backup_token' );
		delete_transient( self::CACHE_KEY );

		/**
		 * Fires after the site has been disconnected from Zinn Digital.
		 *
		 * ⭐ The backup half listens and unschedules itself. A disconnected site that goes on
		 * polling us hourly is traffic the customer did not ask for and cannot see the cause
		 * of — the same reason deactivation unschedules.
		 */
		do_action( 'zinn_connector_disconnected' );

		return array(
			'kind'    => 'success',
			'message' => __( 'Disconnected. The application password has been revoked, so Zinn Digital® can no longer publish to this site. Nothing already published was touched.', 'zinn-connector' ),
		);
	}
}
