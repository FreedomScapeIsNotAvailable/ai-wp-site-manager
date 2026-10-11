<?php
/**
 * Plugin Name:       AI WP Site Manager
 * Plugin URI:        https://github.com/FreedomScapeIsNotAvailable/ai-wp-site-manager
 * Description:       REST endpoints (namespace ai-wp/v1) for plugin/theme updates and theme management, plus {INCLUDE:/path/} content includes and a Settings page to edit llms.txt and robots.txt in place. Used by the team's Claude WordPress connector. Updates itself from GitHub.
 * Version:           1.7.0
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
define( 'AIWP_INCLUDE_TTL', 600 );

add_filter( 'the_content', 'aiwp_render_includes', 20 );

function aiwp_render_includes( $content ) {
	if ( false === strpos( $content, '{INCLUDE:' ) ) {
		return $content;
	}
	// Also swallow the <p> wrapper wpautop puts around a placeholder that stands alone on a line.
	return preg_replace_callback(
		'~(?:<p>\s*)?\{INCLUDE:([^}\s]+)\}(?:\s*</p>)?~',
		function ( $m ) {
			$ttl = (int) apply_filters( 'ai_wp_include_ttl', AIWP_INCLUDE_TTL, $m[1] );
			do_action( 'litespeed_control_set_ttl', $ttl );
			$html = aiwp_fetch_include( $m[1], $ttl );
			return null === $html ? '<!-- include unavailable: ' . esc_html( $m[1] ) . ' -->' : $html;
		},
		$content
	);
}

/** Hosts whose HTML may be included: this site plus the team's own sites (filter ai_wp_include_hosts to extend). */
define( 'AIWP_INCLUDE_HOSTS', 'bocp.ro,www.bocp.ro,cloudsales.ro,www.cloudsales.ro' );
function aiwp_include_allowed_hosts() {
	$hosts   = array_map( 'trim', explode( ',', AIWP_INCLUDE_HOSTS ) );
	$hosts[] = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	return array_values( array_unique( array_filter( array_map( 'strtolower', apply_filters( 'ai_wp_include_hosts', $hosts ) ) ) ) );
}

function aiwp_fetch_include( $target, $ttl ) {
	$home = wp_parse_url( home_url() );
	if ( 0 === strpos( $target, '/' ) ) {
		$url = home_url( $target );
	} else {
		$url  = $target;
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! $host || ! in_array( $host, aiwp_include_allowed_hosts(), true ) ) {
			return null; // only this site and the team's own sites may be included
		}
	}
	$key   = 'aiwp_inc_' . md5( $url );
	$fresh = get_transient( $key );
	if ( false !== $fresh ) {
		return $fresh;
	}
	$response = wp_remote_get( $url, array(
		'timeout'   => 15,
		'sslverify' => true,
		'headers'   => array( 'X-AIWP-Include' => '1', 'Cache-Control' => 'no-cache' ),
	) );
	$code = wp_remote_retrieve_response_code( $response );
	if ( ! is_wp_error( $response ) && 200 === $code ) {
		$html = wp_remote_retrieve_body( $response );
		set_transient( $key, $html, max( 60, $ttl ) );
		update_option( $key . '_last_good', $html, false );
		return $html;
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
define( 'AIWP_PACKAGE_PREFIXES', 'https://raw.githubusercontent.com/FreedomScapeIsNotAvailable/' );

function aiwp_package_allowed( $url ) {
	foreach ( explode( ',', AIWP_PACKAGE_PREFIXES ) as $prefix ) {
		if ( 0 === strpos( (string) $url, trim( $prefix ) ) ) {
			return true;
		}
	}
	return false;
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

/* ------------------------------------------------------------------ self-update */

define( 'CSM_VERSION', '1.7.0' );
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
	set_site_transient( 'csm_update_manifest', $manifest, 6 * HOUR_IN_SECONDS );
	return $manifest;
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
