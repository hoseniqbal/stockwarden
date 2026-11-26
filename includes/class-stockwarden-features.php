<?php
/**
 * StockWarden: stock history, oversell alerts, one daily low stock email and a running-out forecast.
 *
 * @package StockWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Features and screens.
 */
class StockWarden_Features {

	const LAST = '_stockwarden_last';

	/**
	 * Order that is changing stock right now, by product ID.
	 *
	 * @var array
	 */
	private static $context = array();

	/* ------------------------------------------------------------------
	 * Frame contract
	 * ---------------------------------------------------------------- */

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'overview' => __( 'Overview', 'stockwarden' ),
			'forecast' => __( 'Running out', 'stockwarden' ),
			'history'  => __( 'History', 'stockwarden' ),
			'settings' => __( 'Settings', 'stockwarden' ),
		);
	}

	/**
	 * Tagline.
	 *
	 * @return string
	 */
	public static function tagline() {
		return __( 'Every stock change explained, and a warning before you run out.', 'stockwarden' );
	}

	/**
	 * Sections.
	 *
	 * @return array
	 */
	public static function sections() {
		return array( 'settings' => array( 'negative_alert', 'digest', 'days_warn', 'notify_to', 'retention_days', 'delete_data' ) );
	}

	/**
	 * Messages.
	 *
	 * @return array
	 */
	public static function messages() {
		return array();
	}

	/**
	 * Pro intro.
	 *
	 * @return string
	 */
	public static function pro_intro() {
		return __( 'Know how much to reorder, and send the list to your supplier in one click.', 'stockwarden' );
	}

	/**
	 * Pro points.
	 *
	 * @return array
	 */
	public static function pro_points() {
		return array(
			__( 'Reorder suggestions based on how fast each product sells', 'stockwarden' ),
			__( 'Supplier on every product and one-click reorder emails', 'stockwarden' ),
			__( 'Alerts for oversold and sold out products on Slack, Discord, Telegram or any webhook', 'stockwarden' ),
			__( 'Export stock history and reorder lists to CSV', 'stockwarden' ),
		);
	}

	/**
	 * CSS elsewhere.
	 *
	 * @param string $hook Hook.
	 * @return bool
	 */
	public static function needs_css( $hook ) {
		unset( $hook );
		return false;
	}

	/**
	 * Install: remember the current stock of every product.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time copy of stock values into our own meta key.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT s.post_id, %s, s.meta_value FROM {$wpdb->postmeta} s LEFT JOIN {$wpdb->postmeta} l ON l.post_id = s.post_id AND l.meta_key = %s WHERE s.meta_key = '_stock' AND s.meta_value <> '' AND l.meta_id IS NULL",
				self::LAST,
				self::LAST
			)
		);
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {}

	/* ------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------- */

	/**
	 * Runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_order_item_quantity', array( __CLASS__, 'note_order' ), 999, 3 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'stock_changed' ), 20 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'stock_changed' ), 20 );
		add_action( 'woocommerce_restore_order_item_stock', array( __CLASS__, 'restored' ), 20, 4 );
		add_action( 'woocommerce_restock_refunded_item', array( __CLASS__, 'refund_restocked' ), 20, 5 );
	}

	/**
	 * Mark the latest change of a product as caused by an order.
	 *
	 * @param int $product_id Product.
	 * @param int $order_id   Order.
	 * @return void
	 */
	private static function relabel( $product_id, $order_id ) {
		global $wpdb;
		$table = StockWarden_Log::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE object_id = %d AND type = %s AND created_at >= %s ORDER BY id DESC LIMIT 1', $table, $product_id, 'change', gmdate( 'Y-m-d H:i:s', time() - 120 ) ) );
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			$wpdb->update(
				$table,
				array(
					'type'    => 'restock_order',
					'field'   => (string) $order_id,
					'user_id' => 0,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Stock given back for a cancelled or failed order line.
	 *
	 * @param WC_Order_Item_Product $item      Item.
	 * @param int|float             $new_stock New stock.
	 * @param int|float             $old_stock Old stock.
	 * @param WC_Order              $order     Order.
	 * @return void
	 */
	public static function restored( $item, $new_stock, $old_stock, $order ) {
		unset( $new_stock, $old_stock );
		if ( $item instanceof WC_Order_Item_Product && $order instanceof WC_Order ) {
			self::relabel( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(), $order->get_id() );
		}
	}

	/**
	 * Stock given back with a refund.
	 *
	 * @param int        $product_id Product.
	 * @param int|float  $old_stock  Old.
	 * @param int|float  $new_stock  New.
	 * @param WC_Order   $order      Order.
	 * @param WC_Product $product    Product.
	 * @return void
	 */
	public static function refund_restocked( $product_id, $old_stock, $new_stock, $order, $product = null ) {
		unset( $old_stock, $new_stock );
		$id = $product instanceof WC_Product ? $product->get_id() : (int) $product_id;
		if ( $order instanceof WC_Order ) {
			self::relabel( $id, $order->get_id() );
		}
	}

	/**
	 * Admin hooks.
	 *
	 * @return void
	 */
	public static function admin_hooks() {}

	/**
	 * WooCommerce is about to change stock for an order line: remember the order.
	 *
	 * @param int|float     $qty   Quantity.
	 * @param WC_Order      $order Order.
	 * @param WC_Order_Item $item  Item.
	 * @return int|float
	 */
	public static function note_order( $qty, $order, $item ) {
		if ( $order instanceof WC_Order && $item instanceof WC_Order_Item_Product ) {
			$id                   = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			self::$context[ $id ] = $order->get_id();
		}
		return $qty;
	}

	/**
	 * Stock of a product changed: record it.
	 *
	 * @param WC_Product $product Product.
	 * @return void
	 */
	public static function stock_changed( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return;
		}
		$id   = $product->get_id();
		$new  = $product->get_stock_quantity();
		$new  = null === $new ? 0 : (float) $new;
		$last = get_post_meta( $id, self::LAST, true );
		update_post_meta( $id, self::LAST, (string) $new );
		$order = isset( self::$context[ $id ] ) ? (int) self::$context[ $id ] : 0;
		unset( self::$context[ $id ] );
		if ( '' !== $last && (float) $last === $new ) {
			return;
		}
		$diff = '' === $last ? 0 : $new - (float) $last;
		if ( $order ) {
			$type = $diff < 0 ? 'sale' : 'restock_order';
		} else {
			$type = 'change';
		}
		StockWarden_Log::add(
			$type,
			array(
				'object_id' => $id,
				'user_id'   => $order ? 0 : get_current_user_id(),
				'field'     => $order ? (string) $order : '',
				'old'       => $last,
				'new'       => (string) $new,
				'amount'    => $diff,
				'detail'    => $product->get_name(),
			)
		);
		if ( $new < 0 ) {
			self::oversold( $product, $new, $order );
		}
	}

	/**
	 * Stock went below zero.
	 *
	 * @param WC_Product $product Product.
	 * @param float      $qty     Stock.
	 * @param int        $order   Order ID.
	 * @return void
	 */
	private static function oversold( $product, $qty, $order ) {
		StockWarden_Log::add(
			'negative',
			array(
				'object_id' => $product->get_id(),
				'user_id'   => 0,
				'field'     => (string) $order,
				'new'       => (string) $qty,
				'amount'    => $qty,
				'detail'    => $product->get_name(),
			)
		);
		$key = 'stockwarden_neg_' . $product->get_id();
		if ( ! StockWarden_Settings::on( 'negative_alert' ) || get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 12 * HOUR_IN_SECONDS );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		self::mail(
			/* translators: 1: product, 2: site */
			sprintf( __( '%1$s is oversold on %2$s', 'stockwarden' ), $product->get_name(), $site ),
			sprintf(
				/* translators: 1: product, 2: stock */
				__( '%1$s now has a stock of %2$s. You sold more than you have. Order more, or contact the customers before you ship.', 'stockwarden' ),
				$product->get_name(),
				wc_stock_amount( $qty )
			) . "\n\n" . admin_url( 'post.php?post=' . ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) . '&action=edit' )
		);
	}

	/* ------------------------------------------------------------------
	 * Forecast
	 * ---------------------------------------------------------------- */

	/**
	 * Units sold per product in the last N days (from our log).
	 *
	 * @param int $days Days.
	 * @return array product ID => units
	 */
	public static function sold( $days = 30 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, report.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, SUM(-amount) AS n FROM %i WHERE type = %s AND created_at >= %s GROUP BY object_id', StockWarden_Log::table(), 'sale', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['object_id'] ] = (float) $row['n'];
		}
		return $out;
	}

	/**
	 * Days of tracked sales history we have.
	 *
	 * @return int
	 */
	public static function history_days() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, report.
		$first = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created_at) FROM %i WHERE type = %s', StockWarden_Log::table(), 'sale' ) );
		if ( ! $first ) {
			return 0;
		}
		return max( 1, (int) ceil( ( time() - strtotime( $first . ' UTC' ) ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Products with managed stock.
	 *
	 * @return WC_Product[]
	 */
	public static function managed() {
		$out = array();
		foreach ( array( 'product', 'product_variation' ) as $type ) {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 1000,
					'fields'         => 'ids',
					'meta_key'       => '_manage_stock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin report.
					'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- admin report.
				)
			);
			foreach ( $ids as $id ) {
				$p = wc_get_product( $id );
				if ( $p && $p->managing_stock() && ! ( $p->is_type( 'variation' ) && 'publish' !== get_post_status( $p->get_parent_id() ) ) ) {
					$out[ $id ] = $p;
				}
			}
		}
		return $out;
	}

	/**
	 * Low stock level of a product.
	 *
	 * @param WC_Product $p Product.
	 * @return int
	 */
	public static function low_level( $p ) {
		return function_exists( 'wc_get_low_stock_amount' ) ? (int) wc_get_low_stock_amount( $p ) : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
	}

	/**
	 * Forecast rows: stock, speed, days left.
	 *
	 * @return array
	 */
	public static function forecast() {
		$window = max( 1, min( 30, self::history_days() ) );
		$sold   = self::sold( $window );
		$rows   = array();
		foreach ( self::managed() as $id => $p ) {
			$qty   = (float) $p->get_stock_quantity();
			$speed = isset( $sold[ $id ] ) ? $sold[ $id ] / $window : 0;
			$left  = $speed > 0 ? max( 0, $qty ) / $speed : null;
			$rows[ $id ] = array(
				'product' => $p,
				'qty'     => $qty,
				'speed'   => $speed,
				'left'    => $left,
				'low'     => $qty <= self::low_level( $p ),
			);
		}
		uasort(
			$rows,
			static function ( $a, $b ) {
				$x = null === $a['left'] ? PHP_INT_MAX : $a['left'];
				$y = null === $b['left'] ? PHP_INT_MAX : $b['left'];
				return $x === $y ? $a['qty'] <=> $b['qty'] : $x <=> $y;
			}
		);
		return $rows;
	}

	/* ------------------------------------------------------------------
	 * Jobs
	 * ---------------------------------------------------------------- */

	/**
	 * Hourly.
	 *
	 * @return void
	 */
	public static function hourly() {}

	/**
	 * Daily: one email with everything low or running out.
	 *
	 * @return void
	 */
	public static function daily() {
		if ( ! StockWarden_Settings::on( 'digest' ) ) {
			return;
		}
		$text = self::digest_text();
		if ( '' === $text ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site */
		self::mail( sprintf( __( 'Stock to check on %s today', 'stockwarden' ), $site ), $text );
	}

	/**
	 * Digest body, or '' when nothing needs attention.
	 *
	 * @return string
	 */
	public static function digest_text() {
		$warn  = (int) StockWarden_Settings::get( 'days_warn' );
		$out   = array();
		$soon  = array();
		$low   = array();
		foreach ( self::forecast() as $row ) {
			$name = $row['product']->get_name();
			if ( $row['qty'] <= 0 ) {
				/* translators: 1: product, 2: stock */
				$out[] = sprintf( __( '%1$s: %2$s left', 'stockwarden' ), $name, wc_stock_amount( $row['qty'] ) );
			} elseif ( null !== $row['left'] && $row['left'] <= $warn ) {
				/* translators: 1: product, 2: stock, 3: days */
				$soon[] = sprintf( __( '%1$s: %2$s left, about %3$s days', 'stockwarden' ), $name, wc_stock_amount( $row['qty'] ), number_format_i18n( max( 1, $row['left'] ), 0 ) );
			} elseif ( $row['low'] ) {
				/* translators: 1: product, 2: stock */
				$low[] = sprintf( __( '%1$s: %2$s left', 'stockwarden' ), $name, wc_stock_amount( $row['qty'] ) );
			}
		}
		if ( ! $out && ! $soon && ! $low ) {
			return '';
		}
		$lines = array();
		foreach ( array( array( __( 'Sold out', 'stockwarden' ), $out ), array( __( 'Running out soon', 'stockwarden' ), $soon ), array( __( 'Low stock', 'stockwarden' ), $low ) ) as $group ) {
			if ( $group[1] ) {
				$lines[] = $group[0];
				foreach ( array_slice( $group[1], 0, 50 ) as $l ) {
					$lines[] = '- ' . $l;
				}
				$lines[] = '';
			}
		}
		$lines[] = admin_url( 'admin.php?page=stockwarden&tab=forecast' );
		return implode( "\n", $lines );
	}

	/**
	 * Email the owner from the store's address.
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return bool
	 */
	public static function mail( $subject, $body ) {
		$to      = StockWarden_Settings::get( 'notify_to' );
		$to      = '' !== $to ? array_map( 'trim', explode( ',', $to ) ) : array( get_option( 'admin_email' ) );
		$headers = array();
		$from    = get_option( 'woocommerce_email_from_address' );
		if ( is_email( $from ) ) {
			$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$headers[] = 'From: ' . str_replace( array( "\r", "\n", '<', '>' ), '', (string) get_option( 'woocommerce_email_from_name', $site ) ) . ' <' . $from . '>';
		}
		return wp_mail( $to, $subject, $body, $headers );
	}

	/* ------------------------------------------------------------------
	 * Screens
	 * ---------------------------------------------------------------- */

	/**
	 * Edit link for a product or variation.
	 *
	 * @param WC_Product $p    Product.
	 * @param string     $name Name.
	 * @return string
	 */
	private static function link( $p, $name = '' ) {
		$id = $p->get_parent_id() ? $p->get_parent_id() : $p->get_id();
		return '<a href="' . esc_url( admin_url( 'post.php?post=' . $id . '&action=edit' ) ) . '">' . esc_html( '' !== $name ? $name : $p->get_name() ) . '</a>';
	}

	/**
	 * What caused a change.
	 *
	 * @param array $row Log row.
	 * @return string HTML.
	 */
	private static function cause( $row ) {
		$a = 'StockWarden_Admin';
		if ( in_array( $row['type'], array( 'sale', 'restock_order' ), true ) ) {
			$order = wc_get_order( (int) $row['field'] );
			$label = 'sale' === $row['type'] ? __( 'Order', 'stockwarden' ) : __( 'Returned from order', 'stockwarden' );
			return $a::tag( 'sale' === $row['type'] ? '' : 'is-ok', $label ) . ' ' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '#' . esc_html( $row['field'] ) );
		}
		if ( 'negative' === $row['type'] ) {
			return $a::tag( 'is-blocked', __( 'Oversold', 'stockwarden' ) );
		}
		$user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
		return $a::tag( 'is-would', __( 'Changed by', 'stockwarden' ) ) . ' ' . esc_html( $user ? $user->display_name : __( 'an import or another plugin', 'stockwarden' ) );
	}

	/**
	 * Overview.
	 *
	 * @return void
	 */
	public static function render_overview() {
		$a     = 'StockWarden_Admin';
		$rows  = self::forecast();
		$warn  = (int) StockWarden_Settings::get( 'days_warn' );
		$out   = 0;
		$low   = 0;
		$soon  = 0;
		foreach ( $rows as $r ) {
			if ( $r['qty'] <= 0 ) {
				++$out;
			} elseif ( null !== $r['left'] && $r['left'] <= $warn ) {
				++$soon;
			} elseif ( $r['low'] ) {
				++$low;
			}
		}
		$neg = StockWarden_Log::count( 'negative', WEEK_IN_SECONDS );
		if ( $neg ) {
			/* translators: %s: number */
			$a::banner( 'is-learning', __( 'You oversold this week', 'stockwarden' ), sprintf( _n( '%s time stock went below zero. Check History.', '%s times stock went below zero. Check History.', $neg, 'stockwarden' ), number_format_i18n( $neg ) ) );
		} elseif ( $out || $soon ) {
			$a::banner( 'is-learning', __( 'Some products need stock', 'stockwarden' ), __( 'See Running out for what to order first.', 'stockwarden' ) );
		} else {
			$a::banner( 'is-on', __( 'Stock looks healthy', 'stockwarden' ), __( 'Every stock change is recorded with its reason.', 'stockwarden' ) );
		}
		$a::figures(
			array(
				__( 'Sold out', 'stockwarden' )          => number_format_i18n( $out ),
				__( 'Running out soon', 'stockwarden' )  => number_format_i18n( $soon ),
				__( 'Low stock', 'stockwarden' )         => number_format_i18n( $low ),
				__( 'Tracked products', 'stockwarden' )  => number_format_i18n( count( $rows ) ),
			)
		);
		self::history_panel( 0, 10, __( 'Latest stock changes', 'stockwarden' ) );
	}

	/**
	 * History panel.
	 *
	 * @param int    $product Product filter.
	 * @param int    $limit   Rows.
	 * @param string $title   Title.
	 * @return void
	 */
	private static function history_panel( $product, $limit, $title ) {
		$a       = 'StockWarden_Admin';
		$entries = StockWarden_Log::recent(
			array(
				'types'     => array( 'sale', 'restock_order', 'change', 'negative' ),
				'object_id' => $product,
				'limit'     => $limit,
			)
		);
		$rows    = array();
		foreach ( $entries as $row ) {
			$p      = wc_get_product( (int) $row['object_id'] );
			$diff   = (float) $row['amount'];
			$rows[] = array(
				'when'       => $a::ago( $row['created_at'] ),
				'html_item'  => $p ? self::link( $p, $row['detail'] ) : esc_html( $row['detail'] ),
				'change'     => 'negative' === $row['type'] ? '' : ( '' !== $row['old_value'] ? wc_stock_amount( $row['old_value'] ) . ' > ' : '' ) . wc_stock_amount( $row['new_value'] ) . ( $diff ? ' (' . ( $diff > 0 ? '+' : '' ) . wc_stock_amount( $diff ) . ')' : '' ),
				'html_cause' => self::cause( $row ),
			);
		}
		$a::panel_open( $title, __( 'Sales, returns, edits and imports, with the reason for each change.', 'stockwarden' ) );
		if ( $rows ) {
			$a::table(
				array(
					'when'       => __( 'When', 'stockwarden' ),
					'html_item'  => __( 'Product', 'stockwarden' ),
					'change'     => __( 'Stock', 'stockwarden' ),
					'html_cause' => __( 'Reason', 'stockwarden' ),
				),
				$rows
			);
		} else {
			$a::empty_state( __( 'No stock changes yet', 'stockwarden' ), __( 'Changes appear here from now on.', 'stockwarden' ) );
		}
		$a::panel_close();
	}

	/**
	 * Running out.
	 *
	 * @return void
	 */
	public static function render_forecast() {
		$a     = 'StockWarden_Admin';
		$warn  = (int) StockWarden_Settings::get( 'days_warn' );
		$days  = self::history_days();
		$rows  = array();
		foreach ( self::forecast() as $r ) {
			if ( $r['qty'] > 0 && ! $r['low'] && ( null === $r['left'] || $r['left'] > $warn ) ) {
				continue;
			}
			if ( $r['qty'] <= 0 ) {
				$state = $a::tag( 'is-blocked', __( 'Sold out', 'stockwarden' ) );
			} elseif ( null !== $r['left'] && $r['left'] <= $warn ) {
				$state = $a::tag( 'is-would', __( 'Running out', 'stockwarden' ) );
			} else {
				$state = $a::tag( '', __( 'Low', 'stockwarden' ) );
			}
			$rows[] = array(
				'html_item'  => self::link( $r['product'] ),
				'stock'      => wc_stock_amount( $r['qty'] ),
				'speed'      => $r['speed'] > 0 ? number_format_i18n( $r['speed'] * 7, 1 ) : '–',
				'left'       => null === $r['left'] ? '–' : ( $r['left'] < 1 ? '<1' : number_format_i18n( $r['left'], 0 ) ),
				'html_state' => $state,
			);
		}
		$note = $days < 7
			/* translators: %s: days */
			? sprintf( __( 'Based on %s days of sales so far. The forecast gets better every day.', 'stockwarden' ), number_format_i18n( $days ) )
			: __( 'Based on sales of the last 30 days.', 'stockwarden' );
		$a::panel_open( __( 'What to order first', 'stockwarden' ), $note );
		if ( $rows ) {
			$a::table(
				array(
					'html_item'  => __( 'Product', 'stockwarden' ),
					'stock'      => __( 'Stock', 'stockwarden' ),
					'speed'      => __( 'Sold per week', 'stockwarden' ),
					'left'       => __( 'Days left', 'stockwarden' ),
					'html_state' => __( 'State', 'stockwarden' ),
				),
				$rows
			);
		} else {
			$a::empty_state( __( 'Nothing is running out', 'stockwarden' ), __( 'Products that are low, sold out or will run out soon appear here.', 'stockwarden' ) );
		}
		$a::panel_close();
	}

	/**
	 * History.
	 *
	 * @return void
	 */
	public static function render_history() {
		$product = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$p       = $product ? wc_get_product( $product ) : null;
		/* translators: %s: product */
		self::history_panel( $product, 100, $p ? sprintf( __( 'Stock history of %s', 'stockwarden' ), $p->get_name() ) : __( 'Stock history', 'stockwarden' ) );
	}

	/**
	 * Settings.
	 *
	 * @return void
	 */
	public static function render_settings() {
		$a = 'StockWarden_Admin';
		$a::form_open( 'settings' );
		$a::panel_open( __( 'Emails', 'stockwarden' ) );
		$a::row(
			__( 'Oversold', 'stockwarden' ),
			__( 'When stock goes below zero, so you can order more or contact customers before shipping.', 'stockwarden' ),
			static function () use ( $a ) {
				$a::switch_field( 'negative_alert', __( 'Email me when a product is oversold', 'stockwarden' ) );
			}
		);
		$a::row(
			__( 'Daily summary', 'stockwarden' ),
			__( 'One email a day instead of one email per product. You can turn off the WooCommerce low stock emails in WooCommerce, Settings, Emails.', 'stockwarden' ),
			static function () use ( $a ) {
				$a::switch_field( 'digest', __( 'Send one daily email with sold out, running out and low stock products', 'stockwarden' ) );
				/* translators: %1$s: days */
				$a::sentence( __( 'Running out means less than %1$s days of stock at the current sales speed.', 'stockwarden' ), array( 'days_warn' => __( 'Days', 'stockwarden' ) ) );
				$a::text( 'notify_to', __( 'Email addresses', 'stockwarden' ), get_option( 'admin_email' ) );
			}
		);
		$a::panel_close();
		$a::panel_open( __( 'Data', 'stockwarden' ) );
		$a::row(
			__( 'History', 'stockwarden' ),
			__( 'Keep at least 30 days for a good forecast.', 'stockwarden' ),
			static function () use ( $a ) {
				/* translators: %1$s: days */
				$a::sentence( __( 'Keep stock history for %1$s days.', 'stockwarden' ), array( 'retention_days' => __( 'Days', 'stockwarden' ) ) );
				$a::switch_field( 'delete_data', __( 'Delete settings and data when the plugin is deleted', 'stockwarden' ) );
			}
		);
		$a::panel_close();
		$a::form_close();
	}
}
