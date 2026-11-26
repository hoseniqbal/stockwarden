<?php
/**
 * Data: one event log table.
 *
 * @package StockWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events with a type, an object, who did it and a short note. No customer details.
 */
class StockWarden_Log {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'stockwarden_log';
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			type varchar(30) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			field varchar(60) NOT NULL DEFAULT '',
			old_value varchar(191) NOT NULL DEFAULT '',
			new_value varchar(191) NOT NULL DEFAULT '',
			amount decimal(19,4) NOT NULL DEFAULT 0,
			detail varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY type_created (type, created_at),
			KEY object_type (object_id, type),
			KEY user_created (user_id, created_at)
		) {$charset};"
		);
		update_option( 'stockwarden_db_version', STOCKWARDEN_DB_VERSION, false );
	}

	/**
	 * Add an event.
	 *
	 * @param string $type Type.
	 * @param array  $args object_id, user_id, field, old, new, amount, detail.
	 * @return void
	 */
	public static function add( $type, $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'object_id' => 0,
				'user_id'   => get_current_user_id(),
				'field'     => '',
				'old'       => '',
				'new'       => '',
				'amount'    => 0,
				'detail'    => '',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, write.
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'type'       => substr( sanitize_key( $type ), 0, 30 ),
				'object_id'  => absint( $args['object_id'] ),
				'user_id'    => absint( $args['user_id'] ),
				'field'      => substr( sanitize_key( $args['field'] ), 0, 60 ),
				'old_value'  => substr( sanitize_text_field( (string) $args['old'] ), 0, 191 ),
				'new_value'  => substr( sanitize_text_field( (string) $args['new'] ), 0, 191 ),
				'amount'     => (float) $args['amount'],
				'detail'     => substr( sanitize_text_field( (string) $args['detail'] ), 0, 255 ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%f', '%s' )
		);
		/**
		 * Something was recorded. Pro add-ons listen here.
		 *
		 * @param string $type Event type.
		 * @param array  $args Event data.
		 */
		do_action( 'stockwarden_event', $type, $args );
	}

	/**
	 * Count events of one or more types in the last N seconds.
	 *
	 * @param string|string[] $types   Types.
	 * @param int             $seconds Window.
	 * @param int             $object  Object ID or 0 for all.
	 * @return int
	 */
	public static function count( $types, $seconds, $object = 0 ) {
		global $wpdb;
		$types = array_values( (array) $types );
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $seconds );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args  = array_merge( array( self::table() ), $types, array( $since ) );
		$sql   = "SELECT COUNT(*) FROM %i WHERE type IN ($in) AND created_at >= %s";
		if ( $object ) {
			$sql   .= ' AND object_id = %d';
			$args[] = (int) $object;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Sum of the amount column.
	 *
	 * @param string|string[] $types   Types.
	 * @param int             $seconds Window.
	 * @param int             $user    User ID or 0.
	 * @return float
	 */
	public static function sum( $types, $seconds, $user = 0 ) {
		global $wpdb;
		$types = array_values( (array) $types );
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $seconds );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args  = array_merge( array( self::table() ), $types, array( $since ) );
		$sql   = "SELECT COALESCE(SUM(amount),0) FROM %i WHERE type IN ($in) AND created_at >= %s";
		if ( $user ) {
			$sql   .= ' AND user_id = %d';
			$args[] = (int) $user;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		return (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Recent events.
	 *
	 * @param array $filter types (array), object_id, user_id, limit, offset.
	 * @return array
	 */
	public static function recent( $filter = array() ) {
		global $wpdb;
		$filter = wp_parse_args(
			$filter,
			array(
				'types'     => array(),
				'object_id' => 0,
				'user_id'   => 0,
				'limit'     => 50,
				'offset'    => 0,
			)
		);
		$where  = '1=1';
		$args   = array( self::table() );
		if ( $filter['types'] ) {
			$where .= ' AND type IN (' . implode( ',', array_fill( 0, count( (array) $filter['types'] ), '%s' ) ) . ')';
			$args   = array_merge( $args, array_values( (array) $filter['types'] ) );
		}
		if ( $filter['object_id'] ) {
			$where .= ' AND object_id = %d';
			$args[] = (int) $filter['object_id'];
		}
		if ( $filter['user_id'] ) {
			$where .= ' AND user_id = %d';
			$args[] = (int) $filter['user_id'];
		}
		$args[] = (int) $filter['limit'];
		$args[] = (int) $filter['offset'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A );
	}

	/**
	 * Delete old events.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;
		$days = (int) StockWarden_Settings::get( 'retention_days' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 10000', self::table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
