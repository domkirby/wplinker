<?php
/**
 * Plugin Name:       WPLinker
 * Plugin URI:        https://github.com/domkirby/wplinker
 * Update URI:        https://github.com/domkirby/wplinker
 * Description:       API-first URL routing engine. Fast 301/302 redirects with wildcard subpath matching, backed by a custom table and a full REST API.
 * Version:           0.1.2
 * Requires at least: 5.8
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Dom Kirby
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wplinker
 *
 * @package WPLinker
 */

defined( 'ABSPATH' ) || exit;

define( 'WPLINKER_VERSION', '0.1.2' );
define( 'WPLINKER_DB_VERSION', 1 );
define( 'WPLINKER_FILE', __FILE__ );
define( 'WPLINKER_PATH', plugin_dir_path( __FILE__ ) );

require_once WPLINKER_PATH . 'includes/class-wplinker-install.php';
require_once WPLINKER_PATH . 'includes/class-wplinker-routes.php';
require_once WPLINKER_PATH . 'includes/class-wplinker-validator.php';
require_once WPLINKER_PATH . 'includes/class-wplinker-router.php';
require_once WPLINKER_PATH . 'includes/class-wplinker-rest-controller.php';

if ( is_admin() ) {
	require_once WPLINKER_PATH . 'admin/class-wplinker-admin.php';
}

register_activation_hook( __FILE__, array( 'WPLinker_Install', 'activate' ) );

/**
 * Boot the plugin.
 *
 * The router is wired up as early as possible so that a redirect costs a single
 * indexed query and nothing else. Everything else loads lazily.
 *
 * @return void
 */
function wplinker_bootstrap() {
	WPLinker_Install::maybe_upgrade();

	WPLinker_Router::instance()->register();

	add_action(
		'rest_api_init',
		static function () {
			$controller = new WPLinker_REST_Routes_Controller();
			$controller->register_routes();
		}
	);

	if ( is_admin() ) {
		WPLinker_Admin::instance()->register();
	}

	// Updates are checked in the admin, on cron (where wp_update_plugins()
	// actually runs) and under WP-CLI. A request being served a redirect never
	// loads this code.
	if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		require_once WPLINKER_PATH . 'includes/class-wplinker-updater.php';
		WPLinker_Updater::instance()->register();
	}
}
add_action( 'plugins_loaded', 'wplinker_bootstrap' );
