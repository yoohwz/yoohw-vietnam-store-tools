<?php
/**
 * Vietnamese ward/commune support for WooCommerce Shipping Zones.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Shipping_Zones {

	const LOCATION_TYPE = 'vck_ward';

	private $has_ward_zones = null;

	public function __construct() {
		add_filter( 'woocommerce_valid_location_types', [ $this, 'register_location_type' ] );
		add_action( 'woocommerce_before_shipping_zone_object_save', [ $this, 'sync_ward_locations_before_save' ], 10, 2 );
		add_filter( 'woocommerce_get_zone_criteria', [ $this, 'add_ward_zone_criteria' ], 10, 3 );
		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'invalidate_ward_zone_cache' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Register an additive location type for Vietnamese wards/communes.
	 *
	 * @param array $types Valid WooCommerce shipping-zone location types.
	 * @return array
	 */
	public function register_location_type( $types ) {
		$types = is_array( $types ) ? $types : [];

		if ( ! in_array( self::LOCATION_TYPE, $types, true ) ) {
			$types[] = self::LOCATION_TYPE;
		}

		return $types;
	}

	/**
	 * Normalize and validate a stored ward location code.
	 *
	 * Stored format: <province_code>:<ward_code>.
	 *
	 * @param mixed $value Raw location code.
	 * @return string
	 */
	public static function sanitize_ward_location_code( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value || false === strpos( $value, ':' ) ) {
			return '';
		}

		list( $province, $ward ) = array_pad( explode( ':', $value, 2 ), 2, '' );
		$province                = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $province );
		$ward                    = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $ward );

		if ( '' === $province || '' === $ward || ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward, $province ) ) {
			return '';
		}

		return $province . ':' . $ward;
	}

	/**
	 * Synchronize custom ward locations from WooCommerce's zone editor request.
	 *
	 * WooCommerce clears its built-in location types before saving but does not
	 * know how to clear this plugin's additive type. Synchronizing immediately
	 * before the zone object is persisted keeps removed wards from becoming stale.
	 *
	 * @param WC_Shipping_Zone $zone       Shipping zone being saved.
	 * @param object           $data_store WooCommerce zone data store.
	 * @return void
	 */
	public function sync_ward_locations_before_save( $zone, $data_store ) {
		unset( $data_store );

		if ( ! is_object( $zone ) || ! is_callable( [ $zone, 'clear_locations' ] ) || ! is_callable( [ $zone, 'add_location' ] ) ) {
			return;
		}

		if ( ! $this->is_zone_editor_save_request() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in is_zone_editor_save_request().
		$changes = isset( $_POST['changes'] ) && is_array( $_POST['changes'] ) ? wp_unslash( $_POST['changes'] ) : [];

		if ( ! array_key_exists( 'zone_locations', $changes ) ) {
			return;
		}

		$locations = is_array( $changes['zone_locations'] ) ? $changes['zone_locations'] : [];
		$zone->clear_locations( self::LOCATION_TYPE );

		foreach ( $locations as $location ) {
			$location = is_scalar( $location ) ? (string) $location : '';
			$prefix   = self::LOCATION_TYPE . ':';

			if ( 0 !== strpos( $location, $prefix ) ) {
				continue;
			}

			$code = self::sanitize_ward_location_code( substr( $location, strlen( $prefix ) ) );

			if ( '' !== $code ) {
				$zone->add_location( $code, self::LOCATION_TYPE );
			}
		}

		$this->has_ward_zones = null;
	}

	/**
	 * Add ward-aware criteria to WooCommerce's shipping-zone lookup.
	 *
	 * Any zone containing at least one vck_ward location becomes ward-restricted:
	 * it must contain the current destination ward even when the zone also has a
	 * broader country/state location. This prevents a parent province selection
	 * from accidentally defeating a ward restriction.
	 *
	 * @param array $criteria           Existing SQL criteria fragments.
	 * @param array $package            Shipping package.
	 * @param array $postcode_locations Existing postcode locations.
	 * @return array
	 */
	public function add_ward_zone_criteria( $criteria, $package, $postcode_locations ) {
		unset( $postcode_locations );

		if ( ! is_array( $criteria ) ) {
			return $criteria;
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! is_callable( [ $wpdb, 'prepare' ] ) ) {
			return $criteria;
		}

		$table       = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];
		$country     = isset( $destination['country'] ) ? wc_strtoupper( wc_clean( $destination['country'] ) ) : '';
		$province    = isset( $destination['state'] ) ? Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $destination['state'] ) : '';
		$ward        = isset( $destination['city'] ) ? Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $destination['city'] ) : '';
		$ward_code   = '';

		if ( 'VN' === $country && '' !== $province && '' !== $ward && Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward, $province ) ) {
			$ward_code = $province . ':' . $ward;
		}

		if ( '' !== $ward_code && isset( $criteria[3] ) ) {
			$ward_match = $wpdb->prepare(
				'OR ( location_type = %s AND location_code = %s )',
				self::LOCATION_TYPE,
				$ward_code
			);
			$criteria[3] = $ward_match . ' ' . $criteria[3];
		}

		if ( '' !== $ward_code ) {
			$criteria[] = $wpdb->prepare(
				"AND ( zones.zone_id NOT IN ( SELECT zone_id FROM {$table} WHERE location_type = %s ) OR zones.zone_id IN ( SELECT zone_id FROM {$table} WHERE location_type = %s AND location_code = %s ) )",
				self::LOCATION_TYPE,
				self::LOCATION_TYPE,
				$ward_code
			);
		} else {
			$criteria[] = $wpdb->prepare(
				"AND zones.zone_id NOT IN ( SELECT zone_id FROM {$table} WHERE location_type = %s )",
				self::LOCATION_TYPE
			);
		}

		return $criteria;
	}

	/**
	 * Invalidate WooCommerce's country/state/postcode zone-match cache for
	 * packages whose city field carries a valid Vietnamese ward code.
	 *
	 * Core does not include city in this cache key, so two wards in the same
	 * province could otherwise reuse the wrong cached zone.
	 *
	 * @param array $packages Cart shipping packages.
	 * @return array
	 */
	public function invalidate_ward_zone_cache( $packages ) {
		if ( ! is_array( $packages ) || ! $this->has_ward_zones() || ! class_exists( 'WC_Cache_Helper' ) ) {
			return $packages;
		}

		foreach ( $packages as $package ) {
			$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];
			$country     = isset( $destination['country'] ) ? wc_strtoupper( wc_clean( $destination['country'] ) ) : '';
			$state       = isset( $destination['state'] ) ? wc_strtoupper( wc_clean( $destination['state'] ) ) : '';
			$city        = isset( $destination['city'] ) ? Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $destination['city'] ) : '';

			if ( 'VN' !== $country || '' === $state || '' === $city || ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $city, $state ) ) {
				continue;
			}

			$postcode  = isset( $destination['postcode'] ) ? wc_normalize_postcode( wc_clean( $destination['postcode'] ) ) : '';
			$cache_key = WC_Cache_Helper::get_cache_prefix( 'shipping_zones' ) . 'wc_shipping_zone_' . md5( sprintf( '%s+%s+%s', $country, $state, $postcode ) );
			wp_cache_delete( $cache_key, 'shipping_zones' );
		}

		return $packages;
	}

	/**
	 * Enqueue the Shipping Zone ward editor and list enhancements.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets() {
		if ( ! $this->is_shipping_settings_screen() ) {
			return;
		}

		$script_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/js/admin/shipping-zones.js';

		if ( ! file_exists( $script_path ) ) {
			return;
		}

		wp_enqueue_script(
			'yoohw-vietnam-store-tools-shipping-zones',
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/shipping-zones.js',
			[ 'jquery', 'wc-enhanced-select' ],
			filemtime( $script_path ),
			true
		);

		wp_localize_script(
			'yoohw-vietnam-store-tools-shipping-zones',
			'yoohwVietnamStoreToolsShippingZones',
			[
				'locationType' => self::LOCATION_TYPE,
				'provinces'    => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_provinces(),
				'wards'        => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards(),
				'zoneLabels'   => $this->get_zone_ward_labels(),
				'i18n'         => [
					'ward'    => __( 'Ward / Commune', 'yoohw-vietnam-store-tools' ),
					'anyWard' => __( 'Any ward / commune', 'yoohw-vietnam-store-tools' ),
				],
			]
		);
	}

	/**
	 * Determine whether this is WooCommerce's authenticated zone-save request.
	 *
	 * @return bool
	 */
	private function is_zone_editor_save_request() {
		if ( ! wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked below.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( 'woocommerce_shipping_zone_methods_save_changes' !== $action || ! isset( $_POST['wc_shipping_zones_nonce'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce values are verified, not sanitized as content.
		$nonce = wp_unslash( $_POST['wc_shipping_zones_nonce'] );

		return is_string( $nonce ) && (bool) wp_verify_nonce( $nonce, 'wc_shipping_zones_nonce' );
	}

	/**
	 * Check whether at least one custom ward location exists.
	 *
	 * @return bool
	 */
	private function has_ward_zones() {
		if ( null !== $this->has_ward_zones ) {
			return $this->has_ward_zones;
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! is_callable( [ $wpdb, 'get_var' ] ) || ! is_callable( [ $wpdb, 'prepare' ] ) ) {
			$this->has_ward_zones = false;
			return false;
		}

		$table = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
		$this->has_ward_zones = (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE location_type = %s LIMIT 1", self::LOCATION_TYPE )
		);

		return $this->has_ward_zones;
	}

	/**
	 * Build human-readable ward labels keyed by zone ID for the zones list.
	 *
	 * @return array
	 */
	private function get_zone_ward_labels() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! is_callable( [ $wpdb, 'get_results' ] ) || ! is_callable( [ $wpdb, 'prepare' ] ) ) {
			return [];
		}

		$table = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT zone_id, location_code FROM {$table} WHERE location_type = %s ORDER BY zone_id ASC, location_id ASC",
				self::LOCATION_TYPE
			)
		);
		$labels = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$code = self::sanitize_ward_location_code( isset( $row->location_code ) ? $row->location_code : '' );

			if ( '' === $code ) {
				continue;
			}

			list( $province, $ward ) = explode( ':', $code, 2 );
			$zone_id                 = isset( $row->zone_id ) ? absint( $row->zone_id ) : 0;

			if ( ! $zone_id ) {
				continue;
			}

			$labels[ $zone_id ][] = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_ward_name( $ward, $province ) . ', ' . Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_province_name( $province );
		}

		return $labels;
	}

	/**
	 * Check whether the current admin screen is WooCommerce Shipping settings.
	 *
	 * @return bool
	 */
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
