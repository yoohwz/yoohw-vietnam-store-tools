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

	private $shipping_zones = null;

	private $has_ward_zones = null;

	private $zone_cache_ward_tokens = [];

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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; only individually validated vck_ward values are consumed.
		$changes = isset( $_POST['changes'] ) && is_array( $_POST['changes'] ) ? wp_unslash( $_POST['changes'] ) : [];

		if ( ! array_key_exists( 'zone_locations', $changes ) ) {
			return;
		}

		$locations  = is_array( $changes['zone_locations'] ) ? $changes['zone_locations'] : [];
		$ward_codes = [];
		$zone->clear_locations( self::LOCATION_TYPE );

		foreach ( $locations as $location ) {
			$location = is_scalar( $location ) ? (string) $location : '';
			$prefix   = self::LOCATION_TYPE . ':';

			if ( 0 !== strpos( $location, $prefix ) ) {
				continue;
			}

			$code = self::sanitize_ward_location_code( substr( $location, strlen( $prefix ) ) );

			if ( '' !== $code ) {
				$ward_codes[ $code ] = true;
			}
		}

		foreach ( array_keys( $ward_codes ) as $code ) {
			$zone->add_location( $code, self::LOCATION_TYPE );
		}

		$this->shipping_zones = null;
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

		if ( '' !== $ward_code ) {
			// A ward row may open the core OR group only when the zone has no
			// country/state/continent row of its own. Mixed zones must first
			// satisfy one of those native rows, then the exact ward condition below.
			$ward_only_match = $wpdb->prepare(
				"OR ( location_type = %s AND location_code = %s AND NOT EXISTS ( SELECT 1 FROM %i AS vck_native_locations WHERE vck_native_locations.zone_id = zones.zone_id AND vck_native_locations.location_type IN ( 'country', 'state', 'continent' ) ) )",
				self::LOCATION_TYPE,
				$ward_code,
				$table
			);

			foreach ( $criteria as $index => $criterion ) {
				if ( is_string( $criterion ) && false !== strpos( $criterion, 'location_type IS NULL' ) ) {
					$criteria[ $index ] = $ward_only_match . ' ' . $criterion;
					break;
				}
			}
		}

		if ( '' !== $ward_code ) {
			$criteria[] = $wpdb->prepare(
				"AND ( zones.zone_id NOT IN ( SELECT zone_id FROM %i WHERE location_type = %s ) OR zones.zone_id IN ( SELECT zone_id FROM %i WHERE location_type = %s AND location_code = %s ) )",
				$table,
				self::LOCATION_TYPE,
				$table,
				self::LOCATION_TYPE,
				$ward_code
			);
		} else {
			$criteria[] = $wpdb->prepare(
				"AND zones.zone_id NOT IN ( SELECT zone_id FROM %i WHERE location_type = %s )",
				$table,
				self::LOCATION_TYPE
			);
		}

		return $criteria;
	}

	/**
	 * Invalidate WooCommerce's country/state/postcode zone-match cache before
	 * ward-aware Vietnamese package matching.
	 *
	 * Core does not include city in this cache key, so two wards in the same
	 * province could otherwise reuse the wrong cached zone. Missing or invalid
	 * wards must also invalidate the key so they cannot reuse a positive match.
	 * The first package always clears a possibly persistent cached value; later
	 * identical packages in the same request avoid redundant deletion.
	 *
	 * @param array $packages Cart shipping packages.
	 * @return array
	 */
	public function invalidate_ward_zone_cache( $packages ) {
		if (
			! is_array( $packages )
			|| ! class_exists( 'WC_Cache_Helper' )
			|| ! function_exists( 'wp_cache_delete' )
			|| ! $this->has_ward_restricted_zones()
		) {
			return $packages;
		}

		foreach ( $packages as $package ) {
			$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];
			$country     = isset( $destination['country'] ) ? wc_strtoupper( wc_clean( $destination['country'] ) ) : '';
			$state       = isset( $destination['state'] ) ? wc_strtoupper( wc_clean( $destination['state'] ) ) : '';

			if ( 'VN' !== $country ) {
				continue;
			}

			$postcode       = isset( $destination['postcode'] ) ? wc_normalize_postcode( wc_clean( $destination['postcode'] ) ) : '';
			$city           = isset( $destination['city'] ) ? Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $destination['city'] ) : '';
			$province       = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $state );
			$valid_ward     = '' !== $province && '' !== $city && Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $city, $province );
			$ward_token     = $valid_ward ? $province . ':' . $city : '__invalid__';
			$cache_key      = WC_Cache_Helper::get_cache_prefix( 'shipping_zones' ) . 'wc_shipping_zone_' . md5( sprintf( '%s+%s+%s', $country, $state, $postcode ) );
			$first_package  = ! array_key_exists( $cache_key, $this->zone_cache_ward_tokens );
			$ward_changed   = ! $first_package && $ward_token !== $this->zone_cache_ward_tokens[ $cache_key ];

			if ( $first_package || $ward_changed ) {
				wp_cache_delete( $cache_key, 'shipping_zones' );
				$this->zone_cache_ward_tokens[ $cache_key ] = $ward_token;
			}
		}

		return $packages;
	}

	/**
	 * Enqueue the Shipping Zone ward editor and list enhancements.
	 *
	 * Reuses the Shipping Rules localized address dataset on this same settings
	 * screen instead of serializing all 3,321 wards a second time.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets() {
		if ( ! $this->is_shipping_settings_screen() ) {
			return;
		}

		$script_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/js/admin/shipping-zones.js';
		$style_path  = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/css/admin/shipping-zones.css';
		$screen_mode = $this->get_shipping_zones_screen_mode();

		if ( '' === $screen_mode || ! file_exists( $script_path ) ) {
			return;
		}

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'yoohw-vietnam-store-tools-shipping-zones',
				YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/admin/shipping-zones.css',
				[],
				filemtime( $style_path )
			);
		}

		$dependencies = [ 'jquery', 'yoohw-vietnam-store-tools-shipping-rules' ];

		if ( 'editor' === $screen_mode ) {
			$dependencies[] = 'wc-enhanced-select';
			$this->add_region_picker_ward_capture();
		}

		wp_enqueue_script(
			'yoohw-vietnam-store-tools-shipping-zones',
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/shipping-zones.js',
			$dependencies,
			filemtime( $script_path ),
			true
		);

		wp_localize_script(
			'yoohw-vietnam-store-tools-shipping-zones',
			'yoohwVietnamStoreToolsShippingZones',
			[
				'locationType'  => self::LOCATION_TYPE,
				'zoneSummaries' => 'list' === $screen_mode ? $this->get_zone_ward_summaries() : [],
			]
		);
	}

	/**
	 * Capture ward values after WooCommerce initializes its editor data but
	 * before the dependent React region picker reads those locations.
	 *
	 * The plugin's main script intentionally does not depend on the core editor
	 * handle. Enqueuing that dependency during admin_enqueue_scripts would print
	 * WooCommerce's script before its settings screen localizes the handle.
	 *
	 * @return void
	 */
	private function add_region_picker_ward_capture() {
		$prefix = wp_json_encode( self::LOCATION_TYPE . ':' );
		$script = "(function () {\n"
			. "\tvar data = window.shippingZoneMethodsLocalizeScript;\n"
			. "\tvar prefix = {$prefix};\n"
			. "\twindow.yoohwVietnamStoreToolsShippingZoneInitialWards = [];\n"
			. "\tif (!data || !Array.isArray(data.locations)) { return; }\n"
			. "\twindow.yoohwVietnamStoreToolsShippingZoneInitialWards = data.locations.filter(function (location) { return String(location).indexOf(prefix) === 0; });\n"
			. "\tdata.locations = data.locations.filter(function (location) { return String(location).indexOf(prefix) !== 0; });\n"
			. '}());';

		wp_add_inline_script( 'wc-shipping-zone-methods', $script, 'after' );
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
	 * Build ward summaries keyed by zone ID for the zones list.
	 *
	 * @return array
	 */
	private function get_zone_ward_summaries() {
		$summaries = [];

		foreach ( $this->get_shipping_zones() as $zone ) {
			$zone_id   = $this->get_zone_id( $zone );
			$locations = $this->get_zone_locations( $zone );

			if ( ! $zone_id ) {
				continue;
			}

			$labels               = [];
			$has_native_locations = false;

			foreach ( $locations as $location ) {
				if ( ! is_object( $location ) || ! isset( $location->type, $location->code ) || self::LOCATION_TYPE !== $location->type ) {
					if ( is_object( $location ) && isset( $location->type ) && self::LOCATION_TYPE !== $location->type ) {
						$has_native_locations = true;
					}
					continue;
				}

				$code = self::sanitize_ward_location_code( $location->code );

				if ( '' === $code ) {
					continue;
				}

				list( $province, $ward ) = explode( ':', $code, 2 );
				$label                   = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_ward_name( $ward, $province ) . ', ' . Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_province_name( $province );
				$labels[ $label ]        = true;
			}

			if ( ! empty( $labels ) ) {
				$summaries[ $zone_id ] = [
					'labels'             => array_keys( $labels ),
					'hasNativeLocations' => $has_native_locations,
				];
			}
		}

		return $summaries;
	}

	/**
	 * Determine whether any configured zone has a ward restriction.
	 *
	 * @return bool
	 */
	private function has_ward_restricted_zones() {
		if ( null !== $this->has_ward_zones ) {
			return $this->has_ward_zones;
		}

		$this->has_ward_zones = false;

		foreach ( $this->get_shipping_zones() as $zone ) {
			foreach ( $this->get_zone_locations( $zone ) as $location ) {
				if ( is_object( $location ) && isset( $location->type ) && self::LOCATION_TYPE === $location->type ) {
					$this->has_ward_zones = true;
					break 2;
				}
			}
		}

		return $this->has_ward_zones;
	}

	/**
	 * Load zone records through APIs available from WooCommerce 8.9 onward.
	 *
	 * @return array
	 */
	private function get_shipping_zones() {
		if ( null !== $this->shipping_zones ) {
			return $this->shipping_zones;
		}

		$this->shipping_zones = [];

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $this->shipping_zones;
		}

		if ( is_callable( [ 'WC_Shipping_Zones', 'get_shipping_zones' ] ) ) {
			$zones = WC_Shipping_Zones::get_shipping_zones();
		} elseif ( is_callable( [ 'WC_Shipping_Zones', 'get_zones' ] ) ) {
			$zones = WC_Shipping_Zones::get_zones( 'admin' );
		} else {
			$zones = [];
		}

		$this->shipping_zones = is_array( $zones ) ? $zones : [];

		return $this->shipping_zones;
	}

	/**
	 * Get a zone ID from either the modern object API or WooCommerce 8.9 data.
	 *
	 * @param object|array $zone Zone record.
	 * @return int
	 */
	private function get_zone_id( $zone ) {
		if ( is_object( $zone ) && is_callable( [ $zone, 'get_id' ] ) ) {
			return absint( $zone->get_id() );
		}

		return is_array( $zone ) && isset( $zone['zone_id'] ) ? absint( $zone['zone_id'] ) : 0;
	}

	/**
	 * Get zone locations from either the modern object API or WooCommerce 8.9 data.
	 *
	 * @param object|array $zone Zone record.
	 * @return array
	 */
	private function get_zone_locations( $zone ) {
		if ( is_object( $zone ) && is_callable( [ $zone, 'get_zone_locations' ] ) ) {
			$locations = $zone->get_zone_locations();
		} elseif ( is_array( $zone ) && isset( $zone['zone_locations'] ) ) {
			$locations = $zone['zone_locations'];
		} else {
			$locations = [];
		}

		return is_array( $locations ) ? $locations : [];
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

	/**
	 * Identify the native zones list or an editable non-default zone.
	 *
	 * @return string `list`, `editor`, or an empty string.
	 */
	private function get_shipping_zones_screen_mode() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		if ( '' !== $section || isset( $_GET['instance_id'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		if ( isset( $_GET['zone_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
			$zone_id = absint( wp_unslash( $_GET['zone_id'] ) );

			return 0 < $zone_id ? 'editor' : '';
		}

		return 'list';
	}
}
