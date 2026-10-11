# Changelog

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
