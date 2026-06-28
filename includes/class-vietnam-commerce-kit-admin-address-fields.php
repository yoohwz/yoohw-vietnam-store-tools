<?php
/**
 * WooCommerce admin Vietnam address fields outside order editing.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Admin_Address_Fields {

	const COUNTRY_CODE = 'VN';

	public function __construct() {
		add_filter( 'woocommerce_general_settings', [ $this, 'prepare_general_settings' ], 20 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_woocommerce_default_country', [ $this, 'normalize_default_country_option' ], 20, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_woocommerce_store_city', [ $this, 'normalize_store_city_option' ], 20, 3 );
		add_filter( 'woocommerce_customer_meta_fields', [ $this, 'prepare_customer_meta_fields' ], 20 );
		add_action( 'personal_options_update', [ $this, 'normalize_customer_profile_post_data' ], 9 );
		add_action( 'edit_user_profile_update', [ $this, 'normalize_customer_profile_post_data' ], 9 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	public function prepare_general_settings( $settings ) {
		list( $country, $state ) = $this->get_store_country_state();
		$is_vietnam             = self::COUNTRY_CODE === $country;

		foreach ( $settings as &$setting ) {
			if ( empty( $setting['id'] ) ) {
				continue;
			}

			if ( 'woocommerce_store_city' === $setting['id'] ) {
				$setting['row_class'] = $this->append_field_classes( isset( $setting['row_class'] ) ? $setting['row_class'] : '', [ 'vck-store-city-row' ] );

				if ( $is_vietnam ) {
					$ward                = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $this->get_posted_or_option_value( 'woocommerce_store_city' ) );
					$setting['title']    = __( 'Ward / Commune', 'yoohw-vietnam-store-tools' );
					$setting['type']     = 'select';
					$setting['class']    = $this->append_field_classes( isset( $setting['class'] ) ? $setting['class'] : '', [ 'wc-enhanced-select', 'vck-admin-store-ward-select' ] );
					$setting['options']  = [ '' => __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' ) ] + Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( $state );
					$setting['value']    = $ward;
				}
			}

			if ( 'woocommerce_default_country' === $setting['id'] ) {
				$setting['type']    = 'select';
				$setting['class']   = $this->append_field_classes( isset( $setting['class'] ) ? $setting['class'] : '', [ 'wc-enhanced-select', 'vck-admin-store-location-select' ] );
				$setting['options'] = $this->get_country_state_options();
				$setting['value']   = $this->format_country_state_value( $country, $state );
			}

		}
		unset( $setting );

		return $settings;
	}

	public function prepare_customer_meta_fields( $fieldsets ) {
		foreach ( [ 'billing', 'shipping' ] as $address_type ) {
			if ( empty( $fieldsets[ $address_type ]['fields'] ) ) {
				continue;
			}

			$country = $this->get_customer_profile_field_value( $address_type . '_country' );
			$is_vietnam_address = self::COUNTRY_CODE === $country || ( '' === $country && $this->is_single_vietnam_store() );

			if ( $is_vietnam_address && isset( $fieldsets[ $address_type ]['fields'][ $address_type . '_state' ] ) ) {
				$fieldsets[ $address_type ]['fields'][ $address_type . '_state' ]['label']       = __( 'City / Province', 'yoohw-vietnam-store-tools' );
				$fieldsets[ $address_type ]['fields'][ $address_type . '_state' ]['description'] = '';
				$fieldsets[ $address_type ]['fields'][ $address_type . '_state' ]['class']       = $this->append_field_classes(
					isset( $fieldsets[ $address_type ]['fields'][ $address_type . '_state' ]['class'] ) ? $fieldsets[ $address_type ]['fields'][ $address_type . '_state' ]['class'] : 'regular-text',
					[ 'vck-admin-profile-province-field' ]
				);
			}

			if ( $is_vietnam_address && isset( $fieldsets[ $address_type ]['fields'][ $address_type . '_city' ] ) ) {
				$fieldsets[ $address_type ]['fields'][ $address_type . '_city' ]['label'] = __( 'Ward / Commune', 'yoohw-vietnam-store-tools' );
				$fieldsets[ $address_type ]['fields'][ $address_type . '_city' ]['class'] = $this->append_field_classes(
					isset( $fieldsets[ $address_type ]['fields'][ $address_type . '_city' ]['class'] ) ? $fieldsets[ $address_type ]['fields'][ $address_type . '_city' ]['class'] : 'regular-text',
					[ 'vck-admin-profile-ward-field' ]
				);
			}

			$fieldsets[ $address_type ]['fields'] = $this->reorder_customer_profile_address_fields( $fieldsets[ $address_type ]['fields'], $address_type );
		}

		return $fieldsets;
	}

	public function normalize_customer_profile_post_data( $user_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Yoohw_Vietnam_Store_Tools_Request_Security::verify_user_profile_request( $user_id ) ) {
			return;
		}

		foreach ( [ 'billing', 'shipping' ] as $address_type ) {
			$country_key  = $address_type . '_country';
			$state_key    = $address_type . '_state';
			$city_key     = $address_type . '_city';
			$country = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $country_key );

			if ( self::COUNTRY_CODE !== $country ) {
				continue;
			}

			if ( Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $state_key ) ) {
				$_POST[ $state_key ] = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $state_key ) );
			}

			if ( Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $city_key ) ) {
				$_POST[ $city_key ] = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $city_key ) );
			}
		}
	}

	public function enqueue_scripts( $hook_suffix ) {
		if ( ! $this->is_supported_admin_screen() ) {
			return;
		}

		$handle = 'yoohw-vietnam-store-tools-admin-address-fields';

		wp_enqueue_script(
			$handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/address-fields.js',
			[ 'jquery', 'selectWoo' ],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'yoohwVietnamStoreToolsAdminAddressFields',
			[
				'country'  => self::COUNTRY_CODE,
				'wards'    => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards(),
				'selected' => [
					'store'    => $this->get_store_selected_values(),
					'billing'  => $this->get_customer_profile_selected_values( 'billing' ),
					'shipping' => $this->get_customer_profile_selected_values( 'shipping' ),
				],
				'i18n'     => [
					'cityLabel'           => __( 'City', 'yoohw-vietnam-store-tools' ),
					'provinceLabel'       => __( 'City / Province', 'yoohw-vietnam-store-tools' ),
					'stateLabel'          => __( 'State / County', 'woocommerce' ),
					'wardLabel'           => __( 'Ward / Commune', 'yoohw-vietnam-store-tools' ),
					'selectWard'          => __( 'Select a ward / commune', 'yoohw-vietnam-store-tools' ),
					'selectProvinceFirst' => __( 'Select a city / province first', 'yoohw-vietnam-store-tools' ),
				],
			]
		);
	}

	public function normalize_default_country_option( $value, $option, $raw_value ) {
		list( $country, $state ) = $this->parse_country_state_value( $value );

		if ( self::COUNTRY_CODE !== $country ) {
			return $value;
		}

		$state = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $state );

		return '' !== $state ? self::COUNTRY_CODE . ':' . $state : self::COUNTRY_CODE;
	}

	public function normalize_store_city_option( $value, $option, $raw_value ) {
		list( $country, $state ) = $this->get_store_country_state();

		if ( self::COUNTRY_CODE !== $country ) {
			return $value;
		}

		$ward = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $value );

		return Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward, $state ) ? $ward : '';
	}

	private function get_store_selected_values() {
		list( $country, $state ) = $this->get_store_country_state();

		return [
			'country' => $country,
			'state'   => $state,
			'city'    => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $this->get_posted_or_option_value( 'woocommerce_store_city' ) ),
		];
	}

	private function get_country_state_options() {
		$options = [];

		foreach ( WC()->countries->get_countries() as $country_code => $country_name ) {
			$states = WC()->countries->get_states( $country_code );

			if ( is_array( $states ) && ! empty( $states ) ) {
				foreach ( $states as $state_code => $state_name ) {
					$options[ $country_code . ':' . (string) $state_code ] = $country_name . ' - ' . $state_name;
				}

				continue;
			}

			$options[ $country_code ] = $country_name;
		}

		return $options;
	}

	private function get_customer_profile_selected_values( $address_type ) {
		return [
			'country' => $this->get_customer_profile_field_value( $address_type . '_country' ),
			'state'   => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $this->get_customer_profile_field_value( $address_type . '_state' ) ),
			'city'    => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $this->get_customer_profile_field_value( $address_type . '_city' ) ),
		];
	}

	private function get_store_country_state() {
		return $this->parse_country_state_value( $this->get_posted_or_option_value( 'woocommerce_default_country' ) );
	}

	private function parse_country_state_value( $value ) {
		$value = wc_clean( $value );

		if ( false === strpos( $value, ':' ) ) {
			return [ $value, '' ];
		}

		list( $country, $state ) = explode( ':', $value, 2 );
		$state                   = '*' === $state ? '' : $state;

		if ( self::COUNTRY_CODE === $country ) {
			$state = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $state );
		}

		return [ $country, $state ];
	}

	private function format_country_state_value( $country, $state ) {
		if ( '' === $country ) {
			return '';
		}

		return '' !== $state ? $country . ':' . $state : $country;
	}

	private function get_posted_or_option_value( $key ) {
		if ( Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $key ) ) {
			return Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $key );
		}

		return wc_clean( get_option( $key, '' ) );
	}

	private function get_customer_profile_field_value( $key ) {
		if ( Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $key ) ) {
			return Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $key );
		}

		$user_id = $this->get_current_profile_user_id();

		if ( ! $user_id ) {
			return '';
		}

		return wc_clean( get_user_meta( $user_id, $key, true ) );
	}

	private function reorder_customer_profile_address_fields( $fields, $address_type ) {
		$address_keys = [
			$address_type . '_country',
			$address_type . '_state',
			$address_type . '_city',
			$address_type . '_address_1',
			$address_type . '_address_2',
			$address_type . '_postcode',
		];

		$ordered  = [];
		$inserted = false;

		foreach ( $fields as $key => $field ) {
			if ( in_array( $key, $address_keys, true ) ) {
				if ( ! $inserted ) {
					foreach ( $address_keys as $address_key ) {
						if ( isset( $fields[ $address_key ] ) ) {
							$ordered[ $address_key ] = $fields[ $address_key ];
						}
					}

					$inserted = true;
				}

				continue;
			}

			$ordered[ $key ] = $field;
		}

		if ( ! $inserted ) {
			foreach ( $address_keys as $address_key ) {
				if ( isset( $fields[ $address_key ] ) ) {
					$ordered[ $address_key ] = $fields[ $address_key ];
				}
			}
		}

		return $ordered;
	}

	private function is_single_vietnam_store() {
		if ( 'specific' !== get_option( 'woocommerce_allowed_countries' ) ) {
			return false;
		}

		$countries = get_option( 'woocommerce_specific_allowed_countries', [] );
		$countries = is_array( $countries ) ? $countries : [];
		$countries = array_values( array_unique( array_filter( array_map( 'wc_clean', $countries ) ) ) );

		return 1 === count( $countries ) && self::COUNTRY_CODE === $countries[0];
	}

	private function get_current_profile_user_id() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return 0;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return 0;
		}

		if ( 'profile' === $screen->id && function_exists( 'get_current_user_id' ) ) {
			return get_current_user_id();
		}

		if ( 'user-edit' === $screen->id ) {
			global $user_id;

			$target_user_id = absint( $user_id );

			return $target_user_id && current_user_can( 'edit_user', $target_user_id ) ? $target_user_id : 0;
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			return get_current_user_id();
		}

		return 0;
	}

	private function is_supported_admin_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		if ( in_array( $screen->id, [ 'profile', 'user-edit' ], true ) ) {
			return true;
		}

		if ( ! in_array( $screen->id, [ 'woocommerce_page_wc-settings', 'admin_page_wc-settings' ], true ) ) {
			return false;
		}

		$tab = Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'tab', 'general' );

		return '' === $tab || 'general' === $tab;
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
