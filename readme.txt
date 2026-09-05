=== Zinn® Connector ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-connector
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: publishing, content, api, automation, seo
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.0
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

== External services ==

This plugin connects your WordPress site to Zinn Digital® so that articles scheduled there are
published to this site. It is useless without that service, and it only ever talks to it.

**What is sent, and when**

* **Pairing (once, when you paste a pairing code and click Connect).** The plugin posts the code
  to `https://api.zinndigital.com/v1/connector/claim` together with your site URL, and — this is
  the part to be clear about — **it creates a WordPress application password for the connecting
  administrator and sends that password to Zinn Digital®.** That credential is what lets the
  service publish posts to this site through the WordPress REST API. It is created by
  `WP_Application_Passwords`, is scoped to one user, and is visible and revocable at any time
  under **Users → Profile → Application Passwords**.
* **Disconnecting.** Removing the connection deletes that application password from this site, so
  the credential Zinn Digital® holds stops working immediately.
* **Backups (only when you start one).** The plugin posts to
  `/v1/connector/backup/claim` and `/v1/connector/backup/complete` to obtain an upload
  destination and report completion.

**No content, visitor data or analytics are transmitted**, and the plugin makes no outbound
request until you pair it.

Service terms: https://zinndigital.com/legal/terms
Privacy policy: https://zinndigital.com/legal/privacy

== Translations ==

**Every string this plugin adds to your admin is translated into 57 languages** — labels, notices,
errors and settings, not a subset. The catalogues are bundled in the plugin, so they work as soon
as you set your site language; there is no separate language pack to install.

All 33 user-visible strings are complete in every one of the 53 languages WordPress can serve
today:

Amharic (am), Arabic (ar), Azerbaijani (az), Bulgarian (bg_BG), Bengali (Bangladesh)
(bn_BD), Czech (cs_CZ), German (de_DE), Greek (el), Spanish (Spain) (es_ES), Persian
(fa_IR), French (France) (fr_FR), Gujarati (gu), Hebrew (he_IL), Hindi (hi_IN), Croatian
(hr), Hungarian (hu_HU), Armenian (hy), Indonesian (id_ID), Italian (it_IT), Japanese
(ja), Georgian (ka_GE), Kazakh (kk), Khmer (km), Kannada (kn), Korean (ko_KR), Lao (lo),
Malayalam (ml_IN), Mongolian (mn), Marathi (mr), Malay (ms_MY), Myanmar (Burmese)
(my_MM), Nepali (ne_NP), Dutch (nl_NL), Panjabi (India) (pa_IN), Polish (pl_PL), Pashto
(ps), Portuguese (Brazil) (pt_BR), Romanian (ro_RO), Russian (ru_RU), Sinhala (si_LK),
Albanian (sq), Serbian (sr_RS), Swahili (sw), Tamil (ta_IN), Telugu (te), Thai (th),
Tagalog (tl), Turkish (tr_TR), Ukrainian (uk), Urdu (ur), Uzbek (uz_UZ), Vietnamese
(vi), Chinese (China) (zh_CN)

A further 4 ship complete in the plugin — Hausa (ha), Somali (so_SO), Tajik (tg), Yoruba (yo) — but
WordPress core does not currently provide a locale for them, so WordPress cannot load them.

= Right-to-left =

Arabic, Persian, Hebrew, Pashto and Urdu are right-to-left. Every screen this plugin adds was
rendered in a real WordPress install in each of those languages and checked, not assumed.

= For translators =

`languages/` holds the `.pot` template plus a `.po`, `.mo` and `.l10n.php` for every language, so
corrections and new languages can be contributed directly.

== Frequently Asked Questions ==

= Does it send my content anywhere? =

No. It sends the site's address, the WordPress username, and a credential it creates for Zinn Digital®. Nothing else leaves the site.

= Can I connect without the plugin? =

Yes. Create an application password yourself under Users → Profile and enter it in your Zinn Digital® dashboard. The plugin exists so you do not have to handle the credential.

= Does it slow my site down? =

No. Everything it does happens in wp-admin, and it makes no requests on the front end.

== Changelog ==

= 1.1.0 =
* Added the Zinn® panel: links to Zinn Digital® hosting, the Zinn® marketplace, Zinn Hub® and this plugin's user guide, from inside the WordPress admin.
* Removed an unreachable dashboard screen that no menu entry pointed at.

= 1.0.0 =
* First release.
