<?php
/** Surviving BACS/VietQR account and QR normalization contracts. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
require __DIR__ . '/support/assertions.php';

$GLOBALS['vst_options'] = [];
function add_action() {}
function add_filter() {}
function __( $value ) { return $value; }
function wc_clean( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function update_option( $name, $value ) { $GLOBALS['vst_options'][ $name ] = $value; }
function get_option( $name, $default = false ) { return $GLOBALS['vst_options'][ $name ] ?? $default; }
function get_bloginfo() { return 'Example Store'; }
function wp_specialchars_decode( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wc_price( $amount ) { return number_format( $amount, 0 ) . ' ₫'; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }

class WC_Order {
	public $currency = 'VND';
	public $total = 250000;
	public $payment_method = 'bacs';
	public function get_id() { return 60; }
	public function get_order_number() { return 'INV-60'; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_payment_method() { return $this->payment_method; }
}

require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-bacs-vietqr.php';
$ui = new Yoohw_Vietnam_Store_Tools_BACS_VietQR();
$preserved = [ 'enabled' => 'yes', 'title' => 'Core title', 'unknown_extension' => [ 'keep' => true ], Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_TRANSFER_CONTENT => 'KEEP-{order_number}' ];
$GLOBALS['vst_options']['woocommerce_bacs_settings'] = $preserved;
$GLOBALS['vst_options']['woocommerce_bacs_accounts'] = [ [ 'account_number' => 'preserved' ] ];
Yoohw_Vietnam_Store_Tools_BACS_VietQR::save_dashboard_settings( [ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED => 'yes', Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_IMAGE_TEMPLATE => 'invalid', 'title' => 'attacker', Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_TRANSFER_CONTENT => 'attacker' ] );
$stored = $GLOBALS['vst_options']['woocommerce_bacs_settings'];
foreach ( $preserved as $key => $value ) { vst_assert_same( $value, $stored[ $key ], 'Dashboard preserves unowned BACS key ' . $key ); }
vst_assert_same( [ [ 'account_number' => 'preserved' ] ], $GLOBALS['vst_options']['woocommerce_bacs_accounts'], 'Dashboard never writes accounts' );
vst_assert_same( 'yes', $stored[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED ], 'Enable persisted' );
vst_assert_same( 'no', $stored[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_INCLUDE_AMOUNT ], 'Unchecked amount disabled' );
vst_assert_same( 'qr_only', $stored[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_IMAGE_TEMPLATE ], 'Invalid template safely defaults' );
vst_assert_same( 'no', $stored[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_SHOW_EMAIL ], 'Unchecked email disabled' );
Yoohw_Vietnam_Store_Tools_BACS_VietQR::save_dashboard_settings( [ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED => [ 'yes' ], Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_IMAGE_TEMPLATE => 'compact2' ] );
vst_assert_same( 'no', Yoohw_Vietnam_Store_Tools_BACS_VietQR::get_dashboard_settings()[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED ], 'Malformed checkbox safely disabled' );
vst_assert_same( 'compact2', Yoohw_Vietnam_Store_Tools_BACS_VietQR::get_dashboard_settings()[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_IMAGE_TEMPLATE ], 'Allowed template retained' );
$fields = $ui->add_bacs_vietqr_settings( [ 'account_details' => [ 'type' => 'account_details' ] ] );
vst_assert_same( $fields, $ui->add_bacs_vietqr_settings( $fields ), 'Native field provider is idempotent' );
$order = new WC_Order();
$qr = new ReflectionMethod( $ui, 'get_vietqr_payment_accounts' );
if ( PHP_VERSION_ID < 80100 ) {
	$qr->setAccessible( true );
}
$GLOBALS['vst_options']['woocommerce_bacs_accounts'] = [
	[ 'bank_name' => 'QR Bank', 'account_name' => 'Shop', 'account_number' => '333 444', 'sort_code' => '970436' ],
];
$GLOBALS['vst_options']['woocommerce_bacs_settings'] = [
	Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED => 'no',
	Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_TRANSFER_CONTENT => 'PAY-{order_number}',
	Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_INCLUDE_AMOUNT => 'yes',
];

vst_assert_same( [], $qr->invoke( $ui, $order ), 'Disabled VietQR renders no account' );
$GLOBALS['vst_options']['woocommerce_bacs_settings'][ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED ] = 'yes';
$accounts = $qr->invoke( $ui, $order );
vst_assert_same( 1, count( $accounts ), 'Configured VietQR account is usable' );
vst_assert_same( '333444', $accounts[0]['account_number'], 'Account number is normalized' );
vst_assert_same( '970436', $accounts[0]['bank_bin'], 'Legacy sort code BIN remains supported' );
$GLOBALS['vst_options']['woocommerce_bacs_accounts'][0]['bic'] = '970418';
vst_assert_same( '970418', $qr->invoke( $ui, $order )[0]['bank_bin'], 'React-selected BIN wins over an old legacy sort code' );
$GLOBALS['vst_options']['woocommerce_bacs_accounts'][0]['bic'] = 'VCBVVNVX123';
vst_assert_same( '970436', $qr->invoke( $ui, $order )[0]['bank_bin'], 'Real SWIFT code does not shadow a legacy BIN' );
$GLOBALS['vst_options']['woocommerce_bacs_accounts'][0]['bic'] = '';
vst_assert_same( 'PAY-INV-60', $accounts[0]['transfer_content'], 'Order transfer template is expanded' );
vst_assert_same( '250000', $accounts[0]['amount'], 'VND QR includes amount' );
vst_assert_true( false !== strpos( $accounts[0]['qr_url'], '970436-333444' ), 'QR URL uses BIN and normalized account' );
vst_assert_true( false !== strpos( $accounts[0]['qr_url'], 'addInfo=PAY-INV-60' ), 'QR URL includes transfer reference' );

$GLOBALS['vst_options']['woocommerce_bacs_settings'][ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_INCLUDE_AMOUNT ] = 'no';
vst_assert_same( '', $qr->invoke( $ui, $order )[0]['amount'], 'Amount setting can omit QR amount' );
$GLOBALS['vst_options']['woocommerce_bacs_settings'][ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_INCLUDE_AMOUNT ] = 'yes';
$order->currency = 'USD';
vst_assert_same( '', $qr->invoke( $ui, $order )[0]['amount'], 'Non-VND QR omits amount' );
$order->currency = 'VND';
$order->payment_method = 'cod';
vst_assert_same( [], $qr->invoke( $ui, $order ), 'Non-BACS order has no VietQR' );

vst_finish_contract_suite( 'BACS/VietQR normalization' );
