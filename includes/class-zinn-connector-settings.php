<?php
/**
 * The Settings → Zinn Digital screen: paste a code, see the connection.
 *
 * @package ZinnConnector
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One screen, one field, one button.
 */
class Zinn_Connector_Settings {

	private const PAGE  = 'zinn-connector';
	private const NONCE = 'zinn_connector_claim';

	/** Transient prefix for the last connect outcome, per user. Short-lived by design. */
	private const RESULT_KEY = 'zinn_connector_result_';

	/**
	 * Hook the screen up.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_zinn_connector_claim', array( $this, 'handle_claim' ) );
	}

	/**
	 * Add the settings page.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Zinn® Connector', 'zinn-connector' ),
			__( 'Zinn® Connector', 'zinn-connector' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = (array) get_option( 'zinn_connector_state', array() );

		/*
		 * ⛔⛔ The outcome travels in a per-user TRANSIENT, not in the query string, and that
		 * is a correctness choice before it is a lint one. A `$_GET` read on a screen that also
		 * processes a form is `WordPress.Security.NonceVerification.Recommended` — and the sniff
		 * is right: a message rendered straight out of the URL is a surface anyone can put text
		 * into by handing an administrator a link. Escaping makes that harmless rather than
		 * absent. A transient cannot be set by whoever crafted the link.
		 *
		 * ⭐ Deleted on read, so a refresh does not re-show a result the customer has already
		 * seen and acted on.
		 */
		$result = get_transient( self::RESULT_KEY . get_current_user_id() );
		delete_transient( self::RESULT_KEY . get_current_user_id() );
		$notice = is_array( $result ) ? (string) ( $result['message'] ?? '' ) : '';
		$ok     = is_array( $result ) && ! empty( $result['ok'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zinn® Connector', 'zinn-connector' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>">
					<p><?php echo esc_html( $notice ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $state['connected_at'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: the WordPress username the credential belongs to. */
						esc_html__( 'This site is connected to Zinn Digital®, publishing as %s.', 'zinn-connector' ),
						'<strong>' . esc_html( (string) ( $state['user_login'] ?? '' ) ) . '</strong>'
					);
					?>
				</p>
				<p>
					<?php
					printf(
						/* translators: %s: link to the WordPress profile screen. */
						esc_html__( 'To disconnect, revoke the “Zinn Digital® (publishing)” application password on %s.', 'zinn-connector' ),
						'<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'your profile', 'zinn-connector' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connect this site', 'zinn-connector' ); ?></h2>
			<p>
				<?php esc_html_e( 'Open your Zinn Digital® dashboard, go to Content → Other sites, and press “Get a pairing code”. Paste it below.', 'zinn-connector' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zinn_connector_claim" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="zinn_connector_code"><?php esc_html_e( 'Pairing code', 'zinn-connector' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="zinn_connector_code"
								name="zinn_connector_code"
								class="regular-text"
								autocomplete="off"
								spellcheck="false"
								required
							/>
							<p class="description">
								<?php esc_html_e( 'It expires thirty minutes after you generate it, and can only be used once.', 'zinn-connector' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Connect this site', 'zinn-connector' ) ); ?>
			</form>
			<?php
			// ⛔⛔ AT THE BOTTOM OF THE SCREEN, INSIDE `.wrap`, BELOW THE CONTROLS — NEVER ABOVE
			// THEM. Somebody who opened a settings screen came to change a setting. A promotion
			// that pushes the thing they came for below the fold is the "disruptive upselling"
			// a WordPress.org reviewer rejects, and it would deserve it.
			Zinn_Connector_Promo::render_panel();
			?>
		</div>
		<?php
	}

	/**
	 * Handle the form post.
	 *
	 * ⛔ Capability check **and** nonce, in that order. The nonce stops a cross-site request
	 * riding an administrator's session; the capability check stops a subscriber who has one
	 * legitimately. Neither substitutes for the other, and this form's outcome is handing a
	 * live publishing credential to an external service.
	 */
	public function handle_claim(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this site.', 'zinn-connector' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$code = isset( $_POST['zinn_connector_code'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['zinn_connector_code'] ) )
			: '';

		$result = ( new Zinn_Connector_Claim() )->redeem( $code );

		// 60 seconds: long enough for the redirect that follows, short enough that a result
		// cannot resurface on a screen opened much later.
		set_transient( self::RESULT_KEY . get_current_user_id(), $result, 60 );

		wp_safe_redirect(
			add_query_arg( array( 'page' => self::PAGE ), admin_url( 'options-general.php' ) )
		);
		exit;
	}
}
