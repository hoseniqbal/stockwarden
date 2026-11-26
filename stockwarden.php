<?php
/**
 * Plugin Name:       StockWarden: Stock History and Low Stock Forecast for WooCommerce
 * Plugin URI:        https://github.com/hoseniqbal/stockwarden
 * Description:       Records every stock change with its reason, emails you when you oversell, sends one daily low stock summary and shows what will run out soon.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Iqbal Hossen
 * Author URI:        https://profiles.wordpress.org/iqbal511138/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stockwarden
 * WC requires at least: 7.6
 * WC tested up to:   11.3
 *
 * @package StockWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STOCKWARDEN_VERSION', '1.0.0' );
define( 'STOCKWARDEN_DB_VERSION', '1' );
define( 'STOCKWARDEN_FILE', __FILE__ );
define( 'STOCKWARDEN_PATH', plugin_dir_path( __FILE__ ) );
define( 'STOCKWARDEN_URL', plugin_dir_url( __FILE__ ) );

require_once STOCKWARDEN_PATH . 'includes/class-stockwarden-settings.php';
require_once STOCKWARDEN_PATH . 'includes/class-stockwarden-log.php';
require_once STOCKWARDEN_PATH . 'includes/class-stockwarden-features.php';
require_once STOCKWARDEN_PATH . 'includes/class-stockwarden-admin.php';
require_once STOCKWARDEN_PATH . 'includes/class-stockwarden-plugin.php';

register_activation_hook( __FILE__, array( 'StockWarden_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'StockWarden_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'StockWarden_Plugin', 'init' ) );
