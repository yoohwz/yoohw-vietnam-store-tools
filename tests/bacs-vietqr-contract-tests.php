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
vst_assert_same( [ 'cod', 'third_party', 'cheque' ], $ui->use_native_bacs_settings( [ 'cod', 'bacs', 'third_party', 'cheque' ] ), 'Only BACS falls back; other sections retain order' );
vst_assert_same( [ 'cod' ], $ui->use_native_bacs_settings( [ 'cod' ] ), 'Absent BACS leaves the list unchanged' );
vst_assert_same( [], $ui->use_native_bacs_settings( [ 'bacs', 'bacs' ] ), 'No duplicate BACS section survives' );
vst_assert_same( null, $ui->use_native_bacs_settings( null ), 'Unexpected input is preserved' );
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
