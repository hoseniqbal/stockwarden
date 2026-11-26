<?php
/**
 * Admin frame: menu, header, tabs, form helpers, save action.
 *
 * @package StockWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screens. Each tab's content comes from StockWarden_Features.
 */
class StockWarden_Admin {

	const PAGE = 'stockwarden';
	const CAP  = 'manage_woocommerce';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 64 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_stockwarden_save', array( __CLASS__, 'handle_save' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( STOCKWARDEN_FILE ), array( __CLASS__, 'action_links' ) );
		StockWarden_Features::admin_hooks();
	}

	/**
	 * Tabs, with the Pro tab while Pro is not active.
	 *
	 * @return array
	 */
	public static function tabs() {
		$tabs = StockWarden_Features::tabs();
		if ( ! class_exists( 'SKWP_Plugin' ) ) {
			$tabs['pro'] = __( 'Pro', 'stockwarden' );
		}
		return $tabs;
	}

	/**
	 * Page URL.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra args.
	 * @return string
	 */
	public static function url( $tab = 'overview', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Plugins screen link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Dashboard', 'stockwarden' ) . '</a>' );
		return $links;
	}

	/**
	 * Menu.
	 *
	 * @return void
	 */
	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.5 2.8 4.2v5.2c0 4.4 3 7.7 7.2 8.9 4.2-1.2 7.2-4.5 7.2-8.9V4.2L10 1.5Z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon.
		$tabs = self::tabs();
		add_menu_page( __( 'StockWarden', 'stockwarden' ), __( 'StockWarden', 'stockwarden' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ), $icon, 58 );
		add_submenu_page( self::PAGE, $tabs['overview'], $tabs['overview'], self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
		foreach ( $tabs as $tab => $label ) {
			if ( 'overview' !== $tab ) {
				add_submenu_page( self::PAGE, $label, $label, self::CAP, 'admin.php?page=' . self::PAGE . '&tab=' . $tab );
			}
		}
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch.
		return isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
	}

	/**
	 * Highlight the open tab in the menu.
	 *
	 * @param string|null $file Submenu file.
	 * @return string|null
	 */
	public static function submenu_file( $file ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- menu highlight.
		if ( self::PAGE !== $page ) {
			return $file;
		}
		$tab = self::current_tab();
		return 'overview' === $tab ? self::PAGE : 'admin.php?page=' . self::PAGE . '&tab=' . $tab;
	}

	/**
	 * Styles.
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) && ! StockWarden_Features::needs_css( $hook ) ) {
			return;
		}
		$file = STOCKWARDEN_PATH . 'assets/css/admin.css';
		wp_enqueue_style( 'stockwarden-admin', STOCKWARDEN_URL . 'assets/css/admin.css', array(), STOCKWARDEN_VERSION . '.' . (int) filemtime( $file ) );
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab = self::current_tab();
		?>
		<div class="wrap stockwarden">
			<div class="ow-hero">
				<div class="stockwarden-header">
					<span class="ow-logo"><svg width="34" height="34" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M16 2.5 4.5 6.8v8.4c0 7.1 4.9 12.4 11.5 14.3 6.6-1.9 11.5-7.2 11.5-14.3V6.8L16 2.5Z" fill="#12704c"/><path d="m16 9 6 3v7l-6 3-6-3v-7l6-3Z M10 12l6 3 6-3 M16 15v7" fill="none" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/><circle cx="23.5" cy="21.5" r="4" fill="#e0457b" stroke="#fff" stroke-width="1.5"/></svg></span>
					<div class="ow-title">
						<h1><?php esc_html_e( 'StockWarden', 'stockwarden' ); ?></h1>
						<p class="ow-tagline"><?php echo esc_html( StockWarden_Features::tagline() ); ?></p>
					</div>
					<span class="stockwarden-version"><?php echo esc_html( 'v' . STOCKWARDEN_VERSION ); ?></span>
				</div>
				<nav class="stockwarden-tabs" aria-label="<?php esc_attr_e( 'StockWarden sections', 'stockwarden' ); ?>">
					<?php foreach ( self::tabs() as $key => $label ) : ?>
						<a href="<?php echo esc_url( self::url( $key ) ); ?>"<?php echo 'pro' === $key ? ' class="ow-tab-pro"' : ''; ?><?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
			</div>
			<hr class="wp-header-end">
			<?php
			self::notice();
			if ( 'pro' === $tab ) {
				self::render_pro();
			} else {
				call_user_func( array( 'StockWarden_Features', 'render_' . $tab ) );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Result message.
	 *
	 * @return void
	 */
	private static function notice() {
		$msg = isset( $_GET['wd_msg'] ) ? sanitize_key( wp_unslash( $_GET['wd_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( '' === $msg ) {
			return;
		}
		$detail   = isset( $_GET['wd_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['wd_detail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$messages = array_merge(
			array(
				'saved'  => array( 'success', __( 'Settings saved.', 'stockwarden' ) ),
				'done'   => array( 'success', $detail ),
				'failed' => array( 'error', $detail ),
			),
			StockWarden_Features::messages()
		);
		if ( isset( $messages[ $msg ] ) && '' !== $messages[ $msg ][1] ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
		}
	}

	/**
	 * Pro tab: what the add-on adds. Shows "Coming soon" until a store link is set.
	 *
	 * @return void
	 */
	private static function render_pro() {
		/**
		 * Where to buy the Pro add-on. Empty shows "Coming soon".
		 *
		 * @param string $url URL.
		 */
		$buy = (string) apply_filters( 'stockwarden_pro_url', '' );
		?>
		<section class="ow-sale">
			<div class="ow-sale-main">
				<h2><?php esc_html_e( 'StockWarden Pro', 'stockwarden' ); ?></h2>
				<p><?php echo esc_html( StockWarden_Features::pro_intro() ); ?></p>
				<ul class="ow-points">
					<?php foreach ( StockWarden_Features::pro_points() as $point ) : ?>
						<li><?php echo esc_html( $point ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="ow-sale-aside">
				<span class="ow-pro-badge"><?php esc_html_e( 'Pro', 'stockwarden' ); ?></span>
				<h3 style="margin-top:14px"><?php esc_html_e( 'A separate add-on', 'stockwarden' ); ?></h3>
				<p><?php esc_html_e( 'Everything you use today stays free, with no limits. Pro adds the features on the left.', 'stockwarden' ); ?></p>
				<?php if ( '' === $buy ) : ?>
					<p><strong><?php esc_html_e( 'Coming soon.', 'stockwarden' ); ?></strong></p>
				<?php else : ?>
					<a class="button ow-btn-gold" href="<?php echo esc_url( $buy ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See plans', 'stockwarden' ); ?></a>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Helpers for the Features class
	 * ---------------------------------------------------------------- */

	/**
	 * Status banner.
	 *
	 * @param string $state is-on, is-learning or is-off.
	 * @param string $title Title.
	 * @param string $text  Text.
	 * @return void
	 */
	public static function banner( $state, $title, $text ) {
		?>
		<section class="stockwarden-banner <?php echo esc_attr( $state ); ?>">
			<div><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $text ); ?></p></div>
		</section>
		<?php
	}

	/**
	 * Figure cards.
	 *
	 * @param array $figures label => value.
	 * @return void
	 */
	public static function figures( $figures ) {
		?>
		<section class="stockwarden-panel">
			<dl class="stockwarden-figures">
				<?php foreach ( $figures as $label => $value ) : ?>
					<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div>
				<?php endforeach; ?>
			</dl>
		</section>
		<?php
	}

	/**
	 * Open a panel.
	 *
	 * @param string $title Title.
	 * @param string $note  Note on the right.
	 * @return void
	 */
	public static function panel_open( $title, $note = '' ) {
		?>
		<section class="stockwarden-panel">
			<div class="stockwarden-panel-head"><h2><?php echo esc_html( $title ); ?></h2><?php if ( '' !== $note ) : ?><p><?php echo esc_html( $note ); ?></p><?php endif; ?></div>
		<?php
	}

	/**
	 * Close a panel.
	 *
	 * @return void
	 */
	public static function panel_close() {
		echo '</section>';
	}

	/**
	 * Empty state.
	 *
	 * @param string $title Title.
	 * @param string $text  Text.
	 * @return void
	 */
	public static function empty_state( $title, $text ) {
		?>
		<div class="stockwarden-empty"><strong><?php echo esc_html( $title ); ?></strong><?php echo esc_html( $text ); ?></div>
		<?php
	}

	/**
	 * A table. Cells are plain text unless the column key starts with "html_" (already escaped).
	 *
	 * @param array $columns key => label.
	 * @param array $rows    Rows keyed like columns.
	 * @return void
	 */
	public static function table( $columns, $rows ) {
		?>
		<table class="stockwarden-table">
			<thead><tr>
				<?php foreach ( $columns as $label ) : ?>
					<th scope="col"><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<?php foreach ( array_keys( $columns ) as $key ) : ?>
						<td>
							<?php
							$cell = isset( $row[ $key ] ) ? $row[ $key ] : '';
							if ( 0 === strpos( $key, 'html_' ) ) {
								echo wp_kses_post( $cell );
							} else {
								echo esc_html( $cell );
							}
							?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * A status tag (escaped HTML).
	 *
	 * @param string $class is-ok, is-would, is-blocked or ''.
	 * @param string $text  Text.
	 * @return string
	 */
	public static function tag( $class, $text ) {
		return '<span class="stockwarden-tag ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Time ago.
	 *
	 * @param string $mysql_gmt Date in UTC.
	 * @return string
	 */
	public static function ago( $mysql_gmt ) {
		/* translators: %s: time difference */
		return sprintf( __( '%s ago', 'stockwarden' ), human_time_diff( (int) strtotime( $mysql_gmt . ' UTC' ) ) );
	}

	/**
	 * Open a settings form.
	 *
	 * @param string $section Section key.
	 * @return void
	 */
	public static function form_open( $section ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="stockwarden_save">
			<input type="hidden" name="section" value="<?php echo esc_attr( $section ); ?>">
			<?php wp_nonce_field( 'stockwarden_save_' . $section ); ?>
		<?php
	}

	/**
	 * Close a settings form.
	 *
	 * @return void
	 */
	public static function form_close() {
		?>
			<div class="stockwarden-actions">
				<?php submit_button( __( 'Save settings', 'stockwarden' ), 'stockwarden-btn', 'submit', false ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * A settings row.
	 *
	 * @param string   $label Label.
	 * @param string   $note  Note.
	 * @param callable $body  Prints the control.
	 * @return void
	 */
	public static function row( $label, $note, $body ) {
		?>
		<div class="stockwarden-row">
			<div class="stockwarden-row-label"><?php echo esc_html( $label ); ?><?php if ( '' !== $note ) : ?><p><?php echo esc_html( $note ); ?></p><?php endif; ?></div>
			<div class="stockwarden-row-control"><?php call_user_func( $body ); ?></div>
		</div>
		<?php
	}

	/**
	 * Switch.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @return void
	 */
	public static function switch_field( $key, $label ) {
		?>
		<label class="stockwarden-switch">
			<input type="checkbox" name="wd[<?php echo esc_attr( $key ); ?>]" value="yes" <?php checked( StockWarden_Settings::get( $key ), 'yes' ); ?>>
			<span><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	/**
	 * Sentence with number inputs (%1$s, %2$s ...).
	 *
	 * @param string $format Translated sentence.
	 * @param array  $keys   key => aria label.
	 * @return void
	 */
	public static function sentence( $format, $keys ) {
		$inputs = array();
		foreach ( $keys as $key => $label ) {
			$inputs[] = sprintf( '<input type="number" min="0" step="1" name="wd[%1$s]" value="%2$s" aria-label="%3$s">', esc_attr( $key ), esc_attr( StockWarden_Settings::get( $key ) ), esc_attr( $label ) );
		}
		echo '<p>' . wp_kses(
			vsprintf( esc_html( $format ), $inputs ),
			array(
				'input' => array(
					'type'       => true,
					'min'        => true,
					'step'       => true,
					'name'       => true,
					'value'      => true,
					'aria-label' => true,
				),
			)
		) . '</p>';
	}

	/**
	 * Text input or textarea.
	 *
	 * @param string $key         Key.
	 * @param string $label       Aria label.
	 * @param string $placeholder Placeholder.
	 * @param string $type        text, email or textarea.
	 * @return void
	 */
	public static function text( $key, $label, $placeholder = '', $type = 'text' ) {
		if ( 'textarea' === $type ) {
			printf( '<textarea class="wd-wide" name="wd[%1$s]" aria-label="%2$s" placeholder="%3$s">%4$s</textarea>', esc_attr( $key ), esc_attr( $label ), esc_attr( $placeholder ), esc_textarea( (string) StockWarden_Settings::get( $key ) ) );
			return;
		}
		printf( '<input type="%1$s" class="wd-wide" name="wd[%2$s]" value="%3$s" aria-label="%4$s" placeholder="%5$s">', esc_attr( $type ), esc_attr( $key ), esc_attr( (string) StockWarden_Settings::get( $key ) ), esc_attr( $label ), esc_attr( $placeholder ) );
	}

	/**
	 * Select.
	 *
	 * @param string $key     Key.
	 * @param array  $options value => label.
	 * @param string $label   Aria label.
	 * @return void
	 */
	public static function select( $key, $options, $label ) {
		echo '<p><select name="wd[' . esc_attr( $key ) . ']" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) StockWarden_Settings::get( $key ), (string) $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * A one-button form for an admin-post action.
	 *
	 * @param string $action Action.
	 * @param string $label  Label.
	 * @param array  $fields Hidden fields.
	 * @param string $class  Button class.
	 * @return void
	 */
	public static function button( $action, $label, $fields = array(), $class = 'stockwarden-btn-secondary' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wd-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, $class, 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Capability and nonce check for actions.
	 *
	 * @param string $nonce Nonce action.
	 * @return void
	 */
	public static function guard( $nonce ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'stockwarden' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Redirect back with a message.
	 *
	 * @param string $tab    Tab.
	 * @param string $msg    Message key.
	 * @param string $detail Detail.
	 * @return void
	 */
	public static function back( $tab, $msg, $detail = '' ) {
		$args = array( 'wd_msg' => $msg );
		if ( '' !== $detail ) {
			$args['wd_detail'] = rawurlencode( $detail );
		}
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/**
	 * Save a section.
	 *
	 * @return void
	 */
	public static function handle_save() {
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		self::guard( 'stockwarden_save_' . $section );
		$sections = StockWarden_Features::sections();
		if ( ! isset( $sections[ $section ] ) ) {
			wp_die( esc_html__( 'Unknown section.', 'stockwarden' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); each field is cleaned in StockWarden_Settings::clean().
		$input = isset( $_POST['wd'] ) && is_array( $_POST['wd'] ) ? wp_unslash( $_POST['wd'] ) : array();
		StockWarden_Settings::save( $input, $sections[ $section ] );
		self::back( 'settings' === $section ? 'settings' : self::current_tab_from_section( $section ), 'saved' );
	}

	/**
	 * Tab to return to after saving a section.
	 *
	 * @param string $section Section.
	 * @return string
	 */
	private static function current_tab_from_section( $section ) {
		return isset( self::tabs()[ $section ] ) ? $section : 'settings';
	}
}
