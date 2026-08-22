<?php
/**
 * WooCommerce admin order Vietnam address fields.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Admin_Order_Fields {

	const COUNTRY_CODE = 'VN';

	private $reported_invalid_addresses = [];

	public function __construct() {
		add_filter( 'woocommerce_admin_billing_fields', [ $this, 'prepare_billing_fields' ], 20, 3 );
		add_filter( 'woocommerce_admin_shipping_fields', [ $this, 'prepare_shipping_fields' ], 20, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	public function prepare_billing_fields( $fields, $order = false, $context = 'edit' ) {
		return $this->prepare_address_fields( $fields, $order, $context, 'billing' );
	}

	public function prepare_shipping_fields( $fields, $order = false, $context = 'edit' ) {
		return $this->prepare_address_fields( $fields, $order, $context, 'shipping' );
	}

	public function enqueue_scripts( $hook_suffix ) {
		if ( ! $this->is_order_admin_screen( $hook_suffix ) ) {
			return;
		}

		$handle = 'yoohw-vietnam-store-tools-admin-order-address-fields';

		wp_enqueue_script(
			$handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/order-address-fields.js',
			[ 'jquery', 'selectWoo' ],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'yoohwVietnamStoreToolsAdminOrderFields',
			[
				'country'  => self::COUNTRY_CODE,
				'wards'    => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards(),
				'selected' => $this->get_current_order_selected_values(),
				'i18n'     => [
						'cityLabel'           => __( 'City', 'yoohw-vietnam-store-tools' ),
					'wardLabel'           => __( 'Ward / Commune', 'yoohw-vietnam-store-tools' ),
					'selectWard'          => __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' ),
					'selectProvinceFirst' => __( 'Select a city / province first', 'yoohw-vietnam-store-tools' ),
				],
			]
		);
	}

	public function update_admin_order_address_field( $field_id, $value, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::verify_admin_order_request( $order ) ) {
			return;
		}

		$address_type = 0 === strpos( $field_id, '_shipping_' ) ? 'shipping' : 'billing';
		$field        = preg_replace( '/^_' . preg_quote( $address_type, '/' ) . '_/', '', $field_id );
		$country      = $this->get_posted_admin_field_value( $address_type, 'country' );

		if ( self::COUNTRY_CODE === $country ) {
			if ( in_array( $field, [ 'state', 'city' ], true ) && ! $this->is_posted_vietnam_address_valid( $address_type ) ) {
				$this->report_invalid_admin_address( $address_type );
				return;
			}

			if ( 'state' === $field ) {
				$value = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $value );
			} elseif ( 'city' === $field ) {
				$value = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $value );
			}
		}

		$setter = 'set_' . $address_type . '_' . $field;

		if ( is_callable( [ $order, $setter ] ) ) {
			$order->{$setter}( $value );
			return;
		}

		$order->update_meta_data( $field_id, $value );
	}

	private function is_posted_vietnam_address_valid( $address_type ) {
		$state = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $this->get_posted_admin_field_value( $address_type, 'state' ) );
		$ward  = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $this->get_posted_admin_field_value( $address_type, 'city' ) );

		return Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $state )
			&& Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward, $state );
	}

	private function report_invalid_admin_address( $address_type ) {
		if ( isset( $this->reported_invalid_addresses[ $address_type ] ) ) {
			return;
		}

		$this->reported_invalid_addresses[ $address_type ] = true;

		if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::add_error(
				__( 'Please select a valid ward / commune for the selected city / province.', 'yoohw-vietnam-store-tools' )
			);
		}
	}

	private function prepare_address_fields( $fields, $order, $context, $address_type ) {
		$country    = $this->get_admin_address_value( $order, $address_type, 'country' );
		$is_vietnam = self::COUNTRY_CODE === $country;

		$state = $this->get_admin_address_value( $order, $address_type, 'state' );
		$ward  = $this->get_admin_address_value( $order, $address_type, 'city' );

		if ( $is_vietnam ) {
			$state = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $state );
			$ward  = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $ward );
		}

		if ( isset( $fields['state'] ) ) {
			$fields['state']['update_callback'] = [ $this, 'update_admin_order_address_field' ];

			if ( $is_vietnam ) {
				$fields['state']['label']       = __( 'City / Province', 'yoohw-vietnam-store-tools' );
				$fields['state']['placeholder'] = __( 'Select a city / province', 'yoohw-vietnam-store-tools' );
				$fields['state']['value']       = $state;
				$fields['state']['class']       = $this->append_field_classes( isset( $fields['state']['class'] ) ? $fields['state']['class'] : '', [ 'js_field-state', 'select', 'short', 'vck-admin-province-select' ] );

				if ( 'view' === $context ) {
					$fields['state']['value'] = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_province_name( $state );
				}
			}
		}

		if ( isset( $fields['city'] ) ) {
			$fields['city']['id']              = '_' . $address_type . '_city';
			$fields['city']['update_callback'] = [ $this, 'update_admin_order_address_field' ];

			if ( $is_vietnam ) {
				if ( 'view' === $context ) {
					$fields['city']['label'] = __( 'Ward / Commune', 'yoohw-vietnam-store-tools' );
					$fields['city']['value'] = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_ward_name( $ward, $state );
				} else {
					$fields['city'] = $this->prepare_admin_ward_field( $fields['city'], $address_type, $state, $ward );
				}
			}
		}

		return $fields;
	}

	private function prepare_admin_ward_field( $field, $address_type, $state, $ward ) {
		$wards = '' !== $state ? Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( $state ) : [];

		$field['id']          = '_' . $address_type . '_city';
		$field['type']        = 'select';
		$field['label']       = __( 'Ward / Commune', 'yoohw-vietnam-store-tools' );
		$field['placeholder'] = __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' );
		$field['class']       = $this->append_field_classes( isset( $field['class'] ) ? $field['class'] : '', [ 'select', 'short', 'vck-admin-ward-select' ] );
		$field['options']     = [ '' => __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' ) ] + $wards;
		$field['value']       = $ward;

		$field['custom_attributes'] = isset( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] ) ? $field['custom_attributes'] : [];
		$field['custom_attributes']['data-vck-address-type'] = $address_type;
		$field['custom_attributes']['data-selected-value']   = $ward;
		$field['custom_attributes']['data-placeholder']      = __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' );

		return $field;
	}

	private function get_admin_address_value( $order, $address_type, $field ) {
		$posted_value = $this->get_posted_admin_field_value( $address_type, $field, null );

		if ( null !== $posted_value ) {
			return $posted_value;
		}

		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$getter = 'get_' . $address_type . '_' . $field;

		if ( is_callable( [ $order, $getter ] ) ) {
			return wc_clean( $order->{$getter}( 'edit' ) );
		}

		return wc_clean( $order->get_meta( '_' . $address_type . '_' . $field ) );
	}

	private function get_posted_admin_field_value( $address_type, $field, $default = '' ) {
		$key = '_' . $address_type . '_' . $field;

		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $key ) ) {
			return $default;
		}

		return Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $key );
	}

	private function get_current_order_selected_values() {
		$order = $this->get_current_admin_order();

		return [
			'billing'  => $this->get_selected_address_values( $order, 'billing' ),
			'shipping' => $this->get_selected_address_values( $order, 'shipping' ),
		];
	}

	private function get_selected_address_values( $order, $address_type ) {
		$state = $this->get_admin_address_value( $order, $address_type, 'state' );
		$city  = $this->get_admin_address_value( $order, $address_type, 'city' );

		return [
			'state' => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $state ),
			'city'  => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $city ),
		];
	}

	private function get_current_admin_order() {
		return Yoohw_Vietnam_Store_Tools_Request_Security::get_admin_order_from_query( [ 'post', 'id', 'order_id' ] );
	}

	private function is_order_admin_screen( $hook_suffix ) {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::is_order_edit_screen() || \Automattic\WooCommerce\Utilities\OrderUtil::is_new_order_screen();
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		if ( 'post.php' === $hook_suffix && ( 'shop_order' === $screen->id || 'shop_order' === $screen->post_type ) ) {
			return true;
		}

		return in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders', 'admin_page_wc-orders' ], true );
	}

	private function append_field_classes( $classes, $append ) {
		$classes = is_array( $classes ) ? $classes : preg_split( '/\s+/', (string) $classes );
		$classes = array_filter( $classes );

		foreach ( $append as $class ) {
			if ( ! in_array( $class, $classes, true ) ) {
				$classes[] = $class;
			}
		}

		return implode( ' ', $classes );
	}
}
