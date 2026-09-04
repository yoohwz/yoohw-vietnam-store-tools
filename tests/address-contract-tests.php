<?php
/**
 * Standalone contract tests for Vietnamese address integration.
 *
 * Run with: php tests/address-contract-tests.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['vck_test_doing_ajax']    = false;
$GLOBALS['vck_test_manage_woo']    = false;
$GLOBALS['vck_test_nonce_valid']   = false;
$GLOBALS['vck_test_cache_deletes'] = [];

function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { unset( $hook ); return $value; }
function wc_clean( $value ) { return is_scalar( $value ) ? trim( (string) $value ) : ''; }
function wc_strtoupper( $value ) { return mb_strtoupper( $value, 'UTF-8' ); }
function wc_normalize_postcode( $value ) { return strtoupper( preg_replace( '/\s+/', '', (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_html( $value ) { return (string) $value; }
function __( $value ) { return $value; }
function get_locale() { return 'en_US'; }
function wp_doing_ajax() { return $GLOBALS['vck_test_doing_ajax']; }
function current_user_can( $capability ) { return 'manage_woocommerce' === $capability && $GLOBALS['vck_test_manage_woo']; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_verify_nonce( $nonce, $action ) { return $GLOBALS['vck_test_nonce_valid'] && 'valid-nonce' === $nonce && 'wc_shipping_zones_nonce' === $action; }
function wp_cache_delete( $key, $group ) { $GLOBALS['vck_test_cache_deletes'][] = [ $key, $group ]; return true; }

class WC_Cache_Helper {
	public static function get_cache_prefix( $group ) {
		return 'shipping_zones' === $group ? 'vck-test-prefix-' : '';
	}
}

class WC_Shipping_Zones {
	public static $zones = [];

	public static function get_shipping_zones() {
		return self::$zones;
	}
}

class VCK_Address_Test_Shipping_Zone {
	private $id;
	private $locations;

	public function __construct( $id, $locations = [] ) {
		$this->id        = $id;
		$this->locations = $locations;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_zone_locations() {
		return $this->locations;
	}

	public function clear_locations( $types ) {
		$types = is_array( $types ) ? $types : [ $types ];
		$this->locations = array_values(
			array_filter(
				$this->locations,
				function ( $location ) use ( $types ) {
					return ! in_array( $location->type, $types, true );
				}
			)
		);
	}

	public function add_location( $code, $type ) {
		$this->locations[] = (object) [
			'code' => $code,
			'type' => $type,
		];
	}
}

class WC_Order {
	public $billing_state = '01';
	public $billing_city = '';

	public function set_billing_state( $value ) { $this->billing_state = $value; }
	public function set_billing_city( $value ) { $this->billing_city = $value; }
}

class WC_Admin_Meta_Boxes {
	public static $errors = [];

	public static function add_error( $message ) { self::$errors[] = $message; }
}

class Yoohw_Vietnam_Store_Tools_Request_Security {
	public static function verify_admin_order_request() { return true; }
	public static function has_post_key( $key ) { return array_key_exists( $key, $_POST ); }
	public static function get_post_text( $key ) { return isset( $_POST[ $key ] ) ? wc_clean( $_POST[ $key ] ) : ''; }
}

class VCK_Address_Test_WPDB {
	public $prefix = 'wp_';

	public function prepare( $query ) {
		$args = array_slice( func_get_args(), 1 );

		foreach ( $args as $arg ) {
			$replacement = "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = preg_replace( '/%s/', $replacement, $query, 1 );
		}

		return $query;
	}
}

require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-vietnam-address-data.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-address-fields.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-admin-order-fields.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-shipping-zones.php';

$failures  = [];
$assertions = 0;

function assert_address_contract( $expected, $actual, $label ) {
	global $assertions, $failures;

	++$assertions;

	if ( $expected !== $actual ) {
		$failures[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	}
}

$provinces        = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_provinces();
$province_code    = (string) array_key_first( $provinces );
$wards            = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( $province_code );
$ward_code        = (string) array_key_first( $wards );
$ward_codes       = array_keys( $wards );
$second_ward_code = isset( $ward_codes[1] ) ? (string) $ward_codes[1] : $ward_code;

$address_fields = new Yoohw_Vietnam_Store_Tools_Address_Fields();
$formatted     = $address_fields->format_vietnam_address_replacements(
	[ '{state}' => $province_code, '{city}' => $ward_code ],
	[ 'country' => '', 'state' => $province_code, 'city' => $ward_code ]
);

assert_address_contract( $provinces[ $province_code ], $formatted['{state}'], 'Missing-country province code is formatted' );
assert_address_contract( $wards[ $ward_code ], $formatted['{city}'], 'Missing-country ward code is formatted' );

$international = $address_fields->format_vietnam_address_replacements(
	[ '{state}' => 'CA', '{city}' => 'Los Angeles' ],
	[ 'country' => '', 'state' => 'CA', 'city' => 'Los Angeles' ]
);
assert_address_contract( 'CA', $international['{state}'], 'Unknown missing-country address is not treated as Vietnamese' );

$admin_fields = new Yoohw_Vietnam_Store_Tools_Admin_Order_Fields();
$order        = new WC_Order();
$_POST        = [
	'_billing_country' => 'VN',
	'_billing_state'   => $province_code,
	'_billing_city'    => $ward_code,
];
$admin_fields->update_admin_order_address_field( '_billing_state', $province_code, $order );
$admin_fields->update_admin_order_address_field( '_billing_city', $ward_code, $order );
assert_address_contract( $province_code, $order->billing_state, 'Valid admin province is saved' );
assert_address_contract( $ward_code, $order->billing_city, 'Valid admin ward is saved' );

$other_province = '';
foreach ( array_keys( $provinces ) as $candidate ) {
	if ( $candidate !== $province_code && ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward_code, $candidate ) ) {
		$other_province = $candidate;
		break;
	}
}

$other_wards     = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( $other_province );
$mismatched_ward = (string) array_key_first( $other_wards );

$_POST['_billing_state'] = $other_province;
$admin_fields->update_admin_order_address_field( '_billing_state', $other_province, $order );
$admin_fields->update_admin_order_address_field( '_billing_city', $ward_code, $order );
assert_address_contract( $province_code, $order->billing_state, 'Invalid admin province/ward pair does not overwrite province' );
assert_address_contract( $ward_code, $order->billing_city, 'Invalid admin province/ward pair does not overwrite ward' );
assert_address_contract( 1, count( WC_Admin_Meta_Boxes::$errors ), 'Invalid admin pair reports one error' );

$shipping_zones = new Yoohw_Vietnam_Store_Tools_Shipping_Zones();
$location_types = $shipping_zones->register_location_type( [ 'postcode', 'state', 'country', 'continent' ] );
assert_address_contract( true, in_array( Yoohw_Vietnam_Store_Tools_Shipping_Zones::LOCATION_TYPE, $location_types, true ), 'Ward location type is registered additively' );
assert_address_contract(
	$province_code . ':' . $ward_code,
	Yoohw_Vietnam_Store_Tools_Shipping_Zones::sanitize_ward_location_code( $province_code . ':' . $ward_code ),
	'Valid ward shipping-zone code is preserved'
);
assert_address_contract(
	'',
	Yoohw_Vietnam_Store_Tools_Shipping_Zones::sanitize_ward_location_code( $other_province . ':' . $ward_code ),
	'Invalid province/ward shipping-zone pair is rejected'
);
assert_address_contract(
	'',
	Yoohw_Vietnam_Store_Tools_Shipping_Zones::sanitize_ward_location_code( [ $province_code, $ward_code ] ),
	'Non-scalar ward shipping-zone code is rejected'
);

$native_location = (object) [ 'type' => 'state', 'code' => 'VN:' . $province_code ];
$saved_zone      = new VCK_Address_Test_Shipping_Zone(
	42,
	[
		$native_location,
		(object) [ 'type' => Yoohw_Vietnam_Store_Tools_Shipping_Zones::LOCATION_TYPE, 'code' => $province_code . ':' . $second_ward_code ],
	]
);
$GLOBALS['vck_test_doing_ajax']  = true;
$GLOBALS['vck_test_manage_woo']  = true;
$GLOBALS['vck_test_nonce_valid'] = true;
$_REQUEST['action']               = 'woocommerce_shipping_zone_methods_save_changes';
$_POST                            = [
	'wc_shipping_zones_nonce' => 'valid-nonce',
	'changes'                 => [
		'zone_locations' => [
			'state:VN:' . $province_code,
			'vck_ward:' . $province_code . ':' . $ward_code,
			'vck_ward:' . $province_code . ':' . $ward_code,
			'vck_ward:' . $other_province . ':' . $ward_code,
			'vck_ward:not-valid',
		],
	],
];
$shipping_zones->sync_ward_locations_before_save( $saved_zone, null );
$saved_locations = $saved_zone->get_zone_locations();
$saved_native     = array_values( array_filter( $saved_locations, function ( $location ) { return 'state' === $location->type; } ) );
$saved_wards      = array_values( array_filter( $saved_locations, function ( $location ) { return 'vck_ward' === $location->type; } ) );
assert_address_contract( 1, count( $saved_native ), 'Ward save preserves native zone locations' );
assert_address_contract( 'VN:' . $province_code, $saved_native[0]->code, 'Ward save leaves native location value unchanged' );
assert_address_contract( 1, count( $saved_wards ), 'Ward save validates and deduplicates submitted locations' );
assert_address_contract( $province_code . ':' . $ward_code, $saved_wards[0]->code, 'Ward save stores normalized province/ward pair' );

$_POST['changes']['zone_locations'] = [ 'state:VN:' . $province_code ];
$shipping_zones->sync_ward_locations_before_save( $saved_zone, null );
$remaining_wards = array_filter( $saved_zone->get_zone_locations(), function ( $location ) { return 'vck_ward' === $location->type; } );
assert_address_contract( 0, count( $remaining_wards ), 'Removing all ward selections clears only ward locations' );
assert_address_contract( 1, count( $saved_zone->get_zone_locations() ), 'Removing wards preserves native locations' );

$saved_zone->add_location( $province_code . ':' . $ward_code, 'vck_ward' );
$_REQUEST['action'] = 'not_the_zone_editor';
$shipping_zones->sync_ward_locations_before_save( $saved_zone, null );
$preserved_wards = array_filter( $saved_zone->get_zone_locations(), function ( $location ) { return 'vck_ward' === $location->type; } );
assert_address_contract( 1, count( $preserved_wards ), 'Non-editor saves preserve stored ward locations' );

$_REQUEST = [];
$_POST    = [];
$GLOBALS['vck_test_doing_ajax']  = false;
$GLOBALS['vck_test_manage_woo']  = false;
$GLOBALS['vck_test_nonce_valid'] = false;

$GLOBALS['wpdb'] = new VCK_Address_Test_WPDB();
$criteria        = [
	"( ( location_type = 'country' AND location_code = 'VN' )",
	"OR ( location_type = 'state' AND location_code = 'VN:{$province_code}' )",
	"OR ( location_type = 'continent' AND location_code = 'AS' )",
	'OR ( location_type IS NULL ) )',
];
$ward_criteria   = $shipping_zones->add_ward_zone_criteria(
	$criteria,
	[
		'destination' => [
			'country' => 'VN',
			'state'   => $province_code,
			'city'    => $ward_code,
		],
	],
	[]
);
$ward_sql = implode( ' ', $ward_criteria );
assert_address_contract( true, false !== strpos( $ward_sql, "location_type = 'vck_ward'" ) && false !== strpos( $ward_sql, $province_code . ':' . $ward_code ), 'Zone criteria include the exact ward location' );
assert_address_contract( true, false !== strpos( $ward_sql, 'zones.zone_id NOT IN' ) && false !== strpos( $ward_sql, 'zones.zone_id IN' ), 'Ward-restricted zones cannot fall through broader regions' );
assert_address_contract( true, false !== strpos( $ward_sql, 'NOT EXISTS' ) && false !== strpos( $ward_sql, "location_type IN ( 'country', 'state', 'continent' )" ), 'Exact ward OR is limited to zones without native broad regions' );
assert_address_contract( true, false !== strpos( $ward_sql, 'OR ( location_type IS NULL ) )' ), 'Ward-only eligibility remains inside the core region OR group' );

$second_ward_criteria = $shipping_zones->add_ward_zone_criteria(
	$criteria,
	[
		'destination' => [
			'country' => 'VN',
			'state'   => $province_code,
			'city'    => $second_ward_code,
		],
	],
	[]
);
assert_address_contract( true, false !== strpos( implode( ' ', $second_ward_criteria ), $province_code . ':' . $second_ward_code ), 'A second configured ward receives its own exact criterion' );

$postcode_constraint = 'AND zones.zone_id NOT IN (99)';
$postcode_criteria   = array_merge( $criteria, [ $postcode_constraint ] );
$ward_postcode_sql   = implode(
	' ',
	$shipping_zones->add_ward_zone_criteria(
		$postcode_criteria,
		[
			'destination' => [
				'country' => 'VN',
				'state'   => $province_code,
				'city'    => $ward_code,
			],
		],
		[]
	)
);
assert_address_contract( true, false !== strpos( $ward_postcode_sql, $postcode_constraint ), 'Ward criteria preserve WooCommerce postcode exclusions' );

$missing_ward_criteria = $shipping_zones->add_ward_zone_criteria(
	$criteria,
	[
		'destination' => [
			'country' => 'VN',
			'state'   => $province_code,
			'city'    => '',
		],
	],
	[]
);
$missing_ward_sql = implode( ' ', $missing_ward_criteria );
assert_address_contract( true, false !== strpos( $missing_ward_sql, 'zones.zone_id NOT IN' ), 'Ward-restricted zones are excluded until a valid ward is known' );
assert_address_contract( false, false !== strpos( $missing_ward_sql, $province_code . ':' . $ward_code ), 'Missing ward does not inject an exact ward match' );

$international_ward_sql = implode(
	' ',
	$shipping_zones->add_ward_zone_criteria(
		$criteria,
		[
			'destination' => [
				'country' => 'US',
				'state'   => 'CA',
				'city'    => $ward_code,
			],
		],
		[]
	)
);
assert_address_contract( true, false !== strpos( $international_ward_sql, 'zones.zone_id NOT IN' ), 'Non-Vietnamese destinations exclude ward-restricted zones' );
assert_address_contract( false, false !== strpos( $international_ward_sql, 'NOT EXISTS' ), 'Non-Vietnamese destinations do not open ward-only eligibility' );

WC_Shipping_Zones::$zones = [];
$GLOBALS['vck_test_cache_deletes'] = [];
$no_ward_cache_integration = new Yoohw_Vietnam_Store_Tools_Shipping_Zones();
$package = [
	'destination' => [
		'country'  => 'VN',
		'state'    => $province_code,
		'city'     => $ward_code,
		'postcode' => '700 00',
	],
];
$no_ward_cache_integration->invalidate_ward_zone_cache( [ $package ] );
assert_address_contract( 0, count( $GLOBALS['vck_test_cache_deletes'] ), 'Cache remains untouched when no ward-restricted zone exists' );

WC_Shipping_Zones::$zones = [
	new VCK_Address_Test_Shipping_Zone(
		42,
		[ (object) [ 'type' => 'vck_ward', 'code' => $province_code . ':' . $ward_code ] ]
	),
];
$GLOBALS['vck_test_cache_deletes'] = [];
$cache_integration = new Yoohw_Vietnam_Store_Tools_Shipping_Zones();
$cache_integration->invalidate_ward_zone_cache( [ $package ] );
$cache_integration->invalidate_ward_zone_cache( [ $package ] );
assert_address_contract( 1, count( $GLOBALS['vck_test_cache_deletes'] ), 'First package clears a persistent cache value and an unchanged ward is deduplicated' );

$second_package = $package;
$second_package['destination']['city'] = $second_ward_code;
$cache_integration->invalidate_ward_zone_cache( [ $second_package ] );
assert_address_contract( 2, count( $GLOBALS['vck_test_cache_deletes'] ), 'Changing wards under the same core key clears the zone cache again' );
assert_address_contract( $GLOBALS['vck_test_cache_deletes'][0][0], $GLOBALS['vck_test_cache_deletes'][1][0], 'Different wards share the same WooCommerce country/state/postcode cache key' );

$missing_package = $package;
$missing_package['destination']['city'] = '';
$cache_integration->invalidate_ward_zone_cache( [ $missing_package ] );
assert_address_contract( 3, count( $GLOBALS['vck_test_cache_deletes'] ), 'Valid ward to missing ward transition clears a positive cache result' );

$invalid_package = $package;
$invalid_package['destination']['city'] = '99999';
$cache_integration->invalidate_ward_zone_cache( [ $invalid_package ] );
assert_address_contract( 3, count( $GLOBALS['vck_test_cache_deletes'] ), 'Equivalent invalid ward outcomes avoid redundant cache deletion' );

$mismatched_package = $package;
$mismatched_package['destination']['city'] = $mismatched_ward;
$cache_integration->invalidate_ward_zone_cache( [ $mismatched_package ] );
assert_address_contract( 3, count( $GLOBALS['vck_test_cache_deletes'] ), 'Province/ward mismatch shares the fail-closed invalid cache outcome' );

$cache_integration->invalidate_ward_zone_cache( [ $package ] );
assert_address_contract( 4, count( $GLOBALS['vck_test_cache_deletes'] ), 'Missing or invalid ward to valid ward transition clears the cache' );

$fresh_cache_integration = new Yoohw_Vietnam_Store_Tools_Shipping_Zones();
$fresh_cache_integration->invalidate_ward_zone_cache( [ $package ] );
assert_address_contract( 5, count( $GLOBALS['vck_test_cache_deletes'] ), 'A fresh request clears the first encountered persistent cache value' );

$international_package = $package;
$international_package['destination']['country'] = 'US';
$fresh_cache_integration->invalidate_ward_zone_cache( [ $international_package ] );
assert_address_contract( 5, count( $GLOBALS['vck_test_cache_deletes'] ), 'Non-Vietnamese package cache remains untouched' );

$order_copy_script     = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin/order-address-fields.js' );
$profile_copy_script   = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin/address-fields.js' );
$blocks_script         = file_get_contents( dirname( __DIR__ ) . '/assets/js/frontend/blocks-address-fields.js' );
$shipping_zones_script = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin/shipping-zones.js' );
$shipping_zones_style  = file_get_contents( dirname( __DIR__ ) . '/assets/css/admin/shipping-zones.css' );
$shipping_zones_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-shipping-zones.php' );
$plugin_source         = file_get_contents( dirname( __DIR__ ) . '/yoohw-vietnam-store-tools.php' );
$readme_source         = file_get_contents( dirname( __DIR__ ) . '/readme.txt' );

assert_address_contract( true, false !== strpos( $order_copy_script, "a.billing-same-as-shipping" ) && false !== strpos( $order_copy_script, "setSelectedWard( 'shipping'" ), 'Admin order copy restores shipping ward' );
assert_address_contract( true, false !== strpos( $profile_copy_script, 'button.js_copy-billing' ) && false !== strpos( $profile_copy_script, "setSelected( 'shipping', 'state'" ), 'Customer profile copy restores Vietnamese state and ward' );
assert_address_contract( true, false !== strpos( $blocks_script, 'billingAddress' ) && false !== strpos( $blocks_script, 'shippingAddress' ) && false !== strpos( $blocks_script, 'dataApi.subscribe' ), 'Checkout Blocks observes copied billing and shipping addresses' );
assert_address_contract( true, false !== strpos( $shipping_zones_script, 'wc_region_picker_update' ) && false !== strpos( $shipping_zones_script, "data.locationType + ':'" ) && false !== strpos( $shipping_zones_script, 'coreLocations.concat' ) && false !== strpos( $shipping_zones_script, 'window.setTimeout(dispatchCombinedLocations, 0)' ), 'Shipping Zone editor preserves native regions while adding ward selections after the core native event' );
assert_address_contract( true, false !== strpos( $shipping_zones_source, "wp_add_inline_script( 'wc-shipping-zone-methods'" ) && false !== strpos( $shipping_zones_source, 'ShippingZoneInitialWards' ) && false !== strpos( $shipping_zones_script, 'initialWards' ), 'Ward locations are captured before WooCommerce renders its native region picker' );
assert_address_contract( true, false === strpos( $shipping_zones_source, "\$dependencies[] = 'wc-shipping-zone-methods'" ) && false === strpos( $shipping_zones_source, "\$dependencies[] = 'wc-shipping-zones'" ), 'Shipping Zone assets do not enqueue core scripts before WooCommerce localizes them' );
assert_address_contract( true, false !== strpos( $shipping_zones_script, 'rulesData.provinces' ) && false !== strpos( $shipping_zones_script, 'rulesData.wards' ) && false === strpos( $shipping_zones_source, "'provinces'    =>" ), 'Shipping Zone editor reuses the existing localized address dataset' );
assert_address_contract( true, false !== strpos( $shipping_zones_script, "attr('for', 'vck-shipping-zone-wards')" ) && false !== strpos( $shipping_zones_script, 'MutationObserver' ), 'Ward editor is labelled and zone-list enhancements survive a native rerender' );
assert_address_contract( true, false !== strpos( $shipping_zones_script, 'summary.hasNativeLocations' ) && false !== strpos( $shipping_zones_script, '$cell.empty()' ), 'Ward-only zones replace the misleading native Everywhere summary' );
assert_address_contract( true, false !== strpos( $shipping_zones_style, '.vck-shipping-zone-wards__label' ) && false !== strpos( $shipping_zones_style, '.vck-shipping-zone-ward-labels' ), 'Shipping Zone editor and list styles are present' );
assert_address_contract( true, false !== strpos( $shipping_zones_source, 'woocommerce_before_shipping_zone_object_save' ) && false !== strpos( $shipping_zones_source, 'woocommerce_get_zone_criteria' ), 'Shipping Zone integration covers persistence and matching hooks' );
assert_address_contract( true, false !== strpos( $shipping_zones_source, 'woocommerce_cart_shipping_packages' ) && false !== strpos( $shipping_zones_source, 'wc_shipping_zone_' ) && false !== strpos( $shipping_zones_source, 'has_ward_restricted_zones' ), 'Shipping Zone integration handles the core cache key only when ward zones exist' );
assert_address_contract( true, false !== strpos( $shipping_zones_source, "get_zones( 'admin' )" ) && false !== strpos( $shipping_zones_source, 'get_shipping_zones' ), 'Shipping Zone lookup supports WooCommerce 8.9 and the modern object API' );
assert_address_contract( true, false !== strpos( $plugin_source, "'includes/class-vietnam-commerce-kit-shipping-zones.php'" ) && false !== strpos( $plugin_source, 'new Yoohw_Vietnam_Store_Tools_Shipping_Zones()' ), 'Shipping Zone restrictions remain bootstrapped independently from the address feature toggle' );
assert_address_contract( true, false !== strpos( $readme_source, 'Deactivating the plugin removes ward narrowing' ) && false !== strpos( $readme_source, 'ward-only zones stop matching' ), 'Rollback behavior is documented for mixed and ward-only zones' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS: ' . $assertions . " Vietnamese address contract checks.\n";
