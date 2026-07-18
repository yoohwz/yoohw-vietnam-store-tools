<?php
/**
 * Vietnam shipping rules integration.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Shipping_Rules {

	const METHOD_ID = 'yoohw_vietnam_store_tools_shipping_rules';

	public function __construct() {
		add_action( 'woocommerce_shipping_init', [ $this, 'load_shipping_method' ] );
		add_filter( 'woocommerce_shipping_methods', [ $this, 'register_shipping_method' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_cod_gateway' ], 30 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	public function load_shipping_method() {
		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Shipping_Rules_Method' ) || ! class_exists( 'WC_Shipping_Method' ) ) {
			return;
		}

		$file = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'includes/class-vietnam-commerce-kit-shipping-rules-method.php';

		if ( file_exists( $file ) ) {
			include_once $file;
		}
	}

	public function register_shipping_method( $methods ) {
		$this->load_shipping_method();

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Shipping_Rules_Method' ) ) {
			$methods[ self::METHOD_ID ] = 'Yoohw_Vietnam_Store_Tools_Shipping_Rules_Method';
		}

		return $methods;
	}

	public function enqueue_admin_assets() {
		if ( ! $this->is_shipping_settings_screen() ) {
			return;
		}

		$script_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/js/admin/shipping-rules.js';
		$style_path  = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/css/admin/shipping-rules.css';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'yoohw-vietnam-store-tools-shipping-rules',
				YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/admin/shipping-rules.css',
				[],
				filemtime( $style_path )
			);
		}

		if ( ! file_exists( $script_path ) ) {
			return;
		}

		wp_enqueue_script(
			'yoohw-vietnam-store-tools-shipping-rules',
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/shipping-rules.js',
			[ 'jquery' ],
			filemtime( $script_path ),
			true
		);

		wp_localize_script(
			'yoohw-vietnam-store-tools-shipping-rules',
			'yoohwVietnamStoreToolsShippingRules',
			[
				'provinces'      => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_provinces(),
				'wards'          => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards(),
				'shippingClasses' => $this->get_shipping_classes(),
				'currencySymbol' => get_woocommerce_currency_symbol(),
				'weightUnit'     => get_option( 'woocommerce_weight_unit', 'kg' ),
				'csvHeaders'     => [
					'enabled',
					'name',
					'province',
					'ward',
					'min_total',
					'max_total',
					'min_weight',
					'max_weight',
					'shipping_class',
					'fee',
					'free_threshold',
					'cod',
				],
				'i18n'           => [
					'addRule'            => __( 'Add rule', 'yoohw-vietnam-store-tools' ),
					'importCsv'          => __( 'Import CSV', 'yoohw-vietnam-store-tools' ),
					'exportCsv'          => __( 'Export CSV', 'yoohw-vietnam-store-tools' ),
					'conditions'         => __( 'Conditions', 'yoohw-vietnam-store-tools' ),
					'shippingOutcome'    => __( 'Shipping outcome', 'yoohw-vietnam-store-tools' ),
					'emptyRules'         => __( 'No shipping rules have been added yet.', 'yoohw-vietnam-store-tools' ),
					'ruleName'           => __( 'Rule name', 'yoohw-vietnam-store-tools' ),
					'editRule'           => __( 'Edit rule', 'yoohw-vietnam-store-tools' ),
					'enabled'            => __( 'Enabled', 'yoohw-vietnam-store-tools' ),
					'anyProvince'        => __( 'Any city / province', 'yoohw-vietnam-store-tools' ),
					'anyWard'            => __( 'Any ward / commune', 'yoohw-vietnam-store-tools' ),
					'selectProvince'     => __( 'Select a city / province first', 'yoohw-vietnam-store-tools' ),
					'province'           => __( 'City / Province', 'yoohw-vietnam-store-tools' ),
					'ward'               => __( 'Ward / Commune', 'yoohw-vietnam-store-tools' ),
					'minTotal'           => __( 'Minimum cart total', 'yoohw-vietnam-store-tools' ),
					'maxTotal'           => __( 'Maximum cart total', 'yoohw-vietnam-store-tools' ),
					'minWeight'          => __( 'Minimum weight', 'yoohw-vietnam-store-tools' ),
					'maxWeight'          => __( 'Maximum weight', 'yoohw-vietnam-store-tools' ),
					'shippingClass'      => __( 'Shipping class', 'yoohw-vietnam-store-tools' ),
					'anyClass'           => __( 'Any shipping class', 'yoohw-vietnam-store-tools' ),
					'noClass'            => __( 'Products without a shipping class', 'yoohw-vietnam-store-tools' ),
					'fee'                => __( 'Shipping fee', 'yoohw-vietnam-store-tools' ),
					'freeThreshold'      => __( 'Free shipping threshold', 'yoohw-vietnam-store-tools' ),
					'cod'                => __( 'Cash on delivery', 'yoohw-vietnam-store-tools' ),
					'inheritCod'         => __( 'Use method default', 'yoohw-vietnam-store-tools' ),
					'allowCod'           => __( 'Allow COD', 'yoohw-vietnam-store-tools' ),
					'disallowCod'        => __( 'Disallow COD', 'yoohw-vietnam-store-tools' ),
					'moveUp'             => __( 'Move up', 'yoohw-vietnam-store-tools' ),
					'moveDown'           => __( 'Move down', 'yoohw-vietnam-store-tools' ),
					'remove'             => __( 'Remove rule', 'yoohw-vietnam-store-tools' ),
					'confirmImport'      => __( 'Importing will replace the current rules in this editor. Continue?', 'yoohw-vietnam-store-tools' ),
					'imported'           => __( 'CSV rules were imported. Save changes to apply them.', 'yoohw-vietnam-store-tools' ),
					'importFailed'       => __( 'Could not import the CSV file. Check the header row and data format.', 'yoohw-vietnam-store-tools' ),
					'exported'           => __( 'CSV rules were exported.', 'yoohw-vietnam-store-tools' ),
					'csvFilename'        => __( 'vietnam-shipping-rules.csv', 'yoohw-vietnam-store-tools' ),
					'newRule'            => __( 'New shipping rule', 'yoohw-vietnam-store-tools' ),
				],
			]
		);
	}

	public function filter_cod_gateway( $gateways ) {
		if ( ! isset( $gateways['cod'] ) || ( is_admin() && ! wp_doing_ajax() ) || ! function_exists( 'WC' ) ) {
			return $gateways;
		}

		$shipping_methods = [];

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );

			if ( $order ) {
				$shipping_methods = $order->get_shipping_methods();
			}
		} elseif ( WC()->cart ) {
			$shipping_methods = WC()->cart->get_shipping_methods();
		}

		if ( self::shipping_methods_allow_cod( $shipping_methods ) ) {
			return $gateways;
		}

		unset( $gateways['cod'] );

		return $gateways;
	}

	/**
	 * Check the selected shipping rates for this method's COD metadata.
	 *
	 * @param array $shipping_methods Selected shipping rates or order shipping items.
	 * @return bool
	 */
	public static function shipping_methods_allow_cod( $shipping_methods ) {
		if ( ! is_array( $shipping_methods ) ) {
			return true;
		}

		foreach ( $shipping_methods as $shipping_method ) {
			if ( ! is_object( $shipping_method )
				|| ! is_callable( [ $shipping_method, 'get_method_id' ] )
				|| self::METHOD_ID !== $shipping_method->get_method_id() ) {
				continue;
			}

			if ( $shipping_method instanceof WC_Shipping_Rate ) {
				$meta        = $shipping_method->get_meta_data();
				$cod_allowed = isset( $meta['vck_cod_allowed'] ) ? $meta['vck_cod_allowed'] : '';
			} else {
				$cod_allowed = is_callable( [ $shipping_method, 'get_meta' ] ) ? $shipping_method->get_meta( 'vck_cod_allowed', true ) : '';
			}

			if ( 'no' === $cod_allowed ) {
				return false;
			}
		}

		return true;
	}

	private function get_shipping_classes() {
		$classes = [];

		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return $classes;
		}

		foreach ( WC()->shipping()->get_shipping_classes() as $shipping_class ) {
			if ( $shipping_class instanceof WP_Term ) {
				$classes[ $shipping_class->slug ] = $shipping_class->name;
			}
		}

		return $classes;
	}

	private function is_shipping_settings_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-settings', 'admin_page_wc-settings' ], true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return 'shipping' === $tab;
	}
}
