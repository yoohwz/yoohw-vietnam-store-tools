<?php
/**
 * WooCommerce Vietnam address fields.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Address_Fields {

	const COUNTRY_CODE = 'VN';

	public function __construct() {
		add_filter( 'woocommerce_states', [ $this, 'add_vietnam_provinces' ] );
		add_filter( 'woocommerce_get_country_locale', [ $this, 'add_vietnam_locale' ] );
		add_filter( 'woocommerce_get_country_locale_default', [ $this, 'add_default_contact_locale' ] );
		add_filter( 'woocommerce_billing_fields', [ $this, 'prepare_billing_fields' ], 20, 2 );
		add_filter( 'woocommerce_shipping_fields', [ $this, 'prepare_shipping_fields' ], 20, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout_fields' ], 10, 2 );
		add_action( 'woocommerce_after_save_address_validation', [ $this, 'validate_account_address_fields' ], 10, 4 );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'normalize_order_address_values' ], 10, 2 );
		add_action( 'woocommerce_checkout_update_customer', [ $this, 'normalize_customer_address_values' ], 10, 2 );
		add_filter( 'woocommerce_cart_calculate_shipping_address', [ $this, 'normalize_cart_shipping_calculator_address' ] );
		add_filter( 'woocommerce_localisation_address_formats', [ $this, 'add_vietnam_address_format' ] );
		add_filter( 'woocommerce_formatted_address_replacements', [ $this, 'format_vietnam_address_replacements' ], 10, 2 );
		add_filter( 'woocommerce_process_checkout_field_billing_state', [ $this, 'normalize_billing_province_field_value' ] );
		add_filter( 'woocommerce_process_checkout_field_shipping_state', [ $this, 'normalize_shipping_province_field_value' ] );
		add_filter( 'woocommerce_process_checkout_field_billing_city', [ $this, 'normalize_billing_ward_field_value' ] );
		add_filter( 'woocommerce_process_checkout_field_shipping_city', [ $this, 'normalize_shipping_ward_field_value' ] );
		add_filter( 'woocommerce_process_checkout_field_billing_last_name', [ $this, 'clear_last_name_field_value' ] );
		add_filter( 'woocommerce_process_checkout_field_shipping_last_name', [ $this, 'clear_last_name_field_value' ] );
		add_filter( 'woocommerce_process_myaccount_field_billing_state', [ $this, 'normalize_billing_province_field_value' ] );
		add_filter( 'woocommerce_process_myaccount_field_shipping_state', [ $this, 'normalize_shipping_province_field_value' ] );
		add_filter( 'woocommerce_process_myaccount_field_billing_city', [ $this, 'normalize_billing_ward_field_value' ] );
		add_filter( 'woocommerce_process_myaccount_field_shipping_city', [ $this, 'normalize_shipping_ward_field_value' ] );
		add_filter( 'woocommerce_process_myaccount_field_billing_last_name', [ $this, 'clear_last_name_field_value' ] );
		add_filter( 'woocommerce_process_myaccount_field_shipping_last_name', [ $this, 'clear_last_name_field_value' ] );
		add_filter( 'woocommerce_process_checkout_field_billing_country', [ $this, 'normalize_single_country_field_value' ] );
		add_filter( 'woocommerce_process_checkout_field_shipping_country', [ $this, 'normalize_single_country_field_value' ] );
		add_filter( 'woocommerce_process_myaccount_field_billing_country', [ $this, 'normalize_single_country_field_value' ] );
		add_filter( 'woocommerce_process_myaccount_field_shipping_country', [ $this, 'normalize_single_country_field_value' ] );
		add_filter( 'woocommerce_checkout_get_value', [ $this, 'get_frontend_name_checkout_value' ], 9, 2 );
		add_filter( 'woocommerce_checkout_get_value', [ $this, 'get_single_country_checkout_value' ], 10, 2 );
		add_filter( 'woocommerce_my_account_edit_address_field_value', [ $this, 'get_frontend_name_account_field_value' ], 9, 3 );
		add_filter( 'woocommerce_my_account_edit_address_field_value', [ $this, 'get_single_country_account_field_value' ], 10, 3 );
		add_action( 'wp_ajax_yoohw_vietnam_store_tools_wards', [ $this, 'ajax_get_wards' ] );
		add_action( 'wp_ajax_nopriv_yoohw_vietnam_store_tools_wards', [ $this, 'ajax_get_wards' ] );
	}

	public function add_vietnam_provinces( $states ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $states;
		}

		$states[ self::COUNTRY_CODE ] = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_provinces();

		return $states;
	}

	public function add_vietnam_locale( $locale ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $locale;
		}

		$locale[ self::COUNTRY_CODE ] = isset( $locale[ self::COUNTRY_CODE ] ) ? $locale[ self::COUNTRY_CODE ] : [];

		$locale[ self::COUNTRY_CODE ]['state'] = [
			'label'       => __( 'City / Province', 'yoohw-vietnam-store-tools' ),
			'placeholder' => __( 'Select a city / province', 'yoohw-vietnam-store-tools' ),
			'required'    => true,
			'hidden'      => false,
			'priority'    => 45,
			'class'       => [ 'form-row-first', 'address-field', 'update_totals_on_change' ],
		];

		$locale[ self::COUNTRY_CODE ]['city'] = [
			'label'       => __( 'Ward / Commune', 'yoohw-vietnam-store-tools' ),
			'placeholder' => __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' ),
			'required'    => true,
			'hidden'      => false,
			'priority'    => 46,
			'class'       => [ 'form-row-last', 'address-field', 'update_totals_on_change' ],
		];

		$locale[ self::COUNTRY_CODE ]['phone'] = [
			'priority' => 35,
			'class'    => [ 'form-row-first' ],
		];

		$locale[ self::COUNTRY_CODE ]['address_2'] = [
			'required' => false,
			'hidden'   => false,
			'priority' => 55,
		];

		$locale[ self::COUNTRY_CODE ]['postcode'] = [
			'required' => false,
			'hidden'   => true,
			'priority' => 90,
		];

		return $locale;
	}

	public function add_default_contact_locale( $locale ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $locale;
		}

		if ( isset( $locale['phone'] ) ) {
			$locale['phone']['priority'] = 35;
			$locale['phone']['class']    = [ 'form-row-first' ];
		}

		return $locale;
	}

	public function prepare_billing_fields( $fields, $country ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $fields;
		}

		return $this->prepare_address_fields( $fields, $this->get_effective_country( $country ), 'billing' );
	}

	public function prepare_shipping_fields( $fields, $country ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $fields;
		}

		return $this->prepare_address_fields( $fields, $this->get_effective_country( $country ), 'shipping' );
	}

	public function enqueue_scripts() {
		if ( $this->should_defer_checkout_address_fields() ) {
			return;
		}

		if ( ! $this->should_enqueue_frontend_address_script() ) {
			return;
		}

		$handle = 'yoohw-vietnam-store-tools-address-fields';
		$selected = $this->get_frontend_selected_values();

		wp_enqueue_script(
			$handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/frontend/address-fields.js',
			[ 'jquery', 'wc-country-select', 'wc-address-i18n' ],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION,
			true
		);

		$inline_styles = [
			'#billing_company_field,#shipping_company_field,.vck-hidden-company-field{display:none!important;}',
			'#billing_last_name_field,#shipping_last_name_field,.vck-hidden-last-name-field{display:none!important;}',
		];

		if ( $this->should_hide_frontend_country_field() ) {
			$inline_styles[] = '#billing_country_field,#shipping_country_field,#calc_shipping_country_field,.vck-hidden-country-field{display:none!important;}';
		}

		if ( ! empty( $inline_styles ) ) {
			$style_handle = $handle . '-style';

			wp_register_style( $style_handle, false, [], YOOHW_VIETNAM_STORE_TOOLS_VERSION );
			wp_enqueue_style( $style_handle );
			wp_add_inline_style( $style_handle, implode( "\n", $inline_styles ) );
		}

		wp_localize_script(
			$handle,
			'yoohwVietnamStoreToolsAddressFields',
			[
				'country'           => self::COUNTRY_CODE,
				'singleCountry'     => $this->get_single_allowed_country(),
				'hideCountry'       => $this->should_hide_frontend_country_field(),
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'wardsNonce'        => wp_create_nonce( 'yoohw_vietnam_store_tools_wards' ),
				'addressTypes'      => $this->get_frontend_address_types(),
				'lazyLoadWards'     => $this->should_lazy_load_frontend_wards(),
				'wardsCacheVersion' => YOOHW_VIETNAM_STORE_TOOLS_VERSION,
				'wardsCacheTtl'     => defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800,
				'wards'             => $this->get_frontend_preloaded_wards( $selected ),
				'selected'          => $selected,
				'i18n'              => [
					'selectWard'          => __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' ),
					'selectProvinceFirst' => __( 'Select a city / province first', 'yoohw-vietnam-store-tools' ),
					'loadingWards'        => __( 'Loading ward / commune list...', 'yoohw-vietnam-store-tools' ),
					'optional'            => __( 'optional', 'yoohw-vietnam-store-tools' ),
				],
			]
		);
	}

	public function ajax_get_wards() {
		check_ajax_referer( 'yoohw_vietnam_store_tools_wards', 'nonce' );

		$state = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'state' );
		$state = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $state );

		if ( '' === $state || ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $state ) ) {
			wp_send_json_success(
				[
					'state' => $state,
					'wards' => [],
				]
			);
		}

		wp_send_json_success(
			[
				'state' => $state,
				'wards' => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( $state ),
			]
		);
	}

	public function validate_checkout_fields( $data, $errors ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return;
		}

		$this->validate_address_values( $data, 'billing', $errors );

		if ( ! empty( $data['ship_to_different_address'] ) ) {
			$this->validate_address_values( $data, 'shipping', $errors );
		}
	}

	public function normalize_cart_shipping_calculator_address( $address ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $address;
		}

		$single_country = $this->get_single_allowed_country();

		if ( '' !== $single_country ) {
			$address['country'] = $single_country;
		}

		if ( empty( $address['country'] ) || self::COUNTRY_CODE !== $address['country'] ) {
			return $address;
		}

		$address['state']    = $this->normalize_province_field_value( isset( $address['state'] ) ? $address['state'] : '' );
		$address['city']     = $this->normalize_ward_field_value( isset( $address['city'] ) ? $address['city'] : '' );
		$address['postcode'] = '';

		if ( ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $address['state'] ) ) {
			throw new Exception( esc_html__( 'Please select a valid city / province.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $address['city'], $address['state'] ) ) {
			throw new Exception( esc_html__( 'Please select a valid ward / commune for the selected city / province.', 'yoohw-vietnam-store-tools' ) );
		}

		return $address;
	}

	public function validate_account_address_fields( $user_id, $address_type, $address, $customer ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return;
		}

		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::verify_account_address_request() ) {
			return;
		}

		$country_key = $address_type . '_country';
		$state_key   = $address_type . '_state';
		$ward_key    = $address_type . '_city';

		$country = $this->get_effective_country( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $country_key ) );

		if ( self::COUNTRY_CODE !== $country ) {
			return;
		}

		$state = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $state_key );
		$ward  = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $ward_key );

		if ( ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $state ) ) {
			wc_add_notice( __( 'Please select a valid city / province.', 'yoohw-vietnam-store-tools' ), 'error', [ 'id' => $state_key ] );
		}

		if ( ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward, $state ) ) {
			wc_add_notice( __( 'Please select a valid ward / commune for the selected city / province.', 'yoohw-vietnam-store-tools' ), 'error', [ 'id' => $ward_key ] );
		}
	}

	public function add_vietnam_address_format( $formats ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $formats;
		}

		$formats[ self::COUNTRY_CODE ] = "{name}\n{company}\n{address_1}\n{address_2}\n{city}\n{state}\n{country}";

		return $formats;
	}

	public function format_vietnam_address_replacements( $replacements, $args ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $replacements;
		}

		if ( empty( $args['country'] ) || self::COUNTRY_CODE !== $args['country'] ) {
			return $replacements;
		}

		if ( ! empty( $args['state'] ) ) {
			$replacements['{state}']       = esc_html( Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_province_name( $args['state'] ) );
			$replacements['{state_upper}'] = wc_strtoupper( $replacements['{state}'] );
		}

		if ( ! empty( $args['city'] ) ) {
			$replacements['{city}']       = esc_html( Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_ward_name( $args['city'], $args['state'] ) );
			$replacements['{city_upper}'] = wc_strtoupper( $replacements['{city}'] );
		}

		return $replacements;
	}

	private function prepare_address_fields( $fields, $country, $address_type ) {
		$fields = $this->prepare_name_fields( $fields, $address_type );
		$fields = $this->prepare_company_field( $fields, $address_type );
		$fields = $this->prepare_contact_fields( $fields, $address_type );
		$fields = $this->prepare_single_country_field( $fields, $address_type );

		if ( self::COUNTRY_CODE !== $country ) {
			return $fields;
		}

		$state_key    = $address_type . '_state';
		$ward_key     = $address_type . '_city';
		$postcode_key = $address_type . '_postcode';

		if ( isset( $fields[ $state_key ] ) ) {
			$fields[ $state_key ]['label']       = __( 'City / Province', 'yoohw-vietnam-store-tools' );
			$fields[ $state_key ]['placeholder'] = __( 'Select a city / province', 'yoohw-vietnam-store-tools' );
			$fields[ $state_key ]['required']    = true;
			$fields[ $state_key ]['priority']    = 45;
			$fields[ $state_key ]['country']     = self::COUNTRY_CODE;
			$fields[ $state_key ]['class']       = $this->set_field_row_class(
				isset( $fields[ $state_key ]['class'] ) ? $fields[ $state_key ]['class'] : [],
				'form-row-first',
				[ 'address-field', 'update_totals_on_change' ]
			);
		}

		$state = $this->get_current_field_value( $state_key, $address_type );
		$ward  = $this->get_current_field_value( $ward_key, $address_type );
		$wards = $this->get_frontend_field_ward_options( $state, $ward, $address_type );

		$fields[ $ward_key ] = wp_parse_args(
			isset( $fields[ $ward_key ] ) ? $fields[ $ward_key ] : [],
			[
				'type'         => 'select',
				'label'        => __( 'Ward / Commune', 'yoohw-vietnam-store-tools' ),
				'placeholder'  => __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' ),
				'required'     => true,
				'class'        => [ 'form-row-last', 'address-field', 'update_totals_on_change', 'vck-ward-field' ],
				'input_class'  => [ 'vck-ward-select' ],
				'autocomplete' => 'section-' . $address_type . ' ' . $address_type . ' address-level2',
				'priority'     => 46,
				'options'      => [],
			]
		);

		$fields[ $ward_key ]['type']         = 'select';
		$fields[ $ward_key ]['label']        = __( 'Ward / Commune', 'yoohw-vietnam-store-tools' );
		$fields[ $ward_key ]['placeholder']  = __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' );
		$fields[ $ward_key ]['required']     = true;
		$fields[ $ward_key ]['priority']     = 46;
		$fields[ $ward_key ]['options']      = [ '' => __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' ) ] + $wards;
		$fields[ $ward_key ]['default']      = $ward;
		$fields[ $ward_key ]['label_class']  = [];
		$fields[ $ward_key ]['class']        = $this->set_field_row_class( $fields[ $ward_key ]['class'], 'form-row-last', [ 'address-field', 'update_totals_on_change', 'vck-ward-field' ] );
		$fields[ $ward_key ]['input_class']  = $this->append_field_classes( $fields[ $ward_key ]['input_class'], [ 'vck-ward-select' ] );
		$fields[ $ward_key ]['custom_attributes'] = isset( $fields[ $ward_key ]['custom_attributes'] ) && is_array( $fields[ $ward_key ]['custom_attributes'] ) ? $fields[ $ward_key ]['custom_attributes'] : [];
		$fields[ $ward_key ]['custom_attributes']['data-selected-value'] = $ward;

		if ( isset( $fields[ $postcode_key ] ) ) {
			$fields[ $postcode_key ]['type']     = 'hidden';
			$fields[ $postcode_key ]['label']    = '';
			$fields[ $postcode_key ]['required'] = false;
			$fields[ $postcode_key ]['validate'] = [];
			$fields[ $postcode_key ]['class']    = [ 'hidden' ];
		}

		return $fields;
	}

	private function validate_address_values( $data, $address_type, $errors ) {
		$country = $this->get_effective_country( isset( $data[ $address_type . '_country' ] ) ? $data[ $address_type . '_country' ] : '' );

		if ( self::COUNTRY_CODE !== $country ) {
			return;
		}

		$state = isset( $data[ $address_type . '_state' ] ) ? $data[ $address_type . '_state' ] : '';
		$ward  = isset( $data[ $address_type . '_city' ] ) ? $data[ $address_type . '_city' ] : '';

		if ( ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $state ) ) {
			$errors->add(
				$address_type . '_state_validation',
				__( 'Please select a valid city / province.', 'yoohw-vietnam-store-tools' ),
				[ 'id' => $address_type . '_state' ]
			);
		}

		if ( ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward, $state ) ) {
			$errors->add(
				$address_type . '_city_validation',
				__( 'Please select a valid ward / commune for the selected city / province.', 'yoohw-vietnam-store-tools' ),
				[ 'id' => $address_type . '_city' ]
			);
		}
	}

	public function normalize_order_address_values( $order, $data ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( self::COUNTRY_CODE === $order->get_billing_country() ) {
			$order->set_billing_state( $this->normalize_province_field_value( $order->get_billing_state() ) );
			$order->set_billing_city( $this->normalize_ward_field_value( $order->get_billing_city() ) );
		}

		if ( self::COUNTRY_CODE === $order->get_shipping_country() ) {
			$order->set_shipping_state( $this->normalize_province_field_value( $order->get_shipping_state() ) );
			$order->set_shipping_city( $this->normalize_ward_field_value( $order->get_shipping_city() ) );
		}
	}

	public function normalize_customer_address_values( $customer, $data ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return;
		}

		if ( ! $customer instanceof WC_Customer ) {
			return;
		}

		if ( self::COUNTRY_CODE === $customer->get_billing_country() ) {
			$customer->set_billing_state( $this->normalize_province_field_value( $customer->get_billing_state() ) );
			$customer->set_billing_city( $this->normalize_ward_field_value( $customer->get_billing_city() ) );
		}

		if ( self::COUNTRY_CODE === $customer->get_shipping_country() ) {
			$customer->set_shipping_state( $this->normalize_province_field_value( $customer->get_shipping_state() ) );
			$customer->set_shipping_city( $this->normalize_ward_field_value( $customer->get_shipping_city() ) );
		}
	}

	public function normalize_ward_field_value( $value ) {
		return Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $value );
	}

	public function normalize_province_field_value( $value ) {
		return Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $value );
	}

	public function normalize_billing_ward_field_value( $value ) {
		return $this->normalize_posted_vietnam_address_field( $value, 'billing', 'ward' );
	}

	public function normalize_shipping_ward_field_value( $value ) {
		return $this->normalize_posted_vietnam_address_field( $value, 'shipping', 'ward' );
	}

	public function normalize_billing_province_field_value( $value ) {
		return $this->normalize_posted_vietnam_address_field( $value, 'billing', 'province' );
	}

	public function normalize_shipping_province_field_value( $value ) {
		return $this->normalize_posted_vietnam_address_field( $value, 'shipping', 'province' );
	}

	public function clear_last_name_field_value( $value ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $value;
		}

		return '';
	}

	public function get_frontend_name_checkout_value( $value, $input ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $value;
		}

		if ( in_array( $input, [ 'billing_last_name', 'shipping_last_name' ], true ) ) {
			return '';
		}

		if ( ! in_array( $input, [ 'billing_first_name', 'shipping_first_name' ], true ) ) {
			return $value;
		}

		$address_type = 0 === strpos( $input, 'shipping_' ) ? 'shipping' : 'billing';
		$first_name   = is_null( $value ) ? $this->get_customer_name_field_value( $address_type, 'first_name' ) : $value;
		$last_name    = $this->get_customer_name_field_value( $address_type, 'last_name' );
		$full_name    = $this->combine_full_name( $first_name, $last_name );

		return '' !== $full_name ? $full_name : $value;
	}

	public function get_frontend_name_account_field_value( $value, $key, $address_type ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $value;
		}

		if ( in_array( $key, [ 'billing_last_name', 'shipping_last_name' ], true ) ) {
			return '';
		}

		if ( ! in_array( $key, [ 'billing_first_name', 'shipping_first_name' ], true ) ) {
			return $value;
		}

		$last_name = get_user_meta( get_current_user_id(), $address_type . '_last_name', true );

		return $this->combine_full_name( $value, $last_name );
	}

	public function normalize_single_country_field_value( $value ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $value;
		}

		$single_country = $this->get_single_allowed_country();

		return '' !== $single_country ? $single_country : $value;
	}

	public function get_single_country_checkout_value( $value, $input ) {
		if ( ! in_array( $input, [ 'billing_country', 'shipping_country' ], true ) ) {
			return $value;
		}

		return $this->normalize_single_country_field_value( $value );
	}

	public function get_single_country_account_field_value( $value, $key, $address_type ) {
		if ( ! in_array( $key, [ 'billing_country', 'shipping_country' ], true ) ) {
			return $value;
		}

		return $this->normalize_single_country_field_value( $value );
	}

	private function normalize_posted_vietnam_address_field( $value, $address_type, $field_type ) {
		if ( $this->should_defer_checkout_address_fields() ) {
			return $value;
		}

		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::verify_checkout_or_account_address_request() ) {
			return $value;
		}

		$country_key = $address_type . '_country';
		$country     = $this->get_effective_country( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $country_key ) );

		if ( self::COUNTRY_CODE !== $country ) {
			return $value;
		}

		return 'province' === $field_type ? $this->normalize_province_field_value( $value ) : $this->normalize_ward_field_value( $value );
	}

	private function prepare_name_fields( $fields, $address_type ) {
		$first_name_key = $address_type . '_first_name';
		$last_name_key  = $address_type . '_last_name';

		if ( isset( $fields[ $first_name_key ] ) ) {
			$row_class = 'shipping' === $address_type ? 'form-row-first' : 'form-row-wide';

			$fields[ $first_name_key ]['label']        = __( 'Full name', 'yoohw-vietnam-store-tools' );
			$fields[ $first_name_key ]['placeholder']  = '';
			$fields[ $first_name_key ]['required']     = true;
			$fields[ $first_name_key ]['autocomplete'] = 'section-' . $address_type . ' ' . $address_type . ' name';
			$fields[ $first_name_key ]['priority']     = 10;
			$fields[ $first_name_key ]['class']        = $this->set_field_row_class(
				isset( $fields[ $first_name_key ]['class'] ) ? $fields[ $first_name_key ]['class'] : [],
				$row_class
			);
		}

		if ( isset( $fields[ $last_name_key ] ) ) {
			$fields[ $last_name_key ]['type']     = 'hidden';
			$fields[ $last_name_key ]['label']    = '';
			$fields[ $last_name_key ]['required'] = false;
			$fields[ $last_name_key ]['validate'] = [];
			$fields[ $last_name_key ]['priority'] = 20;
			$fields[ $last_name_key ]['default']  = '';
			$fields[ $last_name_key ]['class']    = $this->append_field_classes(
				isset( $fields[ $last_name_key ]['class'] ) ? $fields[ $last_name_key ]['class'] : [],
				[ 'hidden', 'vck-hidden-last-name-field' ]
			);
		}

		return $fields;
	}

	private function prepare_company_field( $fields, $address_type ) {
		$company_key = $address_type . '_company';

		if ( ! isset( $fields[ $company_key ] ) ) {
			return $fields;
		}

		$fields[ $company_key ]['type']     = 'hidden';
		$fields[ $company_key ]['label']    = '';
		$fields[ $company_key ]['required'] = false;
		$fields[ $company_key ]['validate'] = [];
		$fields[ $company_key ]['class']    = $this->append_field_classes(
			isset( $fields[ $company_key ]['class'] ) ? $fields[ $company_key ]['class'] : [],
			[ 'hidden', 'vck-hidden-company-field' ]
		);

		return $fields;
	}

	private function prepare_contact_fields( $fields, $address_type ) {
		$phone_key = $address_type . '_phone';
		$email_key = $address_type . '_email';

		if ( isset( $fields[ $phone_key ] ) ) {
			$fields[ $phone_key ]['priority'] = 35;
			$fields[ $phone_key ]['class']    = $this->set_field_row_class(
				isset( $fields[ $phone_key ]['class'] ) ? $fields[ $phone_key ]['class'] : [],
				'form-row-first'
			);
		}

		if ( isset( $fields[ $email_key ] ) ) {
			$fields[ $email_key ]['priority'] = 36;
			$fields[ $email_key ]['class']    = $this->set_field_row_class(
				isset( $fields[ $email_key ]['class'] ) ? $fields[ $email_key ]['class'] : [],
				'form-row-last'
			);
		}

		return $fields;
	}

	private function prepare_single_country_field( $fields, $address_type ) {
		$single_country = $this->get_single_allowed_country();
		$country_key    = $address_type . '_country';

		if ( '' === $single_country || ! isset( $fields[ $country_key ] ) ) {
			return $fields;
		}

		$fields[ $country_key ]['default']      = $single_country;
		$fields[ $country_key ]['required']     = false;
		$fields[ $country_key ]['class']        = $this->append_field_classes( isset( $fields[ $country_key ]['class'] ) ? $fields[ $country_key ]['class'] : [], [ 'vck-hidden-country-field' ] );
		$fields[ $country_key ]['input_class']  = $this->append_field_classes( isset( $fields[ $country_key ]['input_class'] ) ? $fields[ $country_key ]['input_class'] : [], [ 'vck-single-country-select' ] );
		$fields[ $country_key ]['custom_attributes'] = isset( $fields[ $country_key ]['custom_attributes'] ) && is_array( $fields[ $country_key ]['custom_attributes'] ) ? $fields[ $country_key ]['custom_attributes'] : [];
		$fields[ $country_key ]['custom_attributes']['data-vck-single-country'] = $single_country;

		return $fields;
	}

	private function get_effective_country( $country ) {
		$single_country = $this->get_single_allowed_country();

		return '' !== $single_country ? $single_country : $country;
	}

	private function should_hide_frontend_country_field() {
		return '' !== $this->get_single_allowed_country();
	}

	private function should_enqueue_frontend_address_script() {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return $this->is_account_edit_address_request();
		}

		return false;
	}

	private function get_frontend_address_types() {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return [ 'calc_shipping' ];
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return [ 'billing', 'shipping' ];
		}

		return [ 'billing', 'shipping', 'calc_shipping' ];
	}

	private function should_lazy_load_frontend_wards() {
		return function_exists( 'is_cart' ) && is_cart();
	}

	private function is_account_edit_address_request() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' ) ) {
			return true;
		}

		if ( function_exists( 'WC' ) && WC()->query ) {
			$value = WC()->query->get_current_endpoint();

			if ( 'edit-address' === $value ) {
				return true;
			}
		}

		return false;
	}

	private function get_frontend_field_ward_options( $state, $ward, $address_type ) {
		$state = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $state );
		$ward  = $this->normalize_ward_field_value( $ward );

		if ( '' === $state || ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $state ) ) {
			return [];
		}

		if ( $this->should_preload_frontend_ward_list( $state, $ward, $address_type ) ) {
			return Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( $state );
		}

		if ( '' !== $ward && Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward, $state ) ) {
			return [
				$ward => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_ward_name( $ward, $state ),
			];
		}

		return [];
	}

	private function should_preload_frontend_ward_list( $state, $ward, $address_type ) {
		unset( $address_type );

		if ( '' === $state || ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $state ) ) {
			return false;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return false;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return '' !== $ward && Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward, $state );
		}

		return true;
	}

	private function get_frontend_selected_values() {
		return [
			'billing'       => [
				'state' => $this->get_current_field_value( 'billing_state', 'billing' ),
				'city'  => $this->get_current_field_value( 'billing_city', 'billing' ),
			],
			'shipping'      => [
				'state' => $this->get_current_field_value( 'shipping_state', 'shipping' ),
				'city'  => $this->get_current_field_value( 'shipping_city', 'shipping' ),
			],
			'calc_shipping' => [
				'country' => $this->get_current_calculator_field_value( 'country' ),
				'state'   => $this->get_current_calculator_field_value( 'state' ),
				'city'    => $this->get_current_calculator_field_value( 'city' ),
			],
		];
	}

	private function get_frontend_preloaded_wards( $selected ) {
		$states = [];

		foreach ( $this->get_frontend_address_types() as $address_type ) {
			if ( empty( $selected[ $address_type ]['state'] ) ) {
				continue;
			}

			$state = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $selected[ $address_type ]['state'] );
			$ward  = ! empty( $selected[ $address_type ]['city'] ) ? $this->normalize_ward_field_value( $selected[ $address_type ]['city'] ) : '';

			if ( $this->should_preload_frontend_ward_list( $state, $ward, $address_type ) ) {
				$states[] = $state;
			}
		}

		$states = array_values( array_unique( $states ) );
		$wards  = [];

		foreach ( $states as $state ) {
			if ( Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $state ) ) {
				$wards[ $state ] = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( $state );
			}
		}

		return $wards;
	}

	private function should_defer_checkout_address_fields() {
		return class_exists( 'Yoohw_Vietnam_Store_Tools_Legacy_Plugin_Guard' ) && Yoohw_Vietnam_Store_Tools_Legacy_Plugin_Guard::should_defer_checkout_fields();
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

	private function get_customer_name_field_value( $address_type, $field ) {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}

		$getter = 'get_' . $address_type . '_' . $field;

		if ( is_callable( [ WC()->customer, $getter ] ) ) {
			return wc_clean( WC()->customer->{$getter}() );
		}

		return '';
	}

	private function combine_full_name( $first_name, $last_name ) {
		$first_name = trim( wc_clean( (string) $first_name ) );
		$last_name  = trim( wc_clean( (string) $last_name ) );

		if ( '' === $first_name ) {
			return $last_name;
		}

		if ( '' === $last_name ) {
			return $first_name;
		}

		if ( preg_match( '/(?:^|\s)' . preg_quote( $last_name, '/' ) . '$/u', $first_name ) ) {
			return $first_name;
		}

		return $first_name . ' ' . $last_name;
	}

	private function get_current_field_value( $field_key, $address_type ) {
		if ( Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $field_key ) ) {
			return Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $field_key );
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() && is_user_logged_in() ) {
			$value = get_user_meta( get_current_user_id(), $field_key, true );

			if ( '' !== $value ) {
				return wc_clean( $value );
			}
		}

		if ( function_exists( 'WC' ) && WC()->checkout() ) {
			$value = WC()->checkout()->get_value( $field_key );

			if ( '' !== $value ) {
				return wc_clean( $value );
			}
		}

		if ( function_exists( 'WC' ) && WC()->customer ) {
			$field  = preg_replace( '/^' . preg_quote( $address_type, '/' ) . '_/', '', $field_key );
			$getter = 'get_' . $address_type . '_' . $field;

			if ( is_callable( [ WC()->customer, $getter ] ) ) {
				return wc_clean( WC()->customer->{$getter}() );
			}
		}

		return '';
	}

	private function get_current_calculator_field_value( $field ) {
		$post_key = 'calc_shipping_' . $field;

		if ( Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $post_key ) ) {
			return Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $post_key );
		}

		if ( function_exists( 'WC' ) && WC()->customer ) {
			$getter = 'get_shipping_' . $field;

			if ( is_callable( [ WC()->customer, $getter ] ) ) {
				return wc_clean( WC()->customer->{$getter}() );
			}
		}

		return '';
	}

	private function set_field_row_class( $classes, $row_class, $append = [] ) {
		$classes = is_array( $classes ) ? $classes : [ $classes ];
		$classes = array_diff( $classes, [ 'form-row-first', 'form-row-last', 'form-row-wide' ] );

		return $this->append_field_classes( $classes, array_merge( [ $row_class ], $append ) );
	}

	private function append_field_classes( $classes, $append ) {
		$classes = is_array( $classes ) ? $classes : [ $classes ];

		foreach ( $append as $class ) {
			if ( ! in_array( $class, $classes, true ) ) {
				$classes[] = $class;
			}
		}

		return array_filter( $classes );
	}
}
