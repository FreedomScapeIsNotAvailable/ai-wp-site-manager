# Changelog

## 1.16.0
- Bulk user cleanup: GET ai-wp/v1/users/role-count?role=subscriber and POST ai-wp/v1/users/delete-by-role {role, expect_count, reassign, limit 1..500, default 200} delete the users of one role in batches (e.g. subscribers imported years ago), giving their content to the reassign user. Administrators, users who can manage options and the calling user are never deleted; the administrator role is refused; expect_count must equal the live count (409 otherwise, nothing deleted). Needs manage_options and delete_users; single sites only. Built because the core batch endpoint is blocked by some server firewalls.

## 1.15.0
- GET/POST ai-wp/v1/seo-meta/&lt;id&gt;: read and set the SEO title, meta description, canonical URL and noindex of one post or page, for Yoast SEO or Rank Math (whichever is active). Only the fields sent are changed, an empty string clears a field, noindex null returns to the site default. Yoast's indexable is rebuilt at once, so the new description is served without re-saving the page; the page cache of that post is purged. Needs edit_post on that post; 409 when no SEO plugin is active.

## 1.14.0
- Security keys: GET ai-wp/v1/salts reports whether the eight AUTH/SECURE_AUTH/LOGGED_IN/NONCE KEY and SALT constants are plain define() lines in wp-config.php (never their values) and when they were last rotated; POST ai-wp/v1/salts/rotate replaces them with new 64-character random values, and Settings -> AI Site Manager has a "Rotate security keys" button. Every user is logged out; application passwords keep working.
- Safe by refusal: a key that is missing, defined twice or loaded from somewhere else stops the rotation with nothing written. The new file is checked to parse, written to a 0600 temp file next to wp-config.php with the original permissions and renamed over it; opcache is invalidated and the LiteSpeed cache purged. No copy of wp-config.php is stored. Needs manage_options (manage_network_options on multisite).

## 1.13.0
- Includes from forms.bocp.eu (AIWP_INCLUDE_LIVE_HOSTS) are fetched fresh on every page view and the page is marked no-cache for LiteSpeed: a BOCP form carries a validation key that changes when the form is saved, so a cached copy failed on submit. The last good copy is still used if forms.bocp.eu does not answer. Other includes keep the 5-minute cache.

## 1.12.0
- {INCLUDE:} may also fetch from forms.bocp.eu, so a BOCP form's embed.html fragment (form only, scoped CSS) can be placed directly in a page instead of an iframe.
- Include cache lowered from 10 to 5 minutes (AIWP_INCLUDE_TTL 300; LiteSpeed's page TTL follows it on pages with an include).

## 1.11.0
- GET/POST ai-wp/v1/stub-shortcodes {names: []}: a list of leftover shortcode names (from removed plugins) that are registered as empty shortcodes, so they render as nothing instead of raw "[name]" text. A name already registered by an active plugin is left alone (GET reports it as registered_by_other_plugin). Names: letters, digits, _ and -, at most 50; the list replaces the previous one. Needs manage_options; removed on uninstall.

## 1.10.0
- GET ai-wp/v1/logs and GET ai-wp/v1/logs/&lt;key&gt;?kb=128: list and read the site's error logs (debug, aiwp, php, php-admin, php-ini) by fixed key; paths are resolved on the server and must stay inside the site. Reads return the last N KB (max 1024) from a line boundary, with passwords, tokens, keys and Authorization values masked.
- POST ai-wp/v1/logs/&lt;key&gt;/clear {expect: "size:mtime"}: empties a log in place, refuses with 409 if it changed since it was read, and keeps the last 256 KB in the option aiwp_log_prev_&lt;key&gt;.
- GET/POST ai-wp/v1/error-logging {enabled, hours}: timed PHP error logging (1 to 168 hours, default 24) through a must-use plugin that removes itself at the deadline, without editing wp-config.php. Logs go to a randomly named file outside the web root when possible (else wp-content/aiwp-logs with deny rules), stop at 5 MB, and leave WP_DEBUG_LOG alone when it is already on. Deactivating or uninstalling the plugin removes the switch.
- On multisite these routes need manage_network_options (the logs and the switch are network-wide); elsewhere manage_options.

## 1.9.0
- GET/POST ai-wp/v1/custom-css: read and replace a theme's Additional CSS (the Customizer's custom_css post), also for an installed theme that is not active yet. Lets a site recolour the shared BOCP theme without a child theme, and the CSS survives theme updates. Needs edit_css; CSS containing "<" is refused.

## 1.8.0
- GET/POST ai-wp/v1/auto-updates: read and switch WordPress's automatic updates for plugins, themes and core ("all", "none" or per item; core "all", "minor" or "off"), the same options as the Plugins, Themes and Updates screens. The reply also reports constants or filters that would block them.

## 1.7.1
- Security: content includes no longer expand inside a request made by an include (a page including itself could exhaust the server), are limited to 5 per page, follow no redirects, need HTTPS and are capped at 2 MB. HTML from authors without unfiltered_html goes through wp_kses_post, so an Author can no longer use an include to run a script on the site; the permanent last-good copy is kept only for trusted authors.
- Security: install-package accepts only exact release zips (raw.githubusercontent.com, FreedomScapeIsNotAvailable/wp-theme-bocp or ai-wp-site-manager, main/dist/..., .zip); ".." segments, other accounts, query strings and ports are refused (filter ai_wp_package_allowed).
- Security: the release manifest carries a sha256 of the zip, and the update is refused when the downloaded zip does not match.

## 1.7.0
- Content includes may read from the team's own sites (bocp.ro, cloudsales.ro; filter ai_wp_include_hosts), so one price script can feed several sites.

## 1.6.1
- GET/POST ai-wp/v1/theme-mods: read and set the active theme's Customizer settings (logo, colours, header buttons) remotely, so a theme switch can be completed in one go.

## 1.6.0
- POST themes/install-package and plugins/install-package: install or replace a theme/plugin from a zip under the team release location on GitHub (other URLs are refused). Lets the connector put the BOCP theme on a new site without a manual upload.

## 1.5.1
- The updates endpoint fires the ai_wp_before_update_check action so self-updating themes (e.g. the BOCP theme) refresh their release manifest immediately.

## 1.5.0
- 301 redirects: `/old/ -> /new/` lines in Settings -> AI Site Manager and at ai-wp/v1/redirects. Needed for renamed or removed pages, which WordPress core never redirects by itself.

## 1.4.0
- Settings page: list, create and revoke the current administrator's application passwords inside WordPress, for hosts whose firewall blocks /wp-json/wp/v2/users/.

## 1.3.2
- Enables WordPress automatic updates for this plugin on first run (can be switched off on the Plugins page).

## 1.3.1
- The updates endpoint always performs a real check instead of reusing WordPress's 12-hour cached result.

## 1.3.0
- Settings -> AI Site Manager: edit the physical llms.txt and robots.txt in the web root, with previous-version restore and a warning for site-wide Disallow rules.
- REST: GET/POST ai-wp/v1/rootfiles/<name> and POST .../restore (Administrators).

## 1.2.1
- Purge LiteSpeed cache also when the plugin is re-uploaded as a zip over an existing copy.

## 1.2.0
- Content includes: `{INCLUDE:/path/}` in a post or page is replaced with the HTML that same-site URL returns, cached 10 minutes, last good copy kept as fallback. LiteSpeed page TTL is lowered to match.

## 1.1.1
- Send no-cache headers on all ai-wp/v1 responses so page caches (LiteSpeed, CDNs) never serve them publicly.
- Purge LiteSpeed cache after the plugin updates or activates.
- The updates endpoint always re-reads the release manifest, so new releases show up immediately.

## 1.1.0
- Self-update from this repository via the WordPress Update URI mechanism.
- Installable as a normal plugin (zip upload) as well as a must-use plugin.

## 1.0.0
- REST endpoints under ai-wp/v1: list updates, update plugin, update theme, install/activate/delete theme.
