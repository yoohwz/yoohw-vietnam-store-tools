<?php
/**
 * Standalone contract tests for Vietnamese address integration.
 *
 * Run with: php tests/address-contract-tests.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { unset( $hook ); return $value; }
function wc_clean( $value ) { return is_scalar( $value ) ? trim( (string) $value ) : ''; }
function wc_strtoupper( $value ) { return mb_strtoupper( $value, 'UTF-8' ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_html( $value ) { return (string) $value; }
function __( $value ) { return $value; }
function get_locale() { return 'en_US'; }

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

$provinces     = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_provinces();
$province_code = (string) array_key_first( $provinces );
$wards         = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( $province_code );
$ward_code     = (string) array_key_first( $wards );

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

$order_copy_script     = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin/order-address-fields.js' );
$profile_copy_script   = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin/address-fields.js' );
$blocks_script         = file_get_contents( dirname( __DIR__ ) . '/assets/js/frontend/blocks-address-fields.js' );
$shipping_zones_script = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin/shipping-zones.js' );
$shipping_zones_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-shipping-zones.php' );

assert_address_contract( true, false !== strpos( $order_copy_script, "a.billing-same-as-shipping" ) && false !== strpos( $order_copy_script, "setSelectedWard( 'shipping'" ), 'Admin order copy restores shipping ward' );
assert_address_contract( true, false !== strpos( $profile_copy_script, 'button.js_copy-billing' ) && false !== strpos( $profile_copy_script, "setSelected( 'shipping', 'state'" ), 'Customer profile copy restores Vietnamese state and ward' );
assert_address_contract( true, false !== strpos( $blocks_script, 'billingAddress' ) && false !== strpos( $blocks_script, 'shippingAddress' ) && false !== strpos( $blocks_script, 'dataApi.subscribe' ), 'Checkout Blocks observes copied billing and shipping addresses' );
assert_address_contract( true, false !== strpos( $shipping_zones_script, 'wc_region_picker_update' ) && false !== strpos( $shipping_zones_script, "data.locationType + ':'" ), 'Shipping Zone editor preserves native regions while adding ward selections' );
assert_address_contract( true, false !== strpos( $shipping_zones_source, 'woocommerce_before_shipping_zone_object_save' ) && false !== strpos( $shipping_zones_source, 'woocommerce_get_zone_criteria' ), 'Shipping Zone integration covers persistence and matching hooks' );
assert_address_contract( true, false !== strpos( $shipping_zones_source, 'woocommerce_cart_shipping_packages' ) && false !== strpos( $shipping_zones_source, 'wc_shipping_zone_' ), 'Shipping Zone integration handles the core cache key that omits city' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS: ' . $assertions . " Vietnamese address contract checks.\n";
