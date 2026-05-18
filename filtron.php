<?php
/**
 * Plugin Name:       Filtron
 * Plugin URI:        https://wordpress.org/plugins/filtron/
 * Description:       Fast, secure filter system for WooCommerce, custom post types, and directories.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Filtron
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       filtron
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FILTRON_VERSION', '1.0.0' );
define( 'FILTRON_DB_VERSION', '1.0' );
define( 'FILTRON_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FILTRON_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FILTRON_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once FILTRON_PLUGIN_DIR . 'includes/class-filtron-autoload.php';

Filtron_Autoload::register();

register_activation_hook( __FILE__, array( 'Filtron_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Filtron_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Filtron', 'instance' ) );
