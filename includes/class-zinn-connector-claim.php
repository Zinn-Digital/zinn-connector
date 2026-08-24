<?php
/**
 * Redeeming a pairing code: mint a credential, hand it over, remember the outcome.
 *
 * @package ZinnConnector
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one outbound call this plugin makes.
 *
 * ⛔⛔ **The application password is minted HERE and never displayed.** That is the whole
 * point of the plugin over the manual route: the customer copies a short pairing code *into*
 * WordPress instead of copying a live credential *out* of it. A credential that is never
 * shown cannot be pasted into the wrong window, screen-shared, or left in a support ticket.
 */
class Zinn_Connector_Claim {

	/**
	 * The name the credential appears under in the user's profile.
	 *
	 * ⭐ Named for the SERVICE, not for the plugin, because that is the list a customer reads
	 * when they are deciding what to revoke. "Zinn Connector" would make them ask what a
	 * connector is; "Zinn Digital (publishing)" answers the question they actually have.
	 */
	private const CREDENTIAL_NAME = 'Zinn Digital® (publishing)';

	/**
	 * Redeem `$code` and connect this site.
	 *
	 * @param string $code The pairing code from the Zinn dashboard.
	 * @return array{ok:bool,message:string} Outcome, with a sentence for the administrator.
	 */
	public function redeem( string $code ): array {
		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() || ! user_can( $user, 'publish_posts' ) ) {
			// ⛔ Checked against the user the credential will BELONG to, not merely against
			// `manage_options`. An application password inherits its user's capabilities, so
			// one minted for an administrator who cannot publish produces a connection that
			// authenticates perfectly and then fails at the first post — with an error about
			// permissions that names nothing the customer can act on.
			return array(
				'ok'      => false,
				'message' => __( 'This WordPress user cannot publish posts, so a connection made now could not publish either. Sign in as a user who can, and try again.', 'zinn-connector' ),
			);
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Application passwords are turned off on this site, so we cannot create a credential. Ask your host to enable them, or connect the site with a password you create yourself in your Zinn Digital® dashboard.', 'zinn-connector' ),
			);
		}

		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => self::CREDENTIAL_NAME )
		);

		if ( is_wp_error( $created ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'WordPress would not create a credential for this site. Nothing has been sent anywhere.', 'zinn-connector' ),
			);
		}

		list( $password, $item ) = $created;

		$response = wp_remote_post(
			zinn_connector_api_base() . '/v1/connector/claim',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'code'                 => $code,
						'site_url'             => home_url(),
						'username'             => $user->user_login,
						'application_password' => $password,
						'site_name'            => get_bloginfo( 'name' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// ⛔⛔ **The credential is destroyed when the handover fails.** Otherwise every
			// failed attempt leaves a live application password on the customer's site that
			// nothing holds and nobody will ever revoke — the plugin would accumulate
			// credentials as a side effect of not working.
			$this->forget( $user->ID, (string) ( $item['uuid'] ?? '' ) );
			return array(
				'ok'      => false,
				'message' => __( 'We could not reach Zinn Digital® from this site. Nothing was connected, and the credential we made has been removed. Check the site can make outgoing requests, and try again.', 'zinn-connector' ),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $status ) {
			$this->forget( $user->ID, (string) ( $item['uuid'] ?? '' ) );
			$detail = is_array( $body ) && isset( $body['detail'] ) ? (string) $body['detail'] : '';
			return array(
				'ok'      => false,
				// ⭐ The engine's own sentence when there is one — it is authored for this
				// reader and says what to do. The fallback never invents a reason.
				'message' => '' !== $detail
					? $detail
					: __( 'Zinn Digital® did not accept that pairing code. Nothing was connected. Generate a fresh code in your dashboard and try again.', 'zinn-connector' ),
			);
		}

		update_option(
			'zinn_connector_state',
			array(
				'connected_at'  => time(),
				'user_login'    => $user->user_login,
				// ⛔ The UUID, so the settings screen can link to the right row in the user's
				// profile — never the password, which is not stored anywhere on this site by
				// WordPress either (only its hash).
				'password_uuid' => (string) ( $item['uuid'] ?? '' ),
				'site_id'       => is_array( $body ) && isset( $body['site_id'] ) ? (string) $body['site_id'] : '',
			),
			false
		);

		return array(
			'ok'      => true,
			'message' => __( 'Connected. Zinn Digital® can now publish to this site.', 'zinn-connector' ),
		);
	}

	/**
	 * Remove an application password we created and then could not hand over.
	 *
	 * @param int    $user_id The owning user.
	 * @param string $uuid    The credential's uuid.
	 */
	private function forget( int $user_id, string $uuid ): void {
		if ( '' === $uuid || ! class_exists( 'WP_Application_Passwords' ) ) {
			return;
		}
		WP_Application_Passwords::delete_application_password( $user_id, $uuid );
	}
}
