<?php
/**
 * Plugin Name:       Zinn® Connector
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/zinn-connector
 * Description:       Connects this WordPress site to Zinn Digital® so scheduled articles can be published to it. Paste a pairing code from your Zinn® dashboard and the plugin sets up its own credential — nothing is copied by hand.
 * Version:           1.2.1
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zinn-connector
 * Domain Path:       /languages
 * Update URI:        https://zinndigital.com
 *
 * @package ZinnConnector
 *
 * ⚖️ **Owner ruling, 2026-08-22, asked directly whether to ship an application-password flow
 * OR a connector plugin:** *"we shoukd offer both app passowrd or the connector pluign and the
 * connector plkugin must upsell our hsoting and markeplac as well as the Zinnhub.com global
 * freelance marketplace in their admin dashboard"*. Both shipped; this is the second.
 *
 * ⭐ **The upsell that ruling required now lives in `includes/class-zinn-connector-promo.php`, shared by
 * every Zinn plugin** (W37-BH, 2026-09-01, on the owner's wider instruction that *all* the
 * plugins promote hosting, the marketplace and Zinn Hub®). This plugin's own
 * `Zinn_Connector_Dashboard` widget was the prototype and is **deleted, not disabled**: it
 * promoted the same three destinations, so leaving it beside the shared panel gave a site
 * running the Connector and Zinn® Cache together two identical widgets on one dashboard —
 * exactly the aggressive advertising a WordPress.org reviewer rejects, and it would have been
 * our own doing. Nothing the ruling asked for was lost; the guide link was gained.
 *
 * ⛔⛔ **THIS PLUGIN IS NOT A SECOND WAY TO PUBLISH, and that distinction is the whole reason
 * "both" was affordable.** It obtains an ordinary WordPress **application password** and hands
 * it to Zinn Digital; publishing then goes down the same REST path an application password
 * typed by hand would use. `docs/202` forbids a second path to a customer's live site —
 * publishing is a durable workflow with an idempotent ledger and four independent layers
 * against double-posting, because posting twice across 300 network sites is a footprint event,
 * not a duplicate row.
 *
 * ⛔ It exposes NO endpoint of its own. A plugin that opened a route would be a new attack
 * surface on every site it is installed on, for no capability we do not already have.
 *
 * ⛔⛤ **AND THE SENTENCE THAT USED TO FOLLOW THAT ONE WAS FALSE: *"It makes one outbound call,
 * when an administrator presses a button."*** True of 1.0.0, and untrue from the moment
 * `class-zinn-connector-backup.php` landed beside it — an hourly cron poll, an upload and a
 * completion call — and untrue again when the updater was added. The identical claim was in
 * `readme.txt`, and the WordPress.org reviewer pended the submission over it on 2026-09-05.
 * ⭐ Neither copy was ever *edited* to become wrong: they were correct, a feature was added,
 * and nothing on the estate asks a comment whether it is still true. **Outbound requests are
 * enumerated in ONE place now — `readme.txt`'s `== External services ==` — and this docblock
 * points at it rather than repeating it**, because two descriptions of one behaviour is one
 * description and one lie (§2.56, one subject over).
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZINN_CONNECTOR_VERSION', '1.2.1' );
define( 'ZINN_CONNECTOR_FILE', __FILE__ );

/**
 * Where the claim is posted.
 *
 * ⛔ Filterable so a staging site can point at `api.dev.zinndigital.com` without editing a
 * plugin file — but it defaults to production and is never read from the database, so a
 * compromised option cannot redirect a customer's credential to somebody else's server.
 */
function zinn_connector_api_base(): string {
	/**
	 * Filters the Zinn Digital API base URL.
	 *
	 * @param string $base Absolute URL with no trailing slash.
	 */
	return (string) apply_filters( 'zinn_connector_api_base', 'https://api.zinndigital.com' );
}

// ⛔ The shared settings framework is loaded UNCONDITIONALLY, not behind `is_admin()`.
// `Zinn_Connector_Admin_UI::get()` is read by the publishing guard on a REST request and by
// the backup job in WP-Cron — both of which are front-end contexts. A framework registered
// only in wp-admin is a plugin whose settings silently become their defaults everywhere that
// matters (§2.38).
require_once __DIR__ . '/includes/class-zinn-connector-admin-fields.php';
require_once __DIR__ . '/includes/class-zinn-connector-admin-ui.php';
require_once __DIR__ . '/includes/class-zinn-connector-connection.php';
require_once __DIR__ . '/includes/class-zinn-connector-diagnostics.php';
require_once __DIR__ . '/includes/class-zinn-connector-style-presets.php';
require_once __DIR__ . '/includes/class-zinn-connector-status.php';
require_once __DIR__ . '/includes/class-zinn-connector-guard.php';
require_once __DIR__ . '/includes/class-zinn-connector-settings.php';
require_once __DIR__ . '/includes/class-zinn-connector-claim.php';
require_once __DIR__ . '/includes/class-zinn-connector-streaming-upload.php'; // Generated by wp/bin/build-streaming-upload.php.
require_once __DIR__ . '/includes/class-zinn-connector-backup.php';
require_once __DIR__ . '/includes/class-zinn-connector-updater.php'; // Generated by wp/bin/build-updater.php.

add_action(
	'plugins_loaded',
	static function (): void {
		// ⛔ Registered on every request, not only in wp-admin: the limits it enforces apply
		// to the REST calls our engine makes, which never touch an admin screen. It declares
		// no translated strings at registration time, so it is safe this early.
		Zinn_Connector_Guard::register();
		( new Zinn_Connector_Updater( ZINN_CONNECTOR_FILE, ZINN_CONNECTOR_VERSION ) )->register();
		// ⛔ Self-healing schedule. `ensure_scheduled()` is guarded by `wp_next_scheduled`,
		// so calling it on every load queues nothing extra — and it restores the event on a
		// site whose cron table was cleared by a migration or a host's "optimisation" tool,
		// which is otherwise a site that silently stops being backed up.
		// ⛔ Scheduling moved to `init`, below: the settings that decide whether to schedule
		// at all are not readable until the page has been declared, and asking early gets the
		// DEFAULT rather than the customer's answer — a switch that visibly does nothing.
	}
);

// ⛔⛔ THE TEXT DOMAIN AND THE SETTINGS SCREENS LOAD ON `init`, NOT ON `plugins_loaded`.
// WordPress 6.7 raises *"Translation loading for the zinn-connector domain was triggered too
// early"* — a `_doing_it_wrong` notice on EVERY request — for any `__()` that runs before
// `init`, and a settings page declares translated labels by construction. Measured on a real
// WordPress 7.1 before this moved; invisible to `php -l`, to the stub test suite and to
// reading the code back (§2.24).
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'zinn-connector', false, dirname( plugin_basename( ZINN_CONNECTOR_FILE ) ) . '/languages' );
		( new Zinn_Connector_Settings() )->register();

		// ⛔ Self-healing schedule, guarded by `wp_next_scheduled`, so calling it on every
		// load queues nothing extra — and it restores the event on a site whose cron table
		// was cleared by a migration or a host's "optimisation" tool, which is otherwise a
		// site that silently stops being backed up.
		if ( Zinn_Connector_Backup::enabled() ) {
			Zinn_Connector_Backup::ensure_scheduled();
		} else {
			Zinn_Connector_Backup::unschedule();
		}
	},
	5
);

// ⛔ The cron callback is registered UNCONDITIONALLY, not inside `plugins_loaded`'s closure.
// WP-Cron fires on a bare request where `plugins_loaded` has run but any conditional
// registration keyed on admin context has not — a hook registered too narrowly is an event
// that fires into nothing, for ever, with no error anywhere.
add_action(
	Zinn_Connector_Backup::CRON_HOOK,
	static function (): void {
		( new Zinn_Connector_Backup() )->run();
	}
);

// ⛔ Deactivation must unschedule. A disabled plugin whose event survives goes on polling
// Zinn hourly from a site that is no longer connected — traffic the customer did not ask for
// and cannot see the cause of.
register_deactivation_hook( __FILE__, array( 'Zinn_Connector_Backup', 'unschedule' ) );

// ⛔ A disconnected site that goes on polling us hourly is traffic the customer did not ask
// for and cannot see the cause of — the same reason deactivation unschedules. `Disconnect`
// is a deliberate act by the site owner and must stop everything, not only publishing.
add_action( 'zinn_connector_disconnected', array( 'Zinn_Connector_Backup', 'unschedule' ) );

/**
 * Uninstall is handled by `uninstall.php`, not by a hook.
 *
 * ⛔⛔ THERE WAS A `register_uninstall_hook()` HERE AND IT MADE THE PLUGIN IMPOSSIBLE TO
 * ACTIVATE (D13181, W26-I). It was passed a closure, and that function does not store a
 * callback — it serialises one into the `uninstall_plugins` option, so activation died with
 * `Uncaught Exception: Serialization of 'Closure' is not allowed`, on every WordPress
 * version and not just the current one. See `uninstall.php` for the full account. ⛔ Do not
 * reintroduce a hook here: `uninstall.php` already takes precedence over one, so a second
 * mechanism would be dead code that looks load-bearing.
 */

// ── The Zinn® panel ──────────────────────────────────────────────────────────────────────
//
// ⚖️ Owner, 2026-09-01: *"each plugin should promote our hosting and marketplace as well as
// Zinn Hub global marketplace inside people's site in the admin dashboard"*, and *"user
// guides for them … linked to in the plugins dashboard"*.
//
// ⛔ `require_once` rather than the autoloader, and a STRING callable rather than
// `array( Zinn_Connector_Promo::class, … )`. The class is deliberately global — it is shipped
// identically into seven plugins with different namespacing conventions, and three of them
// bootstrap inside a namespace where `Zinn_Connector_Promo::class` would resolve to a class that does
// not exist. A string callable is resolved in the global namespace at call time, which is
// correct from every one of the seven. `php -l` cannot see that mistake; only running it can.
require_once __DIR__ . '/includes/class-zinn-connector-promo.php';
add_action( 'plugins_loaded', array( 'Zinn_Connector_Promo', 'register' ) );
