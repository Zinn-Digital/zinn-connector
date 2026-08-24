<?php
/**
 * The wp-admin dashboard widget — what Zinn Digital sells, where the site owner can see it.
 *
 * @package ZinnConnector
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A dashboard widget naming the three things we sell.
 *
 * ⚖️ **Required by the owner, 2026-08-22, in the same sentence that approved this plugin:**
 * *"the connector plkugin must upsell our hsoting and markeplac as well as the Zinnhub.com
 * global freelance marketplace in their admin dashboard"*. Three destinations, named
 * explicitly, and all three are here.
 *
 * ⛔⛔ **THIS IS OUR SURFACE INSIDE THEIR ADMIN, WHICH IS NOT THE SAME THING AS OUR CONTENT ON
 * THEIR SITE — and the difference is a hard rule, not a nicety.** `docs/203` §8 (lane W25-P,
 * ruled this same day): our brand stops at the **publish boundary**. A logged-in
 * administrator looking at wp-admin is looking at a plugin they installed from us, so our name
 * belongs here. An article we generate and publish is the *customer's* content on the
 * *customer's* public domain, and a Zinn token recurring across it is a **footprint event
 * before it is a branding one** (§2.14) — the exact signature a footprint audit hunts for
 * across a network. Nothing in this file may ever be rendered by the front end, which is why
 * every entry point is an `admin_*` hook and there is no shortcode, no widget and no filter on
 * `the_content`.
 *
 * ⭐ It is also `capability`-gated to the site's own administrators. A dashboard widget shown
 * to every contributor would be advertising to people who did not choose to see it.
 */
class Zinn_Connector_Dashboard {

	/**
	 * Hook the widget up.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Register the widget for administrators only.
	 */
	public function add_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'zinn_connector_overview',
			__( 'Zinn Digital®', 'zinn-connector' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Render the widget.
	 *
	 * ⛔ No remote call and no tracking pixel. A dashboard widget that fetched anything would
	 * put a third-party request on the critical path of every admin page load on a site we do
	 * not host — slow for them, and a liability for us the first time our API is down.
	 */
	public function render(): void {
		$links = array(
			array(
				'url'   => 'https://zinndigital.com/hosting',
				'title' => __( 'Managed WordPress hosting', 'zinn-connector' ),
				'body'  => __( 'Move this site onto Zinn Digital® and publishing needs no credential at all — we hold the server, so it just works. LiteSpeed, daily backups, and a control panel built for people who run more than one site.', 'zinn-connector' ),
			),
			array(
				'url'   => 'https://zinndigital.com/marketplace',
				'title' => __( 'The Zinn® marketplace', 'zinn-connector' ),
				'body'  => __( 'Buy and sell established sites, domains and link placements, with the money held safely until both sides are happy.', 'zinn-connector' ),
			),
			array(
				'url'   => 'https://zinnhub.com',
				'title' => __( 'Zinnhub — hire people who do this for a living', 'zinn-connector' ),
				'body'  => __( 'Our global freelance marketplace: writers, designers, developers and SEOs, for the jobs you would rather not do yourself.', 'zinn-connector' ),
			),
		);
		?>
		<ul>
			<?php foreach ( $links as $link ) : ?>
				<li style="margin-bottom:1em;">
					<strong>
						<?php // ⛔ `noopener` on every external target. A window opened with `_blank` can otherwise reach back through `window.opener`, and this widget links off a site we do not control the destination styling of. ?>
						<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $link['title'] ); ?>
						</a>
					</strong>
					<br />
					<span><?php echo esc_html( $link['body'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
