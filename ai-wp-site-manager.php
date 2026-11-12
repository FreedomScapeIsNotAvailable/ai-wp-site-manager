<?php
/**
 * Plugin Name:       AI WP Site Manager
 * Plugin URI:        https://github.com/FreedomScapeIsNotAvailable/ai-wp-site-manager
 * Description:       REST endpoints (namespace ai-wp/v1) for plugin/theme updates and theme management, plus {INCLUDE:/path/} content includes and a Settings page to edit llms.txt and robots.txt in place. Used by the team's Claude WordPress connector. Updates itself from GitHub.
 * Version:           1.18.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            reallife.solutions
 * Update URI:        https://github.com/FreedomScapeIsNotAvailable/ai-wp-site-manager
 *
 * Every endpoint requires the normal WordPress capability for the action
 * (update_plugins, update_themes, install_themes, switch_themes, delete_themes),
 * so it works with Application Passwords of Administrator users and nothing else.
 * It mirrors what wp-admin's own AJAX updaters do (wp_ajax_update_plugin etc.).
 *
 * Self-update: WordPress calls the update_plugins_github.com filter for plugins whose
 * Update URI points at github.com. We answer by reading dist/manifest.json from the
 * public repository, so the normal "update available" flow (and the connector's
 * update_plugin tool) works for this plugin like for any other.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'csm_register_routes' );

/*
 * Never let page caches store our responses. LiteSpeed Cache (and some CDNs) treat
 * Application-Password requests as anonymous because they carry no login cookie, and
 * would otherwise serve an administrator's update list to the public for days.
 */
add_filter( 'rest_post_dispatch', 'csm_no_cache_headers', 10, 3 );
function csm_no_cache_headers( $response, $server, $request ) {
	if ( 0 !== strpos( $request->get_route(), '/ai-wp/v1/' ) ) {
		return $response;
	}
	if ( ! ( $response instanceof WP_HTTP_Response ) ) {
		$response = rest_ensure_response( $response );
	}
	foreach ( wp_get_nocache_headers() as $name => $value ) {
		$response->header( $name, $value );
	}
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
	$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
	do_action( 'litespeed_control_set_nocache', 'ai-wp-site-manager API response' );
	return $response;
}

/* After this plugin is updated, flush LiteSpeed's page cache so no stale copy of our responses survives. */
add_action( 'upgrader_process_complete', 'csm_purge_after_self_update', 10, 2 );
function csm_purge_after_self_update( $upgrader, $hook_extra ) {
	if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
		return;
	}
	$plugins = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array();
	$mine    = in_array( plugin_basename( __FILE__ ), $plugins, true );
	// A zip re-upload ("Replace current with uploaded") arrives as an install, not an update.
	if ( ! $mine && isset( $upgrader->result['destination_name'] ) && 'ai-wp-site-manager' === $upgrader->result['destination_name'] ) {
		$mine = true;
	}
	if ( $mine ) {
		do_action( 'litespeed_purge_all' );
	}
}
register_activation_hook( __FILE__, function () { do_action( 'litespeed_purge_all' ); } );

function csm_register_routes() {
	$ns = 'ai-wp/v1';

	register_rest_route( $ns, '/updates', array(
		'methods'             => 'GET',
		'callback'            => 'csm_list_updates',
		'permission_callback' => function () {
			return current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' );
		},
	) );

	register_rest_route( $ns, '/plugins/update', array(
		'methods'             => 'POST',
		'callback'            => 'csm_update_plugin',
		'permission_callback' => function () { return current_user_can( 'update_plugins' ); },
		'args'                => array( 'plugin' => array( 'required' => true, 'type' => 'string' ) ),
	) );

	register_rest_route( $ns, '/themes/update', array(
		'methods'             => 'POST',
		'callback'            => 'csm_update_theme',
		'permission_callback' => function () { return current_user_can( 'update_themes' ); },
		'args'                => array( 'stylesheet' => array( 'required' => true, 'type' => 'string' ) ),
	) );

	register_rest_route( $ns, '/themes/install', array(
		'methods'             => 'POST',
		'callback'            => 'csm_install_theme',
		'permission_callback' => function () { return current_user_can( 'install_themes' ); },
		'args'                => array( 'slug' => array( 'required' => true, 'type' => 'string' ) ),
	) );

	register_rest_route( $ns, '/themes/activate', array(
		'methods'             => 'POST',
		'callback'            => 'csm_activate_theme',
		'permission_callback' => function () { return current_user_can( 'switch_themes' ); },
		'args'                => array( 'stylesheet' => array( 'required' => true, 'type' => 'string' ) ),
	) );

	register_rest_route( $ns, '/themes/delete', array(
		'methods'             => 'POST',
		'callback'            => 'csm_delete_theme',
		'permission_callback' => function () { return current_user_can( 'delete_themes' ); },
		'args'                => array( 'stylesheet' => array( 'required' => true, 'type' => 'string' ) ),
	) );
}

/* ------------------------------------------------------------------ helpers */

function csm_includes() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	require_once ABSPATH . 'wp-admin/includes/theme-install.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

/** Writes need direct filesystem access; FTP-credential hosts cannot be driven from the API. */
function csm_require_direct_fs() {
	csm_includes();
	$method = get_filesystem_method();
	if ( 'direct' !== $method ) {
		return new WP_Error(
			'csm_filesystem',
			sprintf( 'This site uses the "%s" filesystem method; updates need "direct" access (define FS_METHOD as direct in wp-config.php or fix file ownership).', $method ),
			array( 'status' => 500 )
		);
	}
	return true;
}

/** Accepts 'akismet/akismet' (REST style) or 'akismet/akismet.php'; returns the plugin file or WP_Error. */
function csm_normalize_plugin( $plugin ) {
	$plugin = ltrim( (string) $plugin, '/' );
	if ( '.php' !== substr( $plugin, -4 ) ) {
		$plugin .= '.php';
	}
	if ( 0 !== validate_file( $plugin ) ) {
		return new WP_Error( 'csm_bad_plugin', 'Invalid plugin identifier.', array( 'status' => 400 ) );
	}
	if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
		return new WP_Error( 'csm_not_found', 'Plugin not installed: ' . $plugin, array( 'status' => 404 ) );
	}
	return $plugin;
}

function csm_plugin_id( $file ) {
	return preg_replace( '/\.php$/', '', $file );
}

function csm_skin_error( WP_Ajax_Upgrader_Skin $skin, $fallback ) {
	if ( is_wp_error( $skin->result ) && $skin->result->has_errors() ) {
		$err = $skin->result;
	} elseif ( $skin->get_errors()->has_errors() ) {
		$err = $skin->get_errors();
	} else {
		$err = new WP_Error( 'csm_failed', $fallback );
	}
	$err->add_data( array( 'status' => 500, 'log' => $skin->get_upgrade_messages() ), $err->get_error_code() );
	return $err;
}

/* ------------------------------------------------------------------ updates list */

function csm_list_updates() {
	csm_includes();
	delete_site_transient( 'csm_update_manifest' ); // always look for a fresh release of this plugin too
	do_action( 'ai_wp_before_update_check' ); // lets self-updating themes/plugins drop their own manifest caches
	// WordPress skips the real check if it ran in the last 12 hours; forget the timestamp so this call is always fresh.
	foreach ( array( 'update_plugins', 'update_themes' ) as $transient ) {
		$t = get_site_transient( $transient );
		if ( is_object( $t ) && isset( $t->last_checked ) ) {
			unset( $t->last_checked );
			set_site_transient( $transient, $t );
		}
	}
	wp_version_check( array(), true );
	wp_update_plugins();
	wp_update_themes();

	$out = array( 'core' => array(), 'plugins' => array(), 'themes' => array() );

	$core = get_site_transient( 'update_core' );
	if ( $core && ! empty( $core->updates ) ) {
		foreach ( $core->updates as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response ) {
				$out['core'][] = array( 'current' => get_bloginfo( 'version' ), 'new' => $u->version );
			}
		}
	}

	$plugins = get_site_transient( 'update_plugins' );
	if ( $plugins && ! empty( $plugins->response ) ) {
		foreach ( $plugins->response as $file => $info ) {
			$data            = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$out['plugins'][] = array(
				'plugin'  => csm_plugin_id( $file ),
				'name'    => $data['Name'],
				'current' => $data['Version'],
				'new'     => $info->new_version,
				'active'  => is_plugin_active( $file ),
			);
		}
	}

	$themes = get_site_transient( 'update_themes' );
	if ( $themes && ! empty( $themes->response ) ) {
		foreach ( $themes->response as $stylesheet => $info ) {
			$theme           = wp_get_theme( $stylesheet );
			$out['themes'][] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'current'    => $theme->get( 'Version' ),
				'new'        => $info['new_version'],
				'active'     => get_stylesheet() === $stylesheet,
			);
		}
	}

	return $out;
}

/* ------------------------------------------------------------------ plugin update */

function csm_update_plugin( WP_REST_Request $req ) {
	$fs = csm_require_direct_fs();
	if ( is_wp_error( $fs ) ) {
		return $fs;
	}
	$plugin = csm_normalize_plugin( $req->get_param( 'plugin' ) );
	if ( is_wp_error( $plugin ) ) {
		return $plugin;
	}

	wp_update_plugins();
	$before     = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
	$was_active = is_plugin_active( $plugin );

	// bulk_upgrade() keeps the plugin active across the update, same as wp-admin's AJAX updater.
	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->bulk_upgrade( array( $plugin ) );

	if ( is_wp_error( $skin->result ) || $skin->get_errors()->has_errors() || false === $result ) {
		return csm_skin_error( $skin, 'Plugin update failed (filesystem credentials or download error).' );
	}

	wp_clean_plugins_cache( true );
	if ( $was_active && ! is_plugin_active( $plugin ) ) {
		activate_plugin( $plugin );
	}
	$after = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );

	return array(
		'plugin'  => csm_plugin_id( $plugin ),
		'name'    => $after['Name'],
		'from'    => $before['Version'],
		'to'      => $after['Version'],
		'updated' => $before['Version'] !== $after['Version'],
		'active'  => is_plugin_active( $plugin ),
		'log'     => $skin->get_upgrade_messages(),
	);
}

/* ------------------------------------------------------------------ theme update */

function csm_update_theme( WP_REST_Request $req ) {
	$fs = csm_require_direct_fs();
	if ( is_wp_error( $fs ) ) {
		return $fs;
	}
	$stylesheet = sanitize_text_field( $req->get_param( 'stylesheet' ) );
	$theme      = wp_get_theme( $stylesheet );
	if ( ! $theme->exists() ) {
		return new WP_Error( 'csm_not_found', 'Theme not installed: ' . $stylesheet, array( 'status' => 404 ) );
	}

	wp_update_themes();
	$before = $theme->get( 'Version' );

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Theme_Upgrader( $skin );
	$result   = $upgrader->bulk_upgrade( array( $stylesheet ) );

	if ( is_wp_error( $skin->result ) || $skin->get_errors()->has_errors() || false === $result ) {
		return csm_skin_error( $skin, 'Theme update failed (filesystem credentials or download error).' );
	}

	wp_clean_themes_cache( true );
	$after = wp_get_theme( $stylesheet )->get( 'Version' );

	return array(
		'stylesheet' => $stylesheet,
		'name'       => $theme->get( 'Name' ),
		'from'       => $before,
		'to'         => $after,
		'updated'    => $before !== $after,
		'active'     => get_stylesheet() === $stylesheet,
		'log'        => $skin->get_upgrade_messages(),
	);
}

/* ------------------------------------------------------------------ theme install */

function csm_install_theme( WP_REST_Request $req ) {
	$fs = csm_require_direct_fs();
	if ( is_wp_error( $fs ) ) {
		return $fs;
	}
	$slug = sanitize_key( $req->get_param( 'slug' ) );
	if ( wp_get_theme( $slug )->exists() ) {
		return new WP_Error( 'csm_exists', 'Theme already installed: ' . $slug, array( 'status' => 409 ) );
	}

	$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
	if ( is_wp_error( $api ) ) {
		$api->add_data( array( 'status' => 404 ), $api->get_error_code() );
		return $api;
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Theme_Upgrader( $skin );
	$result   = $upgrader->install( $api->download_link );

	if ( is_wp_error( $result ) || is_wp_error( $skin->result ) || $skin->get_errors()->has_errors() || ! $result ) {
		return csm_skin_error( $skin, 'Theme install failed.' );
	}

	wp_clean_themes_cache( true );
	$theme = wp_get_theme( $upgrader->theme_info()->get_stylesheet() );

	return array(
		'stylesheet' => $theme->get_stylesheet(),
		'name'       => $theme->get( 'Name' ),
		'version'    => $theme->get( 'Version' ),
		'active'     => false,
		'log'        => $skin->get_upgrade_messages(),
	);
}

/* ------------------------------------------------------------------ theme activate */

function csm_activate_theme( WP_REST_Request $req ) {
	$stylesheet = sanitize_text_field( $req->get_param( 'stylesheet' ) );
	$theme      = wp_get_theme( $stylesheet );
	if ( ! $theme->exists() ) {
		return new WP_Error( 'csm_not_found', 'Theme not installed: ' . $stylesheet, array( 'status' => 404 ) );
	}
	if ( $theme->errors() ) {
		$err = $theme->errors();
		$err->add_data( array( 'status' => 400 ), $err->get_error_code() );
		return $err;
	}
	if ( is_multisite() && ! $theme->is_allowed() ) {
		return new WP_Error( 'csm_not_allowed', 'Theme is not network-enabled.', array( 'status' => 403 ) );
	}
	$previous = get_stylesheet();
	switch_theme( $stylesheet );

	return array(
		'stylesheet' => get_stylesheet(),
		'name'       => wp_get_theme()->get( 'Name' ),
		'previous'   => $previous,
		'active'     => get_stylesheet() === $stylesheet,
	);
}

/* ------------------------------------------------------------------ theme delete */

function csm_delete_theme( WP_REST_Request $req ) {
	$fs = csm_require_direct_fs();
	if ( is_wp_error( $fs ) ) {
		return $fs;
	}
	$stylesheet = sanitize_text_field( $req->get_param( 'stylesheet' ) );
	$theme      = wp_get_theme( $stylesheet );
	if ( ! $theme->exists() ) {
		return new WP_Error( 'csm_not_found', 'Theme not installed: ' . $stylesheet, array( 'status' => 404 ) );
	}
	if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
		return new WP_Error( 'csm_active', 'Cannot delete the active theme or its parent.', array( 'status' => 409 ) );
	}

	$result = delete_theme( $stylesheet );
	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 500 ), $result->get_error_code() );
		return $result;
	}
	if ( false === $result ) {
		return new WP_Error( 'csm_failed', 'Theme delete failed.', array( 'status' => 500 ) );
	}

	return array( 'stylesheet' => $stylesheet, 'deleted' => true );
}

/* ------------------------------------------------------------------ content includes */

/*
 * {INCLUDE:/some/path/} or {INCLUDE:https://this-site.tld/some/path/} inside post/page content is
 * replaced with the HTML that URL returns. Only this site and the team's own hosts are allowed. The
 * result is cached for AIWP_INCLUDE_TTL seconds (filter 'ai_wp_include_ttl'); if the fetch fails,
 * the last good copy is used so a hiccup in the generating script never blanks the page.
 * LiteSpeed's page cache TTL is lowered to the same value on pages that contain an include.
 */
define( 'AIWP_INCLUDE_TTL', 300 );

add_filter( 'the_content', 'aiwp_render_includes', 20 );

/** Most includes expanded in one piece of content; the rest are dropped. */
define( 'AIWP_INCLUDE_MAX', 5 );

function aiwp_render_includes( $content ) {
	if ( false === strpos( $content, '{INCLUDE:' ) ) {
		return $content;
	}
	$pattern = '~(?:<p>\s*)?\{INCLUDE:([^}\s]+)\}(?:\s*</p>)?~';
	// A request made by an include must never expand includes itself, otherwise a page that includes itself
	// would make the server request itself until every PHP worker is busy.
	if ( ! empty( $_SERVER['HTTP_X_AIWP_INCLUDE'] ) ) {
		return preg_replace( $pattern, '', $content );
	}
	// Includes are raw HTML: only authors who may post unfiltered HTML get it unfiltered. For everyone else the
	// fetched HTML goes through wp_kses_post, so an Author cannot use an include to put a script on the site.
	$post    = get_post();
	$author  = $post ? (int) $post->post_author : 0;
	$trusted = $author && user_can( $author, 'unfiltered_html' );
	$count   = 0;
	// Also swallow the <p> wrapper wpautop puts around a placeholder that stands alone on a line.
	return preg_replace_callback(
		$pattern,
		function ( $m ) use ( $trusted, &$count ) {
			if ( ++$count > AIWP_INCLUDE_MAX ) {
				return '';
			}
			$ttl = aiwp_include_is_live( $m[1] ) ? 0 : (int) apply_filters( 'ai_wp_include_ttl', AIWP_INCLUDE_TTL, $m[1] );
			if ( $ttl > 0 ) {
				do_action( 'litespeed_control_set_ttl', $ttl );
			} else {
				do_action( 'litespeed_control_set_nocache', 'aiwp live include' );
			}
			$html = aiwp_fetch_include( $m[1], $ttl, $trusted );
			if ( null === $html ) {
				return '<!-- include unavailable: ' . esc_html( $m[1] ) . ' -->';
			}
			return $trusted ? $html : wp_kses_post( $html );
		},
		$content
	);
}

/** Hosts whose HTML may be included: this site, the team's own sites and the BOCP forms host (filter ai_wp_include_hosts to extend). */
define( 'AIWP_INCLUDE_HOSTS', 'bocp.ro,www.bocp.ro,cloudsales.ro,www.cloudsales.ro,forms.bocp.eu' );
function aiwp_include_allowed_hosts() {
	$hosts   = array_map( 'trim', explode( ',', AIWP_INCLUDE_HOSTS ) );
	$hosts[] = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	return array_values( array_unique( array_filter( array_map( 'strtolower', apply_filters( 'ai_wp_include_hosts', $hosts ) ) ) ) );
}

/*
 * Hosts fetched fresh on every view and never page-cached: a BOCP form carries a validation key that
 * changes when the form is saved, so a cached copy would fail on submit.
 */
define( 'AIWP_INCLUDE_LIVE_HOSTS', 'forms.bocp.eu' );
function aiwp_include_is_live( $target ) {
	$host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
	return $host && in_array( $host, array_map( 'trim', explode( ',', AIWP_INCLUDE_LIVE_HOSTS ) ), true );
}

function aiwp_fetch_include( $target, $ttl, $trusted = false ) {
	if ( 0 === strpos( $target, '/' ) ) {
		$url = home_url( $target );
	} else {
		$url    = $target;
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'https' !== $scheme || ! $host || ! in_array( $host, aiwp_include_allowed_hosts(), true ) ) {
			return null; // only this site and the team's own sites may be included, over HTTPS
		}
	}
	$key   = 'aiwp_inc_' . md5( $url );
	$fresh = $ttl > 0 ? get_transient( $key ) : false;
	if ( false !== $fresh ) {
		return $fresh;
	}
	$response = wp_remote_get( $url, array(
		'timeout'             => 15,
		'sslverify'           => true,
		'redirection'         => 0, // an allowed host must not be able to send the request somewhere else
		'limit_response_size' => 2 * MB_IN_BYTES,
		'headers'             => array( 'X-AIWP-Include' => '1', 'Cache-Control' => 'no-cache' ),
	) );
	$code = wp_remote_retrieve_response_code( $response );
	if ( ! is_wp_error( $response ) && 200 === $code ) {
		$html = wp_remote_retrieve_body( $response );
		if ( $ttl > 0 ) {
			set_transient( $key, $html, max( 60, $ttl ) );
		}
		if ( $trusted ) { // the permanent last-good copy is only kept for includes written by trusted authors
			update_option( $key . '_last_good', $html, false );
		}
		return $html;
	}
	if ( ! $trusted ) {
		return null;
	}
	$stale = get_option( $key . '_last_good', null );
	return is_string( $stale ) ? $stale : null;
}

/* ------------------------------------------------------------------ root files (llms.txt, robots.txt) */

/*
 * Settings -> AI Site Manager lets Administrators edit the physical llms.txt and robots.txt in the
 * site's web root. Files are edited in place (created on first save), the previous version is kept
 * for a one-click restore, and the same operations are exposed at ai-wp/v1/rootfiles for the connector.
 */
define( 'AIWP_ROOT_FILES', 'llms.txt,robots.txt' );

function aiwp_root_file_names() {
	return explode( ',', AIWP_ROOT_FILES );
}

function aiwp_root_dir() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	return trailingslashit( get_home_path() ); // web root, even when WordPress lives in a subdirectory
}

function aiwp_root_file_path( $name ) {
	return in_array( $name, aiwp_root_file_names(), true ) ? aiwp_root_dir() . $name : null;
}

function aiwp_root_file_info( $name ) {
	$path = aiwp_root_file_path( $name );
	if ( ! $path ) {
		return null;
	}
	$exists = file_exists( $path );
	$prev   = get_option( 'aiwp_rootfile_prev_' . $name );
	return array(
		'name'     => $name,
		'path'     => $path,
		'url'      => home_url( '/' . $name ),
		'exists'   => $exists,
		'content'  => $exists ? (string) file_get_contents( $path ) : '',
		'size'     => $exists ? filesize( $path ) : 0,
		'modified' => $exists ? gmdate( 'c', filemtime( $path ) ) : null,
		'writable' => $exists ? is_writable( $path ) : is_writable( aiwp_root_dir() ),
		'previous' => is_array( $prev ) ? $prev['saved'] : null,
	);
}

function aiwp_root_file_write( $name, $content ) {
	$path = aiwp_root_file_path( $name );
	if ( ! $path ) {
		return new WP_Error( 'aiwp_bad_file', 'Unknown file.', array( 'status' => 400 ) );
	}
	$content = str_replace( array( "\r\n", "\r" ), "\n", (string) $content );
	if ( '' !== $content && "\n" !== substr( $content, -1 ) ) {
		$content .= "\n";
	}
	if ( file_exists( $path ) ) {
		update_option( 'aiwp_rootfile_prev_' . $name, array( 'saved' => current_time( 'c' ), 'content' => (string) file_get_contents( $path ) ), false );
	}
	$ok = ( false !== file_put_contents( $path, $content, LOCK_EX ) );
	if ( ! $ok ) {
		return new WP_Error( 'aiwp_not_writable', 'Could not write ' . $path . '. Check file permissions on the web root.', array( 'status' => 500 ) );
	}
	do_action( 'litespeed_purge_url', home_url( '/' . $name ) );
	return aiwp_root_file_info( $name );
}

function aiwp_root_file_restore( $name ) {
	$prev = get_option( 'aiwp_rootfile_prev_' . $name );
	if ( ! is_array( $prev ) ) {
		return new WP_Error( 'aiwp_no_previous', 'No previous version stored.', array( 'status' => 404 ) );
	}
	return aiwp_root_file_write( $name, $prev['content'] );
}

/** Lines that would hide the whole site from a crawler. */
function aiwp_robots_warnings( $content ) {
	$warnings = array();
	if ( preg_match( '/^\s*Disallow:\s*\/\s*$/mi', $content ) ) {
		$warnings[] = 'Contains "Disallow: /" which blocks an entire crawler from the whole site.';
	}
	return $warnings;
}

/* REST: GET ai-wp/v1/rootfiles, GET/POST ai-wp/v1/rootfiles/<name>, POST ai-wp/v1/rootfiles/<name>/restore */
add_action( 'rest_api_init', 'aiwp_register_rootfile_routes' );
function aiwp_register_rootfile_routes() {
	$can = function () {
		return current_user_can( 'manage_options' );
	};
	register_rest_route( 'ai-wp/v1', '/rootfiles', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function () {
			return array_map( 'aiwp_root_file_info', aiwp_root_file_names() );
		},
	) );
	register_rest_route( 'ai-wp/v1', '/rootfiles/(?P<name>[a-z0-9.-]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function ( $req ) {
				$info = aiwp_root_file_info( $req['name'] );
				return $info ? $info : new WP_Error( 'aiwp_bad_file', 'Unknown file.', array( 'status' => 404 ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'args'                => array( 'content' => array( 'required' => true, 'type' => 'string' ) ),
			'callback'            => function ( $req ) {
				$res = aiwp_root_file_write( $req['name'], $req->get_param( 'content' ) );
				if ( ! is_wp_error( $res ) && 'robots.txt' === $req['name'] ) {
					$res['warnings'] = aiwp_robots_warnings( $res['content'] );
				}
				return $res;
			},
		),
	) );
	register_rest_route( 'ai-wp/v1', '/rootfiles/(?P<name>[a-z0-9.-]+)/restore', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => function ( $req ) {
			return aiwp_root_file_restore( $req['name'] );
		},
	) );
}

/* Admin page: Settings -> AI Site Manager */
add_action( 'admin_menu', 'aiwp_register_settings_page' );
function aiwp_register_settings_page() {
	add_options_page( 'AI Site Manager', 'AI Site Manager', 'manage_options', 'ai-wp-site-manager', 'aiwp_settings_page' );
}

add_action( 'admin_post_aiwp_save_rootfile', 'aiwp_handle_rootfile_form' );
function aiwp_handle_rootfile_form() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	$name = isset( $_POST['aiwp_file'] ) ? sanitize_file_name( wp_unslash( $_POST['aiwp_file'] ) ) : '';
	check_admin_referer( 'aiwp_rootfile_' . $name );
	$action = isset( $_POST['aiwp_action'] ) ? sanitize_key( $_POST['aiwp_action'] ) : 'save';
	if ( 'restore' === $action ) {
		$res = aiwp_root_file_restore( $name );
	} else {
		$content = isset( $_POST['aiwp_content'] ) ? wp_unslash( $_POST['aiwp_content'] ) : '';
		$res     = aiwp_root_file_write( $name, $content );
	}
	$status = is_wp_error( $res ) ? 'error' : ( 'restore' === $action ? 'restored' : 'saved' );
	set_transient(
		'aiwp_notice_' . get_current_user_id(),
		array( 'status' => $status, 'file' => $name, 'message' => is_wp_error( $res ) ? $res->get_error_message() : '' ),
		60
	);
	wp_safe_redirect( admin_url( 'options-general.php?page=ai-wp-site-manager' ) );
	exit;
}

function aiwp_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = get_transient( 'aiwp_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'aiwp_notice_' . get_current_user_id() );
	}
	echo '<div class="wrap"><h1>AI Site Manager</h1>';
	echo '<p>Version ' . esc_html( CSM_VERSION ) . '. Edits the files below directly in the web root, so what you see here is exactly what crawlers and AI agents receive. Each save keeps the previous version for a one-click restore.</p>';
	if ( $notice && 'created' !== $notice['status'] ) {
		$class = 'error' === $notice['status'] ? 'notice-error' : 'notice-success';
		$text  = 'error' === $notice['status'] ? $notice['message'] : ucfirst( $notice['status'] ) . ' ' . $notice['file'] . '.';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
	$help = array(
		'llms.txt'   => 'Markdown for AI agents: an H1 with the site name, a one-paragraph summary in a blockquote, then H2 sections with lists of links and one-line descriptions. Keep it short and current.',
		'robots.txt' => 'Crawler access rules. Name AI crawlers explicitly (GPTBot, ClaudeBot, Google-Extended for training; OAI-SearchBot, Claude-SearchBot, PerplexityBot for AI search; ChatGPT-User, Claude-User for on-demand fetches). Keep the Sitemap line.',
	);
	foreach ( aiwp_root_file_names() as $name ) {
		$info = aiwp_root_file_info( $name );
		echo '<h2 style="margin-top:2em"><code>/' . esc_html( $name ) . '</code></h2>';
		echo '<p class="description">' . esc_html( $help[ $name ] ) . '</p>';
		echo '<p><strong>File:</strong> <code>' . esc_html( $info['path'] ) . '</code> &middot; ';
		if ( $info['exists'] ) {
			echo esc_html( size_format( $info['size'] ) ) . ', last modified ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $info['modified'] ) ), 'Y-m-d H:i' ) ) . ' &middot; ';
		} else {
			echo 'does not exist yet' . ( 'robots.txt' === $name ? ' (WordPress is generating a default one; saving creates a real file)' : '' ) . ' &middot; ';
		}
		echo '<a href="' . esc_url( $info['url'] ) . '" target="_blank" rel="noopener">open ' . esc_html( $info['url'] ) . '</a></p>';
		if ( 'robots.txt' === $name ) {
			foreach ( aiwp_robots_warnings( $info['content'] ) as $w ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( $w ) . '</p></div>';
			}
		}
		if ( ! $info['writable'] ) {
			echo '<div class="notice notice-error inline"><p>This file is not writable by WordPress. Fix permissions on the web root, or upload the content by FTP.</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aiwp_rootfile_' . $name );
		echo '<input type="hidden" name="action" value="aiwp_save_rootfile"><input type="hidden" name="aiwp_file" value="' . esc_attr( $name ) . '">';
		echo '<textarea name="aiwp_content" rows="18" style="width:100%;max-width:900px;font-family:monospace" spellcheck="false"' . ( $info['writable'] ? '' : ' readonly' ) . '>' . esc_textarea( $info['content'] ) . '</textarea>';
		echo '<p>';
		if ( $info['writable'] ) {
			echo '<button type="submit" name="aiwp_action" value="save" class="button button-primary">Save ' . esc_html( $name ) . '</button> ';
			if ( $info['previous'] ) {
				echo '<button type="submit" name="aiwp_action" value="restore" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( 'Replace the current file with the version saved ' . $info['previous'] . '?' ) ) . ')">Restore previous version (' . esc_html( $info['previous'] ) . ')</button>';
			}
		}
		echo '</p></form>';
	}
	aiwp_render_app_passwords_section( $notice );
	aiwp_render_redirects_section();
	aiwp_render_salts_section();
	aiwp_render_security_section();
	echo '<h2 style="margin-top:2em">Content includes</h2><p class="description">Write <code>{INCLUDE:/some/path/}</code> in any page or post to insert the HTML that same-site URL returns (cached ' . esc_html( AIWP_INCLUDE_TTL / 60 ) . ' minutes).</p>';
	echo '</div>';
}

/* ------------------------------------------------------------------ application passwords (admin page, no REST) */

/*
 * Some hosting firewalls return an HTML 403 for anything under /wp-json/wp/v2/users/, which breaks the
 * profile page's Application Passwords box (it creates and revokes over that route). This section does the
 * same operations server-side from Settings -> AI Site Manager, for the logged-in administrator only.
 */
add_action( 'admin_post_aiwp_app_password', 'aiwp_handle_app_password_form' );
function aiwp_handle_app_password_form() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'aiwp_app_password' );
	$user_id = get_current_user_id();
	$action  = isset( $_POST['aiwp_action'] ) ? sanitize_key( $_POST['aiwp_action'] ) : '';
	$notice  = array( 'status' => 'error', 'file' => '', 'message' => 'Unknown action.' );

	if ( 'create' === $action ) {
		$name = isset( $_POST['aiwp_name'] ) ? sanitize_text_field( wp_unslash( $_POST['aiwp_name'] ) ) : '';
		if ( '' === $name ) {
			$notice['message'] = 'Give the application password a name.';
		} elseif ( ! wp_is_application_passwords_available_for_user( $user_id ) ) {
			$notice['message'] = 'Application passwords are disabled for this user (check Wordfence or a similar plugin, and that the site uses HTTPS).';
		} else {
			$created = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $name ) );
			if ( is_wp_error( $created ) ) {
				$notice['message'] = $created->get_error_message();
			} else {
				// Shown once on the next page load, then forgotten; WordPress stores only a hash.
				$notice = array( 'status' => 'created', 'file' => $name, 'message' => WP_Application_Passwords::chunk_password( $created[0] ) );
			}
		}
	} elseif ( 'revoke' === $action ) {
		$uuid = isset( $_POST['aiwp_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['aiwp_uuid'] ) ) : '';
		$res  = WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		$notice = is_wp_error( $res ) ? array( 'status' => 'error', 'file' => '', 'message' => $res->get_error_message() ) : array( 'status' => 'revoked', 'file' => 'application password', 'message' => '' );
	} elseif ( 'revoke_all' === $action ) {
		$res    = WP_Application_Passwords::delete_all_application_passwords( $user_id );
		$notice = is_wp_error( $res ) ? array( 'status' => 'error', 'file' => '', 'message' => $res->get_error_message() ) : array( 'status' => 'revoked', 'file' => 'all application passwords', 'message' => '' );
	}

	set_transient( 'aiwp_notice_' . $user_id, $notice, 60 );
	wp_safe_redirect( admin_url( 'options-general.php?page=ai-wp-site-manager#app-passwords' ) );
	exit;
}

/** Rendered inside aiwp_settings_page(). $notice is the pending notice (already fetched there). */
function aiwp_render_app_passwords_section( $notice ) {
	$user_id = get_current_user_id();
	echo '<h2 id="app-passwords" style="margin-top:2em">Application passwords for ' . esc_html( wp_get_current_user()->user_login ) . '</h2>';
	echo '<p class="description">The same list as on your Profile page, handled inside WordPress instead of over the REST API, for hosts whose firewall blocks that route. Application passwords let tools such as the Claude connector act as this user.</p>';
	if ( $notice && 'created' === $notice['status'] ) {
		echo '<div class="notice notice-success inline"><p>New application password for <strong>' . esc_html( $notice['file'] ) . '</strong>. Copy it now, it will not be shown again:</p>';
		echo '<p><code style="font-size:1.2em;user-select:all">' . esc_html( $notice['message'] ) . '</code></p></div>';
	}
	$items = WP_Application_Passwords::get_user_application_passwords( $user_id );
	if ( $items ) {
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Name</th><th>Created</th><th>Last used</th><th>Last IP</th><th></th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr><td>' . esc_html( $item['name'] ) . '</td>';
			echo '<td>' . esc_html( $item['created'] ? wp_date( 'Y-m-d H:i', $item['created'] ) : '' ) . '</td>';
			echo '<td>' . esc_html( $item['last_used'] ? wp_date( 'Y-m-d H:i', $item['last_used'] ) : 'never' ) . '</td>';
			echo '<td>' . esc_html( $item['last_ip'] ? $item['last_ip'] : '' ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0">';
			wp_nonce_field( 'aiwp_app_password' );
			echo '<input type="hidden" name="action" value="aiwp_app_password"><input type="hidden" name="aiwp_action" value="revoke"><input type="hidden" name="aiwp_uuid" value="' . esc_attr( $item['uuid'] ) . '">';
			echo '<button type="submit" class="button button-small" onclick="return confirm(' . esc_attr( wp_json_encode( 'Revoke "' . $item['name'] . '"? Anything using it stops working.' ) ) . ')">Revoke</button></form></td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0 16px">';
		wp_nonce_field( 'aiwp_app_password' );
		echo '<input type="hidden" name="action" value="aiwp_app_password"><input type="hidden" name="aiwp_action" value="revoke_all">';
		echo '<button type="submit" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( 'Revoke every application password of this user?' ) ) . ')">Revoke all</button></form>';
	} else {
		echo '<p>No application passwords for this user.</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'aiwp_app_password' );
	echo '<input type="hidden" name="action" value="aiwp_app_password"><input type="hidden" name="aiwp_action" value="create">';
	echo '<p><label>Name for a new application password <input type="text" name="aiwp_name" class="regular-text" placeholder="Claude Code" required></label> ';
	echo '<button type="submit" class="button button-primary">Create</button></p></form>';
}

/* ------------------------------------------------------------------ redirects */

/*
 * Simple 301 redirects, one per line in Settings -> AI Site Manager:  /old-path/  ->  /new-path/
 * Targets may be a path on this site or a full https URL. Matching ignores the trailing slash and
 * the query string. WordPress core only auto-redirects renamed *posts*, never pages, so renamed or
 * removed pages need an entry here to keep inbound links and search rankings.
 * The same list is readable and writable at ai-wp/v1/redirects for the connector.
 */
function aiwp_get_redirects() {
	$list = get_option( 'aiwp_redirects', array() );
	return is_array( $list ) ? $list : array();
}

/** Parse "from -> to" lines into a normalised list; returns array( list, errors ). */
function aiwp_parse_redirect_lines( $text ) {
	$list   = array();
	$errors = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $n => $line ) {
		$line = trim( $line );
		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}
		if ( ! preg_match( '/^(\S+)\s*(?:->|=>|\s)\s*(\S+)$/', $line, $m ) ) {
			$errors[] = 'Line ' . ( $n + 1 ) . ': expected "/old/ -> /new/".';
			continue;
		}
		$from = aiwp_normalize_path( $m[1] );
		$to   = $m[2];
		if ( null === $from || '/' === $from ) {
			$errors[] = 'Line ' . ( $n + 1 ) . ': the source must be a path on this site, not the homepage.';
			continue;
		}
		if ( ! preg_match( '#^(/|https://)#', $to ) ) {
			$errors[] = 'Line ' . ( $n + 1 ) . ': the target must start with / or https://.';
			continue;
		}
		if ( aiwp_normalize_path( $to ) === $from ) {
			$errors[] = 'Line ' . ( $n + 1 ) . ': source and target are the same.';
			continue;
		}
		$list[ $from ] = $to;
	}
	return array( $list, $errors );
}

/** "/Old-Path/?x=1" or a full URL on this host -> "/old-path"; null if on another host. */
function aiwp_normalize_path( $value ) {
	$value = trim( (string) $value );
	if ( preg_match( '#^https?://#i', $value ) ) {
		$host = wp_parse_url( $value, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( strtolower( (string) $host ) !== strtolower( (string) $home ) ) {
			return null;
		}
		$value = (string) wp_parse_url( $value, PHP_URL_PATH );
	}
	$value = strtok( $value, '?#' );
	$value = '/' . trim( rawurldecode( $value ), '/' );
	return strtolower( $value );
}

function aiwp_redirects_to_text( $list ) {
	$lines = array();
	foreach ( $list as $from => $to ) {
		$lines[] = $from . '/ -> ' . $to;
	}
	return implode( "\n", $lines );
}

add_action( 'template_redirect', 'aiwp_apply_redirects', 1 );
function aiwp_apply_redirects() {
	$list = aiwp_get_redirects();
	if ( ! $list || empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}
	$path = aiwp_normalize_path( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
	if ( null === $path || ! isset( $list[ $path ] ) ) {
		return;
	}
	$to = $list[ $path ];
	if ( 0 === strpos( $to, '/' ) ) {
		$to = home_url( $to );
	}
	// Preserve the query string so tracking parameters survive the hop.
	$query = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_QUERY );
	if ( $query && false === strpos( $to, '?' ) ) {
		$to .= '?' . $query;
	}
	do_action( 'litespeed_control_set_nocache', 'ai-wp-site-manager redirect' );
	wp_redirect( $to, 301, 'AI WP Site Manager' );
	exit;
}

function aiwp_save_redirects_text( $text ) {
	list( $list, $errors ) = aiwp_parse_redirect_lines( $text );
	if ( $errors ) {
		return new WP_Error( 'aiwp_bad_redirects', implode( ' ', $errors ), array( 'status' => 400 ) );
	}
	update_option( 'aiwp_redirects', $list, false );
	do_action( 'litespeed_purge_all' );
	return $list;
}

add_action( 'rest_api_init', 'aiwp_register_redirect_routes' );
function aiwp_register_redirect_routes() {
	$can = function () {
		return current_user_can( 'manage_options' );
	};
	register_rest_route( 'ai-wp/v1', '/redirects', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function () {
				return array( 'redirects' => aiwp_get_redirects(), 'text' => aiwp_redirects_to_text( aiwp_get_redirects() ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'args'                => array( 'text' => array( 'required' => true, 'type' => 'string' ) ),
			'callback'            => function ( $req ) {
				$res = aiwp_save_redirects_text( $req->get_param( 'text' ) );
				return is_wp_error( $res ) ? $res : array( 'redirects' => $res, 'count' => count( $res ) );
			},
		),
	) );
}

/*
 * Leftover shortcodes: when a plugin is removed, its [shortcode] stays in post content and is shown as raw text.
 * Names listed here are registered as empty shortcodes (only if nothing else registers them), so they render as
 * nothing until the content is cleaned. GET/POST ai-wp/v1/stub-shortcodes {names: []}; the list replaces the old one.
 */
define( 'AIWP_STUB_SHORTCODES_OPTION', 'aiwp_stub_shortcodes' );
define( 'AIWP_STUB_SHORTCODES_MAX', 50 );

function aiwp_get_stub_shortcodes() {
	$list = get_option( AIWP_STUB_SHORTCODES_OPTION, array() );
	return is_array( $list ) ? array_values( $list ) : array();
}

add_action( 'init', 'aiwp_register_stub_shortcodes', 99 );
function aiwp_register_stub_shortcodes() {
	foreach ( aiwp_get_stub_shortcodes() as $name ) {
		if ( ! shortcode_exists( $name ) ) {
			add_shortcode( $name, '__return_empty_string' );
		}
	}
}

function aiwp_save_stub_shortcodes( $names ) {
	if ( ! is_array( $names ) ) {
		return new WP_Error( 'aiwp_bad_names', 'names must be a list of shortcode names.', array( 'status' => 400 ) );
	}
	$clean = array();
	foreach ( $names as $name ) {
		$name = trim( (string) $name, " \t[]" );
		if ( '' === $name ) {
			continue;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $name ) ) {
			return new WP_Error( 'aiwp_bad_name', 'Invalid shortcode name: ' . substr( $name, 0, 64 ) . ' (letters, digits, _ and - only).', array( 'status' => 400 ) );
		}
		$clean[ $name ] = $name;
	}
	if ( count( $clean ) > AIWP_STUB_SHORTCODES_MAX ) {
		return new WP_Error( 'aiwp_too_many', 'At most ' . AIWP_STUB_SHORTCODES_MAX . ' names.', array( 'status' => 400 ) );
	}
	update_option( AIWP_STUB_SHORTCODES_OPTION, array_values( $clean ), false );
	do_action( 'litespeed_purge_all' );
	return array_values( $clean );
}

add_action( 'rest_api_init', 'aiwp_register_stub_shortcode_routes' );
function aiwp_register_stub_shortcode_routes() {
	$can = function () {
		return current_user_can( 'manage_options' );
	};
	register_rest_route( 'ai-wp/v1', '/stub-shortcodes', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function () {
				$names = aiwp_get_stub_shortcodes();
				$state = array();
				foreach ( $names as $name ) {
					$state[ $name ] = shortcode_exists( $name ) && '__return_empty_string' !== $GLOBALS['shortcode_tags'][ $name ] ? 'registered_by_other_plugin' : 'stubbed';
				}
				return array( 'names' => $names, 'state' => (object) $state );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'args'                => array( 'names' => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
			'callback'            => function ( $req ) {
				$res = aiwp_save_stub_shortcodes( $req->get_param( 'names' ) );
				return is_wp_error( $res ) ? $res : array( 'names' => $res, 'count' => count( $res ) );
			},
		),
	) );
}

/*
 * SEO meta of one post or page, for whichever SEO plugin is active (Yoast SEO or Rank Math).
 * Their meta keys are protected (underscore) or unregistered, so wp/v2 cannot write them.
 * GET  ai-wp/v1/seo-meta/<id>  -> {provider, title, description, canonical, noindex}
 * POST ai-wp/v1/seo-meta/<id>  {title?, description?, canonical?, noindex?}: only the fields sent are
 * changed, "" clears a field, noindex null returns to the site default. Yoast's indexable is rebuilt
 * so the new values show at once; the post's page cache is purged. Needs edit_post on that post.
 */
function aiwp_seo_provider() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		return 'rankmath';
	}
	return 'none';
}

function aiwp_seo_keys( $provider ) {
	if ( 'yoast' === $provider ) {
		return array( 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'canonical' => '_yoast_wpseo_canonical', 'noindex' => '_yoast_wpseo_meta-robots-noindex' );
	}
	return array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'canonical' => 'rank_math_canonical_url', 'noindex' => 'rank_math_robots' );
}

function aiwp_seo_post( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || 'revision' === $post->post_type || ! is_post_type_viewable( $post->post_type ) ) {
		return new WP_Error( 'aiwp_seo_no_post', 'No public post or page with this id.', array( 'status' => 404 ) );
	}
	return $post;
}

function aiwp_seo_read( $post ) {
	$provider = aiwp_seo_provider();
	$out      = array( 'id' => $post->ID, 'post_type' => $post->post_type, 'provider' => $provider );
	if ( 'none' === $provider ) {
		return $out;
	}
	$k = aiwp_seo_keys( $provider );
	$out['title']       = (string) get_post_meta( $post->ID, $k['title'], true );
	$out['description'] = (string) get_post_meta( $post->ID, $k['description'], true );
	$out['canonical']   = (string) get_post_meta( $post->ID, $k['canonical'], true );
	$robots             = get_post_meta( $post->ID, $k['noindex'], true );
	if ( 'yoast' === $provider ) {
		$out['noindex'] = '1' === (string) $robots ? true : ( '2' === (string) $robots ? false : null );
	} else {
		$robots         = is_array( $robots ) ? $robots : array();
		$out['noindex'] = in_array( 'noindex', $robots, true ) ? true : ( in_array( 'index', $robots, true ) ? false : null );
	}
	return $out;
}

function aiwp_seo_write( $post, $params ) {
	$provider = aiwp_seo_provider();
	if ( 'none' === $provider ) {
		return new WP_Error( 'aiwp_seo_no_plugin', 'Neither Yoast SEO nor Rank Math is active; nothing was changed.', array( 'status' => 409 ) );
	}
	$k      = aiwp_seo_keys( $provider );
	$limits = array( 'title' => 200, 'description' => 500 );
	$todo   = array();
	foreach ( $limits as $field => $max ) {
		if ( array_key_exists( $field, $params ) ) {
			$val = sanitize_text_field( (string) $params[ $field ] );
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $val ) > $max : strlen( $val ) > $max ) {
				return new WP_Error( 'aiwp_seo_too_long', $field . ' is longer than ' . $max . ' characters; nothing was changed.', array( 'status' => 400 ) );
			}
			$todo[ $k[ $field ] ] = $val;
		}
	}
	if ( array_key_exists( 'canonical', $params ) ) {
		$url = trim( (string) $params['canonical'] );
		if ( '' !== $url && ( ! wp_http_validate_url( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
			return new WP_Error( 'aiwp_seo_bad_url', 'canonical must be an absolute http(s) URL; nothing was changed.', array( 'status' => 400 ) );
		}
		$todo[ $k['canonical'] ] = '' === $url ? '' : esc_url_raw( $url );
	}
	if ( array_key_exists( 'noindex', $params ) ) {
		$ni = $params['noindex'];
		if ( 'yoast' === $provider ) {
			$todo[ $k['noindex'] ] = null === $ni ? '' : ( $ni ? '1' : '2' );
		} else {
			$robots = get_post_meta( $post->ID, $k['noindex'], true );
			$robots = array_values( array_diff( is_array( $robots ) ? $robots : array(), array( 'index', 'noindex' ) ) );
			if ( null !== $ni ) {
				$robots[] = $ni ? 'noindex' : 'index';
			}
			$todo[ $k['noindex'] ] = $robots;
		}
	}
	if ( ! $todo ) {
		return new WP_Error( 'aiwp_seo_nothing', 'Send at least one of title, description, canonical, noindex.', array( 'status' => 400 ) );
	}
	foreach ( $todo as $key => $val ) {
		if ( '' === $val || array() === $val ) {
			delete_post_meta( $post->ID, $key );
		} else {
			update_post_meta( $post->ID, $key, $val );
		}
	}
	$refreshed = aiwp_seo_refresh_index( $post->ID, $provider );
	do_action( 'litespeed_purge_post', $post->ID );
	$out                    = aiwp_seo_read( $post );
	$out['index_refreshed'] = $refreshed;
	return $out;
}

/* Yoast serves the head from its indexables table, which is only rebuilt on a post save; rebuild it now. */
function aiwp_seo_refresh_index( $post_id, $provider ) {
	if ( 'yoast' !== $provider ) {
		return true;
	}
	if ( ! function_exists( 'YoastSEO' ) || ! class_exists( '\Yoast\WP\SEO\Builders\Indexable_Builder' ) ) {
		return false;
	}
	try {
		$repo      = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
		$builder   = YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class );
		$indexable = $repo->find_by_id_and_type( $post_id, 'post', false );
		return false !== $builder->build_for_id_and_type( $post_id, 'post', $indexable );
	} catch ( \Throwable $e ) {
		return false;
	}
}

add_action( 'rest_api_init', 'aiwp_register_seo_meta_routes' );
function aiwp_register_seo_meta_routes() {
	$can = function ( $req ) {
		return current_user_can( 'edit_post', (int) $req['id'] );
	};
	register_rest_route( 'ai-wp/v1', '/seo-meta/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function ( $req ) {
				$post = aiwp_seo_post( $req['id'] );
				return is_wp_error( $post ) ? $post : aiwp_seo_read( $post );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'args'                => array(
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'canonical'   => array( 'type' => 'string' ),
				'noindex'     => array( 'type' => array( 'boolean', 'null' ) ),
			),
			'callback'            => function ( $req ) {
				$post = aiwp_seo_post( $req['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				$params = array();
				foreach ( array( 'title', 'description', 'canonical', 'noindex' ) as $f ) {
					if ( $req->has_param( $f ) ) {
						$params[ $f ] = $req->get_param( $f );
					}
				}
				return aiwp_seo_write( $post, $params );
			},
		),
	) );
}

add_action( 'admin_post_aiwp_save_redirects', 'aiwp_handle_redirects_form' );
function aiwp_handle_redirects_form() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'aiwp_redirects' );
	$res    = aiwp_save_redirects_text( isset( $_POST['aiwp_redirects'] ) ? wp_unslash( $_POST['aiwp_redirects'] ) : '' );
	$notice = is_wp_error( $res )
		? array( 'status' => 'error', 'file' => '', 'message' => $res->get_error_message() )
		: array( 'status' => 'saved', 'file' => count( $res ) . ' redirect(s)', 'message' => '' );
	set_transient( 'aiwp_notice_' . get_current_user_id(), $notice, 60 );
	wp_safe_redirect( admin_url( 'options-general.php?page=ai-wp-site-manager#redirects' ) );
	exit;
}

function aiwp_render_redirects_section() {
	echo '<h2 id="redirects" style="margin-top:2em">Redirects (301)</h2>';
	echo '<p class="description">One per line: <code>/old-path/ -> /new-path/</code>. Use this when a page is renamed or removed, so old links and search results keep working. WordPress only does this by itself for posts, not pages. Lines starting with # are comments.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'aiwp_redirects' );
	echo '<input type="hidden" name="action" value="aiwp_save_redirects">';
	echo '<textarea name="aiwp_redirects" rows="8" style="width:100%;max-width:900px;font-family:monospace" spellcheck="false" placeholder="/old-page/ -> /new-page/">' . esc_textarea( aiwp_redirects_to_text( aiwp_get_redirects() ) ) . '</textarea>';
	echo '<p><button type="submit" class="button button-primary">Save redirects</button></p></form>';
}

/* ------------------------------------------------------------------ install from the team's own release zips */

/*
 * POST ai-wp/v1/themes/install-package  {package, overwrite}
 * POST ai-wp/v1/plugins/install-package {package, overwrite, activate}
 * Installs (or, with overwrite, replaces) a theme or plugin from a zip URL. Only URLs under the
 * team's own GitHub release location are accepted, so this cannot be pointed at arbitrary code.
 * Needed because an inactive theme cannot self-update (its code never runs), so a freshly
 * uploaded old version has no way to reach the current release without a manual re-upload.
 */
define( 'AIWP_PACKAGE_PREFIXES', 'https://raw.githubusercontent.com/FreedomScapeIsNotAvailable/' ); // informational; the check below parses the URL

/**
 * Exact check, not a string prefix: https, raw.githubusercontent.com, no port, query or credentials, and a path that is one
 * of the team's release zips (<repo>/main/dist/[children/]<name>.zip). A prefix check let "..", look-alike paths and
 * other accounts through. Extend with the ai_wp_package_allowed filter.
 */
function aiwp_package_allowed( $url ) {
	$p = wp_parse_url( (string) $url );
	$ok = is_array( $p )
		&& isset( $p['scheme'], $p['host'], $p['path'] )
		&& 'https' === strtolower( $p['scheme'] )
		&& 'raw.githubusercontent.com' === strtolower( $p['host'] )
		&& empty( $p['port'] ) && empty( $p['user'] ) && empty( $p['pass'] ) && empty( $p['query'] ) && empty( $p['fragment'] )
		&& 1 === preg_match( '~^/FreedomScapeIsNotAvailable/(wp-theme-bocp|ai-wp-site-manager)/main/dist/(children/)?[A-Za-z0-9._-]+\.zip$~', $p['path'] )
		&& false === strpos( $p['path'], '..' );
	return (bool) apply_filters( 'ai_wp_package_allowed', $ok, $url );
}

add_action( 'rest_api_init', 'aiwp_register_install_package_routes' );
function aiwp_register_install_package_routes() {
	register_rest_route( 'ai-wp/v1', '/themes/install-package', array(
		'methods'             => 'POST',
		'callback'            => 'aiwp_install_theme_package',
		'permission_callback' => function () {
			return current_user_can( 'install_themes' ) && current_user_can( 'update_themes' );
		},
		'args'                => array(
			'package'   => array( 'required' => true, 'type' => 'string' ),
			'overwrite' => array( 'type' => 'boolean', 'default' => true ),
		),
	) );
	register_rest_route( 'ai-wp/v1', '/plugins/install-package', array(
		'methods'             => 'POST',
		'callback'            => 'aiwp_install_plugin_package',
		'permission_callback' => function () {
			return current_user_can( 'install_plugins' ) && current_user_can( 'update_plugins' );
		},
		'args'                => array(
			'package'   => array( 'required' => true, 'type' => 'string' ),
			'overwrite' => array( 'type' => 'boolean', 'default' => true ),
			'activate'  => array( 'type' => 'boolean', 'default' => false ),
		),
	) );
}

function aiwp_install_theme_package( WP_REST_Request $req ) {
	$fs = csm_require_direct_fs();
	if ( is_wp_error( $fs ) ) {
		return $fs;
	}
	$package = esc_url_raw( $req->get_param( 'package' ) );
	if ( ! aiwp_package_allowed( $package ) ) {
		return new WP_Error( 'aiwp_package_not_allowed', 'Only packages from the team release location are allowed.', array( 'status' => 403 ) );
	}
	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Theme_Upgrader( $skin );
	$result   = $upgrader->install( $package, array( 'overwrite_package' => (bool) $req->get_param( 'overwrite' ) ) );
	if ( is_wp_error( $result ) || is_wp_error( $skin->result ) || $skin->get_errors()->has_errors() || ! $result ) {
		return csm_skin_error( $skin, 'Theme package install failed.' );
	}
	wp_clean_themes_cache( true );
	$stylesheet = isset( $upgrader->result['destination_name'] ) ? $upgrader->result['destination_name'] : '';
	$theme      = $stylesheet ? wp_get_theme( $stylesheet ) : null;
	return array(
		'stylesheet' => $stylesheet,
		'name'       => $theme ? $theme->get( 'Name' ) : null,
		'version'    => $theme ? $theme->get( 'Version' ) : null,
		'active'     => get_stylesheet() === $stylesheet,
		'log'        => $skin->get_upgrade_messages(),
	);
}

function aiwp_install_plugin_package( WP_REST_Request $req ) {
	$fs = csm_require_direct_fs();
	if ( is_wp_error( $fs ) ) {
		return $fs;
	}
	$package = esc_url_raw( $req->get_param( 'package' ) );
	if ( ! aiwp_package_allowed( $package ) ) {
		return new WP_Error( 'aiwp_package_not_allowed', 'Only packages from the team release location are allowed.', array( 'status' => 403 ) );
	}
	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->install( $package, array( 'overwrite_package' => (bool) $req->get_param( 'overwrite' ) ) );
	if ( is_wp_error( $result ) || is_wp_error( $skin->result ) || $skin->get_errors()->has_errors() || ! $result ) {
		return csm_skin_error( $skin, 'Plugin package install failed.' );
	}
	wp_clean_plugins_cache( true );
	$file = $upgrader->plugin_info();
	if ( $file && $req->get_param( 'activate' ) && ! is_plugin_active( $file ) ) {
		activate_plugin( $file );
	}
	$data = $file ? get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false ) : array();
	return array(
		'plugin'  => $file ? csm_plugin_id( $file ) : null,
		'name'    => isset( $data['Name'] ) ? $data['Name'] : null,
		'version' => isset( $data['Version'] ) ? $data['Version'] : null,
		'active'  => $file ? is_plugin_active( $file ) : false,
		'log'     => $skin->get_upgrade_messages(),
	);
}

/* ------------------------------------------------------------------ theme mods (Customizer settings) */

/*
 * GET  ai-wp/v1/theme-mods            -> all Customizer settings of the active theme
 * POST ai-wp/v1/theme-mods {mods}     -> set the given keys (custom_logo, colours, header buttons, ...)
 * Scoped to theme mods only (what Appearance -> Customize edits), capability edit_theme_options.
 * Lets a theme switch be completed remotely: logo, header buttons and colours in one call.
 */
add_action( 'rest_api_init', 'aiwp_register_theme_mod_routes' );
function aiwp_register_theme_mod_routes() {
	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};
	register_rest_route( 'ai-wp/v1', '/theme-mods', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function () {
				return array( 'theme' => get_stylesheet(), 'mods' => get_theme_mods() );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'args'                => array( 'mods' => array( 'required' => true, 'type' => 'object' ) ),
			'callback'            => function ( $req ) {
				$mods = $req->get_param( 'mods' );
				if ( ! is_array( $mods ) ) {
					return new WP_Error( 'aiwp_bad_mods', '"mods" must be an object of setting => value.', array( 'status' => 400 ) );
				}
				$changed = array();
				foreach ( $mods as $key => $value ) {
					$key = sanitize_key( $key );
					if ( '' === $key ) {
						continue;
					}
					if ( null === $value ) {
						remove_theme_mod( $key );
					} else {
						set_theme_mod( $key, $value );
					}
					$changed[ $key ] = get_theme_mod( $key );
				}
				do_action( 'litespeed_purge_all' );
				return array( 'theme' => get_stylesheet(), 'changed' => $changed );
			},
		),
	) );
}

/* ------------------------------------------------------------------ automatic updates */

/*
 * GET  ai-wp/v1/auto-updates  -> which plugins, themes and core versions WordPress updates on its own
 * POST ai-wp/v1/auto-updates  {plugins, themes, core}
 *   plugins / themes: "all" | "none" | { "<plugin file or stylesheet>": true|false }
 *   core:             "all" (major + minor) | "minor" (security releases only) | "off"
 * Writes the same options as the "Enable auto-updates" links on the Plugins and Themes screens
 * and the toggle on Dashboard -> Updates. "all" covers what is installed now; a plugin or theme
 * installed later is opted in by calling it again. Each part needs its own capability.
 */
add_action( 'rest_api_init', 'aiwp_register_auto_update_routes' );
function aiwp_register_auto_update_routes() {
	register_rest_route( 'ai-wp/v1', '/auto-updates', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' ) || current_user_can( 'update_core' );
			},
			'callback'            => 'aiwp_auto_update_state',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' ) || current_user_can( 'update_core' );
			},
			'callback'            => 'aiwp_set_auto_updates',
		),
	) );
}

function aiwp_auto_update_state() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}
	if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
	}
	$on_plugins = (array) get_site_option( 'auto_update_plugins', array() );
	$on_themes  = (array) get_site_option( 'auto_update_themes', array() );

	$plugins = array();
	foreach ( get_plugins() as $file => $data ) {
		$plugins[] = array( 'plugin' => $file, 'name' => $data['Name'], 'auto_update' => in_array( $file, $on_plugins, true ) );
	}
	$themes = array();
	foreach ( wp_get_themes() as $slug => $theme ) {
		$themes[] = array( 'stylesheet' => $slug, 'name' => $theme->get( 'Name' ), 'auto_update' => in_array( $slug, $on_themes, true ) );
	}

	$updater = new WP_Automatic_Updater();
	return array(
		'plugins'                     => $plugins,
		'themes'                      => $themes,
		'core'                        => array(
			'major' => 'enabled' === get_site_option( 'auto_update_core_major' ),
			'minor' => 'unset' !== get_site_option( 'auto_update_core_minor', 'enabled' ),
		),
		// Constants or filters can override the options above; report them so "enabled" is not a false promise.
		'automatic_updater_disabled'  => $updater->is_disabled(),
		'wp_auto_update_core'         => defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : null,
		'plugin_auto_updates_allowed' => wp_is_auto_update_enabled_for_type( 'plugin' ),
		'theme_auto_updates_allowed'  => wp_is_auto_update_enabled_for_type( 'theme' ),
	);
}

/** Resolve "all" | "none" | {id: bool} against the ids installed now. */
function aiwp_auto_update_list( $wanted, $current, $installed ) {
	if ( 'all' === $wanted ) {
		return array_values( $installed );
	}
	if ( 'none' === $wanted ) {
		return array();
	}
	if ( ! is_array( $wanted ) ) {
		return new WP_Error( 'aiwp_bad_auto_update', 'Use "all", "none" or an object of id => true|false.', array( 'status' => 400 ) );
	}
	$list = array_intersect( $current, $installed );
	foreach ( $wanted as $id => $on ) {
		if ( ! in_array( $id, $installed, true ) ) {
			return new WP_Error( 'aiwp_unknown_item', "Not installed: $id", array( 'status' => 404 ) );
		}
		$list = $on ? array_merge( $list, array( $id ) ) : array_diff( $list, array( $id ) );
	}
	return array_values( array_unique( $list ) );
}

function aiwp_set_auto_updates( WP_REST_Request $req ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugins = $req->get_param( 'plugins' );
	$themes  = $req->get_param( 'themes' );
	$core    = $req->get_param( 'core' );
	if ( null === $plugins && null === $themes && null === $core ) {
		return new WP_Error( 'aiwp_nothing_to_do', 'Send at least one of "plugins", "themes" or "core".', array( 'status' => 400 ) );
	}

	if ( null !== $plugins ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error( 'rest_forbidden', 'Changing plugin auto-updates needs update_plugins.', array( 'status' => 403 ) );
		}
		$list = aiwp_auto_update_list( $plugins, (array) get_site_option( 'auto_update_plugins', array() ), array_keys( get_plugins() ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		update_site_option( 'auto_update_plugins', $list );
	}

	if ( null !== $themes ) {
		if ( ! current_user_can( 'update_themes' ) ) {
			return new WP_Error( 'rest_forbidden', 'Changing theme auto-updates needs update_themes.', array( 'status' => 403 ) );
		}
		$list = aiwp_auto_update_list( $themes, (array) get_site_option( 'auto_update_themes', array() ), array_keys( wp_get_themes() ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		update_site_option( 'auto_update_themes', $list );
	}

	if ( null !== $core ) {
		if ( ! current_user_can( 'update_core' ) ) {
			return new WP_Error( 'rest_forbidden', 'Changing core auto-updates needs update_core.', array( 'status' => 403 ) );
		}
		$modes = array(
			'all'   => array( 'enabled', 'enabled' ),
			'minor' => array( 'unset', 'enabled' ),
			'off'   => array( 'unset', 'unset' ),
		);
		if ( ! isset( $modes[ $core ] ) ) {
			return new WP_Error( 'aiwp_bad_core_mode', 'core must be "all", "minor" or "off".', array( 'status' => 400 ) );
		}
		update_site_option( 'auto_update_core_major', $modes[ $core ][0] );
		update_site_option( 'auto_update_core_minor', $modes[ $core ][1] );
	}

	return aiwp_auto_update_state();
}

/* ------------------------------------------------------------------ additional CSS (Customizer) */

/*
 * GET  ai-wp/v1/custom-css[?stylesheet=bocp]        -> the site's Additional CSS for a theme (default: active)
 * POST ai-wp/v1/custom-css {css, stylesheet?}       -> replace it
 * The same storage as Appearance -> Customize -> Additional CSS (a custom_css post per theme), so it
 * survives theme updates and can be prepared for an installed theme before switching to it.
 */
add_action( 'rest_api_init', 'aiwp_register_custom_css_routes' );
function aiwp_register_custom_css_routes() {
	$can = function () {
		return current_user_can( 'edit_css' );
	};
	$theme_arg = array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
	);
	register_rest_route( 'ai-wp/v1', '/custom-css', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'args'                => array( 'stylesheet' => $theme_arg ),
			'callback'            => function ( $req ) {
				$stylesheet = aiwp_custom_css_theme( $req->get_param( 'stylesheet' ) );
				if ( is_wp_error( $stylesheet ) ) {
					return $stylesheet;
				}
				return array( 'stylesheet' => $stylesheet, 'css' => wp_get_custom_css( $stylesheet ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'args'                => array(
				'css'        => array( 'required' => true, 'type' => 'string' ),
				'stylesheet' => $theme_arg,
			),
			'callback'            => function ( $req ) {
				$stylesheet = aiwp_custom_css_theme( $req->get_param( 'stylesheet' ) );
				if ( is_wp_error( $stylesheet ) ) {
					return $stylesheet;
				}
				$css = (string) $req->get_param( 'css' );
				// Same rule as the Customizer: the CSS is printed inside <style>, so no markup.
				if ( false !== strpos( $css, '<' ) ) {
					return new WP_Error( 'aiwp_bad_css', 'CSS must not contain "<".', array( 'status' => 400 ) );
				}
				$post = wp_update_custom_css_post( $css, array( 'stylesheet' => $stylesheet ) );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				do_action( 'litespeed_purge_all' );
				return array( 'stylesheet' => $stylesheet, 'post_id' => $post->ID, 'css' => wp_get_custom_css( $stylesheet ) );
			},
		),
	) );
}

/** Installed theme to read/write Additional CSS for; the active theme when none is given. */
function aiwp_custom_css_theme( $stylesheet ) {
	if ( empty( $stylesheet ) ) {
		return get_stylesheet();
	}
	if ( ! wp_get_theme( $stylesheet )->exists() ) {
		return new WP_Error( 'aiwp_no_theme', 'Theme is not installed: ' . $stylesheet, array( 'status' => 404 ) );
	}
	return $stylesheet;
}

/* ------------------------------------------------------------------ error logs */

/*
 * GET  ai-wp/v1/logs                    -> the known log files (fixed keys, paths resolved here, never from the caller)
 * GET  ai-wp/v1/logs/<key>?kb=128       -> the last N KB, starting at a line boundary, secrets masked
 * POST ai-wp/v1/logs/<key>/clear {expect:"<size>:<mtime>"} -> empty the file in place; the last 256 KB are kept
 *                                         in the option aiwp_log_prev_<key>; 409 when the file changed meanwhile
 * GET|POST ai-wp/v1/error-logging {enabled, hours} -> timed PHP error logging into the plugin's own log ('aiwp'),
 *   done by a must-use plugin that removes itself when the time runs out. wp-config.php is never edited.
 * Responses carry no-store headers through csm_no_cache_headers (it covers the whole ai-wp/v1 namespace).
 */
define( 'AIWP_LOG_KEYS', 'debug,aiwp,php,php-admin,php-ini' );
define( 'AIWP_LOG_KB_DEFAULT', 128 );
define( 'AIWP_LOG_KB_MAX', 1024 );
define( 'AIWP_LOG_PREV_BYTES', 262144 );
define( 'AIWP_LOG_MAX_BYTES', 5242880 );
define( 'AIWP_ERRLOG_OPTION', 'aiwp_error_logging' );
define( 'AIWP_ERRLOG_FILE_OPTION', 'aiwp_log_file' );
define( 'AIWP_ERRLOG_HOOK', 'aiwp_error_logging_expire' );
define( 'AIWP_ERRLOG_MU_FILE', 'aiwp-error-logging.php' );
define( 'AIWP_ERRLOG_HOURS_DEFAULT', 24 );
define( 'AIWP_ERRLOG_HOURS_MAX', 168 );

add_action( AIWP_ERRLOG_HOOK, 'aiwp_error_logging_disable' );
register_deactivation_hook( __FILE__, 'aiwp_error_logging_disable' );
register_uninstall_hook( __FILE__, 'aiwp_error_logging_uninstall' );

/** Same rule as wp_debug_mode(): where WordPress writes its debug log when WP_DEBUG_LOG is on. */
function aiwp_debug_log_path() {
	if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && ! in_array( strtolower( WP_DEBUG_LOG ), array( '', 'true', '1' ), true ) ) {
		return WP_DEBUG_LOG;
	}
	return WP_CONTENT_DIR . '/debug.log';
}

/** True when WordPress itself already sends PHP errors to its debug log (then the timed switch does not repoint error_log). */
function aiwp_wp_debug_logging() {
	return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
}

/** key => array( path, root the real path must stay inside ). */
function aiwp_log_candidates() {
	return array(
		'debug'     => array( aiwp_debug_log_path(), ABSPATH ),
		'aiwp'      => array( (string) get_site_option( AIWP_ERRLOG_FILE_OPTION, '' ), dirname( ABSPATH ) ),
		'php'       => array( ABSPATH . 'error_log', ABSPATH ),
		'php-admin' => array( ABSPATH . 'wp-admin/error_log', ABSPATH ),
		'php-ini'   => array( (string) ini_get( 'error_log' ), ABSPATH ),
	);
}

/** The plugin's own log is only ever aiwp-logs/aiwp-errors-<16 alphanumerics>.log, whatever the option says. */
function aiwp_is_own_log_name( $path ) {
	return 'aiwp-logs' === basename( dirname( $path ) ) && (bool) preg_match( '/^aiwp-errors-[A-Za-z0-9]{16}\.log$/', basename( $path ) );
}

function aiwp_path_inside( $real, $root ) {
	$root = @realpath( $root );
	if ( false === $root ) {
		return false;
	}
	$root = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;
	return 0 === strpos( rtrim( $real, '/\\' ) . DIRECTORY_SEPARATOR, $root );
}

/** Status of one log; 'path' is internal and stripped by aiwp_log_public(). Null for an unknown key. */
function aiwp_log_resolve( $key ) {
	$candidates = aiwp_log_candidates();
	if ( ! in_array( $key, explode( ',', AIWP_LOG_KEYS ), true ) || ! isset( $candidates[ $key ] ) ) {
		return null;
	}
	list( $path, $root ) = $candidates[ $key ];
	$info = array( 'key' => $key, 'exists' => false, 'size' => 0, 'mtime' => null, 'readable' => false, 'reason' => null, 'path' => null );
	if ( '' === $path ) {
		$info['reason'] = 'missing';
		return $info;
	}
	if ( @is_link( $path ) || ( 'aiwp' === $key && ( @is_link( dirname( $path ) ) || ! aiwp_is_own_log_name( $path ) ) ) ) {
		$info['reason'] = 'outside_site';
		return $info;
	}
	// realpath() is false for a missing file and for anything open_basedir hides; the parent tells the two apart.
	$real = @realpath( $path );
	if ( false === $real ) {
		$parent         = @realpath( dirname( $path ) );
		$info['reason'] = ( false === $parent || ! aiwp_path_inside( $parent, $root ) ) ? 'outside_site' : 'missing';
		return $info;
	}
	if ( ! aiwp_path_inside( $real, $root ) ) {
		$info['reason'] = 'outside_site';
		return $info;
	}
	if ( ! @is_file( $real ) ) {
		$info['reason'] = 'missing';
		return $info;
	}
	clearstatcache( true, $real );
	$info['exists']   = true;
	$info['size']     = (int) filesize( $real );
	$info['mtime']    = (int) filemtime( $real );
	$info['readable'] = is_readable( $real );
	$info['reason']   = $info['readable'] ? null : 'not_readable';
	$info['path']     = $real;
	return $info;
}

function aiwp_log_public( $info ) {
	unset( $info['path'] );
	return $info;
}

/** The log as a usable file, or a WP_Error (404 unknown/missing, 403 outside the site or unreadable). */
function aiwp_log_usable( $key ) {
	$info = aiwp_log_resolve( $key );
	if ( null === $info ) {
		return new WP_Error( 'aiwp_bad_log', 'Unknown log. Use one of: ' . AIWP_LOG_KEYS . '.', array( 'status' => 404 ) );
	}
	if ( null !== $info['reason'] ) {
		$status = 'missing' === $info['reason'] ? 404 : 403;
		return new WP_Error( 'aiwp_log_' . $info['reason'], 'Log "' . $key . '" is not usable: ' . $info['reason'] . '.', array( 'status' => $status ) );
	}
	return $info;
}

function aiwp_log_utf8( $text ) {
	if ( function_exists( 'mb_convert_encoding' ) ) {
		return mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
	}
	return wp_check_invalid_utf8( $text, true );
}

/** Masks credentials before log text leaves the server or is stored in an option. */
function aiwp_log_mask( $text ) {
	$names = '[\w-]*(?:password|passwd|pass|pwd|api_key|key|token|secret)';
	$text  = preg_replace( '/(Authorization["\'\]]?\s*(?:=>|[:=])\s*["\']?\s*(?:Basic|Bearer)\s+)[A-Za-z0-9._~+\/=-]+/i', '$1***', $text );
	$text  = preg_replace( '/(\bBearer\s+)[A-Za-z0-9._~+\/=-]{8,}/i', '$1***', $text );
	$text  = preg_replace( '/(\bBasic\s+)(?=[A-Za-z0-9+\/]*[0-9+\/=])[A-Za-z0-9+\/]{12,}={0,2}/', '$1***', $text );
	$text  = preg_replace( '/("' . $names . '"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/i', '$1***$2', $text );
	$text  = preg_replace( '/(\\\\+"' . $names . '\\\\+"\s*:\s*\\\\+")(?:[^"\\\\]|\\\\(?!"))*(\\\\+")/i', '$1***$2', $text );
	$text  = preg_replace( '/(\'' . $names . '\'\s*=>\s*\')(?:[^\'\\\\]|\\\\.)*(\')/i', '$1***$2', $text );
	$text  = preg_replace( '/((?<![\w-])' . $names . '=)[^&\s"\'<>]+/i', '$1***', $text );
	$text  = preg_replace( '/(\[["\']?' . $names . '["\']?\]\s*=>\s*)[^\r\n]+/i', '$1***', $text );
	return $text;
}

/** Up to $bytes from the end of an open file, starting at a line boundary when the start was cut. */
function aiwp_log_read_tail( $fh, $size, $bytes ) {
	$start = max( 0, $size - $bytes );
	fseek( $fh, $start );
	$data = (string) stream_get_contents( $fh, $size - $start );
	if ( $start > 0 ) {
		$nl = strpos( $data, "\n" );
		if ( false !== $nl ) {
			$data = (string) substr( $data, $nl + 1 );
		}
	}
	return array( $data, $start > 0 );
}

function aiwp_log_read( $key, $kb ) {
	$info = aiwp_log_usable( $key );
	if ( is_wp_error( $info ) ) {
		return $info;
	}
	$fh = @fopen( $info['path'], 'rb' );
	if ( ! $fh ) {
		return new WP_Error( 'aiwp_log_not_readable', 'Could not open log "' . $key . '".', array( 'status' => 403 ) );
	}
	$stat = fstat( $fh );
	list( $data, $truncated ) = aiwp_log_read_tail( $fh, (int) $stat['size'], (int) $kb * 1024 );
	fclose( $fh );
	return array(
		'key'       => $key,
		'size'      => (int) $stat['size'],
		'mtime'     => (int) $stat['mtime'],
		'expect'    => (int) $stat['size'] . ':' . (int) $stat['mtime'],
		'truncated' => $truncated,
		'content'   => aiwp_log_mask( aiwp_log_utf8( $data ) ),
	);
}

function aiwp_log_clear( $key, $expect ) {
	$info = aiwp_log_usable( $key );
	if ( is_wp_error( $info ) ) {
		return $info;
	}
	$fh = @fopen( $info['path'], 'r+' );
	if ( ! $fh ) {
		return new WP_Error( 'aiwp_log_not_writable', 'Log "' . $key . '" is not writable by WordPress.', array( 'status' => 403 ) );
	}
	if ( ! flock( $fh, LOCK_EX ) ) {
		fclose( $fh );
		return new WP_Error( 'aiwp_log_locked', 'Could not lock log "' . $key . '".', array( 'status' => 409 ) );
	}
	clearstatcache( true, $info['path'] );
	$stat    = fstat( $fh );
	$size    = (int) $stat['size'];
	$current = $size . ':' . (int) $stat['mtime'];
	if ( $current !== trim( (string) $expect ) ) {
		flock( $fh, LOCK_UN );
		fclose( $fh );
		return new WP_Error( 'aiwp_log_changed', 'The log changed since it was read; read it again and pass the new expect value.', array( 'status' => 409, 'expect' => $current ) );
	}
	list( $tail ) = aiwp_log_read_tail( $fh, $size, AIWP_LOG_PREV_BYTES );
	// Stored masked and valid UTF-8: the options table rejects invalid UTF-8, and credentials do not belong in the database.
	update_option( 'aiwp_log_prev_' . $key, array( 'saved' => current_time( 'c' ), 'size' => $size, 'content' => aiwp_log_mask( aiwp_log_utf8( $tail ) ) ), false );
	$ok = ftruncate( $fh, 0 );
	fflush( $fh );
	flock( $fh, LOCK_UN );
	fclose( $fh );
	if ( ! $ok ) {
		return new WP_Error( 'aiwp_log_not_cleared', 'Could not empty log "' . $key . '".', array( 'status' => 500 ) );
	}
	return array( 'key' => $key, 'cleared' => $size, 'previous_saved_in' => 'aiwp_log_prev_' . $key );
}

/* -------- timed error logging (must-use plugin) */

function aiwp_errlog_mu_path() {
	return WPMU_PLUGIN_DIR . '/' . AIWP_ERRLOG_MU_FILE;
}

/** Protects a log folder that may be reachable from the web: deny-all .htaccess (Apache 2.4 and 2.2) and an empty index.php. */
function aiwp_log_dir_protect( $dir ) {
	$htaccess = $dir . '/.htaccess';
	$index    = $dir . '/index.php';
	if ( ! file_exists( $htaccess ) && false === @file_put_contents( $htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n", LOCK_EX ) ) {
		return false;
	}
	if ( ! file_exists( $index ) && false === @file_put_contents( $index, "<?php\n", LOCK_EX ) ) {
		return false;
	}
	return true;
}

/** The plugin's own log file: outside the web root when possible, else wp-content/aiwp-logs; random file name, no symlinks. */
function aiwp_log_prepare() {
	$file = (string) get_site_option( AIWP_ERRLOG_FILE_OPTION, '' );
	if ( '' !== $file && @is_dir( dirname( $file ) ) && ! @is_link( dirname( $file ) ) && ! @is_link( $file ) && is_writable( dirname( $file ) ) ) {
		return $file;
	}
	$dir = aiwp_private_dir( 'aiwp-logs' );
	if ( null === $dir ) {
		return new WP_Error( 'aiwp_log_dir', 'No writable folder for the error log (tried ' . dirname( ABSPATH ) . '/aiwp-logs and ' . WP_CONTENT_DIR . '/aiwp-logs).', array( 'status' => 500 ) );
	}
	$file = $dir . '/aiwp-errors-' . wp_generate_password( 16, false ) . '.log';
	update_site_option( AIWP_ERRLOG_FILE_OPTION, $file );
	return $file;
}

/** A folder for the plugin's own files: outside the web root when possible, else under wp-content with deny rules. No symlinks. */
function aiwp_private_dir( $name ) {
	foreach ( array( dirname( ABSPATH ) . '/' . $name, WP_CONTENT_DIR . '/' . $name ) as $dir ) {
		if ( @is_link( $dir ) || ( ! @is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) || @is_link( $dir ) || ! is_writable( $dir ) ) {
			continue;
		}
		// dirname(ABSPATH) is still the web root when WordPress lives in a subfolder, so both locations get the deny rules.
		if ( ! aiwp_log_dir_protect( $dir ) ) {
			continue;
		}
		return $dir;
	}
	return null;
}

/**
 * Replaces a file through a temporary file and rename(), keeping its permissions. With $in_place_fallback, a folder that
 * is not writable falls back to a locked rewrite of the file itself. True, or a WP_Error.
 */
function aiwp_atomic_write( $path, $content, $in_place_fallback = true ) {
	$exists = file_exists( $path );
	$tmp    = dirname( $path ) . '/.aiwp-tmp-' . wp_generate_password( 12, false ) . '.php';
	$fh     = @fopen( $tmp, 'x' );
	if ( $fh ) {
		@chmod( $tmp, 0600 );
		$ok = ( strlen( $content ) === fwrite( $fh, $content ) );
		$ok = fclose( $fh ) && $ok;
		if ( $ok ) {
			@chmod( $tmp, $exists ? ( fileperms( $path ) & 0777 ) : 0644 );
			$ok = @rename( $tmp, $path );
		}
		if ( ! $ok ) {
			@unlink( $tmp );
		}
	} elseif ( $in_place_fallback && $exists && is_writable( $path ) ) {
		$fh = @fopen( $path, 'c' );
		$ok = $fh && flock( $fh, LOCK_EX ) && ftruncate( $fh, 0 ) && strlen( $content ) === fwrite( $fh, $content ) && fflush( $fh );
		if ( $fh ) {
			flock( $fh, LOCK_UN );
			fclose( $fh );
		}
	} else {
		$ok = false;
	}
	if ( ! $ok ) {
		return new WP_Error( 'aiwp_write', 'Could not write ' . $path . '.', array( 'status' => 500 ) );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $path, true );
	}
	return true;
}

function aiwp_errlog_mu_source() {
	return "<?php\n"
		. "/*\n * Plugin Name: AI WP Site Manager - timed error logging\n"
		. " * Description: Written by AI WP Site Manager (ai-wp/v1/error-logging). Logs PHP errors to the plugin's own log until the deadline, then removes itself.\n */\n"
		. "defined( 'ABSPATH' ) || exit;\n"
		. "( function () {\n"
		. "\t\$s = get_site_option( '" . AIWP_ERRLOG_OPTION . "' );\n"
		. "\tif ( ! is_array( \$s ) || empty( \$s['until'] ) || time() >= (int) \$s['until'] ) {\n"
		. "\t\tdelete_site_option( '" . AIWP_ERRLOG_OPTION . "' );\n"
		. "\t\t@unlink( __FILE__ );\n"
		. "\t\treturn;\n"
		. "\t}\n"
		. "\tif ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {\n"
		. "\t\treturn;\n"
		. "\t}\n"
		. "\t\$f = isset( \$s['file'] ) ? (string) \$s['file'] : '';\n"
		. "\tif ( '' === \$f || @is_link( \$f ) || @is_link( dirname( \$f ) ) || 'aiwp-logs' !== basename( dirname( \$f ) )\n"
		. "\t\t|| ! preg_match( '/^aiwp-errors-[A-Za-z0-9]{16}\\.log\$/', basename( \$f ) ) ) {\n"
		. "\t\treturn;\n"
		. "\t}\n"
		. "\tclearstatcache( true, \$f );\n"
		. "\tif ( @file_exists( \$f ) && @filesize( \$f ) > " . AIWP_LOG_MAX_BYTES . " ) {\n"
		. "\t\treturn;\n"
		. "\t}\n"
		. "\tini_set( 'log_errors', '1' );\n"
		. "\tini_set( 'display_errors', '0' );\n"
		. "\terror_reporting( E_ALL );\n"
		. "\tini_set( 'error_log', \$f );\n"
		. "} )();\n";
}

function aiwp_error_logging_state() {
	$s       = get_site_option( AIWP_ERRLOG_OPTION );
	$until   = is_array( $s ) && ! empty( $s['until'] ) ? (int) $s['until'] : 0;
	$mu      = file_exists( aiwp_errlog_mu_path() );
	$enabled = $mu && $until > time();
	$log     = aiwp_log_resolve( 'aiwp' );
	return array(
		'enabled'         => $enabled,
		'until'           => $enabled ? gmdate( 'c', $until ) : null,
		'seconds_left'    => $enabled ? $until - time() : 0,
		'already_logging' => aiwp_wp_debug_logging(),
		'log_key'         => 'aiwp',
		'log_over_limit'  => $log && $log['exists'] && $log['size'] > AIWP_LOG_MAX_BYTES,
		'mu_plugin'       => $mu,
		'limits'          => array(
			'hours_default' => AIWP_ERRLOG_HOURS_DEFAULT,
			'hours_min'     => 1,
			'hours_max'     => AIWP_ERRLOG_HOURS_MAX,
			'max_log_bytes' => AIWP_LOG_MAX_BYTES,
		),
	);
}

function aiwp_error_logging_enable( $hours ) {
	$file = aiwp_log_prepare();
	if ( is_wp_error( $file ) ) {
		return $file;
	}
	if ( ! file_exists( $file ) ) {
		@touch( $file );
	}
	if ( @is_link( $file ) || ! is_writable( $file ) ) {
		return new WP_Error( 'aiwp_log_file', 'The error log file is not a writable regular file.', array( 'status' => 500 ) );
	}
	if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || @is_link( aiwp_errlog_mu_path() ) ) {
		return new WP_Error( 'aiwp_mu_dir', 'Could not create the must-use plugins folder.', array( 'status' => 500 ) );
	}
	$until = time() + (int) $hours * HOUR_IN_SECONDS;
	update_site_option( AIWP_ERRLOG_OPTION, array( 'until' => $until, 'file' => $file ) );
	if ( false === @file_put_contents( aiwp_errlog_mu_path(), aiwp_errlog_mu_source(), LOCK_EX ) ) {
		delete_site_option( AIWP_ERRLOG_OPTION );
		return new WP_Error( 'aiwp_mu_write', 'Could not write ' . aiwp_errlog_mu_path() . '.', array( 'status' => 500 ) );
	}
	wp_clear_scheduled_hook( AIWP_ERRLOG_HOOK );
	wp_schedule_single_event( $until, AIWP_ERRLOG_HOOK );
	return aiwp_error_logging_state();
}

/** Removes the must-use plugin, the scheduled removal and the option; the log file stays readable. */
function aiwp_error_logging_disable() {
	$mu = aiwp_errlog_mu_path();
	if ( file_exists( $mu ) || @is_link( $mu ) ) {
		@unlink( $mu );
	}
	wp_clear_scheduled_hook( AIWP_ERRLOG_HOOK );
	delete_site_option( AIWP_ERRLOG_OPTION );
}

function aiwp_error_logging_uninstall() {
	aiwp_error_logging_disable();
	delete_site_option( AIWP_ERRLOG_FILE_OPTION );
	delete_option( AIWP_STUB_SHORTCODES_OPTION );
	delete_site_option( 'aiwp_salts_rotated' );
	aiwp_rescue_uninstall();
	aiwp_file_backups_purge();
	delete_site_option( AIWP_RESCUE_OPTION );
	delete_site_option( AIWP_SECURITY_OPTION );
}

add_action( 'rest_api_init', 'aiwp_register_log_routes' );
function aiwp_register_log_routes() {
	// On multisite the logs and the switch are network-wide, so a single site's administrator is not enough.
	$can = function () {
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	};
	register_rest_route( 'ai-wp/v1', '/logs', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function () {
			$logs = array();
			foreach ( explode( ',', AIWP_LOG_KEYS ) as $key ) {
				$logs[] = aiwp_log_public( aiwp_log_resolve( $key ) );
			}
			return array( 'logs' => $logs, 'error_logging' => aiwp_error_logging_state() );
		},
	) );
	register_rest_route( 'ai-wp/v1', '/logs/(?P<key>[a-z-]+)', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'args'                => array(
			'kb' => array( 'type' => 'integer', 'default' => AIWP_LOG_KB_DEFAULT, 'minimum' => 1, 'maximum' => AIWP_LOG_KB_MAX ),
		),
		'callback'            => function ( $req ) {
			return aiwp_log_read( $req['key'], (int) $req->get_param( 'kb' ) );
		},
	) );
	register_rest_route( 'ai-wp/v1', '/logs/(?P<key>[a-z-]+)/clear', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'args'                => array( 'expect' => array( 'required' => true, 'type' => 'string' ) ),
		'callback'            => function ( $req ) {
			return aiwp_log_clear( $req['key'], $req->get_param( 'expect' ) );
		},
	) );
	register_rest_route( 'ai-wp/v1', '/error-logging', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => 'aiwp_error_logging_state',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'args'                => array(
				'enabled' => array( 'required' => true, 'type' => 'boolean' ),
				'hours'   => array( 'type' => 'integer', 'default' => AIWP_ERRLOG_HOURS_DEFAULT, 'minimum' => 1, 'maximum' => AIWP_ERRLOG_HOURS_MAX ),
			),
			'callback'            => function ( $req ) {
				if ( ! $req->get_param( 'enabled' ) ) {
					aiwp_error_logging_disable();
					return aiwp_error_logging_state();
				}
				return aiwp_error_logging_enable( (int) $req->get_param( 'hours' ) );
			},
		),
	) );
}

/* ------------------------------------------------------------------ security keys (wp-config.php salts) */

/*
 * Rotates the eight AUTH / SECURE_AUTH / LOGGED_IN / NONCE KEY and SALT constants in wp-config.php, e.g. after
 * an intrusion. Only plain define() lines that appear exactly once and hold the running value are replaced;
 * anything else is refused and nothing is written. Values are never returned. Every user is logged out;
 * application passwords keep working. REST: GET ai-wp/v1/salts, POST ai-wp/v1/salts/rotate; and a button
 * in Settings -> AI Site Manager.
 */
define( 'AIWP_SALT_NAMES', 'AUTH_KEY,SECURE_AUTH_KEY,LOGGED_IN_KEY,NONCE_KEY,AUTH_SALT,SECURE_AUTH_SALT,LOGGED_IN_SALT,NONCE_SALT' );
define( 'AIWP_SALTS_OPTION', 'aiwp_salts_rotated' );

function aiwp_salts_can() {
	return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
}

/** Same lookup as wp-load.php: next to WordPress, or one folder up when that folder is not another install. */
function aiwp_wp_config_path() {
	if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
		return ABSPATH . 'wp-config.php';
	}
	$up = dirname( ABSPATH ) . '/wp-config.php';
	return ( file_exists( $up ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) ? $up : null;
}

function aiwp_salt_literal_value( $quote, $raw ) {
	if ( "'" === $quote ) {
		return preg_replace_callback( '/\\\\([\\\\\'])/', function ( $m ) { return $m[1]; }, $raw );
	}
	return ( false === strpbrk( $raw, '\\$' ) ) ? $raw : null;
}

/** Per key: state ok | missing | duplicate | elsewhere, plus the offset and length of the quoted value when ok. */
function aiwp_salts_scan( $src ) {
	$found = array();
	foreach ( explode( ',', AIWP_SALT_NAMES ) as $name ) {
		$re = '/define\s*\(\s*([\'"])' . $name . '\1\s*,\s*([\'"])((?:(?!\2)[^\\\\]|\\\\.)*)\2\s*\)/s';
		$n  = preg_match_all( $re, $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( 1 !== $n ) {
			$found[ $name ] = array( 'state' => $n ? 'duplicate' : 'missing' );
			continue;
		}
		$value = aiwp_salt_literal_value( $m[0][2][0], $m[0][3][0] );
		$live  = defined( $name ) ? constant( $name ) : null;
		$found[ $name ] = array(
			'state'  => ( null !== $value && $value === $live ) ? 'ok' : 'elsewhere',
			'offset' => $m[0][3][1],
			'length' => strlen( $m[0][3][0] ),
		);
	}
	return $found;
}

function aiwp_salts_state() {
	$path = aiwp_wp_config_path();
	$rot  = get_site_option( AIWP_SALTS_OPTION );
	$out  = array( 'file' => $path, 'writable' => false, 'keys' => array(), 'ready' => false, 'rotated_at' => is_array( $rot ) ? $rot['at'] : null );
	if ( ! $path || ! is_readable( $path ) ) {
		return $out;
	}
	$out['writable'] = ! is_link( $path ) && is_writable( $path ) && is_writable( dirname( $path ) );
	$ready           = $out['writable'];
	foreach ( aiwp_salts_scan( (string) file_get_contents( $path ) ) as $name => $info ) {
		$out['keys'][ $name ] = $info['state'];
		$ready                = $ready && 'ok' === $info['state'];
	}
	$out['ready'] = $ready;
	return $out;
}

function aiwp_salts_problem( $state ) {
	if ( ! $state['file'] ) {
		return 'wp-config.php was not found next to WordPress or one folder above it.';
	}
	if ( ! $state['writable'] ) {
		return 'wp-config.php is not readable or writable by WordPress (or it is a symbolic link); change the keys in the hosting file manager instead.';
	}
	$bad = array();
	foreach ( $state['keys'] as $name => $s ) {
		if ( 'ok' !== $s ) {
			$bad[] = $name . ' (' . $s . ')';
		}
	}
	return $bad ? 'These keys are not plain define() lines in wp-config.php: ' . implode( ', ', $bad ) . '. missing = not in the file, duplicate = more than one line, elsewhere = the running value comes from another place.' : '';
}

function aiwp_salt_generate() {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!#%&()*+,-./:;<=>?@[]^_{|}~';
	$max   = strlen( $chars ) - 1;
	$s     = '';
	for ( $i = 0; $i < 64; $i++ ) {
		$s .= $chars[ random_int( 0, $max ) ];
	}
	return $s;
}

function aiwp_salts_rotate() {
	$state = aiwp_salts_state();
	if ( ! $state['ready'] ) {
		return new WP_Error( 'aiwp_salts_not_ready', 'Nothing was changed. ' . aiwp_salts_problem( $state ), array( 'status' => 409, 'state' => $state ) );
	}
	$path  = $state['file'];
	$src   = (string) file_get_contents( $path );
	$spots = array();
	foreach ( aiwp_salts_scan( $src ) as $name => $info ) {
		if ( 'ok' !== $info['state'] ) {
			return new WP_Error( 'aiwp_salts_changed', 'Nothing was changed: wp-config.php changed while it was being read. Try again.', array( 'status' => 409 ) );
		}
		$spots[] = $info;
	}
	usort( $spots, function ( $a, $b ) { return $b['offset'] - $a['offset']; } );
	$new = $src;
	foreach ( $spots as $s ) {
		$new = substr_replace( $new, aiwp_salt_generate(), $s['offset'], $s['length'] );
	}
	try {
		token_get_all( $new, TOKEN_PARSE );
	} catch ( ParseError $e ) {
		return new WP_Error( 'aiwp_salts_parse', 'Nothing was changed: the new wp-config.php would not parse (' . $e->getMessage() . ').', array( 'status' => 500 ) );
	}
	if ( true !== aiwp_atomic_write( $path, $new, false ) ) {
		return new WP_Error( 'aiwp_salts_write', 'Nothing was changed: writing wp-config.php failed.', array( 'status' => 500 ) );
	}
	$at = gmdate( 'c' );
	update_site_option( AIWP_SALTS_OPTION, array( 'at' => $at, 'user' => get_current_user_id() ) );
	do_action( 'litespeed_purge_all' );
	return array(
		'rotated'    => explode( ',', AIWP_SALT_NAMES ),
		'file'       => $path,
		'rotated_at' => $at,
		'note'       => 'Every user is logged out; application passwords keep working.',
	);
}

add_action( 'rest_api_init', 'aiwp_register_salt_routes' );
function aiwp_register_salt_routes() {
	register_rest_route( 'ai-wp/v1', '/salts', array(
		'methods'             => 'GET',
		'permission_callback' => 'aiwp_salts_can',
		'callback'            => function () {
			return aiwp_salts_state();
		},
	) );
	register_rest_route( 'ai-wp/v1', '/salts/rotate', array(
		'methods'             => 'POST',
		'permission_callback' => 'aiwp_salts_can',
		'callback'            => function () {
			return aiwp_salts_rotate();
		},
	) );
}

add_action( 'admin_post_aiwp_rotate_salts', 'aiwp_handle_salts_form' );
function aiwp_handle_salts_form() {
	if ( ! aiwp_salts_can() ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'aiwp_rotate_salts' );
	$res = aiwp_salts_rotate();
	set_transient(
		'aiwp_notice_' . get_current_user_id(),
		is_wp_error( $res )
			? array( 'status' => 'error', 'file' => '', 'message' => $res->get_error_message() )
			: array( 'status' => 'rotated', 'file' => 'security keys; every user was logged out', 'message' => '' ),
		600
	);
	$back = admin_url( 'options-general.php?page=ai-wp-site-manager#salts' );
	wp_safe_redirect( is_wp_error( $res ) ? $back : wp_login_url( $back ) );
	exit;
}

/** Rendered inside aiwp_settings_page(). */
function aiwp_render_salts_section() {
	if ( ! aiwp_salts_can() ) {
		return;
	}
	$state = aiwp_salts_state();
	echo '<h2 id="salts" style="margin-top:2em">Security keys (salts)</h2>';
	echo '<p class="description">Replaces the eight secret keys in wp-config.php with new random values. Every logged-in user, you included, is logged out at once; application passwords keep working. Use it after a suspected intrusion, or when someone who had server or admin access leaves.</p>';
	$when = $state['rotated_at'] ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $state['rotated_at'] ) ), 'Y-m-d H:i' ) : 'never by this plugin';
	echo '<p><strong>File:</strong> <code>' . esc_html( $state['file'] ? $state['file'] : 'not found' ) . '</code> &middot; last rotated: ' . esc_html( $when ) . '</p>';
	if ( ! $state['ready'] ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html( aiwp_salts_problem( $state ) ) . '</p></div>';
		return;
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'aiwp_rotate_salts' );
	echo '<input type="hidden" name="action" value="aiwp_rotate_salts">';
	echo '<p><button type="submit" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( 'Rotate the security keys now? Every user, you included, will be logged out immediately.' ) ) . ')">Rotate security keys</button></p></form>';
}

/* ------------------------------------------------------------------ bulk user cleanup by role */

/*
 * Deletes the users of one role in batches, e.g. thousands of subscribers imported years ago. Their content
 * goes to the reassign user. Administrators, users who can manage options and the calling user are never
 * deleted. expect_count must equal the live count of that role, so a stale preview cannot delete more.
 * REST: GET ai-wp/v1/users/role-count?role=subscriber, POST ai-wp/v1/users/delete-by-role
 * {role, expect_count, reassign, limit 1..500 default 200}. Single sites only.
 */
function aiwp_role_count( $role ) {
	$q = new WP_User_Query( array( 'role' => $role, 'fields' => 'ID', 'number' => 1, 'count_total' => true ) );
	return (int) $q->get_total();
}

function aiwp_role_check( $role ) {
	if ( is_multisite() ) {
		return new WP_Error( 'aiwp_multisite', 'Not available on multisite.', array( 'status' => 400 ) );
	}
	if ( '' === $role || ! get_role( $role ) ) {
		return new WP_Error( 'aiwp_bad_role', 'Unknown role.', array( 'status' => 400 ) );
	}
	if ( 'administrator' === $role ) {
		return new WP_Error( 'aiwp_bad_role', 'Administrators are never deleted in bulk.', array( 'status' => 400 ) );
	}
	return true;
}

function aiwp_delete_users_by_role( WP_REST_Request $req ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$role  = sanitize_key( (string) $req->get_param( 'role' ) );
	$check = aiwp_role_check( $role );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$reassign = (int) $req->get_param( 'reassign' );
	$target   = get_userdata( $reassign );
	if ( ! $target || in_array( $role, (array) $target->roles, true ) ) {
		return new WP_Error( 'aiwp_bad_reassign', 'reassign must be an existing user outside that role.', array( 'status' => 400 ) );
	}
	$total = aiwp_role_count( $role );
	if ( (int) $req->get_param( 'expect_count' ) !== $total ) {
		return new WP_Error( 'aiwp_count_changed', 'expect_count does not match; nothing was deleted.', array( 'status' => 409, 'count' => $total ) );
	}
	$skip    = array();
	$deleted = 0;
	$ids     = get_users( array( 'role' => $role, 'fields' => 'ID', 'number' => (int) $req->get_param( 'limit' ), 'orderby' => 'ID' ) );
	foreach ( $ids as $id ) {
		$id   = (int) $id;
		$user = get_userdata( $id );
		if ( ! $user || get_current_user_id() === $id || $id === $reassign || user_can( $user, 'manage_options' ) ) {
			$skip[] = $id;
			continue;
		}
		if ( wp_delete_user( $id, $reassign ) ) {
			$deleted++;
		} else {
			$skip[] = $id;
		}
	}
	return array( 'role' => $role, 'deleted' => $deleted, 'skipped' => $skip, 'remaining' => aiwp_role_count( $role ) );
}

add_action( 'rest_api_init', 'aiwp_register_user_cleanup_routes' );
function aiwp_register_user_cleanup_routes() {
	$can = function () {
		return current_user_can( 'manage_options' ) && current_user_can( 'delete_users' );
	};
	register_rest_route( 'ai-wp/v1', '/users/role-count', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'args'                => array( 'role' => array( 'required' => true, 'type' => 'string' ) ),
		'callback'            => function ( $req ) {
			$role  = sanitize_key( (string) $req->get_param( 'role' ) );
			$check = aiwp_role_check( $role );
			return is_wp_error( $check ) ? $check : array( 'role' => $role, 'count' => aiwp_role_count( $role ) );
		},
	) );
	register_rest_route( 'ai-wp/v1', '/users/delete-by-role', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'args'                => array(
			'role'         => array( 'required' => true, 'type' => 'string' ),
			'expect_count' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
			'reassign'     => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
			'limit'        => array( 'type' => 'integer', 'default' => 200, 'minimum' => 1, 'maximum' => 500 ),
		),
		'callback'            => 'aiwp_delete_users_by_role',
	) );
}

/* ------------------------------------------------------------------ security: managing-server IP lock */

/*
 * The application passwords the managing server uses ("claimed" through ai-wp/v1/security/claim) are accepted only from
 * the managing server's addresses. Every other application password behaves as before. Mode off | report (log would-be
 * refusals only) | on. Addresses come from REMOTE_ADDR; a forwarded address is believed only when the request was
 * relayed by this server itself (Imunify WebShield runs on the web server), and refused when the forwarded headers disagree.
 */
if ( ! defined( 'AIWP_DEFAULT_API_IPS' ) ) {
	define( 'AIWP_DEFAULT_API_IPS', '217.156.42.4' );
}
define( 'AIWP_SECURITY_OPTION', 'aiwp_security' );
define( 'AIWP_SECURITY_REPORT_MAX', 50 );

function aiwp_site_admin_can() {
	return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
}

/** Canonical text form; an IPv4-mapped IPv6 address (::ffff:a.b.c.d) becomes plain IPv4. '' when not an address. */
function aiwp_ip_normalize( $ip ) {
	$ip = trim( (string) $ip );
	if ( false !== strpos( $ip, "\0" ) ) {
		return '';
	}
	$bin = @inet_pton( $ip );
	if ( false === $bin ) {
		return '';
	}
	if ( 16 === strlen( $bin ) && 0 === strpos( $bin, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
		$bin = substr( $bin, 12 );
	}
	return (string) inet_ntop( $bin );
}

/** True when the connection comes from this web server itself (a local relay such as Imunify WebShield). */
function aiwp_remote_is_this_server( $remote ) {
	$self = aiwp_ip_normalize( isset( $_SERVER['SERVER_ADDR'] ) ? $_SERVER['SERVER_ADDR'] : '' );
	return '' !== $remote && ( $remote === $self || '127.0.0.1' === $remote || '::1' === $remote );
}

/** Every address named by a proxy header, unparsed. */
function aiwp_forwarded_ips() {
	$found = array();
	foreach ( array( 'HTTP_X_REAL_IP', 'HTTP_X_CLIENT_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ) as $h ) {
		if ( ! empty( $_SERVER[ $h ] ) ) {
			foreach ( explode( ',', (string) $_SERVER[ $h ] ) as $v ) {
				$found[] = $v;
			}
		}
	}
	if ( ! empty( $_SERVER['HTTP_FORWARDED'] ) && preg_match_all( '/for=\s*"?\[?([^\]";,\s]+)/i', (string) $_SERVER['HTTP_FORWARDED'], $m ) ) {
		foreach ( $m[1] as $v ) {
			$found[] = $v;
		}
	}
	return $found;
}

/** The caller's address for the lock, or '' when it cannot be decided. */
function aiwp_client_ip() {
	$remote = aiwp_ip_normalize( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' );
	$fwd    = aiwp_forwarded_ips();
	if ( ! $fwd || ! aiwp_remote_is_this_server( $remote ) ) {
		return $remote;
	}
	$ips = array();
	foreach ( $fwd as $v ) {
		$ips[ aiwp_ip_normalize( $v ) ] = true;
	}
	return ( 1 === count( $ips ) && ! isset( $ips[''] ) ) ? (string) key( $ips ) : '';
}

function aiwp_security_get() {
	$s = get_site_option( AIWP_SECURITY_OPTION );
	return array_merge( array( 'mode' => 'off', 'ips' => null, 'uuids' => array(), 'report' => array() ), is_array( $s ) ? $s : array() );
}

function aiwp_security_save( $s ) {
	update_site_option( AIWP_SECURITY_OPTION, $s );
	aiwp_rescue_sync();
}

/** Managing-server addresses: the AIWP_API_IPS constant from wp-config.php, else the saved list, else the default. Empty = nobody. */
function aiwp_api_ips() {
	if ( defined( 'AIWP_API_IPS' ) ) {
		$list = AIWP_API_IPS;
	} else {
		$s    = aiwp_security_get();
		$list = is_array( $s['ips'] ) ? implode( ',', $s['ips'] ) : AIWP_DEFAULT_API_IPS;
	}
	$out = array();
	foreach ( preg_split( '/[\s,]+/', (string) $list ) as $ip ) {
		$ip = aiwp_ip_normalize( $ip );
		if ( '' !== $ip ) {
			$out[] = $ip;
		}
	}
	return array_values( array_unique( $out ) );
}

function aiwp_request_from_api_ip() {
	$ip = aiwp_client_ip();
	return '' !== $ip && in_array( $ip, aiwp_api_ips(), true );
}

add_action( 'wp_authenticate_application_password_errors', 'aiwp_ip_lock_check', 10, 3 );
function aiwp_ip_lock_check( $error, $user, $item ) {
	$s = aiwp_security_get();
	if ( 'off' === $s['mode'] || empty( $item['uuid'] ) || ! in_array( $item['uuid'], (array) $s['uuids'], true ) || aiwp_request_from_api_ip() ) {
		return;
	}
	array_unshift( $s['report'], array(
		'at'        => gmdate( 'c' ),
		'remote'    => isset( $_SERVER['REMOTE_ADDR'] ) ? substr( (string) $_SERVER['REMOTE_ADDR'], 0, 64 ) : '',
		'seen_as'   => aiwp_client_ip(),
		'forwarded' => substr( implode( ',', aiwp_forwarded_ips() ), 0, 200 ),
		'name'      => isset( $item['name'] ) ? (string) $item['name'] : '',
		'refused'   => 'on' === $s['mode'],
	) );
	$s['report'] = array_slice( $s['report'], 0, AIWP_SECURITY_REPORT_MAX );
	update_site_option( AIWP_SECURITY_OPTION, $s );
	if ( 'on' === $s['mode'] ) {
		$error->add( 'aiwp_ip_locked', 'This application password is only accepted from the managing server.' );
	}
}

function aiwp_current_app_password_uuid() {
	return function_exists( 'rest_get_authenticated_app_password' ) ? (string) rest_get_authenticated_app_password() : '';
}

function aiwp_claimed_passwords( $s ) {
	$out = array();
	foreach ( get_users( array( 'role__in' => array( 'administrator' ), 'fields' => 'ID' ) ) as $uid ) {
		foreach ( WP_Application_Passwords::get_user_application_passwords( (int) $uid ) as $item ) {
			if ( in_array( $item['uuid'], (array) $s['uuids'], true ) ) {
				$out[] = array( 'uuid' => $item['uuid'], 'name' => $item['name'], 'user_id' => (int) $uid, 'last_ip' => $item['last_ip'] );
			}
		}
	}
	return $out;
}

function aiwp_security_state() {
	$s = aiwp_security_get();
	return array(
		'mode'              => $s['mode'],
		'ips'               => aiwp_api_ips(),
		'ips_from_constant' => defined( 'AIWP_API_IPS' ),
		'server_addr'       => aiwp_ip_normalize( isset( $_SERVER['SERVER_ADDR'] ) ? $_SERVER['SERVER_ADDR'] : '' ),
		'claimed'           => aiwp_claimed_passwords( $s ),
		'report'            => array_slice( $s['report'], 0, 20 ),
		'request'           => array( 'seen_as' => aiwp_client_ip(), 'allowed' => aiwp_request_from_api_ip(), 'app_password_uuid' => aiwp_current_app_password_uuid() ),
		'xmlrpc_enabled'    => aiwp_xmlrpc_needed(),
		'alert_email'       => AIWP_ALERT_EMAIL,
		'rescue'            => aiwp_rescue_state(),
	);
}

function aiwp_security_claim() {
	if ( ! aiwp_request_from_api_ip() ) {
		return new WP_Error( 'aiwp_not_api_ip', 'Only the managing server may claim a password.', array( 'status' => 403 ) );
	}
	$uuid = aiwp_current_app_password_uuid();
	if ( '' === $uuid ) {
		return new WP_Error( 'aiwp_no_app_password', 'Call this route with the application password to claim.', array( 'status' => 400 ) );
	}
	$s = aiwp_security_get();
	if ( ! in_array( $uuid, (array) $s['uuids'], true ) ) {
		$s['uuids'][] = $uuid;
	}
	if ( 'off' === $s['mode'] ) {
		$s['mode'] = 'report';
	}
	aiwp_security_save( $s );
	return aiwp_security_state();
}

function aiwp_security_set_mode( $mode ) {
	if ( ! in_array( $mode, array( 'off', 'report', 'on' ), true ) ) {
		return new WP_Error( 'aiwp_bad_mode', 'mode is off, report or on.', array( 'status' => 400 ) );
	}
	$s = aiwp_security_get();
	if ( 'on' === $mode && ! ( aiwp_request_from_api_ip() && in_array( aiwp_current_app_password_uuid(), (array) $s['uuids'], true ) ) ) {
		return new WP_Error( 'aiwp_lock_self_check', 'The lock is switched on only by a claimed application password calling from a managing-server address, so the switch itself proves the lock lets that call through.', array( 'status' => 409, 'request' => aiwp_security_state()['request'] ) );
	}
	$s['mode'] = $mode;
	aiwp_security_save( $s );
	return aiwp_security_state();
}

add_action( 'rest_api_init', 'aiwp_register_security_routes' );
function aiwp_register_security_routes() {
	register_rest_route( 'ai-wp/v1', '/security', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'aiwp_site_admin_can',
			'callback'            => 'aiwp_security_state',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'aiwp_site_admin_can',
			'args'                => array( 'mode' => array( 'required' => true, 'type' => 'string' ) ),
			'callback'            => function ( $req ) {
				return aiwp_security_set_mode( (string) $req->get_param( 'mode' ) );
			},
		),
	) );
	register_rest_route( 'ai-wp/v1', '/security/claim', array(
		'methods'             => 'POST',
		'permission_callback' => 'aiwp_site_admin_can',
		'callback'            => 'aiwp_security_claim',
	) );
}

/* ------------------------------------------------------------------ security: public user data, XML-RPC */

/*
 * Visitors no longer see login slugs: user REST answers drop slug and link, slug/search lookups are ignored, ?author=N is
 * a 404 (only with pretty permalinks, where it is not the normal author link), no users sitemap, no oEmbed author_url.
 */
add_filter( 'rest_prepare_user', 'aiwp_hide_user_slug', 10, 3 );
function aiwp_hide_user_slug( $response, $user, $request ) {
	if ( is_user_logged_in() || ! ( $response instanceof WP_REST_Response ) ) {
		return $response;
	}
	$data = $response->get_data();
	unset( $data['slug'], $data['link'] );
	$response->set_data( $data );
	return $response;
}

add_filter( 'rest_user_query', 'aiwp_limit_user_query', 10, 2 );
function aiwp_limit_user_query( $args, $request ) {
	if ( ! is_user_logged_in() ) {
		unset( $args['search'], $args['search_columns'], $args['nicename__in'], $args['login__in'] );
	}
	return $args;
}

add_action( 'template_redirect', 'aiwp_block_author_query', 1 );
function aiwp_block_author_query() {
	if ( is_user_logged_in() || ! isset( $_GET['author'] ) || ! get_option( 'permalink_structure' ) ) {
		return;
	}
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
	remove_action( 'template_redirect', 'redirect_canonical' );
}

add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}, 10, 2 );
add_filter( 'oembed_response_data', function ( $data ) {
	unset( $data['author_url'] );
	return $data;
} );

/*
 * XML-RPC stays only where a live Jetpack connection (Jetpack, WooPayments, Jetpack Backup, Boost...) needs it. The
 * connection code alone proves nothing: WooCommerce ships it on every shop. Connected = WordPress.com site id + site token.
 */
function aiwp_xmlrpc_needed() {
	$opts    = get_option( 'jetpack_options' );
	$private = get_option( 'jetpack_private_options' );
	$linked  = is_array( $opts ) && ! empty( $opts['id'] ) && is_array( $private ) && ! empty( $private['blog_token'] );
	return (bool) apply_filters( 'aiwp_keep_xmlrpc', $linked );
}

add_filter( 'xmlrpc_enabled', function ( $enabled ) {
	return aiwp_xmlrpc_needed() ? $enabled : false;
} );
add_filter( 'xmlrpc_methods', function ( $methods ) {
	return aiwp_xmlrpc_needed() ? $methods : array();
} );
add_filter( 'wp_headers', function ( $headers ) {
	if ( ! aiwp_xmlrpc_needed() ) {
		unset( $headers['X-Pingback'] );
	}
	return $headers;
} );

/* ------------------------------------------------------------------ security: e-mail alerts */

/* New administrator, administrator role added, super admin granted, new application password: one mail at the end of the request to the site's admin address and the team, at most AIWP_ALERT_MAX_PER_HOUR an hour. */
if ( ! defined( 'AIWP_ALERT_EMAIL' ) ) {
	define( 'AIWP_ALERT_EMAIL', 'alerts@reallife.solutions' );
}
define( 'AIWP_ALERT_MAX_PER_HOUR', 10 );

add_action( 'set_user_role', function ( $user_id, $role, $old_roles ) {
	if ( 'administrator' === $role && ! in_array( 'administrator', (array) $old_roles, true ) ) {
		aiwp_alert_queue( 'New administrator', $user_id );
	}
}, 10, 3 );
add_action( 'add_user_role', function ( $user_id, $role ) {
	if ( 'administrator' === $role ) {
		aiwp_alert_queue( 'Administrator role added', $user_id );
	}
}, 10, 2 );
add_action( 'granted_super_admin', function ( $user_id ) {
	aiwp_alert_queue( 'Super admin granted', $user_id );
} );
add_action( 'wp_create_application_password', function ( $user_id, $item ) {
	aiwp_alert_queue( 'New application password "' . ( isset( $item['name'] ) ? $item['name'] : '' ) . '"', $user_id );
}, 10, 2 );

function aiwp_alert_queue( $event, $user_id ) {
	global $aiwp_alerts;
	if ( empty( $aiwp_alerts ) ) {
		$aiwp_alerts = array();
		add_action( 'shutdown', 'aiwp_alert_send', PHP_INT_MAX );
	}
	$user  = get_userdata( (int) $user_id );
	$actor = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	$via   = '';
	$uuid  = aiwp_current_app_password_uuid();
	if ( $actor && $actor->ID && '' !== $uuid ) {
		$item = WP_Application_Passwords::get_user_application_password( $actor->ID, $uuid );
		$via  = $item ? ' with application password "' . $item['name'] . '"' : ' with an application password';
	}
	$aiwp_alerts[] = $event . ': ' . ( $user ? $user->user_login . ' (id ' . $user->ID . ', ' . $user->user_email . ')' : 'user id ' . (int) $user_id )
		. "\n  by: " . ( $actor && $actor->ID ? $actor->user_login . $via : 'no logged-in user (registration, cron or command line)' )
		. "\n  from IP: " . ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'none' )
		. "\n  at: " . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
}

function aiwp_alert_send() {
	global $aiwp_alerts;
	if ( empty( $aiwp_alerts ) ) {
		return;
	}
	$sent = (int) get_transient( 'aiwp_alerts_sent' );
	if ( $sent >= AIWP_ALERT_MAX_PER_HOUR ) {
		return;
	}
	set_transient( 'aiwp_alerts_sent', $sent + 1, HOUR_IN_SECONDS );
	$events      = $aiwp_alerts;
	$aiwp_alerts = array();
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} elseif ( function_exists( 'litespeed_finish_request' ) ) {
		litespeed_finish_request();
	}
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$to   = array_values( array_unique( array_filter( array( (string) get_option( 'admin_email' ), AIWP_ALERT_EMAIL ) ) ) );
	$body = 'Security alert from ' . home_url( '/' ) . "\n\n" . implode( "\n\n", $events )
		. "\n\nIf you did not expect this, check Users in wp-admin and Settings -> AI Site Manager.\n-- AI WP Site Manager " . CSM_VERSION;
	wp_mail( $to, '[' . $host . '] Security alert: ' . strtok( $events[0], ':' ) . ( count( $events ) > 1 ? ' (+' . ( count( $events ) - 1 ) . ' more)' : '' ), $body );
}

/* ------------------------------------------------------------------ files: read, patch, delete, restore */

/*
 * ai-wp/v1/files?path=themes/x/functions.php (GET), files/patch {path, expect, replacements:[{find, replace}]},
 * files/delete {path, expect}, files/restore {path}. Paths start with themes/, plugins/, mu-plugins/ or root/.
 * Only existing files, only AIWP_FILE_EXTENSIONS, no dot-files; in the web root only .htaccess (patch) and
 * index.html / index.htm (delete). Always from a managing-server address, whatever the lock mode. expect = sha256 of
 * the content that was read; every find must occur exactly once. PHP is parsed before saving. After saving, the home
 * page and /wp-json/ are requested; if they answered before and fail now, the previous version is put back at once.
 * The last AIWP_FILE_BACKUPS_KEEP versions of each file are kept as .bak files in the plugin's private folder.
 */
define( 'AIWP_FILE_EXTENSIONS', 'php,css,js,json,txt,html,htm' );
define( 'AIWP_ROOT_DELETE', 'index.html,index.htm' );
define( 'AIWP_FILE_MAX_BYTES', 2097152 );
define( 'AIWP_FILE_BACKUPS_OPTION', 'aiwp_file_backups' );
define( 'AIWP_FILE_BACKUPS_KEEP', 5 );

function aiwp_file_bases() {
	return array(
		'themes'     => array( get_theme_root(), 'edit_themes' ),
		'plugins'    => array( WP_PLUGIN_DIR, 'edit_plugins' ),
		'mu-plugins' => array( WPMU_PLUGIN_DIR, 'edit_plugins' ),
		'root'       => array( aiwp_root_dir(), 'edit_plugins' ),
	);
}

/*
 * DISALLOW_FILE_EDIT (also switched on by security plugins: Solid / Kadence / iThemes "Disable File Editor") exists to
 * keep a stolen admin browser session away from wp-admin's PHP editor. The file and HTTPS routes are already limited to
 * the managing server's addresses, so there they need manage_options instead; the browser editor stays off.
 * DISALLOW_FILE_MODS still blocks them.
 */
function aiwp_file_editor_bypass() {
	return defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT
		&& ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS )
		&& current_user_can( 'manage_options' ) && aiwp_request_from_api_ip();
}

/** $op read | patch | delete | restore. Returns rel, path, exists, or a WP_Error. */
function aiwp_file_resolve( $rel, $op ) {
	if ( false !== strpos( (string) $rel, "\0" ) ) {
		return new WP_Error( 'aiwp_bad_path', 'NUL bytes are refused.', array( 'status' => 400 ) );
	}
	$parts = explode( '/', trim( str_replace( '\\', '/', (string) $rel ), '/' ) );
	$base  = array_shift( $parts );
	$bases = aiwp_file_bases();
	if ( ! isset( $bases[ $base ] ) || ! $parts ) {
		return new WP_Error( 'aiwp_bad_path', 'path starts with themes/, plugins/, mu-plugins/ or root/.', array( 'status' => 400 ) );
	}
	if ( 'read' !== $op && 'plugins' === $base && dirname( plugin_basename( __FILE__ ) ) === $parts[0] ) {
		return new WP_Error( 'aiwp_file_self', 'This plugin\'s own files are changed only by its release updates.', array( 'status' => 403 ) );
	}
	foreach ( $parts as $p ) {
		if ( '' === $p || '.' === $p || '..' === $p ) {
			return new WP_Error( 'aiwp_bad_path', 'Empty, "." and ".." segments are refused.', array( 'status' => 400 ) );
		}
	}
	$name = end( $parts );
	if ( 'root' === $base ) {
		$allowed = 1 === count( $parts ) && ( ( '.htaccess' === $name && 'delete' !== $op ) || in_array( $name, explode( ',', AIWP_ROOT_DELETE ), true ) );
	} else {
		$dot = false;
		foreach ( $parts as $p ) {
			$dot = $dot || '.' === $p[0];
		}
		$allowed = ! $dot && in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), explode( ',', AIWP_FILE_EXTENSIONS ), true )
			&& ! ( 'mu-plugins' === $base && 0 === strpos( $name, 'aiwp-' ) );
	}
	if ( ! $allowed ) {
		return new WP_Error( 'aiwp_file_not_allowed', 'This file may not be ' . ( 'read' === $op ? 'read' : 'changed' ) . ' through this route.', array( 'status' => 403 ) );
	}
	list( $root, $cap ) = $bases[ $base ];
	if ( ! current_user_can( $cap ) && ! aiwp_file_editor_bypass() ) {
		return new WP_Error( 'aiwp_file_cap', 'Missing capability ' . $cap . ' (DISALLOW_FILE_MODS may be set).', array( 'status' => 403 ) );
	}
	$path = rtrim( $root, '/\\' ) . '/' . implode( '/', $parts );
	$rel  = $base . '/' . implode( '/', $parts );
	if ( @is_link( $path ) ) {
		return new WP_Error( 'aiwp_file_link', 'Symbolic links are refused.', array( 'status' => 403 ) );
	}
	$real = @realpath( $path );
	if ( false === $real ) {
		$parent = @realpath( dirname( $path ) );
		if ( 'restore' === $op && false !== $parent && aiwp_path_inside( $parent, $root ) ) {
			return array( 'rel' => $rel, 'path' => $parent . DIRECTORY_SEPARATOR . $name, 'exists' => false );
		}
		return new WP_Error( 'aiwp_file_missing', 'No such file.', array( 'status' => 404 ) );
	}
	if ( ! aiwp_path_inside( $real, $root ) || ! @is_file( $real ) ) {
		return new WP_Error( 'aiwp_file_outside', 'The file is not a regular file inside ' . $base . '/.', array( 'status' => 403 ) );
	}
	return array( 'rel' => $rel, 'path' => $real, 'exists' => true );
}

function aiwp_file_backups_index() {
	$idx = get_site_option( AIWP_FILE_BACKUPS_OPTION );
	return is_array( $idx ) ? $idx : array();
}

function aiwp_file_backup( $rel, $content, $action ) {
	$dir = aiwp_private_dir( 'aiwp-backups' );
	if ( null === $dir ) {
		return new WP_Error( 'aiwp_backup_dir', 'No writable folder for backups; nothing was changed.', array( 'status' => 500 ) );
	}
	$file = wp_generate_password( 20, false ) . '.bak';
	if ( false === @file_put_contents( $dir . '/' . $file, $content, LOCK_EX ) ) {
		return new WP_Error( 'aiwp_backup_write', 'Could not write the backup; nothing was changed.', array( 'status' => 500 ) );
	}
	$idx    = aiwp_file_backups_index();
	$list   = isset( $idx[ $rel ] ) ? (array) $idx[ $rel ] : array();
	$entry  = array( 'file' => $file, 'dir' => $dir, 'saved' => gmdate( 'c' ), 'sha256' => hash( 'sha256', $content ), 'size' => strlen( $content ), 'before' => $action );
	array_unshift( $list, $entry );
	foreach ( array_slice( $list, AIWP_FILE_BACKUPS_KEEP ) as $old ) {
		@unlink( $old['dir'] . '/' . $old['file'] );
	}
	$idx[ $rel ] = array_slice( $list, 0, AIWP_FILE_BACKUPS_KEEP );
	update_site_option( AIWP_FILE_BACKUPS_OPTION, $idx );
	return $entry;
}

/** Deletes every backup file, the folders' deny files and the folders themselves, then the index. */
function aiwp_file_backups_purge() {
	$dirs = array();
	foreach ( aiwp_file_backups_index() as $list ) {
		foreach ( (array) $list as $b ) {
			if ( preg_match( '/^[A-Za-z0-9]{20}\.bak$/', (string) $b['file'] ) && 'aiwp-backups' === basename( (string) $b['dir'] ) ) {
				@unlink( $b['dir'] . '/' . $b['file'] );
				$dirs[ $b['dir'] ] = true;
			}
		}
	}
	foreach ( array_keys( $dirs ) as $dir ) {
		if ( ! @is_link( $dir ) ) {
			@unlink( $dir . '/.htaccess' );
			@unlink( $dir . '/index.php' );
			@rmdir( $dir );
		}
	}
	delete_site_option( AIWP_FILE_BACKUPS_OPTION );
}

/** Home page and REST index from inside the server, cache-busted. ok = both answered 2xx/3xx. */
function aiwp_site_check() {
	$out = array( 'ok' => true );
	foreach ( array( 'home' => home_url( '/' ), 'rest' => rest_url() ) as $k => $url ) {
		$res = wp_remote_get( add_query_arg( 'aiwp_check', wp_generate_password( 8, false ), $url ), array(
			'timeout'     => 10,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		) );
		$code      = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$out[ $k ] = is_wp_error( $res ) ? $res->get_error_message() : $code;
		if ( $code < 200 || $code >= 400 ) {
			$out['ok'] = false;
		}
	}
	return $out;
}

/** Writes $new (null = delete) over $old (null = absent), with backup, check and automatic roll-back. */
function aiwp_file_commit( $f, $old, $new, $action ) {
	if ( null !== $new && 'php' === strtolower( pathinfo( $f['path'], PATHINFO_EXTENSION ) ) ) {
		try {
			token_get_all( $new, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			return new WP_Error( 'aiwp_file_parse', 'Nothing was written: the new file does not parse on PHP ' . PHP_VERSION . ' (' . $e->getMessage() . ', line ' . $e->getLine() . ').', array( 'status' => 422 ) );
		}
	}
	$before = aiwp_site_check();
	$backup = null;
	if ( null !== $old ) {
		$backup = aiwp_file_backup( $f['rel'], $old, $action );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
	}
	$done = null === $new ? ( @unlink( $f['path'] ) ? true : new WP_Error( 'aiwp_file_delete', 'Could not delete the file.', array( 'status' => 500 ) ) ) : aiwp_atomic_write( $f['path'], $new );
	if ( is_wp_error( $done ) ) {
		return $done;
	}
	$after = aiwp_site_check();
	if ( $before['ok'] && ! $after['ok'] ) {
		$undo = null === $old ? @unlink( $f['path'] ) : aiwp_atomic_write( $f['path'], $old );
		return new WP_Error( 'aiwp_file_rolled_back', 'The site stopped answering after the change, so the previous state was put back.', array(
			'status'       => 409,
			'check_before' => $before,
			'check_after'  => $after,
			'rolled_back'  => true === $undo,
		) );
	}
	do_action( 'litespeed_purge_all' );
	return array(
		'path'         => $f['rel'],
		'action'       => $action,
		'sha256'       => null === $new ? null : hash( 'sha256', $new ),
		'backup_saved' => $backup ? $backup['saved'] : null,
		'check_before' => $before,
		'check_after'  => $after,
	);
}

function aiwp_file_read_checked( $f, $expect ) {
	$size = (int) @filesize( $f['path'] );
	if ( $size > AIWP_FILE_MAX_BYTES ) {
		return new WP_Error( 'aiwp_file_big', 'Files over ' . AIWP_FILE_MAX_BYTES . ' bytes are refused.', array( 'status' => 413 ) );
	}
	$content = @file_get_contents( $f['path'] );
	if ( false === $content ) {
		return new WP_Error( 'aiwp_file_read', 'Could not read the file.', array( 'status' => 500 ) );
	}
	if ( null !== $expect && ! hash_equals( hash( 'sha256', $content ), strtolower( trim( (string) $expect ) ) ) ) {
		return new WP_Error( 'aiwp_file_changed', 'The file changed since it was read; read it again and pass the new sha256 as expect.', array( 'status' => 409, 'sha256' => hash( 'sha256', $content ) ) );
	}
	return $content;
}

function aiwp_file_get( WP_REST_Request $req ) {
	$f = aiwp_file_resolve( $req->get_param( 'path' ), 'read' );
	if ( is_wp_error( $f ) ) {
		return $f;
	}
	$content = aiwp_file_read_checked( $f, null );
	if ( is_wp_error( $content ) ) {
		return $content;
	}
	$idx = aiwp_file_backups_index();
	return array(
		'path'    => $f['rel'],
		'size'    => strlen( $content ),
		'mtime'   => (int) filemtime( $f['path'] ),
		'sha256'  => hash( 'sha256', $content ),
		'content' => $content,
		'backups' => isset( $idx[ $f['rel'] ] ) ? array_map( function ( $b ) { return array( 'saved' => $b['saved'], 'sha256' => $b['sha256'], 'size' => $b['size'], 'before' => $b['before'] ); }, $idx[ $f['rel'] ] ) : array(),
	);
}

function aiwp_file_patch( WP_REST_Request $req ) {
	$f = aiwp_file_resolve( $req->get_param( 'path' ), 'patch' );
	if ( is_wp_error( $f ) ) {
		return $f;
	}
	$old = aiwp_file_read_checked( $f, $req->get_param( 'expect' ) );
	if ( is_wp_error( $old ) ) {
		return $old;
	}
	$new  = $old;
	$crlf = false !== strpos( $old, "\r\n" );
	foreach ( (array) $req->get_param( 'replacements' ) as $i => $r ) {
		$find    = isset( $r['find'] ) ? (string) $r['find'] : '';
		$replace = isset( $r['replace'] ) ? (string) $r['replace'] : '';
		if ( $crlf ) {
			$find    = str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $find ) );
			$replace = str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $replace ) );
		}
		$n = '' === $find ? 0 : substr_count( $new, $find );
		if ( 1 !== $n ) {
			return new WP_Error( 'aiwp_file_find', 'Nothing was written: replacement ' . ( $i + 1 ) . ' occurs ' . $n . ' times, it must occur exactly once.', array( 'status' => 409 ) );
		}
		$new = str_replace( $find, $replace, $new );
	}
	if ( $new === $old ) {
		return new WP_Error( 'aiwp_file_same', 'Nothing to change.', array( 'status' => 400 ) );
	}
	return aiwp_file_commit( $f, $old, $new, 'patch' );
}

function aiwp_file_delete( WP_REST_Request $req ) {
	$f = aiwp_file_resolve( $req->get_param( 'path' ), 'delete' );
	if ( is_wp_error( $f ) ) {
		return $f;
	}
	$old = aiwp_file_read_checked( $f, $req->get_param( 'expect' ) );
	return is_wp_error( $old ) ? $old : aiwp_file_commit( $f, $old, null, 'delete' );
}

function aiwp_file_restore( WP_REST_Request $req ) {
	$f = aiwp_file_resolve( $req->get_param( 'path' ), 'restore' );
	if ( is_wp_error( $f ) ) {
		return $f;
	}
	$idx   = aiwp_file_backups_index();
	$saved = (string) $req->get_param( 'saved' );
	$b     = null;
	foreach ( isset( $idx[ $f['rel'] ] ) ? (array) $idx[ $f['rel'] ] : array() as $entry ) {
		if ( '' === $saved || $entry['saved'] === $saved ) {
			$b = $entry;
			break;
		}
	}
	if ( null === $b ) {
		return new WP_Error( 'aiwp_no_backup', '' === $saved ? 'No backup of this file.' : 'No backup of this file was saved at ' . $saved . '; GET files?path= lists the backups.', array( 'status' => 404 ) );
	}
	$content = @file_get_contents( $b['dir'] . '/' . $b['file'] );
	if ( false === $content || ! hash_equals( $b['sha256'], hash( 'sha256', $content ) ) ) {
		return new WP_Error( 'aiwp_backup_bad', 'The newest backup is missing or does not match its checksum.', array( 'status' => 500 ) );
	}
	$old = $f['exists'] ? aiwp_file_read_checked( $f, null ) : null;
	if ( is_wp_error( $old ) ) {
		return $old;
	}
	return aiwp_file_commit( $f, $old, $content, 'restore' );
}

add_action( 'rest_api_init', 'aiwp_register_file_routes' );
function aiwp_register_file_routes() {
	$can = function () {
		return current_user_can( 'manage_options' ) && aiwp_request_from_api_ip();
	};
	$path = array( 'required' => true, 'type' => 'string' );
	register_rest_route( 'ai-wp/v1', '/files', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'args'                => array( 'path' => $path ),
		'callback'            => 'aiwp_file_get',
	) );
	register_rest_route( 'ai-wp/v1', '/files/patch', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'args'                => array(
			'path'         => $path,
			'expect'       => array( 'required' => true, 'type' => 'string' ),
			'replacements' => array( 'required' => true, 'type' => 'array', 'minItems' => 1, 'maxItems' => 50 ),
		),
		'callback'            => 'aiwp_file_patch',
	) );
	register_rest_route( 'ai-wp/v1', '/files/delete', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'args'                => array( 'path' => $path, 'expect' => array( 'required' => true, 'type' => 'string' ) ),
		'callback'            => 'aiwp_file_delete',
	) );
	register_rest_route( 'ai-wp/v1', '/files/restore', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'args'                => array( 'path' => $path, 'saved' => array( 'type' => 'string', 'default' => '' ) ),
		'callback'            => 'aiwp_file_restore',
	) );
}

/* ------------------------------------------------------------------ HTTPS switch */

/*
 * GET ai-wp/v1/https reports the site and WordPress addresses and whether HTTPS works. POST switches both addresses to
 * https with core's wp_update_urls_to_https() (what Site Health's button does; old http links in content are rewritten
 * by core when shown), checks home + /wp-json/ and puts the old addresses back if they stop answering. redirect: true
 * also puts a marked http -> https 301 block at the top of the root .htaccess, through the same backup + check +
 * roll-back as files/patch. Managing-server address only.
 */
define( 'AIWP_HTTPS_MARKER', 'AIWP HTTPS' );

function aiwp_https_block() {
	return '# BEGIN ' . AIWP_HTTPS_MARKER . "\n"
		. "<IfModule mod_rewrite.c>\n"
		. "RewriteEngine On\n"
		. "RewriteCond %{HTTPS} !=on\n"
		. "RewriteCond %{HTTP:X-Forwarded-Proto} !https\n"
		. "RewriteCond %{REQUEST_URI} !^/\\.well-known/\n"
		. "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n"
		. "</IfModule>\n"
		. '# END ' . AIWP_HTTPS_MARKER . "\n";
}

function aiwp_https_htaccess() {
	$f = aiwp_file_resolve( 'root/.htaccess', 'patch' );
	$c = is_wp_error( $f ) ? $f : aiwp_file_read_checked( $f, null );
	if ( is_wp_error( $c ) ) {
		return array( 'file' => null, 'content' => null, 'error' => $c->get_error_message() );
	}
	return array( 'file' => $f, 'content' => $c, 'error' => '' );
}

function aiwp_https_state() {
	$ht = aiwp_https_htaccess();
	return array(
		'home'               => get_option( 'home' ),
		'siteurl'            => get_option( 'siteurl' ),
		'using_https'        => wp_is_using_https(),
		'https_supported'    => wp_is_https_supported(),
		'detection_errors'   => function_exists( 'wp_get_https_detection_errors' ) ? wp_get_https_detection_errors() : array(),
		'home_constant'      => defined( 'WP_HOME' ),
		'siteurl_constant'   => defined( 'WP_SITEURL' ),
		'migration_required' => (bool) get_option( 'https_migration_required' ),
		'redirect'           => null === $ht['content'] ? null : false !== strpos( $ht['content'], '# BEGIN ' . AIWP_HTTPS_MARKER ),
		'htaccess_error'     => $ht['error'],
	);
}

function aiwp_https_switch_urls( $state ) {
	if ( $state['using_https'] ) {
		return array( 'switched' => false, 'reason' => 'Both addresses already use https.' );
	}
	if ( $state['home_constant'] || $state['siteurl_constant'] ) {
		return new WP_Error( 'aiwp_https_constant', 'WP_HOME or WP_SITEURL is defined in wp-config.php; change it there. Nothing was changed.', array( 'status' => 409 ) );
	}
	if ( ! $state['https_supported'] ) {
		return new WP_Error( 'aiwp_https_unsupported', 'HTTPS does not answer correctly for this site yet (certificate or server). Nothing was changed.', array( 'status' => 409, 'detection_errors' => $state['detection_errors'] ) );
	}
	$home    = get_option( 'home' );
	$siteurl = get_option( 'siteurl' );
	$before  = aiwp_site_check();
	if ( ! wp_update_urls_to_https() ) {
		return new WP_Error( 'aiwp_https_refused', 'WordPress did not accept the https addresses and kept the old ones.', array( 'status' => 500 ) );
	}
	$after = aiwp_site_check();
	if ( $before['ok'] && ! $after['ok'] ) {
		update_option( 'home', $home );
		update_option( 'siteurl', $siteurl );
		return new WP_Error( 'aiwp_https_rolled_back', 'The site stopped answering on https, so the old addresses were put back.', array( 'status' => 409, 'check_before' => $before, 'check_after' => $after ) );
	}
	return array( 'switched' => true, 'from' => array( $home, $siteurl ), 'check_before' => $before, 'check_after' => $after );
}

function aiwp_https_add_redirect() {
	$ht = aiwp_https_htaccess();
	if ( null === $ht['content'] ) {
		return new WP_Error( 'aiwp_https_htaccess', 'The redirect was not added: ' . $ht['error'], array( 'status' => 409 ) );
	}
	$rest = preg_replace( '/# BEGIN ' . AIWP_HTTPS_MARKER . '.*?# END ' . AIWP_HTTPS_MARKER . '\R*/s', '', $ht['content'] );
	$new  = aiwp_https_block() . "\n" . ltrim( $rest, "\r\n" );
	if ( $new === $ht['content'] ) {
		return array( 'added' => false, 'reason' => 'The redirect block is already in place.' );
	}
	$done = aiwp_file_commit( $ht['file'], $ht['content'], $new, 'https-redirect' );
	if ( is_wp_error( $done ) ) {
		return $done;
	}
	$probe = wp_remote_get( set_url_scheme( home_url( '/' ), 'http' ), array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => false ) );
	$done['added'] = true;
	$done['http_probe'] = is_wp_error( $probe ) ? $probe->get_error_message() : array(
		'code'     => (int) wp_remote_retrieve_response_code( $probe ),
		'location' => wp_remote_retrieve_header( $probe, 'location' ),
	);
	return $done;
}

function aiwp_https_switch( WP_REST_Request $req ) {
	$state = aiwp_https_state();
	$urls  = aiwp_https_switch_urls( $state );
	if ( is_wp_error( $urls ) ) {
		$urls->add_data( array_merge( (array) $urls->get_error_data(), array( 'state' => $state ) ) );
		return $urls;
	}
	$out = array( 'ok' => true, 'urls' => $urls );
	if ( $req->get_param( 'redirect' ) ) {
		$redirect = aiwp_https_add_redirect();
		if ( is_wp_error( $redirect ) ) {
			$out['ok'] = false;
			$redirect  = array( 'added' => false, 'error' => $redirect->get_error_code(), 'message' => $redirect->get_error_message(), 'data' => $redirect->get_error_data() );
		}
		$out['redirect'] = $redirect;
	}
	do_action( 'litespeed_purge_all' );
	$out['state'] = aiwp_https_state();
	return $out;
}

add_action( 'rest_api_init', 'aiwp_register_https_routes' );
function aiwp_register_https_routes() {
	$can = function () {
		return current_user_can( 'manage_options' ) && aiwp_request_from_api_ip();
	};
	register_rest_route( 'ai-wp/v1', '/https', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => 'aiwp_https_state',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'args'                => array( 'redirect' => array( 'type' => 'boolean', 'default' => false ) ),
			'callback'            => 'aiwp_https_switch',
		),
	) );
}

/* ------------------------------------------------------------------ rescue mode (must-use plugin) */

/*
 * A REST request (/wp-json/ or ?rest_route=) from a managing-server address that carries the rescue key in the
 * X-AIWP-Rescue header loads WordPress without the other plugins and without the theme's functions.php, so a plugin or
 * theme that breaks every request can still be switched off through the normal routes. ?aiwp_skip=slug1,slug2 skips only
 * those plugins ("theme" also skips the theme). The lists are filtered only while plugins and theme load, so nothing the
 * request saves afterwards sees the shortened lists. The REST call itself still authenticates with an application
 * password. The key is created by POST ai-wp/v1/rescue/key (returned once, stored as sha256). Settings can switch it off.
 */
define( 'AIWP_RESCUE_OPTION', 'aiwp_rescue' );
define( 'AIWP_RESCUE_MU_FILE', 'aiwp-rescue.php' );
define( 'AIWP_RESCUE_VERSION', 1 );

function aiwp_rescue_mu_path() {
	return WPMU_PLUGIN_DIR . '/' . AIWP_RESCUE_MU_FILE;
}

function aiwp_rescue_get() {
	$r = get_site_option( AIWP_RESCUE_OPTION );
	return array_merge( array( 'enabled' => 0, 'hash' => '', 'ips' => array(), 'self' => '' ), is_array( $r ) ? $r : array() );
}

function aiwp_rescue_state() {
	$r = aiwp_rescue_get();
	return array( 'enabled' => (bool) $r['enabled'], 'key_set' => '' !== $r['hash'], 'mu_plugin' => file_exists( aiwp_rescue_mu_path() ) );
}

/** Copies the address list into the rescue option (the must-use plugin reads only that) and writes or removes the file. */
function aiwp_rescue_sync() {
	$r = aiwp_rescue_get();
	if ( '' === $r['hash'] && ! file_exists( aiwp_rescue_mu_path() ) ) {
		return;
	}
	$r['ips']  = aiwp_api_ips();
	$r['self'] = plugin_basename( __FILE__ );
	update_site_option( AIWP_RESCUE_OPTION, $r );
	aiwp_rescue_ensure();
}

add_action( 'admin_init', 'aiwp_rescue_ensure' );
add_action( 'rest_api_init', 'aiwp_rescue_ensure' );
function aiwp_rescue_ensure() {
	$r    = aiwp_rescue_get();
	$path = aiwp_rescue_mu_path();
	if ( ! $r['enabled'] || '' === $r['hash'] ) {
		if ( file_exists( $path ) && ! @is_link( $path ) ) {
			@unlink( $path );
		}
		return;
	}
	if ( $r['ips'] !== aiwp_api_ips() || plugin_basename( __FILE__ ) !== $r['self'] ) {
		aiwp_rescue_sync();
		return;
	}
	if ( file_exists( $path ) && false !== strpos( (string) @file_get_contents( $path, false, null, 0, 600 ), 'AIWP_RESCUE_V' . AIWP_RESCUE_VERSION . ' ' ) ) {
		return;
	}
	if ( wp_mkdir_p( WPMU_PLUGIN_DIR ) && ! @is_link( $path ) ) {
		@file_put_contents( $path, aiwp_rescue_mu_source(), LOCK_EX );
	}
}

function aiwp_rescue_new_key() {
	if ( ! aiwp_request_from_api_ip() ) {
		return new WP_Error( 'aiwp_not_api_ip', 'Only the managing server may create the rescue key.', array( 'status' => 403 ) );
	}
	$key        = bin2hex( random_bytes( 32 ) );
	$r          = aiwp_rescue_get();
	$r['hash']  = hash( 'sha256', $key );
	$r['enabled'] = 1;
	update_site_option( AIWP_RESCUE_OPTION, $r );
	aiwp_rescue_sync();
	return array_merge( array( 'key' => $key, 'header' => 'X-AIWP-Rescue' ), aiwp_rescue_state() );
}

function aiwp_rescue_set_enabled( $enabled ) {
	$r            = aiwp_rescue_get();
	$r['enabled'] = $enabled ? 1 : 0;
	update_site_option( AIWP_RESCUE_OPTION, $r );
	aiwp_rescue_ensure();
	return aiwp_rescue_state();
}

function aiwp_rescue_uninstall() {
	$path = aiwp_rescue_mu_path();
	if ( file_exists( $path ) && ! @is_link( $path ) ) {
		@unlink( $path );
	}
}
register_deactivation_hook( __FILE__, 'aiwp_rescue_uninstall' );

add_action( 'rest_api_init', 'aiwp_register_rescue_routes' );
function aiwp_register_rescue_routes() {
	register_rest_route( 'ai-wp/v1', '/rescue', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'aiwp_site_admin_can',
			'callback'            => function () {
				return array_merge( aiwp_rescue_state(), array( 'active_now' => defined( 'AIWP_RESCUE_ACTIVE' ) ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'aiwp_site_admin_can',
			'args'                => array( 'enabled' => array( 'required' => true, 'type' => 'boolean' ) ),
			'callback'            => function ( $req ) {
				return aiwp_rescue_set_enabled( (bool) $req->get_param( 'enabled' ) );
			},
		),
	) );
	register_rest_route( 'ai-wp/v1', '/rescue/key', array(
		'methods'             => 'POST',
		'permission_callback' => 'aiwp_site_admin_can',
		'callback'            => 'aiwp_rescue_new_key',
	) );
}

/** Self-contained (no plugin functions), loaded before every plugin. Keep it PHP 7.0 compatible. */
function aiwp_rescue_mu_source() {
	return "<?php\n"
		. "/*\n * Plugin Name: AI WP Site Manager - rescue mode\n"
		. " * Description: Written by AI WP Site Manager (AIWP_RESCUE_V" . AIWP_RESCUE_VERSION . " ). Lets the managing server reach the REST API with the other plugins and the theme skipped, when one of them breaks every request. Switch it off in Settings -> AI Site Manager.\n */\n"
		. "defined( 'ABSPATH' ) || exit;\n"
		. "( function () {\n"
		. "\t\$key = isset( \$_SERVER['HTTP_X_AIWP_RESCUE'] ) ? (string) \$_SERVER['HTTP_X_AIWP_RESCUE'] : '';\n"
		. "\t\$uri = isset( \$_SERVER['REQUEST_URI'] ) ? (string) \$_SERVER['REQUEST_URI'] : '';\n"
		. "\tif ( '' === \$key || ( false === strpos( \$uri, '/wp-json/' ) && ! isset( \$_GET['rest_route'] ) ) ) {\n"
		. "\t\treturn;\n"
		. "\t}\n"
		. "\t\$r = get_site_option( '" . AIWP_RESCUE_OPTION . "' );\n"
		. "\tif ( ! is_array( \$r ) || empty( \$r['enabled'] ) || empty( \$r['hash'] ) || empty( \$r['ips'] ) || ! hash_equals( (string) \$r['hash'], hash( 'sha256', \$key ) ) ) {\n"
		. "\t\treturn;\n"
		. "\t}\n"
		. "\t\$norm = function ( \$ip ) {\n"
		. "\t\t\$ip = trim( (string) \$ip );\n"
		. "\t\t\$b = false === strpos( \$ip, \"\\0\" ) ? @inet_pton( \$ip ) : false;\n"
		. "\t\tif ( false === \$b ) {\n"
		. "\t\t\treturn '';\n"
		. "\t\t}\n"
		. "\t\tif ( 16 === strlen( \$b ) && 0 === strpos( \$b, str_repeat( \"\\0\", 10 ) . \"\\xff\\xff\" ) ) {\n"
		. "\t\t\t\$b = substr( \$b, 12 );\n"
		. "\t\t}\n"
		. "\t\treturn (string) inet_ntop( \$b );\n"
		. "\t};\n"
		. "\t\$remote = \$norm( isset( \$_SERVER['REMOTE_ADDR'] ) ? \$_SERVER['REMOTE_ADDR'] : '' );\n"
		. "\t\$fwd = array();\n"
		. "\tforeach ( array( 'HTTP_X_REAL_IP', 'HTTP_X_CLIENT_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ) as \$h ) {\n"
		. "\t\tif ( ! empty( \$_SERVER[ \$h ] ) ) {\n"
		. "\t\t\tforeach ( explode( ',', (string) \$_SERVER[ \$h ] ) as \$v ) {\n"
		. "\t\t\t\t\$fwd[ \$norm( \$v ) ] = true;\n"
		. "\t\t\t}\n"
		. "\t\t}\n"
		. "\t}\n"
		. "\tif ( ! empty( \$_SERVER['HTTP_FORWARDED'] ) && preg_match_all( '/for=\\s*\"?\\[?([^\\]\";,\\s]+)/i', (string) \$_SERVER['HTTP_FORWARDED'], \$m ) ) {\n"
		. "\t\tforeach ( \$m[1] as \$v ) {\n"
		. "\t\t\t\$fwd[ \$norm( \$v ) ] = true;\n"
		. "\t\t}\n"
		. "\t}\n"
		. "\t\$ip = \$remote;\n"
		. "\t\$self_ip = \$norm( isset( \$_SERVER['SERVER_ADDR'] ) ? \$_SERVER['SERVER_ADDR'] : '' );\n"
		. "\tif ( \$fwd && '' !== \$remote && ( \$remote === \$self_ip || '127.0.0.1' === \$remote || '::1' === \$remote ) ) {\n"
		. "\t\t\$ip = ( 1 === count( \$fwd ) && ! isset( \$fwd[''] ) ) ? (string) key( \$fwd ) : '';\n"
		. "\t}\n"
		. "\tif ( '' === \$ip || ! in_array( \$ip, (array) \$r['ips'], true ) ) {\n"
		. "\t\treturn;\n"
		. "\t}\n"
		. "\tdefine( 'AIWP_RESCUE_ACTIVE', true );\n"
		. "\theader( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );\n"
		. "\theader( 'X-LiteSpeed-Cache-Control: no-cache' );\n"
		. "\theader( 'X-AIWP-Rescue: active' );\n"
		. "\t\$self = (string) \$r['self'];\n"
		. "\t\$skip = isset( \$_GET['aiwp_skip'] ) ? array_filter( array_map( 'trim', explode( ',', (string) \$_GET['aiwp_skip'] ) ) ) : array();\n"
		. "\t\$keep = function ( \$file ) use ( \$self, \$skip ) {\n"
		. "\t\tif ( \$file === \$self ) {\n"
		. "\t\t\treturn true;\n"
		. "\t\t}\n"
		. "\t\treturn \$skip && ! in_array( preg_replace( '/\\.php\$/', '', strtok( (string) \$file, '/' ) ), \$skip, true );\n"
		. "\t};\n"
		. "\t\$plugins = function ( \$list ) use ( \$keep ) {\n"
		. "\t\treturn array_values( array_filter( (array) \$list, \$keep ) );\n"
		. "\t};\n"
		. "\t\$network = function ( \$list ) use ( \$keep ) {\n"
		. "\t\t\$out = array();\n"
		. "\t\tforeach ( (array) \$list as \$f => \$t ) {\n"
		. "\t\t\tif ( \$keep( \$f ) ) {\n"
		. "\t\t\t\t\$out[ \$f ] = \$t;\n"
		. "\t\t\t}\n"
		. "\t\t}\n"
		. "\t\treturn \$out;\n"
		. "\t};\n"
		. "\tadd_filter( 'option_active_plugins', \$plugins, PHP_INT_MAX );\n"
		. "\tadd_filter( 'site_option_active_sitewide_plugins', \$network, PHP_INT_MAX );\n"
		. "\t\$unplug = function () use ( \$plugins, \$network, &\$unplug ) {\n"
		. "\t\tremove_filter( 'option_active_plugins', \$plugins, PHP_INT_MAX );\n"
		. "\t\tremove_filter( 'site_option_active_sitewide_plugins', \$network, PHP_INT_MAX );\n"
		. "\t\tremove_action( 'plugin_loaded', \$unplug, PHP_INT_MIN );\n"
		. "\t\tremove_action( 'plugins_loaded', \$unplug, PHP_INT_MIN );\n"
		. "\t};\n"
		. "\tadd_action( 'plugin_loaded', \$unplug, PHP_INT_MIN );\n"
		. "\tadd_action( 'plugins_loaded', \$unplug, PHP_INT_MIN );\n"
		. "\tif ( ! \$skip || in_array( 'theme', \$skip, true ) ) {\n"
		. "\t\t\$none = WP_CONTENT_DIR . '/aiwp-rescue-no-theme';\n"
		. "\t\t\$dir = function () use ( \$none ) {\n"
		. "\t\t\treturn \$none;\n"
		. "\t\t};\n"
		. "\t\tadd_filter( 'template_directory', \$dir, PHP_INT_MAX );\n"
		. "\t\tadd_filter( 'stylesheet_directory', \$dir, PHP_INT_MAX );\n"
		. "\t\tadd_filter( 'validate_current_theme', '__return_false', PHP_INT_MAX );\n"
		. "\t\tadd_action( 'after_setup_theme', function () use ( \$dir ) {\n"
		. "\t\t\tremove_filter( 'template_directory', \$dir, PHP_INT_MAX );\n"
		. "\t\t\tremove_filter( 'stylesheet_directory', \$dir, PHP_INT_MAX );\n"
		. "\t\t\tremove_action( 'init', '_register_theme_block_patterns' );\n"
		. "\t\t}, PHP_INT_MIN );\n"
		. "\t}\n"
		. "} )();\n";
}

/* ------------------------------------------------------------------ security: settings section */

add_action( 'admin_post_aiwp_security', 'aiwp_handle_security_form' );
function aiwp_handle_security_form() {
	if ( ! aiwp_site_admin_can() ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'aiwp_security' );
	$s      = aiwp_security_get();
	$notice = array( 'status' => 'saved', 'file' => 'security settings', 'message' => '' );
	$mode   = isset( $_POST['aiwp_mode'] ) ? sanitize_key( $_POST['aiwp_mode'] ) : $s['mode'];
	if ( 'on' === $mode && 'on' !== $s['mode'] ) {
		$notice = array( 'status' => 'error', 'file' => '', 'message' => 'The lock is switched on only by the managing server itself (POST ai-wp/v1/security {mode: on}), which proves its own calls still pass. Other changes were saved.' );
	} elseif ( in_array( $mode, array( 'off', 'report', 'on' ), true ) ) {
		$s['mode'] = $mode;
	}
	if ( ! defined( 'AIWP_API_IPS' ) && isset( $_POST['aiwp_ips'] ) ) {
		$ips = array();
		foreach ( preg_split( '/[\s,]+/', wp_unslash( (string) $_POST['aiwp_ips'] ) ) as $ip ) {
			$ip = aiwp_ip_normalize( $ip );
			if ( '' !== $ip ) {
				$ips[] = $ip;
			}
		}
		$s['ips'] = $ips ? array_values( array_unique( $ips ) ) : null;
	}
	if ( isset( $_POST['aiwp_unclaim'] ) ) {
		$s['uuids'] = array_values( array_diff( (array) $s['uuids'], array( sanitize_text_field( wp_unslash( $_POST['aiwp_unclaim'] ) ) ) ) );
	}
	if ( isset( $_POST['aiwp_clear_report'] ) ) {
		$s['report'] = array();
	}
	aiwp_security_save( $s );
	aiwp_rescue_set_enabled( ! empty( $_POST['aiwp_rescue_enabled'] ) );
	set_transient( 'aiwp_notice_' . get_current_user_id(), $notice, 60 );
	wp_safe_redirect( admin_url( 'options-general.php?page=ai-wp-site-manager#security' ) );
	exit;
}

/** Rendered inside aiwp_settings_page(). */
function aiwp_render_security_section() {
	if ( ! aiwp_site_admin_can() ) {
		return;
	}
	$st    = aiwp_security_state();
	$modes = array( 'off' => 'Off', 'report' => 'Report only (log, never refuse)', 'on' => 'On (refuse)' );
	echo '<h2 id="security" style="margin-top:2em">Managing server access</h2>';
	echo '<p class="description">The application passwords claimed by the managing server work only from the addresses below; every other application password is not affected. The addresses are also the only ones allowed to use the file routes and rescue mode. Your current address is seen as <code>' . esc_html( $st['request']['seen_as'] ) . '</code>.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'aiwp_security' );
	echo '<input type="hidden" name="action" value="aiwp_security">';
	echo '<p><label>Lock <select name="aiwp_mode">';
	foreach ( $modes as $k => $label ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $st['mode'], $k, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></label></p>';
	echo '<p class="description">"On" can be chosen here only to keep it on; the managing server switches it on itself. An empty address list goes back to the default (' . esc_html( AIWP_DEFAULT_API_IPS ) . ').</p>';
	echo '<p><label>Managing-server addresses, one per line<br><textarea name="aiwp_ips" rows="3" cols="40"' . ( $st['ips_from_constant'] ? ' readonly' : '' ) . '>' . esc_textarea( implode( "\n", $st['ips'] ) ) . '</textarea></label>';
	echo $st['ips_from_constant'] ? '<br><span class="description">Set by AIWP_API_IPS in wp-config.php.</span>' : '';
	echo '</p>';
	if ( $st['claimed'] ) {
		echo '<p><strong>Claimed application passwords:</strong></p><ul>';
		foreach ( $st['claimed'] as $c ) {
			$u = get_userdata( $c['user_id'] );
			echo '<li>' . esc_html( $c['name'] . ' (' . ( $u ? $u->user_login : $c['user_id'] ) . ', last IP ' . $c['last_ip'] . ')' ) . ' <button type="submit" class="button button-small" name="aiwp_unclaim" value="' . esc_attr( $c['uuid'] ) . '">Release</button></li>';
		}
		echo '</ul>';
	} else {
		echo '<p>No application password is claimed; the lock has nothing to protect yet.</p>';
	}
	echo '<p><label><input type="checkbox" name="aiwp_rescue_enabled" value="1"' . checked( $st['rescue']['enabled'], true, false ) . '> Rescue mode</label> <span class="description">(' . ( $st['rescue']['key_set'] ? 'key set' : 'no key yet' ) . ', must-use plugin ' . ( $st['rescue']['mu_plugin'] ? 'present' : 'absent' ) . ')</span></p>';
	echo '<p class="description">XML-RPC is ' . ( $st['xmlrpc_enabled'] ? 'on (the Jetpack connection uses it)' : 'off' ) . '. Alerts about new administrators and application passwords go to the site e-mail address and ' . esc_html( $st['alert_email'] ) . '.</p>';
	echo '<p><button type="submit" class="button button-primary">Save</button></p>';
	if ( $st['report'] ) {
		echo '<p><strong>Calls from other addresses:</strong></p><table class="widefat striped" style="max-width:900px"><thead><tr><th>When</th><th>Seen as</th><th>Connection</th><th>Forwarded</th><th>Password</th><th>Refused</th></tr></thead><tbody>';
		foreach ( $st['report'] as $e ) {
			echo '<tr><td>' . esc_html( $e['at'] ) . '</td><td>' . esc_html( $e['seen_as'] ) . '</td><td>' . esc_html( $e['remote'] ) . '</td><td>' . esc_html( $e['forwarded'] ) . '</td><td>' . esc_html( $e['name'] ) . '</td><td>' . ( $e['refused'] ? 'yes' : 'no' ) . '</td></tr>';
		}
		echo '</tbody></table><p><button type="submit" class="button" name="aiwp_clear_report" value="1">Clear the list</button></p>';
	}
	echo '</form>';
}

/* ------------------------------------------------------------------ self-update */

define( 'CSM_VERSION', '1.18.1' );
define( 'CSM_SLUG', 'ai-wp-site-manager' );
define( 'CSM_REPO_URL', 'https://github.com/FreedomScapeIsNotAvailable/ai-wp-site-manager' );
define( 'CSM_MANIFEST_URL', 'https://raw.githubusercontent.com/FreedomScapeIsNotAvailable/ai-wp-site-manager/main/dist/manifest.json' );

add_filter( 'update_plugins_github.com', 'csm_check_update', 10, 4 );

/*
 * Opt this plugin into WordPress's own automatic updates once, the same as clicking
 * "Enable auto-updates" on the Plugins page. Administrators can switch it off there again.
 * WordPress then installs new releases on its own during its twice-daily update check.
 */
add_action( 'init', 'csm_enable_auto_update_once' );
function csm_enable_auto_update_once() {
	if ( get_site_option( 'csm_autoupdate_opted' ) ) {
		return;
	}
	$me   = plugin_basename( __FILE__ );
	$list = (array) get_site_option( 'auto_update_plugins', array() );
	if ( ! in_array( $me, $list, true ) ) {
		$list[] = $me;
		update_site_option( 'auto_update_plugins', array_values( array_unique( $list ) ) );
	}
	update_site_option( 'csm_autoupdate_opted', 1 );
}
add_filter( 'plugins_api', 'csm_plugin_information', 10, 3 );

/** Fetch and cache the release manifest: { version, package, requires, requires_php, tested, changelog }. */
function csm_fetch_manifest( $force = false ) {
	$cached = get_site_transient( 'csm_update_manifest' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached;
	}
	$response = wp_remote_get( CSM_MANIFEST_URL, array(
		'timeout' => 10,
		'headers' => array( 'Accept' => 'application/json', 'Cache-Control' => 'no-cache' ),
	) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}
	$manifest = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $manifest ) || empty( $manifest['version'] ) || empty( $manifest['package'] ) ) {
		return false;
	}
	// Only ever download from the repository's own raw files over HTTPS.
	if ( 0 !== strpos( $manifest['package'], 'https://raw.githubusercontent.com/FreedomScapeIsNotAvailable/ai-wp-site-manager/' ) ) {
		return false;
	}
	if ( isset( $manifest['sha256'] ) && ! preg_match( '/^[a-f0-9]{64}$/i', (string) $manifest['sha256'] ) ) {
		return false; // a malformed checksum is never trusted
	}
	set_site_transient( 'csm_update_manifest', $manifest, 6 * HOUR_IN_SECONDS );
	return $manifest;
}

/**
 * Verify the release zip against the sha256 in the manifest before WordPress unpacks it. A manifest without a checksum
 * (older releases) is accepted as before. This does not protect against an attacker who controls both the manifest and the
 * zip; it protects against a corrupted or swapped zip, and makes a tampered release visible in the repository history.
 */
add_filter( 'upgrader_pre_download', 'csm_verify_package_hash', 10, 2 );
function csm_verify_package_hash( $reply, $package ) {
	if ( false !== $reply || ! is_string( $package ) ) {
		return $reply;
	}
	$manifest = csm_fetch_manifest();
	if ( ! $manifest || empty( $manifest['sha256'] ) || $package !== $manifest['package'] ) {
		return $reply;
	}
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$file = download_url( $package, 300 );
	if ( is_wp_error( $file ) ) {
		return $file;
	}
	if ( ! hash_equals( strtolower( (string) $manifest['sha256'] ), hash_file( 'sha256', $file ) ) ) {
		wp_delete_file( $file );
		return new WP_Error( 'csm_package_checksum', 'The downloaded package does not match the checksum in the release manifest; update refused.' );
	}
	return $file;
}

function csm_check_update( $update, $plugin_data, $plugin_file, $locales ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $update; // another plugin hosted on github.com
	}
	$manifest = csm_fetch_manifest();
	if ( ! $manifest || version_compare( $manifest['version'], CSM_VERSION, '<=' ) ) {
		return $update;
	}
	return array(
		'id'           => 'github.com/FreedomScapeIsNotAvailable/ai-wp-site-manager',
		'slug'         => CSM_SLUG,
		'plugin'       => $plugin_file,
		'version'      => $manifest['version'],
		'url'          => CSM_REPO_URL,
		'package'      => $manifest['package'],
		'requires'     => isset( $manifest['requires'] ) ? $manifest['requires'] : '5.8',
		'requires_php' => isset( $manifest['requires_php'] ) ? $manifest['requires_php'] : '7.4',
		'tested'       => isset( $manifest['tested'] ) ? $manifest['tested'] : '',
	);
}

/** Powers the "View details" link on the Plugins page. */
function csm_plugin_information( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || CSM_SLUG !== $args->slug ) {
		return $result;
	}
	$manifest = csm_fetch_manifest();
	if ( ! $manifest ) {
		return $result;
	}
	return (object) array(
		'name'          => 'AI WP Site Manager',
		'slug'          => CSM_SLUG,
		'version'       => $manifest['version'],
		'author'        => 'reallife.solutions',
		'homepage'      => CSM_REPO_URL,
		'download_link' => $manifest['package'],
		'requires'      => isset( $manifest['requires'] ) ? $manifest['requires'] : '5.8',
		'requires_php'  => isset( $manifest['requires_php'] ) ? $manifest['requires_php'] : '7.4',
		'tested'        => isset( $manifest['tested'] ) ? $manifest['tested'] : '',
		'sections'      => array(
			'description' => 'REST endpoints for plugin/theme updates and theme management, used by the Claude WordPress connector.',
			'changelog'   => isset( $manifest['changelog'] ) ? wp_kses_post( $manifest['changelog'] ) : '',
		),
	);
}
