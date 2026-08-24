<?php
/**
 * Uninstall handler: forget the stored connection state.
 *
 * ⛔⛔ THIS FILE REPLACES A `register_uninstall_hook()` CALL THAT MADE THE PLUGIN
 * IMPOSSIBLE TO ACTIVATE (D13181, W26-I, 2026-08-24). The hook was passed a **closure**, and
 * `register_uninstall_hook()` does not store a callback — it `maybe_serialize()`s it into the
 * `uninstall_plugins` option, so WordPress raised
 * `Uncaught Exception: Serialization of 'Closure' is not allowed` and the activation fataled.
 * Not on WordPress 7.1 in particular: on **every** version, since the option has always been
 * serialised. The plugin had never once been activated on real WordPress.
 *
 * ⭐ It was invisible to every gate we had. PHPCS passes (valid code), `php -l` passes (it
 * parses), and `wp/tests/` is PHPUnit against hand-written STUBS of WordPress functions —
 * a stub of `register_uninstall_hook` accepts a closure happily, because the thing that
 * refuses it is core's serializer, and there is no core in that harness. Found the first
 * time anything ran these plugins against real WordPress
 * (`wp/bin/test-wp-compat.sh`, which is the other instrument — §2.43's `channel` axis).
 *
 * ⭐ `uninstall.php` rather than a named function: it is what the WordPress.org directory
 * prefers, it takes precedence over the hook, and it is the pattern `zinn-reseller` already
 * uses — so the repair removes the mechanism that failed instead of writing a second,
 * carefully-correct instance of it.
 *
 * ⛔ The application password itself is deliberately NOT revoked. It belongs to the WordPress
 * user, is listed in their profile, and revoking it from here would silently break a
 * customer's publishing the moment they uninstall to try something else. The settings screen
 * tells them where to revoke it if that is what they want.
 *
 * @package Zinn\Connector
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

const ZINN_CONNECTOR_UNINSTALL_OPTION = 'zinn_connector_state';

if ( is_multisite() ) {
	$zinn_connector_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $zinn_connector_site_ids as $zinn_connector_site_id ) {
		switch_to_blog( (int) $zinn_connector_site_id );
		delete_option( ZINN_CONNECTOR_UNINSTALL_OPTION );
		restore_current_blog();
	}
} else {
	delete_option( ZINN_CONNECTOR_UNINSTALL_OPTION );
}
