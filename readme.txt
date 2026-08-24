=== Zinn® Connector ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: publishing, content, api, automation, seo
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect this site to Zinn Digital® so scheduled articles can be published to it, without copying a credential by hand.

== Description ==

If you write your articles in Zinn Digital® and this WordPress site is hosted somewhere else, this plugin is how the two are introduced.

Paste a pairing code from your Zinn Digital® dashboard and the plugin creates a WordPress **application password** for itself and hands it to Zinn Digital®. You never see or copy the credential, so it cannot be pasted into the wrong window or left in a support ticket.

The plugin does not change your site. It adds no endpoint, no shortcode and nothing to your public pages. It makes exactly one outbound request — when an administrator presses the Connect button — and shows a dashboard panel with links to Zinn Digital®'s own services.

= What it needs =

* WordPress application passwords must be enabled (they are, by default, on any site served over HTTPS).
* The WordPress user connecting the site must be able to publish posts. The credential inherits that user's permissions.

= Disconnecting =

Revoke the "Zinn Digital® (publishing)" application password under Users → Profile → Application Passwords. Deactivating the plugin does **not** revoke it, deliberately — deactivating a connector to test something should not silently stop your publishing.

== Installation ==

1. Upload the plugin and activate it.
2. In Zinn Digital®, open Content → Other sites and press "Get a pairing code".
3. In WordPress, go to Settings → Zinn Digital®, paste the code, and press Connect.

== Frequently Asked Questions ==

= Does it send my content anywhere? =

No. It sends the site's address, the WordPress username, and a credential it creates for Zinn Digital®. Nothing else leaves the site.

= Can I connect without the plugin? =

Yes. Create an application password yourself under Users → Profile and enter it in your Zinn Digital® dashboard. The plugin exists so you do not have to handle the credential.

= Does it slow my site down? =

No. Everything it does happens in wp-admin, and it makes no requests on the front end.

== Changelog ==

= 1.0.0 =
* First release.
