<?php
/** VST-60 read-only native payment-link and shared BACS/VietQR contracts. */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/support/assertions.php';

$GLOBALS['vst_options'] = [];
$GLOBALS['vst_can_edit'] = true;
function add_action() {}
function add_filter() {}
function __( $value ) { return $value; }
function esc_html__( $value ) { return esc_html( $value ); }
function esc_attr__( $value ) { return esc_attr( $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_url_raw( $value ) { return $value; }
function wp_parse_url( $value, $part ) { return parse_url( $value, $part ); }
function current_user_can( $cap, $id = null ) { return 'edit_shop_order' === $cap && $GLOBALS['vst_can_edit']; }
function wc_get_order_status_name( $status ) { return ucfirst( $status ); }
function wc_clean( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function get_option( $name, $default = false ) { return $GLOBALS['vst_options'][ $name ] ?? $default; }
function apply_filters( $name, $value ) { return $value; }
function get_bloginfo() { return 'Example Store'; }
function wp_specialchars_decode( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wc_price( $amount ) { return number_format( $amount, 0 ) . ' ₫'; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function add_meta_box() {}

class WC_Order {
	public $id = 60;
	public $key = 'current-key';
	public $status = 'pending';
	public $total = 250000;
	public $currency = 'VND';
	public $payment_method = 'bacs';
	public $payable = true;
	public $paid = false;
	public $url_calls = 0;
	public $url_override = null;
	public function get_id() { return $this->id; }
	public function get_order_key() { return $this->key; }
	public function needs_payment() { return $this->payable; }
	public function get_checkout_payment_url() { ++$this->url_calls; return null !== $this->url_override ? $this->url_override : 'https://example.test/checkout/order-pay/60/?pay_for_order=true&key=' . $this->key; }
	public function get_status() { return $this->status; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_order_number() { return 'INV-60'; }
	public function get_payment_method() { return $this->payment_method; }
	public function is_paid() { return $this->paid; }
	public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
}

require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-bacs-vietqr.php';
$ui = new Yoohw_Vietnam_Store_Tools_BACS_VietQR();
$order = new WC_Order();
$GLOBALS['vst_options']['woocommerce_bacs_accounts'] = [
	[ 'bank_name' => 'Sample Bank', 'account_name' => 'Shop', 'account_number' => '123 456', 'sort_code' => '970436' ],
];
$GLOBALS['vst_options']['woocommerce_bacs_settings'] = [
	Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED => 'yes',
	Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_TRANSFER_CONTENT => 'PAY-{order_number}',
];

ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'key=current-key' ), 'Current WooCommerce URL is shown' );
vst_assert_true( false !== strpos( $html, 'PAY-INV-60' ), 'Copy text reuses normalized transfer reference' );
vst_assert_true( false !== strpos( $html, 'Sample Bank' ), 'BACS instructions include configured bank' );
vst_assert_true( false === strpos( $html, 'img.vietqr.io' ), 'Shared text excludes third-party QR URL' );
vst_assert_same( 1, $order->url_calls, 'Native URL method is sole source' );

$order->key = 'new-key';
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'key=new-key' ) && false === strpos( $html, 'key=current-key' ), 'Admin render reflects regenerated key' );

$order->key = '';
$before = $order->url_calls;
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_same( $before, $order->url_calls, 'Missing key never calls native URL method' );
vst_assert_true( false !== strpos( $html, 'no WooCommerce order key' ), 'Missing key has a useful reason' );
$order->key = 'new-key';
$order->status = 'failed';
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'key=new-key' ), 'Failed payable order can use native link' );
$order->url_override = 'javascript:alert(1)';
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false === strpos( $html, 'javascript:' ) && false !== strpos( $html, 'did not provide a usable payment link' ), 'Non-HTTP URL filter result is not shared' );
$order->url_override = null;

$order->payable = false;
$order->status = 'on-hold';
$before = $order->url_calls;
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_same( $before, $order->url_calls, 'On-hold order does not generate URL' );
vst_assert_true( false !== strpos( $html, 'PAY-INV-60' ) && false === strpos( $html, 'Payment link:' ), 'On-hold BACS keeps instructions without link' );

$order->status = 'completed';
$order->paid = true;
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false === strpos( $html, 'Copy payment instructions' ) && false !== strpos( $html, 'already paid' ), 'Paid order has no sharing action' );
$order->paid = false;
$order->status = 'pending';
$order->total = 0;
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'no amount to pay' ) && false === strpos( $html, 'Copy payment instructions' ), 'Zero-total order has neither sharing action' );

$order->total = 250000;
$order->payable = true;
$GLOBALS['vst_can_edit'] = false;
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_same( '', $html, 'Capability denial reveals no link or copy payload' );
$GLOBALS['vst_can_edit'] = true;

$GLOBALS['vst_options']['woocommerce_bacs_accounts'] = [ [ 'bank_name' => 'Plain Bank', 'account_number' => '111 222' ] ];
$GLOBALS['vst_options']['woocommerce_bacs_settings'][ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED ] = 'no';
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'Plain Bank' ) && false !== strpos( $html, '111222' ), 'Plain BACS instructions work without VietQR' );
$qr = new ReflectionMethod( $ui, 'get_vietqr_payment_accounts' );
$qr->setAccessible( true );
vst_assert_same( [], $qr->invoke( $ui, $order ), 'QR renderer stays disabled' );

$GLOBALS['vst_options']['woocommerce_bacs_settings'][ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED ] = 'yes';
$GLOBALS['vst_options']['woocommerce_bacs_accounts'] = [
	[ 'bank_name' => 'QR Bank', 'account_number' => '333 444', 'sort_code' => '970436' ],
];
$qr_accounts = $qr->invoke( $ui, $order );
vst_assert_same( '333444', $qr_accounts[0]['account_number'], 'QR and copy use the same account normalization' );
vst_assert_same( 'PAY-INV-60', $qr_accounts[0]['transfer_content'], 'QR and copy use the same transfer reference' );
vst_assert_same( '250000', $qr_accounts[0]['amount'], 'VND QR keeps the configured amount' );
vst_assert_true( false !== strpos( $qr_accounts[0]['qr_url'], '970436-333444' ), 'Existing VietQR URL construction remains' );
$GLOBALS['vst_options']['woocommerce_bacs_settings'][ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_INCLUDE_AMOUNT ] = 'no';
vst_assert_same( '', $qr->invoke( $ui, $order )[0]['amount'], 'Disabled QR amount remains omitted' );
$GLOBALS['vst_options']['woocommerce_bacs_settings'][ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_INCLUDE_AMOUNT ] = 'yes';
$order->currency = 'USD';
vst_assert_same( '', $qr->invoke( $ui, $order )[0]['amount'], 'Non-VND QR amount remains omitted' );
$order->currency = 'VND';

$order->payment_method = 'cod';
ob_start();
$ui->render_payment_link_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false === strpos( $html, 'Copy payment instructions' ) && false !== strpos( $html, 'Copy payment link' ), 'Non-BACS order only exposes native link' );
// The fixture intentionally defines no order writes or reconciliation APIs.
vst_finish_contract_suite( 'VST-60 payment link' );
