<?php
/**
 * Plugin Name:       3Best Core
 * Description:       Core application logic for the 3Best lead-generation platform. Owns services, locations, dynamic service/location pages, lead handling, phone verification, delivery and SEO output. Deliberately theme-independent — no business logic belongs in a theme's functions.php.
 * Version:           0.9.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            3Best
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       3best-core
 * Domain Path:       /languages
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

defined( 'ABSPATH' ) || exit;

/*
 * Constants are prefixed THREEBEST_ rather than 3BEST_ because a PHP identifier
 * cannot begin with a digit. The same reasoning drives the threebest_ database
 * and option prefixes; only the plugin directory keeps the 3best-core name.
 */
define( 'THREEBEST_CORE_VERSION', '0.9.0' );
define( 'THREEBEST_CORE_FILE', __FILE__ );
define( 'THREEBEST_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'THREEBEST_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'THREEBEST_CORE_BASENAME', plugin_basename( __FILE__ ) );

require_once THREEBEST_CORE_DIR . 'includes/class-autoloader.php';

Autoloader::register();

/*
 * Requirements are checked before anything else loads. On a host that does not
 * meet them the plugin stays inert and explains itself in the admin, rather
 * than fataling on a syntax or extension it cannot use. This matters because
 * the production cPanel host is not yet verified.
 */
if ( ! Requirements::are_met() ) {
	add_action( 'admin_notices', array( Requirements::class, 'render_notice' ) );

	return;
}

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

/**
 * Boots the plugin once WordPress and all other plugins are loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->init();
	}
);
