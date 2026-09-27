<?php
/** Read-only configuration contracts without a WordPress database. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
require __DIR__ . '/support/assertions.php';
$GLOBALS['options'] = [];
function __( $s ) { return $s; }
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function add_menu_page( $page_title, $menu_title ) { $GLOBALS['menu_labels'][] = $menu_title; return 'vst-menu'; }
function add_submenu_page( $parent, $page_title, $menu_title ) { $GLOBALS['submenu_labels'][] = [ $parent, $menu_title ]; }
function wc_get_base_location() { return $GLOBALS['location']; }
function wc_clean( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wc_strtoupper( $value ) { return strtoupper( $value ); }
function get_locale() { return 'en_US'; }
class Yoohw_Vietnam_Store_Tools_Tax_Invoice {
	public static function accepts_new_requests() { return 'yes' === get_option( 'invoice_requests', 'yes' ); }
}
class Yoohw_Vietnam_Store_Tools_Shipment_Tracking { const OPTION_LOOKUP_ENABLED = 'lookup'; }
foreach ( [ 'admin-menu', 'vietnam-address-data', 'bacs-vietqr', 'store-health' ] as $file ) {
	require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-' . $file . '.php';
}
$GLOBALS['menu_labels'] = [];
$GLOBALS['submenu_labels'] = [];
( new ReflectionClass( 'Yoohw_Vietnam_Store_Tools_Admin_Menu' ) )->newInstanceWithoutConstructor()->register_menu();
( new Yoohw_Vietnam_Store_Tools_Store_Health() )->register_menu();
vst_assert_same( [ 'Vietnam store' ], $GLOBALS['menu_labels'], 'Top-level menu retains brand label' );
vst_assert_same( [ [ 'yoohw-vietnam-store', 'Dashboard' ], [ 'yoohw-vietnam-store', 'Store health' ] ], $GLOBALS['submenu_labels'], 'Submenus use requested labels and order' );
function snapshot() {
	$rows = ( new Yoohw_Vietnam_Store_Tools_Store_Health() )->get_checks();
	return array_column( $rows, 1, 0 );
}
$GLOBALS['location'] = [ 'country' => 'VN', 'state' => '01' ];
$menu = 'Yoohw_Vietnam_Store_Tools_Admin_Menu';
$qr = 'Yoohw_Vietnam_Store_Tools_BACS_VietQR';
$checks = snapshot();
vst_assert_same( 'Good', $checks['Store province / city'], 'Bundled province recognized' );
vst_assert_same( 'Disabled', $checks['Bank transfer (BACS)'], 'Absent gateway is disabled' );
vst_assert_same( 'Enabled', $checks['Vietnam address fields'], 'Feature default matches core' );
vst_assert_same( 'Action available', $checks['Manual shipment tracking'], 'Manual tracking needs no connector' );
$GLOBALS['location']['state'] = 'UNKNOWN';
vst_assert_same( 'Needs attention', snapshot()['Store province / city'], 'Invalid VN state is actionable' );
$GLOBALS['location']['country'] = 'US';
vst_assert_true( ! isset( snapshot()['Store province / city'] ), 'Non-VN store does not require VN province' );
$GLOBALS['options']['woocommerce_bacs_settings'] = [ 'enabled' => 'no', $qr::SETTING_ENABLED => 'yes' ];
$checks = snapshot();
vst_assert_same( 'Enabled', $checks['VietQR bank transfer'], 'VietQR toggle independent of BACS' );
vst_assert_same( 'Needs attention', $checks['Usable VietQR bank account'], 'Missing account diagnosed' );
vst_assert_true( isset( $checks['Enable BACS to offer VietQR at checkout.'] ), 'Disabled BACS with QR diagnosed' );
foreach ( [ 'sort_code', 'bic' ] as $bank_field ) {
	$GLOBALS['options']['woocommerce_bacs_accounts'] = [ [ $bank_field => '970436', 'account_number' => '123 456' ] ];
	vst_assert_same( 'Good', snapshot()['Usable VietQR bank account'], 'Current and legacy bank BIN supported' );
}
$GLOBALS['options']['woocommerce_bacs_accounts'] = [ [ 'sort_code' => 'ABC', 'account_number' => '---' ] ];
vst_assert_same( 'Needs attention', snapshot()['Usable VietQR bank account'], 'Unusable account rejected' );
foreach ( [ 'yes', 'no' ] as $requests ) {
	foreach ( [ 'yes', 'no' ] as $workflow ) {
		$GLOBALS['options']['invoice_requests'] = $requests;
		$GLOBALS['options'][ $menu::OPTION_ELECTRONIC_INVOICE ] = $workflow;
		$checks = snapshot();
		vst_assert_same( 'yes' === $requests ? 'Enabled' : 'Disabled', $checks['Accept invoice requests at checkout'], 'Request toggle independent' );
		vst_assert_same( 'yes' === $workflow ? 'Enabled' : 'Disabled', $checks['Manage electronic invoice workflow'], 'Workflow toggle independent' );
	}
}
$GLOBALS['options']['lookup'] = 'no';
$GLOBALS['options'][ $menu::OPTION_CUSTOMER_SHIPMENT_DISPLAY ] = 'yes';
$GLOBALS['options'][ $menu::OPTION_PHONE_NORMALIZATION ] = 'no';
$checks = snapshot();
vst_assert_same( 'Disabled', $checks['Public order lookup'], 'Lookup independent of display' );
vst_assert_same( 'Enabled', $checks['Customer shipment information'], 'Customer display uses authoritative flag' );
vst_assert_same( 'Disabled', $checks['Vietnam phone validation and normalization'], 'Phone option respected' );
// No DB API or write API is defined: accidental scanning or persistence fails this suite.
vst_finish_contract_suite( 'store-health' );
