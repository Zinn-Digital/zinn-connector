<?php
/**
 * The face this plugin wears on a site paired under a white-label reseller (ANS-209 / P7-229).
 *
 * ⚖️ Owner answer, 2026-11-16: *"Zinn Connector for a white-label reseller's clients:
 * white-labelled with the reseller's brand."* The engine says which face to wear in the two
 * answers the plugin already reads — the `201` of `POST /v1/connector/claim` and
 * `GET /v1/connector/status` — as a `brand` object (`ConnectorBrand` in the OpenAPI spec). It is
 * kept in the `zinn_connector_brand` option, so a change of brand reaches the site on its next
 * status check and the admin screens never call out to find it.
 *
 * When `white_label` is true:
 * - every string in this plugin's text domain has our marks replaced with the reseller's name
 *   (`gettext_zinn-connector`), AFTER translation, so it holds in all 58 locales without a new
 *   msgid — a brand name is a proper noun and is never translated;
 * - the plugin row on the Plugins screen carries the reseller's name;
 * - the dashboard links point at the reseller's own dashboard when it serves one;
 * - the Zinn® panel (our adverts) is switched off for this plugin;
 * - the application password is renamed, so the profile screen agrees with the messages.
 *
 * ⛔ The Author header and the readme credit stay ours (§2.15): that is attribution, required by
 * the plugin licence terms we publish under, and it is not part of the admin screens a client uses.
 * ⛔ The reseller ITSELF and a client of an UNBRANDED reseller get `white_label: false` from the
 * engine and see our copy, unchanged.
 * ⛔ A white-label brand with no usable name (a test reseller named after us) uses its dashboard's
 * host; with neither, our copy stays — there is no name of theirs to print — but the adverts still go.
 *
 * @package ZinnConnector
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The stored brand and what it changes.
 */
final class Zinn_Connector_Brand {

	/**
	 * The option the last answer from the engine is kept in.
	 */
	public const OPTION = 'zinn_connector_brand';

	/**
	 * The link prefix our own copy uses for the customer dashboard.
	 */
	public const OUR_DASHBOARD = 'https://app.zinndigital.com';

	/**
	 * The plugin's main file, relative to the plugins directory.
	 */
	private const PLUGIN_FILE = 'zinn-connector/zinn-connector.php';

	/**
	 * Our marks, longest first, so a longer mark is never half-replaced by a shorter one.
	 */
	private const MARKS = array(
		'api.zinndigital.com',
		'Zinn Digital®',
		'Zinn Digital', // brand-gate: allow — the bare form a white-label reseller's brand replaces; it is matched, never rendered.
		'Zinn®',
	);

	/**
	 * Register the hooks. Safe at file load: nothing here translates or reads before it runs.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'gettext_zinn-connector', array( __CLASS__, 'filter_gettext' ), 20, 1 );
		add_filter( 'ngettext_zinn-connector', array( __CLASS__, 'filter_gettext' ), 20, 1 );
		add_filter( 'gettext_with_context_zinn-connector', array( __CLASS__, 'filter_gettext' ), 20, 1 );
		add_filter( 'all_plugins', array( __CLASS__, 'filter_all_plugins' ), 20, 1 );
		add_filter( 'zinn_promo_enabled', array( __CLASS__, 'filter_promo_enabled' ), 20, 2 );
	}

	/**
	 * A brand as the engine sent it, made safe to store. Pure.
	 *
	 * ⛔ Anything that is not the documented shape is our face: a malformed answer must never
	 * white-label a site, nor strip one.
	 *
	 * @param mixed $raw The decoded `brand` object.
	 * @return array{white_label: bool, name: string, support_url: string, dashboard_url: string}
	 */
	public static function sanitize( $raw ): array {
		$ours = array(
			'white_label'   => false,
			'name'          => '',
			'support_url'   => '',
			'dashboard_url' => '',
		);
		if ( ! is_array( $raw ) || true !== ( $raw['white_label'] ?? null ) ) {
			return $ours;
		}
		// ⛔ The engine already blanks a name that is ours (`is_our_name`); it is not second-guessed here.
		$name = is_string( $raw['name'] ?? null ) ? trim( wp_strip_all_tags( $raw['name'] ) ) : '';
		return array(
			'white_label'   => true,
			'name'          => mb_substr( $name, 0, 200 ),
			'support_url'   => self::https_url( $raw['support_url'] ?? '' ),
			'dashboard_url' => self::https_url( $raw['dashboard_url'] ?? '' ),
		);
	}

	/**
	 * Keep the brand from an engine answer. An answer with no `brand` (an older engine) changes nothing.
	 *
	 * @param mixed $raw The decoded `brand` object, or null.
	 * @return void
	 */
	public static function remember( $raw ): void {
		if ( null === $raw ) {
			return;
		}
		$brand = self::sanitize( $raw );
		if ( get_option( self::OPTION, null ) !== $brand ) {
			update_option( self::OPTION, $brand, true );
		}
		self::rename_credential( $brand );
	}

	/**
	 * The stored brand.
	 *
	 * @return array{white_label: bool, name: string, support_url: string, dashboard_url: string}
	 */
	public static function current(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * The name our marks become, or '' to keep our copy. Pure.
	 *
	 * @param array<string, mixed> $brand A sanitized brand.
	 * @return string
	 */
	public static function display_name( array $brand ): string {
		if ( empty( $brand['white_label'] ) ) {
			return '';
		}
		if ( '' !== (string) $brand['name'] ) {
			return (string) $brand['name'];
		}
		$host = (string) wp_parse_url( (string) $brand['dashboard_url'], PHP_URL_HOST );
		return self::is_ours( $host ) ? '' : $host;
	}

	/**
	 * A string with our marks replaced by the brand's name. Pure.
	 *
	 * @param string               $text  The (already translated) string.
	 * @param array<string, mixed> $brand A sanitized brand.
	 * @return string
	 */
	public static function rewrite( string $text, array $brand ): string {
		if ( false === stripos( $text, 'zinn' ) ) {
			return $text;
		}
		$name = self::display_name( $brand );
		if ( '' === $name ) {
			return $text;
		}
		return str_replace( self::MARKS, $name, $text );
	}

	/**
	 * A dashboard link on the brand's own dashboard when it serves one. Pure.
	 *
	 * @param string               $url   One of our dashboard links.
	 * @param array<string, mixed> $brand A sanitized brand.
	 * @return string
	 */
	public static function dashboard_link( string $url, array $brand ): string {
		if ( empty( $brand['white_label'] ) || '' === (string) $brand['dashboard_url'] ) {
			return $url;
		}
		if ( ! str_starts_with( $url, self::OUR_DASHBOARD . '/' ) && self::OUR_DASHBOARD !== $url ) {
			return $url;
		}
		return (string) $brand['dashboard_url'] . substr( $url, strlen( self::OUR_DASHBOARD ) );
	}

	/**
	 * The Plugins-screen row under the brand. Pure.
	 *
	 * @param array<string, array<string, string>> $plugins Every installed plugin's header data.
	 * @param array<string, mixed>                 $brand   A sanitized brand.
	 * @return array<string, array<string, string>>
	 */
	public static function plugin_rows( array $plugins, array $brand ): array {
		if ( ! isset( $plugins[ self::PLUGIN_FILE ] ) || '' === self::display_name( $brand ) ) {
			return $plugins;
		}
		foreach ( array( 'Name', 'Title', 'Description' ) as $field ) {
			if ( isset( $plugins[ self::PLUGIN_FILE ][ $field ] ) ) {
				$plugins[ self::PLUGIN_FILE ][ $field ] = self::rewrite( (string) $plugins[ self::PLUGIN_FILE ][ $field ], $brand );
			}
		}
		return $plugins;
	}

	/**
	 * `gettext_zinn-connector` (and its plural and context siblings).
	 *
	 * @param mixed $translation The translated string.
	 * @return mixed
	 */
	public static function filter_gettext( $translation ) {
		// ⛔ Fast exit before the option is read: this runs for every string the plugin prints.
		if ( ! is_string( $translation ) || false === stripos( $translation, 'zinn' ) ) {
			return $translation;
		}
		return self::rewrite( $translation, self::current() );
	}

	/**
	 * `all_plugins`.
	 *
	 * @param mixed $plugins Every installed plugin's header data.
	 * @return mixed
	 */
	public static function filter_all_plugins( $plugins ) {
		return is_array( $plugins ) ? self::plugin_rows( $plugins, self::current() ) : $plugins;
	}

	/**
	 * `zinn_promo_enabled`: no advert of ours on a white-labelled site. Pure in its decision.
	 *
	 * @param mixed  $on   Whether the panel is shown.
	 * @param string $slug The plugin asking.
	 * @return bool
	 */
	public static function filter_promo_enabled( $on, $slug = '' ): bool {
		return self::promo_allowed( (bool) $on, (string) $slug, self::current() );
	}

	/**
	 * Whether a plugin's Zinn® panel may show under this brand. Pure.
	 *
	 * @param bool                 $on    The answer so far.
	 * @param string               $slug  The plugin asking.
	 * @param array<string, mixed> $brand A sanitized brand.
	 * @return bool
	 */
	public static function promo_allowed( bool $on, string $slug, array $brand ): bool {
		if ( 'zinn-connector' === $slug && ! empty( $brand['white_label'] ) ) {
			return false;
		}
		return $on;
	}

	/**
	 * The application password's name under this brand. Pure.
	 *
	 * @param array<string, mixed> $brand A sanitized brand.
	 * @return string
	 */
	public static function credential_name( array $brand ): string {
		return self::rewrite( Zinn_Connector_Claim::CREDENTIAL_NAME, $brand );
	}

	/**
	 * Rename the stored application password to match the brand, so the profile screen and the
	 * messages that name it agree. Best effort: a failure changes only a label.
	 *
	 * @param array<string, mixed> $brand A sanitized brand.
	 * @return void
	 */
	private static function rename_credential( array $brand ): void {
		$state = get_option( 'zinn_connector_state', array() );
		if ( ! is_array( $state ) || empty( $state['password_uuid'] ) || empty( $state['user_login'] ) ) {
			return;
		}
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return;
		}
		$user = get_user_by( 'login', (string) $state['user_login'] );
		if ( ! $user ) {
			return;
		}
		$uuid = (string) $state['password_uuid'];
		$item = WP_Application_Passwords::get_user_application_password( $user->ID, $uuid );
		$name = self::credential_name( $brand );
		if ( is_array( $item ) && ( $item['name'] ?? '' ) !== $name ) {
			WP_Application_Passwords::update_application_password( $user->ID, $uuid, array( 'name' => $name ) );
		}
	}

	/**
	 * An absolute https:// URL with no trailing slash, or ''. Pure.
	 *
	 * @param mixed $raw A URL.
	 * @return string
	 */
	private static function https_url( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$url  = rtrim( trim( $raw ), '/' );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || '' === $host || self::is_ours( $host ) ) {
			return '';
		}
		return esc_url_raw( $url );
	}

	/**
	 * Whether a host is one of ours. Pure.
	 *
	 * @param string $host A host.
	 * @return bool
	 */
	private static function is_ours( string $host ): bool {
		$host = strtolower( trim( $host ) );
		return 'zinndigital.com' === $host || str_ends_with( $host, '.zinndigital.com' );
	}
}
