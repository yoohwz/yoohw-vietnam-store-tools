<?php
/**
 * Guardrails for stores migrating from Le Van Toan plugins.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Legacy_Plugin_Guard {

	private const DISMISS_ACTION = 'yoohw_vietnam_store_tools_dismiss_levantoan_plugin_notice';

	private const DISMISS_QUERY_ARG = 'yoohw_vietnam_store_tools_action';

	private const DISMISS_USER_META = '_yoohw_vietnam_store_tools_levantoan_plugin_notice_dismissed';

	private static $active_detection = null;

	private static $detection = null;

	private static $legacy_plugins = [
		'woo-vietnam-checkout/devvn-woo-address-selectbox.php' => 'Vietnam Checkout for WooCommerce',
		'devvn-woo-ghtk/devvn-woo-ghtk.php'                   => 'Giao Hang Tiet Kiem for WooCommerce',
	];

	public function __construct() {
		add_action( 'admin_init', [ $this, 'handle_notice_actions' ] );
		add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );
		add_action( 'woocommerce_before_checkout_form', [ $this, 'render_checkout_conflict_notice' ], 1 );
	}

	public function handle_notice_actions() {
		$action = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( self::DISMISS_QUERY_ARG ) );

		if ( self::DISMISS_ACTION !== $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( self::DISMISS_ACTION );

		update_user_meta( get_current_user_id(), self::DISMISS_USER_META, 'yes' );

		wp_safe_redirect( remove_query_arg( [ self::DISMISS_QUERY_ARG, '_wpnonce' ] ) );
		exit;
	}

	public function render_admin_notice() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) || $this->is_notice_dismissed() ) {
			return;
		}

		if ( ! self::has_active_legacy_plugin() ) {
			return;
		}

		$scan_url    = wp_nonce_url( admin_url( 'admin.php?page=wc-status&tab=tools&action=yoohw_vietnam_store_tools_devvn_migration_dry_run' ), 'debug_action' );
		$tools_url   = admin_url( 'admin.php?page=wc-status&tab=tools' );
		$dismiss_url = wp_nonce_url( add_query_arg( self::DISMISS_QUERY_ARG, self::DISMISS_ACTION ), self::DISMISS_ACTION );

		?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Vietnam Store Toolkit for WooCommerce detected active plugins from Le Van Toan.', 'yoohw-vietnam-store-tools' ); ?></strong></p>
			<p>
				<?php esc_html_e( 'These plugins can also modify WooCommerce Vietnam address and checkout fields. Run the scan and sync tools before disabling the old plugin to avoid city/province, ward/commune, city, state, and address line 2 data conflicts.', 'yoohw-vietnam-store-tools' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $scan_url ); ?>"><?php esc_html_e( 'Scan data', 'yoohw-vietnam-store-tools' ); ?></a>
				<a class="button" href="<?php echo esc_url( $tools_url ); ?>"><?php esc_html_e( 'Open sync tools', 'yoohw-vietnam-store-tools' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'I have handled this', 'yoohw-vietnam-store-tools' ); ?></a>
			</p>
		</div>
		<?php
	}

	public function render_checkout_conflict_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::has_active_legacy_plugin() ) {
			return;
		}

		$message = __( 'Vietnam Store Toolkit for WooCommerce detected an active Le Van Toan plugin. Both plugins may modify city/province and ward/commune checkout fields, which can cause city, state, address line 2, or admin/frontend display conflicts.', 'yoohw-vietnam-store-tools' );

		if ( function_exists( 'wc_print_notice' ) ) {
			wc_print_notice( $message, 'notice' );
			return;
		}

		printf( '<div class="woocommerce-info">%s</div>', esc_html( $message ) );
	}

	public static function has_active_legacy_plugin() {
		$detection = self::get_active_detection();

		return ! empty( $detection['active'] );
	}

	public static function should_defer_checkout_fields() {
		return false;
	}

	public static function get_detection() {
		if ( null !== self::$detection ) {
			return self::$detection;
		}

		$active_detection  = self::get_active_detection();
		$installed_plugins = self::detect_installed_plugins();
		$data_signals      = self::detect_data_signals();

		self::$detection = [
			'active'            => ! empty( $active_detection['active'] ),
			'has_history'       => ! empty( $installed_plugins ) || ! empty( $data_signals ),
			'active_plugins'    => $active_detection['active_plugins'],
			'installed_plugins' => $installed_plugins,
			'runtime_signals'   => $active_detection['runtime_signals'],
			'data_signals'      => $data_signals,
		];

		return self::$detection;
	}

	private static function get_active_detection() {
		if ( null !== self::$active_detection ) {
			return self::$active_detection;
		}

		$active_plugins  = self::detect_active_plugins();
		$runtime_signals = self::detect_runtime_signals();

		self::$active_detection = [
			'active'          => ! empty( $active_plugins ) || ! empty( $runtime_signals ),
			'active_plugins'  => $active_plugins,
			'runtime_signals' => $runtime_signals,
		];

		return self::$active_detection;
	}

	private function is_notice_dismissed() {
		return 'yes' === get_user_meta( get_current_user_id(), self::DISMISS_USER_META, true );
	}

	private static function detect_active_plugins() {
		$active_plugin_files = self::get_active_plugin_files();
		$active_plugins      = [];

		foreach ( self::$legacy_plugins as $plugin_file => $plugin_name ) {
			if ( in_array( $plugin_file, $active_plugin_files, true ) ) {
				$active_plugins[] = [
					'file' => $plugin_file,
					'name' => $plugin_name,
				];
			}
		}

		return $active_plugins;
	}

	private static function detect_installed_plugins() {
		$installed_plugins = [];

		foreach ( self::$legacy_plugins as $plugin_file => $plugin_name ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$installed_plugins[] = [
					'file' => $plugin_file,
					'name' => $plugin_name,
				];
			}
		}

		return $installed_plugins;
	}

	private static function detect_runtime_signals() {
		$signals = [];

		if ( class_exists( 'Woo_Address_Selectbox_Class', false ) || defined( 'DEVVN_DWAS_VERSION_NUM' ) ) {
			$signals[] = 'woo-vietnam-checkout';
		}

		if ( defined( 'DEVVN_GHTK_VERSION_NUM' ) || defined( 'DEVVN_GHTK_BASENAME' ) ) {
			$signals[] = 'devvn-woo-ghtk';
		}

		return array_values( array_unique( $signals ) );
	}

	private static function detect_data_signals() {
		$signals = [];

		if ( false !== get_option( 'devvn_woo_district', false ) ) {
			$signals[] = 'woo-vietnam-checkout-options';
		}

		if ( self::order_meta_exists( '_ghtk_ordercode' ) || self::order_meta_exists( '_order_ghtk_full' ) ) {
			$signals[] = 'ghtk-order-meta';
		}

		if ( self::legacy_address_data_exists() ) {
			$signals[] = 'legacy-address-data';
		}

		return array_values( array_unique( $signals ) );
	}

	private static function get_active_plugin_files() {
		$active_plugins = get_option( 'active_plugins', [] );
		$active_plugins = is_array( $active_plugins ) ? $active_plugins : [];
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This reads the WordPress core active_plugins filter.
		$active_plugins = apply_filters( 'active_plugins', $active_plugins );
		$active_plugins = is_array( $active_plugins ) ? $active_plugins : [];

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', [] );

			if ( is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		return array_values( array_unique( array_map( 'strval', $active_plugins ) ) );
	}

	private static function order_meta_exists( $meta_key ) {
		global $wpdb;

		if ( self::table_exists( $wpdb->postmeta ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy detection scans current WooCommerce order data on demand; caching would hide new migration signals.
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
					$meta_key
				)
			);

			if ( null !== $found ) {
				return true;
			}
		}

		$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';

		if ( self::table_exists( $hpos_meta_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy detection scans current WooCommerce order data on demand; caching would hide new migration signals.
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE meta_key = %s LIMIT 1',
					$hpos_meta_table,
					$meta_key
				)
			);

			return null !== $found;
		}

		return false;
	}

	private static function legacy_address_data_exists() {
		global $wpdb;

		$hpos_addresses_table = $wpdb->prefix . 'wc_order_addresses';

		if ( self::table_exists( $hpos_addresses_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy detection scans current WooCommerce order data on demand; caching would hide new migration signals.
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT order_id FROM %i
					WHERE address_type IN (%s, %s)
						AND country = %s
						AND (
							(TRIM(city) REGEXP '^[0-9]{3}$' AND TRIM(address_2) REGEXP '^[0-9]{5}$')
							OR (
								TRIM(city) REGEXP '^[0-9]{5}$'
								AND (address_2 IS NULL OR TRIM(address_2) = '')
								AND (state IS NULL OR TRIM(state) = '' OR TRIM(state) NOT REGEXP '^[0-9]{2}$')
							)
						)
					LIMIT 1",
					$hpos_addresses_table,
					'billing',
					'shipping',
					'VN'
				)
			);

			if ( null !== $found ) {
				return true;
			}
		}

		if ( ! self::table_exists( $wpdb->postmeta ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy detection scans current WooCommerce order data on demand; caching would hide new migration signals.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT country.post_id
				FROM %i country
				INNER JOIN %i city ON city.post_id = country.post_id AND city.meta_key IN (%s, %s)
				LEFT JOIN %i address_2 ON address_2.post_id = country.post_id AND address_2.meta_key IN (%s, %s)
				LEFT JOIN %i state ON state.post_id = country.post_id AND state.meta_key IN (%s, %s)
				WHERE country.meta_key IN (%s, %s)
					AND country.meta_value = %s
					AND (
						(TRIM(city.meta_value) REGEXP '^[0-9]{3}$' AND TRIM(address_2.meta_value) REGEXP '^[0-9]{5}$')
						OR (
							TRIM(city.meta_value) REGEXP '^[0-9]{5}$'
							AND (address_2.meta_id IS NULL OR TRIM(address_2.meta_value) = '')
							AND (state.meta_id IS NULL OR TRIM(state.meta_value) = '' OR TRIM(state.meta_value) NOT REGEXP '^[0-9]{2}$')
						)
					)
				LIMIT 1",
				$wpdb->postmeta,
				$wpdb->postmeta,
				'_billing_city',
				'_shipping_city',
				$wpdb->postmeta,
				'_billing_address_2',
				'_shipping_address_2',
				$wpdb->postmeta,
				'_billing_state',
				'_shipping_state',
				'_billing_country',
				'_shipping_country',
				'VN'
			)
		);

		return null !== $found;
	}

	private static function table_exists( $table_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence checks must read the database schema directly.
		return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	}
}
