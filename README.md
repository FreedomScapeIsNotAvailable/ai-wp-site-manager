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

## How self-update works

The plugin header declares `Update URI: https://github.com/FreedomScapeIsNotAvailable/ai-wp-site-manager`.
WordPress therefore asks the plugin itself about updates. The plugin reads
`dist/manifest.json` from this repository (cached for six hours), compares versions, and if a
newer one exists offers `dist/ai-wp-site-manager.zip` through the normal update screen. It
only ever downloads from this repository over HTTPS.

## Releasing a new version

See the docstring in `build.py`. In short: bump the version in the PHP header and the
`CSM_VERSION` constant, add a changelog entry, run `python build.py`, commit, push.
