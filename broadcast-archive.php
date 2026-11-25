<?php
/**
 * Plugin Name:       Broadcast Archive
 * Plugin URI:        https://github.com/HansVanEijsden/broadcast-archive
 * Description:       Turn any radio station's podcast feed into a fast, searchable listen-again page with a persistent player and downloads. Station-neutral, translation-ready and safe behind full-page caches.
 * Version:           2.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Hans van Eijsden Consultancy
 * Author URI:        https://www.hansvaneijsden.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       broadcast-archive
 * Domain Path:       /languages
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BROADCAST_ARCHIVE_VERSION', '2.2.0' );
define( 'BROADCAST_ARCHIVE_FILE', __FILE__ );
define( 'BROADCAST_ARCHIVE_DIR', plugin_dir_path( __FILE__ ) );
define( 'BROADCAST_ARCHIVE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Lightweight PSR-4 style autoloader for the plugin's own classes.
 *
 * Class `BroadcastArchive\Rest_Controller` maps to
 * `includes/class-rest-controller.php`. No Composer, no build step.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BroadcastArchive\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = BROADCAST_ARCHIVE_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'BroadcastArchive\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BroadcastArchive\\Plugin', 'deactivate' ) );

BroadcastArchive\Plugin::instance();
