# Changelog

## 1.1.1
- Send no-cache headers on all ai-wp/v1 responses so page caches (LiteSpeed, CDNs) never serve them publicly.
- Purge LiteSpeed cache after the plugin updates or activates.
- The updates endpoint always re-reads the release manifest, so new releases show up immediately.

## 1.1.0
- Self-update from this repository via the WordPress Update URI mechanism.
- Installable as a normal plugin (zip upload) as well as a must-use plugin.

## 1.0.0
- REST endpoints under ai-wp/v1: list updates, update plugin, update theme, install/activate/delete theme.
