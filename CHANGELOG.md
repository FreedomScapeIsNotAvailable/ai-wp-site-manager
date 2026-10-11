# Changelog

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
