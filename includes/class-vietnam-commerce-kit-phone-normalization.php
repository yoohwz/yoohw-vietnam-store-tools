<?php
/**
 * WooCommerce Vietnam phone number normalization.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Phone_Normalization {

	const COUNTRY_CODE = 'VN';
	const PHONE_PLACEHOLDER = '0987654321';

	private static $mobile_carrier_prefixes = [
		'Viettel'       => [ '032', '033', '034', '035', '036', '037', '038', '039', '086', '096', '097', '098' ],
		'VinaPhone'    => [ '081', '082', '083', '084', '085', '088', '091', '094' ],
		'MobiFone'     => [ '070', '071', '072', '073', '074', '075', '076', '077', '078', '079', '089', '090', '093' ],
		'Vietnamobile' => [ '052', '056', '058', '092' ],
		'Gmobile'      => [ '059', '099' ],
		'iTel'         => [ '087' ],
		'Wintel'       => [ '055' ],
	];

	public function __construct() {
		add_filter( 'woocommerce_billing_fields', [ $this, 'prepare_billing_phone_field' ], 30, 2 );
		add_filter( 'woocommerce_shipping_fields', [ $this, 'prepare_shipping_phone_field' ], 30, 2 );
		add_filter( 'woocommerce_process_checkout_field_billing_phone', [ $this, 'normalize_billing_checkout_phone' ] );
		add_filter( 'woocommerce_process_checkout_field_shipping_phone', [ $this, 'normalize_shipping_checkout_phone' ] );
		add_filter( 'woocommerce_process_myaccount_field_billing_phone', [ $this, 'normalize_billing_account_phone' ] );
		add_filter( 'woocommerce_process_myaccount_field_shipping_phone', [ $this, 'normalize_shipping_account_phone' ] );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout_phone_fields' ], 30, 2 );
		add_action( 'woocommerce_after_save_address_validation', [ $this, 'validate_account_phone_fields' ], 30, 4 );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'normalize_order_phone_fields' ], 30, 2 );
		add_action( 'woocommerce_checkout_update_customer', [ $this, 'normalize_checkout_customer_phone_fields' ], 30, 2 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', [ $this, 'normalize_store_api_customer_phone_fields' ], 30, 2 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ $this, 'normalize_store_api_customer_phone_fields' ], 30, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'validate_and_normalize_store_api_order_phone_fields' ], 40, 2 );
		add_action( 'woocommerce_customer_save_address', [ $this, 'normalize_saved_customer_address_phone_fields' ], 30, 4 );
		add_filter( 'woocommerce_admin_billing_fields', [ $this, 'prepare_admin_billing_phone_field' ], 30, 3 );
		add_filter( 'woocommerce_admin_shipping_fields', [ $this, 'prepare_admin_shipping_phone_field' ], 30, 3 );
		add_filter( 'woocommerce_shop_order_search_fields', [ $this, 'add_order_phone_search_fields' ] );
		add_filter( 'woocommerce_order_table_search_query_meta_keys', [ $this, 'add_order_phone_search_fields' ] );
	}

	public function prepare_billing_phone_field( $fields, $country ) {
		return $this->prepare_frontend_phone_field( $fields, $this->get_effective_country( $country ), 'billing' );
	}

	public function prepare_shipping_phone_field( $fields, $country ) {
		return $this->prepare_frontend_phone_field( $fields, $this->get_effective_country( $country ), 'shipping' );
	}

	public function prepare_admin_billing_phone_field( $fields, $order = false, $context = 'edit' ) {
		return $this->prepare_admin_phone_field( $fields, 'billing' );
	}

	public function prepare_admin_shipping_phone_field( $fields, $order = false, $context = 'edit' ) {
		return $this->prepare_admin_phone_field( $fields, 'shipping' );
	}

	public function normalize_billing_checkout_phone( $value ) {
		return $this->normalize_posted_phone_field_value( $value, 'billing' );
	}

	public function normalize_shipping_checkout_phone( $value ) {
		return $this->normalize_posted_phone_field_value( $value, 'shipping' );
	}

	public function normalize_billing_account_phone( $value ) {
		return $this->normalize_posted_phone_field_value( $value, 'billing' );
	}

	public function normalize_shipping_account_phone( $value ) {
		return $this->normalize_posted_phone_field_value( $value, 'shipping' );
	}

	public function validate_checkout_phone_fields( $data, $errors ) {
		$this->validate_checkout_phone_field( $data, $errors, 'billing' );

		if ( ! empty( $data['ship_to_different_address'] ) ) {
			$this->validate_checkout_phone_field( $data, $errors, 'shipping' );
		}
	}

	public function validate_account_phone_fields( $user_id, $address_type, $address, $customer ) {
		if ( ! in_array( $address_type, [ 'billing', 'shipping' ], true ) ) {
			return;
		}

		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::verify_account_address_request() ) {
			return;
		}

		$phone_key = $address_type . '_phone';

		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $phone_key ) ) {
			return;
		}

		$phone   = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $phone_key );
		$country = $this->get_effective_country( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $address_type . '_country' ) );

		if ( '' === $phone || ! $this->should_process_phone( $country, $phone ) ) {
			return;
		}

		$normalized = self::normalize_phone_number( $phone, $country );

		if ( ! $normalized['valid'] ) {
			wc_add_notice( __( 'Please enter a valid Vietnamese phone number.', 'yoohw-vietnam-store-tools' ), 'error', [ 'id' => $phone_key ] );
		}
	}

	public function normalize_order_phone_fields( $order, $data ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->normalize_order_phone_field( $order, 'billing' );
		$this->normalize_order_phone_field( $order, 'shipping' );
	}

	public function normalize_checkout_customer_phone_fields( $customer, $data ) {
		if ( ! $customer instanceof WC_Customer ) {
			return;
		}

		$this->normalize_customer_object_phone_field( $customer, 'billing' );
		$this->normalize_customer_object_phone_field( $customer, 'shipping' );
	}

	public function normalize_store_api_customer_phone_fields( $customer, $request = null ) {
		unset( $request );

		if ( ! $customer instanceof WC_Customer ) {
			return;
		}

		$this->normalize_customer_object_phone_field( $customer, 'billing' );
		$this->normalize_customer_object_phone_field( $customer, 'shipping' );
	}

	public function validate_and_normalize_store_api_order_phone_fields( $order, $request = null ) {
		unset( $request );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->validate_and_normalize_store_api_order_phone_field( $order, 'billing' );
		$this->validate_and_normalize_store_api_order_phone_field( $order, 'shipping' );
	}

	public function normalize_saved_customer_address_phone_fields( $user_id, $address_type, $address = null, $customer = null ) {
		if ( ! in_array( $address_type, [ 'billing', 'shipping' ], true ) ) {
			return;
		}

		if ( $customer instanceof WC_Customer ) {
			$this->normalize_customer_object_phone_field( $customer, $address_type );
			$customer->save();
			return;
		}

		$phone   = get_user_meta( $user_id, $address_type . '_phone', true );
		$country = $this->get_effective_country( get_user_meta( $user_id, $address_type . '_country', true ) );

		$this->save_customer_phone_meta( $user_id, $address_type, $phone, $country );
	}

	public function update_admin_order_phone_field( $field_id, $value, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::verify_admin_order_request( $order ) ) {
			return;
		}

		$address_type = 0 === strpos( $field_id, '_shipping_' ) ? 'shipping' : 'billing';
		$country      = $this->get_effective_country( $this->get_posted_admin_field_value( $address_type, 'country' ) );
		$normalized   = self::normalize_phone_number( $value, $country );
		$phone_value   = $normalized['valid'] ? $normalized['national'] : self::sanitize_phone_storage_value( $value );
		$setter        = 'set_' . $address_type . '_phone';

		if ( is_callable( [ $order, $setter ] ) ) {
			$order->{$setter}( $phone_value );
		} else {
			$order->update_meta_data( '_' . $address_type . '_phone', $phone_value );
		}

		$this->save_order_phone_meta_from_result( $order, $address_type, $normalized );
	}

	public function add_order_phone_search_fields( $fields ) {
		$fields = is_array( $fields ) ? $fields : [];

		foreach ( [ '_billing_phone_e164', '_shipping_phone', '_shipping_phone_e164' ] as $field ) {
			if ( ! in_array( $field, $fields, true ) ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	public static function normalize_phone_number( $phone, $country = self::COUNTRY_CODE ) {
		$raw       = (string) $phone;
		$sanitized = self::sanitize_phone_storage_value( $raw );
		$national  = self::to_vietnam_national_number( $sanitized );

		$result = [
			'raw'                     => $raw,
			'sanitized'               => $sanitized,
			'national'                => '',
			'e164'                    => '',
			'type'                    => '',
			'carrier'                 => '',
			'original_prefix_carrier' => '',
			'valid'                   => false,
		];

		if ( '' === $national ) {
			return $result;
		}

		$type = self::get_vietnam_phone_type( $national );

		if ( '' === $type ) {
			return $result;
		}

		$result['national']                = $national;
		$result['e164']                    = '+84' . substr( $national, 1 );
		$result['type']                    = $type;
		// Prefix data identifies the original carrier allocation, not the current carrier after mobile-number portability.
		$result['original_prefix_carrier'] = 'mobile' === $type ? self::get_mobile_prefix_carrier( $national ) : '';
		// Backward-compatible alias retained for existing consumers and stored metadata.
		$result['carrier']                 = $result['original_prefix_carrier'];
		$result['valid']                   = true;

		return $result;
	}

	public static function sanitize_phone_storage_value( $phone ) {
		$phone = html_entity_decode( wp_strip_all_tags( (string) $phone ), ENT_QUOTES, 'UTF-8' );
		$phone = trim( $phone );
		$phone = preg_replace( '/[^\d+]+/', '', $phone );
		$phone = preg_replace( '/(?!^)\+/', '', $phone );

		return (string) $phone;
	}

	private function prepare_frontend_phone_field( $fields, $country, $address_type ) {
		$phone_key = $address_type . '_phone';

		if ( ! isset( $fields[ $phone_key ] ) ) {
			return $fields;
		}

		$fields[ $phone_key ]['type']        = 'tel';
		$fields[ $phone_key ]['input_class'] = $this->append_field_classes( isset( $fields[ $phone_key ]['input_class'] ) ? $fields[ $phone_key ]['input_class'] : [], [ 'vck-vietnam-phone-field' ] );
		$fields[ $phone_key ]['custom_attributes'] = isset( $fields[ $phone_key ]['custom_attributes'] ) && is_array( $fields[ $phone_key ]['custom_attributes'] ) ? $fields[ $phone_key ]['custom_attributes'] : [];
		$fields[ $phone_key ]['custom_attributes']['inputmode'] = 'tel';

		if ( self::COUNTRY_CODE === $country ) {
			$fields[ $phone_key ]['placeholder'] = self::PHONE_PLACEHOLDER;
		}

		return $fields;
	}

	private function prepare_admin_phone_field( $fields, $address_type ) {
		if ( ! isset( $fields['phone'] ) ) {
			return $fields;
		}

		$fields['phone']['update_callback'] = [ $this, 'update_admin_order_phone_field' ];
		$fields['phone']['placeholder']     = self::PHONE_PLACEHOLDER;

		return $fields;
	}

	private function normalize_posted_phone_field_value( $value, $address_type ) {
		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::verify_checkout_or_account_address_request() ) {
			return $value;
		}

		$country_key = $address_type . '_country';
		$country     = $this->get_effective_country( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $country_key ) );

		if ( ! $this->should_process_phone( $country, $value ) ) {
			return $value;
		}

		$normalized = self::normalize_phone_number( $value, $country );

		return $normalized['valid'] ? $normalized['national'] : self::sanitize_phone_storage_value( $value );
	}

	private function validate_checkout_phone_field( $data, $errors, $address_type ) {
		$phone_key = $address_type . '_phone';

		if ( ! isset( $data[ $phone_key ] ) || '' === $data[ $phone_key ] ) {
			return;
		}

		$country = $this->get_effective_country( isset( $data[ $address_type . '_country' ] ) ? $data[ $address_type . '_country' ] : '' );

		if ( ! $this->should_process_phone( $country, $data[ $phone_key ] ) ) {
			return;
		}

		$normalized = self::normalize_phone_number( $data[ $phone_key ], $country );

		if ( ! $normalized['valid'] ) {
			$errors->add(
				$phone_key . '_vietnam_validation',
				__( 'Please enter a valid Vietnamese phone number.', 'yoohw-vietnam-store-tools' ),
				[ 'id' => $phone_key ]
			);
		}
	}

	private function normalize_order_phone_field( $order, $address_type ) {
		$phone_getter   = 'get_' . $address_type . '_phone';
		$phone_setter   = 'set_' . $address_type . '_phone';
		$country_getter = 'get_' . $address_type . '_country';

		if ( ! is_callable( [ $order, $phone_getter ] ) || ! is_callable( [ $order, $country_getter ] ) ) {
			return;
		}

		$phone   = $order->{$phone_getter}( 'edit' );
		$country = $this->get_effective_country( $order->{$country_getter}( 'edit' ) );

		if ( ! $this->should_process_phone( $country, $phone ) ) {
			$this->delete_order_phone_meta( $order, $address_type );
			return;
		}

		$normalized = self::normalize_phone_number( $phone, $country );

		if ( $normalized['valid'] && is_callable( [ $order, $phone_setter ] ) ) {
			$order->{$phone_setter}( $normalized['national'] );
		}

		$this->save_order_phone_meta_from_result( $order, $address_type, $normalized );
	}

	private function validate_and_normalize_store_api_order_phone_field( $order, $address_type ) {
		$phone_getter   = 'get_' . $address_type . '_phone';
		$phone_setter   = 'set_' . $address_type . '_phone';
		$country_getter = 'get_' . $address_type . '_country';

		if ( ! is_callable( [ $order, $phone_getter ] ) || ! is_callable( [ $order, $country_getter ] ) ) {
			return;
		}

		$phone   = $order->{$phone_getter}( 'edit' );
		$country = $this->get_effective_country( $order->{$country_getter}( 'edit' ) );

		if ( '' === (string) $phone || ! $this->should_process_phone( $country, $phone ) ) {
			$this->delete_order_phone_meta( $order, $address_type );
			return;
		}

		$normalized = self::normalize_phone_number( $phone, $country );

		if ( ! $normalized['valid'] ) {
			$this->throw_store_api_phone_validation_error( $address_type );
		}

		if ( is_callable( [ $order, $phone_setter ] ) ) {
			$order->{$phone_setter}( $normalized['national'] );
		}

		$this->save_order_phone_meta_from_result( $order, $address_type, $normalized );
	}

	private function throw_store_api_phone_validation_error( $address_type ) {
		$exception_class = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		$message         = __( 'Please enter a valid Vietnamese phone number.', 'yoohw-vietnam-store-tools' );

		if ( class_exists( $exception_class ) ) {
			// RouteException carries structured Store API error data; escaping belongs to the HTTP rendering boundary.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new $exception_class(
				'yoohw_vietnam_store_tools_invalid_' . $address_type . '_phone',
				$message,
				400,
				[ 'field' => $address_type . '_phone' ]
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		throw new Exception( esc_html( $message ) );
	}

	private function normalize_customer_object_phone_field( $customer, $address_type ) {
		$phone_getter   = 'get_' . $address_type . '_phone';
		$phone_setter   = 'set_' . $address_type . '_phone';
		$country_getter = 'get_' . $address_type . '_country';

		if ( ! is_callable( [ $customer, $phone_getter ] ) || ! is_callable( [ $customer, $country_getter ] ) ) {
			return;
		}

		$phone   = $customer->{$phone_getter}( 'edit' );
		$country = $this->get_effective_country( $customer->{$country_getter}( 'edit' ) );

		if ( ! $this->should_process_phone( $country, $phone ) ) {
			$this->delete_customer_object_phone_meta( $customer, $address_type );
			return;
		}

		$normalized = self::normalize_phone_number( $phone, $country );

		if ( $normalized['valid'] && is_callable( [ $customer, $phone_setter ] ) ) {
			$customer->{$phone_setter}( $normalized['national'] );
		}

		$this->save_customer_object_phone_meta_from_result( $customer, $address_type, $normalized );
	}

	private function save_customer_phone_meta( $user_id, $address_type, $phone, $country ) {
		if ( ! $this->should_process_phone( $country, $phone ) ) {
			$this->delete_user_phone_meta( $user_id, $address_type );
			return;
		}

		$normalized = self::normalize_phone_number( $phone, $country );

		if ( ! $normalized['valid'] ) {
			$this->delete_user_phone_meta( $user_id, $address_type );
			return;
		}

		update_user_meta( $user_id, $address_type . '_phone', $normalized['national'] );
		update_user_meta( $user_id, $address_type . '_phone_e164', $normalized['e164'] );
		update_user_meta( $user_id, $address_type . '_phone_type', $normalized['type'] );
		update_user_meta( $user_id, $address_type . '_phone_carrier', $normalized['carrier'] );
	}

	private function save_order_phone_meta_from_result( $order, $address_type, $normalized ) {
		if ( empty( $normalized['valid'] ) ) {
			$this->delete_order_phone_meta( $order, $address_type );
			return;
		}

		$order->update_meta_data( '_' . $address_type . '_phone_e164', $normalized['e164'] );
		$order->update_meta_data( '_' . $address_type . '_phone_type', $normalized['type'] );
		$order->update_meta_data( '_' . $address_type . '_phone_carrier', $normalized['carrier'] );
	}

	private function save_customer_object_phone_meta_from_result( $customer, $address_type, $normalized ) {
		if ( empty( $normalized['valid'] ) ) {
			$this->delete_customer_object_phone_meta( $customer, $address_type );
			return;
		}

		$customer->update_meta_data( $address_type . '_phone_e164', $normalized['e164'] );
		$customer->update_meta_data( $address_type . '_phone_type', $normalized['type'] );
		$customer->update_meta_data( $address_type . '_phone_carrier', $normalized['carrier'] );
	}

	private function delete_order_phone_meta( $order, $address_type ) {
		foreach ( [ '_phone_e164', '_phone_type', '_phone_carrier' ] as $suffix ) {
			$order->delete_meta_data( '_' . $address_type . $suffix );
		}
	}

	private function delete_customer_object_phone_meta( $customer, $address_type ) {
		foreach ( [ '_phone_e164', '_phone_type', '_phone_carrier' ] as $suffix ) {
			$customer->delete_meta_data( $address_type . $suffix );
		}
	}

	private function delete_user_phone_meta( $user_id, $address_type ) {
		foreach ( [ '_phone_e164', '_phone_type', '_phone_carrier' ] as $suffix ) {
			delete_user_meta( $user_id, $address_type . $suffix );
		}
	}

	private function should_process_phone( $country, $phone ) {
		if ( self::COUNTRY_CODE === $this->get_effective_country( $country ) ) {
			return true;
		}

		$sanitized = self::sanitize_phone_storage_value( $phone );

		return 1 === preg_match( '/^(?:\+84|0084|84|0[235789])/', $sanitized );
	}

	private static function to_vietnam_national_number( $phone ) {
		$phone = (string) $phone;

		if ( '' === $phone ) {
			return '';
		}

		if ( 0 === strpos( $phone, '0084' ) ) {
			$phone = '+84' . substr( $phone, 4 );
		}

		if ( 0 === strpos( $phone, '+840' ) ) {
			$phone = '0' . substr( $phone, 4 );
		} elseif ( 0 === strpos( $phone, '+84' ) ) {
			$phone = '0' . substr( $phone, 3 );
		} elseif ( 0 === strpos( $phone, '840' ) ) {
			$phone = '0' . substr( $phone, 3 );
		} elseif ( 0 === strpos( $phone, '84' ) ) {
			$phone = '0' . substr( $phone, 2 );
		} elseif ( 1 === preg_match( '/^(?:[35789]\d{8}|2\d{8,9})$/', $phone ) ) {
			$phone = '0' . $phone;
		}

		if ( 1 !== preg_match( '/^\d+$/', $phone ) ) {
			return '';
		}

		return $phone;
	}

	private static function get_vietnam_phone_type( $national ) {
		if ( 1 === preg_match( '/^0[35789]\d{8}$/', $national ) ) {
			return 'mobile';
		}

		if ( 1 === preg_match( '/^02\d{8,9}$/', $national ) ) {
			return 'landline';
		}

		return '';
	}

	private static function get_mobile_prefix_carrier( $national ) {
		$prefix = substr( $national, 0, 3 );

		foreach ( self::$mobile_carrier_prefixes as $carrier => $prefixes ) {
			if ( in_array( $prefix, $prefixes, true ) ) {
				return $carrier;
			}
		}

		return 'unknown';
	}

	private function get_posted_admin_field_value( $address_type, $field, $default = '' ) {
		$key = '_' . $address_type . '_' . $field;

		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $key ) ) {
			return $default;
		}

		return Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $key );
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
