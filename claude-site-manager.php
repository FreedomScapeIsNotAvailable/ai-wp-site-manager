<?php
/**
 * Plugin Name:       Claude Site Manager
 * Plugin URI:        https://github.com/FreedomScapeIsNotAvailable/claude-site-manager
 * Description:       REST endpoints (namespace claude/v1) for plugin/theme updates and theme install/switch/delete, which the core REST API does not provide. Used by the team's Claude WordPress connector. Updates itself from GitHub.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            reallife.solutions
 * Update URI:        https://github.com/FreedomScapeIsNotAvailable/claude-site-manager
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

function csm_register_routes() {
	$ns = 'claude/v1';

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

/* ------------------------------------------------------------------ self-update */

define( 'CSM_VERSION', '1.1.0' );
define( 'CSM_SLUG', 'claude-site-manager' );
define( 'CSM_REPO_URL', 'https://github.com/FreedomScapeIsNotAvailable/claude-site-manager' );
define( 'CSM_MANIFEST_URL', 'https://raw.githubusercontent.com/FreedomScapeIsNotAvailable/claude-site-manager/main/dist/manifest.json' );

add_filter( 'update_plugins_github.com', 'csm_check_update', 10, 4 );
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
	if ( 0 !== strpos( $manifest['package'], 'https://raw.githubusercontent.com/FreedomScapeIsNotAvailable/claude-site-manager/' ) ) {
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
		'id'           => 'github.com/FreedomScapeIsNotAvailable/claude-site-manager',
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
		'name'          => 'Claude Site Manager',
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
