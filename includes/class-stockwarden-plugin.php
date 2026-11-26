<?php
/**
 * Bootstrap.
 *
 * @package StockWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires everything together.
 */
class StockWarden_Plugin {

	/**
	 * Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( get_option( 'stockwarden_db_version' ) !== STOCKWARDEN_DB_VERSION ) {
			StockWarden_Log::install();
			StockWarden_Features::install();
		}
		add_action( 'stockwarden_hourly', array( 'StockWarden_Features', 'hourly' ) );
		add_action( 'stockwarden_daily', array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		StockWarden_Features::init();
		if ( is_admin() ) {
			StockWarden_Admin::init();
		}
	}

	/**
	 * Make sure the jobs are scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'stockwarden_hourly' ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'stockwarden_hourly' );
		}
		if ( ! wp_next_scheduled( 'stockwarden_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'stockwarden_daily' );
		}
	}

	/**
	 * Daily housekeeping.
	 *
	 * @return void
	 */
	public static function daily() {
		StockWarden_Log::purge();
		StockWarden_Features::daily();
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		StockWarden_Log::install();
		if ( class_exists( 'WooCommerce' ) ) {
			StockWarden_Features::install();
			StockWarden_Features::activate();
		}
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'stockwarden_hourly' );
		wp_clear_scheduled_hook( 'stockwarden_daily' );
	}
}
