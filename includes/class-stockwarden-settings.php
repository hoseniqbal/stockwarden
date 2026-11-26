<?php
/**
 * Settings.
 *
 * @package StockWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All settings in one option, described by a schema.
 */
class StockWarden_Settings {

	const OPTION = 'stockwarden_settings';

	/**
	 * Cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Schema: key => array( type, default, min, max ). Types: bool, int, url, text, secret, email.
	 *
	 * @return array
	 */
	public static function schema() {
		return array(
			'negative_alert' => array( 'bool', 'yes' ),
			'digest'         => array( 'bool', 'yes' ),
			'days_warn'      => array( 'int', 14, 1, 120 ),
			'notify_to'      => array( 'email', '' ),
			'retention_days' => array( 'int', 90, 7, 730 ),
			'delete_data'    => array( 'bool', 'no' ),
		);
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::schema() as $key => $def ) {
			$out[ $key ] = $def[1];
		}
		return $out;
	}

	/**
	 * All values.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * One value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Yes/no check.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Clean one value.
	 *
	 * @param string $key Key.
	 * @param mixed  $raw Raw value.
	 * @return mixed
	 */
	public static function clean( $key, $raw ) {
		$schema = self::schema();
		if ( ! isset( $schema[ $key ] ) ) {
			return null;
		}
		$def = $schema[ $key ];
		switch ( $def[0] ) {
			case 'bool':
				return 'yes' === $raw ? 'yes' : 'no';
			case 'int':
				return min( (int) $def[3], max( (int) $def[2], absint( $raw ) ) );
			case 'url':
				$url = esc_url_raw( trim( (string) $raw ) );
				return 0 === strpos( $url, 'https://' ) ? $url : '';
			case 'email':
				$list = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $raw ) ) ), 'is_email' );
				return implode( ', ', array_slice( $list, 0, 10 ) );
			case 'secret':
				return preg_replace( '/[^A-Za-z0-9:_\-]/', '', (string) $raw );
			case 'textarea':
				return sanitize_textarea_field( (string) $raw );
			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * Save some fields. Missing checkboxes count as "no".
	 *
	 * @param array $input  Unslashed input.
	 * @param array $fields Keys to save.
	 * @return void
	 */
	public static function save( $input, $fields ) {
		$all    = self::all();
		$schema = self::schema();
		foreach ( $fields as $key ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}
			$raw         = isset( $input[ $key ] ) ? $input[ $key ] : ( 'bool' === $schema[ $key ][0] ? 'no' : $schema[ $key ][1] );
			$all[ $key ] = self::clean( $key, $raw );
		}
		update_option( self::OPTION, $all, false );
		self::$cache = null;
	}
}
