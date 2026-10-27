# AI WP Site Manager

A small WordPress plugin that adds REST endpoints for things the core REST API cannot do:
update plugins and themes, and install, activate or delete themes. It is used by the
Claude WordPress connector so that an administrator's Application Password is enough to
keep sites up to date remotely.

- One PHP file, no settings page, no database tables.
- Every endpoint checks the normal WordPress capability (`update_plugins`, `update_themes`,
  `install_themes`, `switch_themes`, `delete_themes`). Unauthenticated calls get a 401.
- Uses WordPress's own upgrader classes, the same code path as the "Update now" button.
- Updates itself from this repository through the standard WordPress update screen.

## Install

Download [dist/ai-wp-site-manager.zip](dist/ai-wp-site-manager.zip), then in wp-admin go to
Plugins, Add New Plugin, Upload Plugin, choose the zip, Install Now, Activate.

With WP-CLI:

```bash
wp plugin install https://raw.githubusercontent.com/FreedomScapeIsNotAvailable/ai-wp-site-manager/main/dist/ai-wp-site-manager.zip --activate
```

Alternative: copy `ai-wp-site-manager.php` into `wp-content/mu-plugins/` (top level, not a
subfolder). Must-use plugins cannot be deactivated from wp-admin but also do not self-update.

## Endpoints

All under `/wp-json/ai-wp/v1/`, JSON in and out, authenticated with an Application Password.

| Method | Route | Body | Capability |
|---|---|---|---|
| GET | `updates` | | update_plugins or update_themes |
| POST | `plugins/update` | `{"plugin": "akismet/akismet"}` | update_plugins |
| POST | `themes/update` | `{"stylesheet": "twentytwentyfive"}` | update_themes |
| POST | `themes/install` | `{"slug": "astra"}` | install_themes |
| POST | `themes/activate` | `{"stylesheet": "astra"}` | switch_themes |
| POST | `themes/delete` | `{"stylesheet": "twentytwentyfour"}` | delete_themes |

Hosts that need FTP credentials for updates (filesystem method is not `direct`) get a clear
error. Add `define('FS_METHOD', 'direct');` to `wp-config.php` or fix file ownership.

## Settings page: llms.txt and robots.txt

Settings -> AI Site Manager (Administrators only) edits the physical `llms.txt` and `robots.txt`
in the web root. Files are edited in place and created on first save, so existing files keep
working and nothing needs deleting. The previous version is kept for a one-click restore, and a
`Disallow: /` line triggers a warning. The same operations are available over REST:
`GET ai-wp/v1/rootfiles`, `GET|POST ai-wp/v1/rootfiles/<name>` with `{"content": "..."}`,
`POST ai-wp/v1/rootfiles/<name>/restore`.

## Application passwords without the REST route

Some hosting firewalls answer 403 to anything under `/wp-json/wp/v2/users/`, which breaks the
Profile page's Application Passwords box. Settings -> AI Site Manager lists, creates and revokes
the logged-in administrator's application passwords server-side, so the connector can be set up
on such hosts too.

## Redirects

Settings -> AI Site Manager has a redirects box: one `/old-path/ -> /new-path/` per line, served as
301 before WordPress routes the request. Query strings are preserved. WordPress core only
redirects renamed posts, never pages, so renamed or deleted pages need an entry. Same list over
REST: `GET|POST ai-wp/v1/redirects` with `{"text": "..."}`.

## Automatic updates

`GET ai-wp/v1/auto-updates` lists every plugin and theme with its auto-update flag, plus whether
WordPress updates itself for major and minor releases. It also reports anything that overrides
those settings (`AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE`, filters).

`POST ai-wp/v1/auto-updates` switches them, writing the same options as the Plugins, Themes and
Dashboard -> Updates screens:

```json
{"plugins": "all", "themes": "all", "core": "all"}
```

`plugins` and `themes` take `"all"`, `"none"` or `{"akismet/akismet.php": true}` per item. `core`
takes `"all"` (major and minor), `"minor"` (security releases only) or `"off"`. `"all"` covers
what is installed now; call it again after installing something new. Each part needs its
capability: `update_plugins`, `update_themes` or `update_core`.

## Content includes

Write `{INCLUDE:/pricepage/}` (or a full URL on the same host) anywhere in a page or post.
On display it is replaced with the HTML that URL returns, so a script elsewhere on the site
can generate part of a page. Results are cached for 10 minutes (`ai_wp_include_ttl` filter);
if the URL fails, the last successful copy is shown. Only this site and the team's own hosts
(bocp.ro, cloudsales.ro; filter `ai_wp_include_hosts`) are accepted. On LiteSpeed
Cache sites the page's cache lifetime is lowered to the same 10 minutes.

## How self-update works

The plugin header declares `Update URI: https://github.com/FreedomScapeIsNotAvailable/ai-wp-site-manager`.
WordPress therefore asks the plugin itself about updates. The plugin reads
`dist/manifest.json` from this repository (cached for six hours), compares versions, and if a
newer one exists offers `dist/ai-wp-site-manager.zip` through the normal update screen. It
only ever downloads from this repository over HTTPS.

On first run the plugin enables WordPress's automatic updates for itself, so sites install new
releases on their own within about 12 hours. Administrators can disable that on the Plugins page.

## Releasing a new version

See the docstring in `build.py`. In short: bump the version in the PHP header and the
`CSM_VERSION` constant, add a changelog entry, run `python build.py`, commit, push.
