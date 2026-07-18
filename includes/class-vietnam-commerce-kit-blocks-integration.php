<?php
/**
 * WooCommerce Cart and Checkout Blocks integration.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Blocks_Integration {

	const COUNTRY_CODE = 'VN';

	public function __construct() {
		add_action( 'wp', [ $this, 'prepare_block_customer_names' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 30 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', [ $this, 'normalize_store_api_customer' ], 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ $this, 'normalize_store_api_customer' ], 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'validate_and_normalize_store_api_order' ], 20, 2 );
	}

	public static function is_current_block_page() {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return false;
		}

		// A Cart or Checkout block can be embedded on a page that has not been
		// assigned as WooCommerce's official cart/checkout page. Detect the
		// queried page content first so those blocks receive the integration too.
		$queried_post = get_post( get_queried_object_id() );

		if ( $queried_post instanceof WP_Post && ( has_block( 'woocommerce/cart', $queried_post ) || has_block( 'woocommerce/checkout', $queried_post ) ) ) {
			return true;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return has_block( 'woocommerce/cart' ) || self::is_default_block_page( 'cart' );
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return has_block( 'woocommerce/checkout' ) || self::is_default_block_page( 'checkout' );
		}

		return false;
	}

	public function prepare_block_customer_names() {
		if ( ! self::is_current_block_page() || $this->should_defer_checkout_address_fields() || ! function_exists( 'WC' ) || ! WC()->customer instanceof WC_Customer ) {
			return;
		}

		$this->normalize_customer_address( WC()->customer, 'billing' );
		$this->normalize_customer_address( WC()->customer, 'shipping' );
	}

	public function enqueue_assets() {
		if ( ! self::is_current_block_page() || $this->should_defer_checkout_address_fields() ) {
			return;
		}

		$script_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/js/frontend/blocks-address-fields.js';
		$style_path  = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/css/blocks-address-fields.css';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'yoohw-vietnam-store-tools-blocks-address-fields',
				YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/blocks-address-fields.css',
				[],
				filemtime( $style_path )
			);
		}

		if ( ! file_exists( $script_path ) ) {
			return;
		}

		$handle = 'yoohw-vietnam-store-tools-blocks-address-fields';

		wp_enqueue_script(
			$handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/frontend/blocks-address-fields.js',
			[ 'wp-data', 'wc-blocks-data-store' ],
			filemtime( $script_path ),
			true
		);

		wp_localize_script(
			$handle,
			'yoohwVietnamStoreToolsBlocksAddressFields',
			[
				'country'       => self::COUNTRY_CODE,
				'singleCountry' => $this->get_single_allowed_country(),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'wardsNonce'    => wp_create_nonce( 'yoohw_vietnam_store_tools_wards' ),
				'i18n'          => [
					'wardLabel'           => __( 'Ward / Commune', 'yoohw-vietnam-store-tools' ),
					'selectWard'          => __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' ),
					'selectProvinceFirst' => __( 'Select a city / province first', 'yoohw-vietnam-store-tools' ),
					'loadingWards'        => __( 'Loading ward / commune list...', 'yoohw-vietnam-store-tools' ),
					'loadError'           => __( 'Could not load the ward / commune list. Please try again.', 'yoohw-vietnam-store-tools' ),
				],
			]
		);
	}

	public function normalize_store_api_customer( $customer, $request = null ) {
		unset( $request );

		if ( $this->should_defer_checkout_address_fields() || ! $customer instanceof WC_Customer ) {
			return;
		}

		$this->normalize_customer_address( $customer, 'billing' );
		$this->normalize_customer_address( $customer, 'shipping' );
	}

	public function validate_and_normalize_store_api_order( $order, $request = null ) {
		unset( $request );

		if ( $this->should_defer_checkout_address_fields() || ! $order instanceof WC_Order ) {
			return;
		}

		$this->validate_and_normalize_order_address( $order, 'billing' );
		$this->validate_and_normalize_order_address( $order, 'shipping' );
	}

	private function normalize_customer_address( $customer, $address_type ) {
		$country_getter = 'get_' . $address_type . '_country';
		$country_setter = 'set_' . $address_type . '_country';

		if ( ! is_callable( [ $customer, $country_getter ] ) ) {
			return;
		}

		if ( 'shipping' === $address_type && ! $this->address_has_values( $customer, $address_type ) ) {
			return;
		}

		$country = $this->get_effective_country( $customer->{$country_getter}( 'edit' ) );

		if ( self::COUNTRY_CODE !== $country ) {
			return;
		}

		if ( is_callable( [ $customer, $country_setter ] ) ) {
			$customer->{$country_setter}( self::COUNTRY_CODE );
		}

		$this->normalize_address_object_values( $customer, $address_type );
	}

	private function validate_and_normalize_order_address( $order, $address_type ) {
		$country_getter = 'get_' . $address_type . '_country';
		$country_setter = 'set_' . $address_type . '_country';
		$state_getter   = 'get_' . $address_type . '_state';
		$city_getter    = 'get_' . $address_type . '_city';

		if ( ! is_callable( [ $order, $country_getter ] ) || ! is_callable( [ $order, $state_getter ] ) || ! is_callable( [ $order, $city_getter ] ) ) {
			return;
		}

		// A virtual order can legitimately have no shipping address.
		if ( 'shipping' === $address_type && ! $this->address_has_values( $order, $address_type ) ) {
			return;
		}

		$country = $this->get_effective_country( $order->{$country_getter}( 'edit' ) );

		if ( self::COUNTRY_CODE !== $country ) {
			return;
		}

		$state = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $order->{$state_getter}( 'edit' ) );
		$city  = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $order->{$city_getter}( 'edit' ) );

		if ( ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $state ) ) {
			$this->throw_store_api_validation_error(
				'yoohw_vietnam_store_tools_invalid_' . $address_type . '_state',
				__( 'Please select a valid city / province.', 'yoohw-vietnam-store-tools' ),
				$address_type . '_state'
			);
		}

		if ( ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $city, $state ) ) {
			$this->throw_store_api_validation_error(
				'yoohw_vietnam_store_tools_invalid_' . $address_type . '_city',
				__( 'Please select a valid ward / commune for the selected city / province.', 'yoohw-vietnam-store-tools' ),
				$address_type . '_city'
			);
		}

		if ( is_callable( [ $order, $country_setter ] ) ) {
			$order->{$country_setter}( self::COUNTRY_CODE );
		}

		$this->normalize_address_object_values( $order, $address_type );
	}

	private function normalize_address_object_values( $object, $address_type ) {
		$state_getter      = 'get_' . $address_type . '_state';
		$state_setter      = 'set_' . $address_type . '_state';
		$city_getter       = 'get_' . $address_type . '_city';
		$city_setter       = 'set_' . $address_type . '_city';
		$postcode_setter   = 'set_' . $address_type . '_postcode';
		$first_name_getter = 'get_' . $address_type . '_first_name';
		$first_name_setter = 'set_' . $address_type . '_first_name';
		$last_name_getter  = 'get_' . $address_type . '_last_name';
		$last_name_setter  = 'set_' . $address_type . '_last_name';

		if ( is_callable( [ $object, $state_getter ] ) && is_callable( [ $object, $state_setter ] ) ) {
			$object->{$state_setter}( Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $object->{$state_getter}( 'edit' ) ) );
		}

		if ( is_callable( [ $object, $city_getter ] ) && is_callable( [ $object, $city_setter ] ) ) {
			$object->{$city_setter}( Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $object->{$city_getter}( 'edit' ) ) );
		}

		if ( is_callable( [ $object, $postcode_setter ] ) ) {
			$object->{$postcode_setter}( '' );
		}

		if ( is_callable( [ $object, $first_name_getter ] ) && is_callable( [ $object, $last_name_getter ] ) && is_callable( [ $object, $first_name_setter ] ) && is_callable( [ $object, $last_name_setter ] ) ) {
			$object->{$first_name_setter}( $this->combine_full_name( $object->{$first_name_getter}( 'edit' ), $object->{$last_name_getter}( 'edit' ) ) );
			$object->{$last_name_setter}( '' );
		}
	}

	private function throw_store_api_validation_error( $code, $message, $field ) {
		$exception_class = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

		if ( class_exists( $exception_class ) ) {
			throw new $exception_class( $code, $message, 400, [ 'field' => $field ] );
		}

		throw new Exception( esc_html( $message ) );
	}

	private function get_effective_country( $country ) {
		$single_country = $this->get_single_allowed_country();

		return '' !== $single_country ? $single_country : wc_clean( (string) $country );
	}

	private function get_single_allowed_country() {
		if ( 'specific' !== get_option( 'woocommerce_allowed_countries' ) ) {
			return '';
		}

		$countries = get_option( 'woocommerce_specific_allowed_countries', [] );
		$countries = is_array( $countries ) ? $countries : [];
		$countries = array_values( array_unique( array_filter( array_map( 'wc_clean', $countries ) ) ) );

		return 1 === count( $countries ) ? $countries[0] : '';
	}

	private function combine_full_name( $first_name, $last_name ) {
		$first_name = trim( wc_clean( (string) $first_name ) );
		$last_name  = trim( wc_clean( (string) $last_name ) );

		if ( '' === $first_name ) {
			return $last_name;
		}

		if ( '' === $last_name || preg_match( '/(?:^|\s)' . preg_quote( $last_name, '/' ) . '$/u', $first_name ) ) {
			return $first_name;
		}

		return $first_name . ' ' . $last_name;
	}

	private function address_has_values( $object, $address_type ) {
		$fields = [ 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone' ];

		foreach ( $fields as $field ) {
			$getter = 'get_' . $address_type . '_' . $field;

			if ( is_callable( [ $object, $getter ] ) && '' !== trim( (string) $object->{$getter}( 'edit' ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function should_defer_checkout_address_fields() {
		return class_exists( 'Yoohw_Vietnam_Store_Tools_Legacy_Plugin_Guard' ) && Yoohw_Vietnam_Store_Tools_Legacy_Plugin_Guard::should_defer_checkout_fields();
	}

	private static function is_default_block_page( $page ) {
		$class = '\\Automattic\\WooCommerce\\Blocks\\Utils\\CartCheckoutUtils';
		$method = 'is_' . $page . '_block_default';

		return class_exists( $class ) && is_callable( [ $class, $method ] ) && call_user_func( [ $class, $method ] );
	}
}
