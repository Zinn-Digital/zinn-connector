<?php
/**
 * Plugin Name:       Zinn® Connector
 * Plugin URI:        https://zinndigital.com
 * Description:       Connects this WordPress site to Zinn Digital® so scheduled articles can be published to it. Paste a pairing code from your Zinn® dashboard and the plugin sets up its own credential — nothing is copied by hand.
 * Version:           1.0.0
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
 * ⛔⛔ **THIS PLUGIN IS NOT A SECOND WAY TO PUBLISH, and that distinction is the whole reason
 * "both" was affordable.** It obtains an ordinary WordPress **application password** and hands
 * it to Zinn Digital; publishing then goes down the same REST path an application password
 * typed by hand would use. `docs/202` forbids a second path to a customer's live site —
 * publishing is a durable workflow with an idempotent ledger and four independent layers
 * against double-posting, because posting twice across 300 network sites is a footprint event,
 * not a duplicate row.
 *
 * ⛔ It exposes NO endpoint of its own. It makes one outbound call, when an administrator
 * presses a button. A plugin that opened a route would be a new attack surface on every site
 * it is installed on, for no capability we do not already have.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZINN_CONNECTOR_VERSION', '1.0.0' );
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

require_once __DIR__ . '/includes/class-zinn-connector-settings.php';
require_once __DIR__ . '/includes/class-zinn-connector-claim.php';
require_once __DIR__ . '/includes/class-zinn-connector-dashboard.php';

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'zinn-connector', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		( new Zinn_Connector_Settings() )->register();
		( new Zinn_Connector_Dashboard() )->register();
	}
);

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
