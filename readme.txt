=== Zinn® Connector ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-connector
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: auto post, publishing, backup, application passwords, seo
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.2.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-publish articles from Zinn Digital® to this WordPress site, with optional off-site backups. Pair once with a code — no password to copy.

== Description ==

**Zinn® Connector links a WordPress site hosted anywhere to Zinn Digital®, so the articles you write and schedule there are published here automatically — with their images, in the category and under the author you choose — and, if you want it, the whole site is backed up off-site.**

If you write your articles in Zinn Digital® and this WordPress site is hosted somewhere else, this plugin is how the two are introduced.

Paste a pairing code from your Zinn Digital® dashboard and the plugin creates a WordPress **application password** for itself and hands it to Zinn Digital®. You never see or copy the credential, so it cannot be pasted into the wrong window or left in a support ticket.

The plugin does not change your site. It adds no endpoint, no shortcode and nothing to your public pages, and it makes no outbound request at all until you pair it.

Everything it sends is listed under **External services** below. In short: one request when you press Connect, a connection-status check for its own screen, and — only if you switch scheduled backups on in your Zinn Digital® dashboard — an hourly check for a due backup, plus the upload itself when one is due.

= What you can do with it =

* **Pair in one step.** A single-use pairing code replaces copying an application password between two browser tabs.
* **Publish scheduled articles automatically.** Articles go live straight away, or arrive as drafts if you would rather read everything first.
* **Decide what may happen on your site.** Choose the author shown on each article and the category it lands in, and switch image uploads, edits to earlier articles and a per-article email notification on or off.
* **See whether the connection works.** The status panel says whether the site is paired and, when something is wrong, what is wrong and what to do about it — with a button to check again.
* **Disconnect for real.** "Disconnect this site" deletes the application password, so the credential Zinn Digital® holds stops working at once.
* **Back the site up off-site (optional).** Files and database are archived and streamed in fixed-size chunks straight to object storage, so memory use does not grow with the site. Leave folders out, set a size limit, and a backup is skipped rather than started when the disk has no room for it.
* **Ask for help without exposing secrets.** A support report shows you the exact, already-redacted payload before anything is sent.
* **Use it in your language.** Every screen is translated into 57 languages, right-to-left included.

= Who it is for =

Anyone who writes, schedules or commissions articles in Zinn Digital® for a WordPress site that Zinn Digital® does not host — a client's site, a brand site on another host, or a network of sites. Sites hosted with Zinn Digital® are connected for you and do not need it.

= What it needs =

* WordPress application passwords must be enabled (they are, by default, on any site served over HTTPS).
* The WordPress user connecting the site must be able to publish posts. The credential inherits that user's permissions.

= Disconnecting =

Press **Disconnect this site** on the plugin's Connection tab, or revoke the "Zinn Digital® (publishing)" application password under Users → Profile → Application Passwords. Deactivating the plugin does **not** revoke it, deliberately — deactivating a connector to test something should not silently stop your publishing.

== Installation ==

1. In wp-admin, open Plugins → Add New Plugin, search for **Zinn® Connector**, then choose Install Now and Activate.
2. In Zinn Digital®, open Content → Other sites and press "Get a pairing code".
3. In WordPress, open Zinn Digital® → Connector, paste the code, and press Connect this site.

== Screenshots ==

1. Pairing: paste the code from your Zinn Digital® dashboard and press Connect — the plugin creates and hands over its own credential.
2. Publishing controls: live or draft, the author and category to use, whether images and edits are allowed, and an email per published article.
3. Off-site backups: switch them on, leave folders out, and set a size limit so a large site stops with a message instead of failing silently.
4. The Zinn Digital® overview in wp-admin shows whether each Zinn® plugin on the site is working.
5. Every screen is translated — here the Connection tab in Arabic, right to left.

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
* **Scheduled backups — only after you switch them on in your Zinn Digital® dashboard.** A
  WordPress cron event then asks `https://api.zinndigital.com/v1/connector/backup/claim`, once
  an hour, whether a backup is due; the answer is normally "no" and nothing else happens. When
  one *is* due the plugin builds a `.tar.gz` of this site's files and database, **uploads it
  straight to object storage** using a short-lived, single-use URL the previous answer supplied
  — the archive never passes through Zinn Digital®'s own servers — and then posts the outcome
  to `/v1/connector/backup/complete`. Until you switch backups on, that hourly event finds no
  backup token and returns without contacting anything.
* **Update checks.** The plugin asks Zinn Digital® whether a newer version of itself exists.
  Only the plugin's own slug and version are sent.

**No content, visitor data or analytics are transmitted.** The plugin makes no outbound request
of any kind until you pair it.

Service terms: https://zinndigital.com/legal/terms
Privacy policy: https://zinndigital.com/legal/privacy

* **Support diagnostics (only when you press send).** If you ask us for help, the plugin can send
  a support report to `https://api.zinndigital.com/v1/connector/diagnostics`. **You are shown the
  exact payload first, already redacted, and nothing leaves your site until you press send.**
  Credentials are excluded by declaration rather than by matching key names, and render as
  `[not sent — credential]`. The plugin never sends this on its own initiative.

* **Connection status.** `https://api.zinndigital.com/v1/connector/status` reports whether this
  site's pairing is still live, for the connection state shown on the plugin's own screen.

== Translations ==

**Every string this plugin adds to your admin is translated into 57 languages** — labels, notices,
errors and settings, not a subset. The catalogues are bundled in the plugin, so they work as soon
as you set your site language; there is no separate language pack to install.

They are bundled rather than left to translate.wordpress.org because that service can only offer
the languages volunteers have got to, and Zinn Digital® sells its platform in all 57 of these — a
customer whose site is in Amharic or Khmer would otherwise read an English admin screen
indefinitely. A community translation is always preferred where one exists: WordPress loads a
language pack ahead of a bundled catalogue, so contributing on translate.wordpress.org replaces
ours rather than competing with it.

Every user-visible string is complete in each of the 53 languages WordPress can serve today:

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

= Is the plugin free? =

Yes. The plugin is free and GPL-licensed. It connects your site to a Zinn Digital® account, which is where articles are written and scheduled; there is no paid version of the plugin and nothing in it is locked.

= Does it work with any host? =

Yes. It runs on any WordPress 6.6 or later with PHP 8.2 or later, wherever the site is hosted. The only requirement is that WordPress application passwords are enabled, which they are by default on any site served over HTTPS.

= Can articles arrive as drafts instead of going live? =

Yes. On the Publishing tab, set "Publish articles as" to a draft and every article waits for you to review and publish it yourself.

= Does it send my content anywhere? =

No. It sends the site's address, the WordPress username, and a credential it creates for Zinn Digital®. Nothing else leaves the site.

= Can I connect without the plugin? =

Yes. Create an application password yourself under Users → Profile and enter it in your Zinn Digital® dashboard. The plugin exists so you do not have to handle the credential.

= Does it slow my site down? =

No. Pairing and every screen it adds are in wp-admin, and it adds nothing at all to your public
pages — no script, no stylesheet, no markup.

If you switch scheduled backups on, WordPress's own cron runs the hourly "is a backup due?"
check, and WordPress cron is triggered by a visit. That check is one short request to Zinn
Digital® and it happens **after** the page has been sent to the visitor, so it does not delay
anybody. With backups off, the plugin never runs on a front-end request.

== Changelog ==

= 1.2.2 =
* Fixed: when Zinn Digital® could not finish a connection, the plugin said your pairing code had been refused and told you to generate a new one. It now says the problem is on our side and that the same code still works.
* Fixed: the "not connected" notice on the Zinn Digital® overview told you to paste the code "below", where there is no field. It now points to this plugin’s settings.
* Fixed three translations: Arabic showed "ثلاث0" instead of "thirty" minutes, Amharic showed a garbled label for Backups, and Thai carried a stray digit in the settings-import message.
* Now listed in the WordPress.org plugin directory, so you can install it from Plugins → Add New Plugin by searching for "Zinn® Connector".

= 1.2.1 =
* Hardening: a settings rule can no longer be mistaken for a PHP function with the same name. The same shared settings code is what stopped Zinn® Translate saving its settings. Nothing about how this plugin behaves changes.

= 1.2.0 =
A real connection status that says what is wrong and what to do about it, a Disconnect button that actually revokes the credential, and full control over what may be published to your site — status, author, category, images, edits and email notifications. Backups gain an on/off switch, exclusions and a size limit.
= 1.1.3 =
* Fixed: a scheduled backup could exhaust PHP's memory on a large site. The archive is now compressed and uploaded in fixed-size chunks, so peak memory no longer depends on how big the site is.
* Added: a backup is not attempted unless there is room on disk for it, rather than filling the disk and taking the site down.
* Corrected the description of what the plugin sends and when — it listed one outbound request and made several.

= 1.1.2 =
* Fixed: a new release of this plugin could not reach a site that already had it, so no update ever appeared on the Plugins screen.
* Added: automatic updates from the Zinn Digital® control plane — the same signed, checksum-verified update path the other Zinn® plugins use.

= 1.1.0 =
* Added the Zinn® panel: links to Zinn Digital® hosting, the Zinn® marketplace, Zinn Hub® and this plugin's user guide, from inside the WordPress admin.
* Removed an unreachable dashboard screen that no menu entry pointed at.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.2.2 =
Clearer messages when a connection cannot be completed, and three translation fixes. Recommended for everyone.
