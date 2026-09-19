<?php
/**
 * PPCP 4.1.3 standard Place-order lifecycle contracts.
 *
 * Run with: php tests/paypal-vnd-usd-lifecycle-contract-tests.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

function __( $value ) { return $value; }
function add_filter() {}
function add_action() {}
function get_woocommerce_currency() { return 'VND'; }
function is_checkout() { return true; }
function is_order_received_page() { return false; }
function is_checkout_pay_page() { return false; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000045'; }
function wc_format_decimal( $value ) { return (string) $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

$GLOBALS['vst_paypal_conversion_enabled'] = true;

function get_option( $key, $default = false ) {
	if ( 'woocommerce_ppcp-gateway_settings' === $key ) {
		return array(
			'yoohw_vietnam_store_tools_paypal_vnd_usd_enabled' => $GLOBALS['vst_paypal_conversion_enabled'] ? 'yes' : 'no',
			'yoohw_vietnam_store_tools_paypal_vnd_usd_rate'    => '25000',
		);
	}
	if ( 'woocommerce-ppcp-settings' === $key ) {
		return array( 'authorize_only' => false );
	}
	return $default;
}

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) { unset( $code ); $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

final class Yoohw_Vietnam_Store_Tools_Logger {
	public static function log() {}
}

final class Fake_PayPal_Session {
	public $data = array();
	public function get( $key ) { return $this->data[ $key ] ?? null; }
	public function set( $key, $value ) { $this->data[ $key ] = $value; }
}

final class Fake_PayPal_Cart {
	public $hash = 'cart-hash-45';
	public $total = '135000';
	public function get_cart_hash() { return $this->hash; }
	public function get_total( $context = '' ) { unset( $context ); return $this->total; }
}

$GLOBALS['vst_fake_wc'] = (object) array(
	'session' => new Fake_PayPal_Session(),
	'cart'    => new Fake_PayPal_Cart(),
);

function WC() { return $GLOBALS['vst_fake_wc']; }

class WC_Order {
	public $meta = array();
	public function get_payment_method() { return 'ppcp-gateway'; }
	public function get_meta( $key, $single = true ) { unset( $single ); return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() {}
}

final class Fake_PayPal_Amount {
	private $currency;
	private $value;
	public function __construct( $currency, $value ) { $this->currency = $currency; $this->value = $value; }
	public function currency_code() { return $this->currency; }
	public function value_str() { return $this->value; }
}

final class Fake_PayPal_Unit {
	private $amount;
	public function __construct( $currency, $value ) { $this->amount = new Fake_PayPal_Amount( $currency, $value ); }
	public function amount() { return $this->amount; }
	public function reference_id() { return 'default'; }
}

final class Fake_PayPal_Order {
	private $id;
	private $units;
	public function __construct( $id, $currency, $value ) {
		$this->id = $id;
		$this->units = array( new Fake_PayPal_Unit( $currency, $value ) );
	}
	public function id() { return $this->id; }
	public function purchase_units() { return $this->units; }
}

final class Fake_Checkout_Errors {
	public $codes = array();
	public function add( $code, $message ) { unset( $message ); $this->codes[] = $code; }
}

require __DIR__ . '/support/assertions.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-paypal-conversion.php';

$payload = array(
	'intent'         => 'CAPTURE',
	'payment_source' => array( 'paypal' => array() ),
	'purchase_units' => array(
		array(
			'reference_id' => 'default',
			'amount'       => array(
				'currency_code' => 'VND',
				'value'         => '135000',
				'breakdown'     => array(
					'item_total' => array( 'currency_code' => 'VND', 'value' => '100000' ),
					'shipping'   => array( 'currency_code' => 'VND', 'value' => '20000' ),
					'tax_total'  => array( 'currency_code' => 'VND', 'value' => '15000' ),
				),
			),
			'items'        => array(
				array(
					'name'        => 'Lifecycle item',
					'quantity'    => '2',
					'unit_amount' => array( 'currency_code' => 'VND', 'value' => '50000' ),
					'tax'         => array( 'currency_code' => 'VND', 'value' => '7500' ),
				),
			),
		),
	),
);

$runtime   = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
$unready_gateways = $runtime->filter_available_gateways(
	array(
		'ppcp-gateway' => (object) array(),
		'bacs'         => (object) array(),
	)
);
vst_assert_same( false, isset( $unready_gateways['ppcp-gateway'] ), 'Conversion stays unavailable until the PPCP module contract is registered' );
vst_assert_true( isset( $unready_gateways['bacs'] ), 'Missing PPCP adapter readiness does not affect non-PayPal gateways' );
$adapter_ready = new ReflectionProperty( $runtime, 'adapter_ready' );
$adapter_ready->setAccessible( true );
$adapter_ready->setValue( $runtime, true );
$paypal_gateway = (object) array( 'supports' => array( 'products', 'refunds', 'tokenization', 'subscriptions' ) );
$available = $runtime->filter_available_gateways(
	array(
		'ppcp-gateway'             => $paypal_gateway,
		'ppcp-card-button-gateway' => (object) array(),
		'ppcp-googlepay'           => (object) array(),
		'bacs'                     => (object) array(),
	)
);
vst_assert_true( isset( $available['ppcp-gateway'], $available['bacs'] ), 'Conversion preserves the target PayPal gateway and non-PayPal gateways' );
vst_assert_same( false, isset( $available['ppcp-card-button-gateway'] ) || isset( $available['ppcp-googlepay'] ), 'Unsupported PPCP card and wallet gateways are unavailable' );
vst_assert_same( array( 'products', 'refunds' ), $available['ppcp-gateway']->supports, 'Converted gateway removes tokenization and subscription support' );
$first_quote = WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY );
$runtime->prepare_checkout_attempt();
$same_cart_quote = WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY );
vst_assert_same( $first_quote['token'], $same_cart_quote['token'], 'Same-cart checkout tabs reuse one server-owned quote token' );

WC()->cart->hash = 'cart-hash-changed';
WC()->cart->total = '160000';
$runtime->prepare_checkout_attempt();
$changed_quote = WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY );
vst_assert_same( $first_quote['token'], $changed_quote['token'], 'Pre-payment cart refresh keeps the locked attempt token and rate' );
vst_assert_same( '160000', $changed_quote['vnd_total'], 'Pre-payment quote follows the current VND cart total' );
WC()->cart->hash = 'cart-hash-45';
WC()->cart->total = '135000';
$runtime->prepare_checkout_attempt();

vst_assert_same( $payload, $runtime->convert_create_order_payload( $payload, 'bacs', array() ), 'Non-PayPal gateways keep their original payload' );
vst_assert_same( $payload, $runtime->convert_create_order_payload( $payload, 'ppcp-card-button-gateway', array( 'funding_source' => 'card' ) ), 'Unsupported PPCP sibling gateways keep their server payload unchanged' );

$authorize_payload = $payload;
$authorize_payload['intent'] = 'AUTHORIZE';
try {
	$runtime->convert_create_order_payload( $authorize_payload, 'ppcp-gateway', array( 'funding_source' => 'paypal' ) );
	vst_assert_true( false, 'AUTHORIZE flow must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'AUTHORIZE flow fails closed' );
}

try {
	$runtime->convert_create_order_payload( $payload, 'ppcp-gateway', array( 'context' => 'pay-now', 'funding_source' => 'paypal' ) );
	vst_assert_true( false, 'Pay-for-order context must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Pay-for-order context fails closed' );
}

$vault_payload = $payload;
$vault_payload['payment_source']['paypal']['attributes']['vault'] = array( 'store_in_vault' => 'ON_SUCCESS' );
try {
	$runtime->convert_create_order_payload( $vault_payload, 'ppcp-gateway', array( 'funding_source' => 'paypal' ) );
	vst_assert_true( false, 'Vaulting payload must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Vaulting payload fails closed' );
}

$converted = $runtime->convert_create_order_payload( $payload, 'ppcp-gateway', array( 'funding_source' => 'paypal' ) );
$attempt   = WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY );

vst_assert_same( 'creating', $attempt['state'], 'Standard Place-order create freezes the attempt before outbound request' );
vst_assert_same( 'USD', $converted['purchase_units'][0]['amount']['currency_code'], 'Standard Place-order create sends USD' );
vst_assert_same( '5.40', $attempt['usd_total'], 'Frozen create amount matches the canonical frontend quote' );
vst_assert_same( false, isset( $attempt['payload']['payment_source'] ), 'Frozen financial projection excludes payer/payment-source data' );

try {
	$runtime->convert_create_order_payload( $payload, 'ppcp-gateway', array( 'funding_source' => 'paypal' ) );
	vst_assert_true( false, 'A creating attempt must not create a second PayPal order' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'A creating attempt cannot create a second PayPal order' );
}

WC()->cart->hash = 'cart-hash-locked-change';
WC()->cart->total = '160000';
try {
	$runtime->convert_create_order_payload( $payload, 'ppcp-gateway', array( 'funding_source' => 'paypal' ) );
	vst_assert_true( false, 'A changed cart cannot replace a locked creating attempt' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'A changed cart cannot replace a locked creating attempt' );
}
WC()->cart->hash = 'cart-hash-45';
WC()->cart->total = '135000';

$paypal_order = new Fake_PayPal_Order( 'PAYPAL-45', 'USD', '5.40' );
$runtime->mark_paypal_order_created( $paypal_order );
$attempt = WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY );
vst_assert_same( 'payment_created', $attempt['state'], 'Matching PayPal response transitions the frozen attempt' );
vst_assert_same( 'PAYPAL-45', $attempt['paypal_order_id'], 'Matching PayPal response appends its order ID' );

$wc_order = new WC_Order();
$runtime->link_paypal_order( $wc_order, $paypal_order );
$snapshot = $wc_order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META, true );
vst_assert_true( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::validate_snapshot( $snapshot ), 'WC order receives a valid immutable financial snapshot' );

$conflicting_order = new WC_Order();
$conflicting_order->meta[ Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META ] = array( 'tampered' => true );
try {
	$runtime->link_paypal_order( $conflicting_order, $paypal_order );
	vst_assert_true( false, 'Existing conflicting conversion metadata must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Existing conflicting conversion metadata fails closed' );
}

$patch = array(
	array(
		'op'    => 'replace',
		'path'  => "/purchase_units/@reference_id=='default'",
		'value' => array_merge(
			$payload['purchase_units'][0],
			array(
				'custom_id' => 'order-key',
				'invoice_id' => 'INV-45',
				'shipping' => array( 'address' => array( 'country_code' => 'VN' ) ),
			)
		),
	),
);
$converted_patch = $runtime->convert_idempotent_patch( $patch );
vst_assert_same( 'USD', $converted_patch[0]['value']['amount']['currency_code'], 'Mandatory PPCP patch is converted with the locked rate' );
vst_assert_same( '5.40', $converted_patch[0]['value']['amount']['value'], 'Idempotent patch keeps the exact frozen USD total' );
vst_assert_same( 'INV-45', $converted_patch[0]['value']['invoice_id'], 'Non-financial patch metadata remains intact' );

$wrong_reference_patch = $patch;
$wrong_reference_patch[0]['path'] = "/purchase_units/@reference_id=='other'";
$wrong_reference_patch[0]['value']['reference_id'] = 'other';
try {
	$runtime->convert_idempotent_patch( $wrong_reference_patch );
	vst_assert_true( false, 'Patch for another Purchase Unit reference must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Patch for another Purchase Unit reference fails closed' );
}

$changed_patch = $patch;
$changed_patch[0]['value']['amount']['value'] = '160000';
$changed_patch[0]['value']['amount']['breakdown']['shipping']['value'] = '45000';
try {
	$runtime->convert_idempotent_patch( $changed_patch );
	vst_assert_true( false, 'Late monetary patch change must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Late monetary patch change fails closed' );
}

$unknown_patch = $patch;
$unknown_patch[0]['value']['platform_fee'] = array( 'currency_code' => 'VND', 'value' => '1000' );
try {
	$runtime->convert_idempotent_patch( $unknown_patch );
	vst_assert_true( false, 'Unknown patch monetary node must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Unknown patch monetary node fails closed' );
}

$usd_patch = $patch;
$usd_patch[0]['value']['amount']['currency_code'] = 'USD';
try {
	$runtime->convert_idempotent_patch( $usd_patch );
	vst_assert_true( false, 'Already-USD or mixed patch input must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Already-USD or mixed patch input fails closed' );
}

try {
	$runtime->convert_idempotent_patch( array( $patch[0], $patch[0] ) );
	vst_assert_true( false, 'Multiple Purchase Unit patches must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Multiple Purchase Unit patches fail closed' );
}

$locked_attempt = WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY );
$locked_attempt['expires_at'] = time() - 1;
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, $locked_attempt );
try {
	$runtime->convert_idempotent_patch( $patch );
	vst_assert_true( false, 'Expired converted attempt must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Expired converted attempt fails closed' );
}
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, $attempt );

$GLOBALS['vst_paypal_conversion_enabled'] = false;
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, null );
vst_assert_same( $patch, $runtime->convert_idempotent_patch( $patch ), 'Disabled conversion leaves ordinary PPCP patches unchanged' );
$GLOBALS['vst_paypal_conversion_enabled'] = true;
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, $attempt );

WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, null );
try {
	$runtime->convert_idempotent_patch( $patch );
	vst_assert_true( false, 'Missing active attempt must fail closed while conversion is enabled' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Missing active attempt fails closed while conversion is enabled' );
}
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, $attempt );

$creating_attempt = $attempt;
$creating_attempt['state'] = 'creating';
$creating_attempt['paypal_order_id'] = '';
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, $creating_attempt );
try {
	$runtime->mark_paypal_order_created( new Fake_PayPal_Order( 'PAYPAL-BAD', 'VND', '135000' ) );
	vst_assert_true( false, 'Mismatched PayPal response currency must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Mismatched PayPal response currency fails closed' );
}
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, $attempt );

$creating_attempt = $attempt;
$creating_attempt['state'] = 'creating';
$creating_attempt['paypal_order_id'] = '';
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, $creating_attempt );
try {
	$runtime->mark_paypal_order_created( new Fake_PayPal_Order( 'PAYPAL-BAD-AMOUNT', 'USD', '5.41' ) );
	vst_assert_true( false, 'Mismatched PayPal response amount must fail closed' );
} catch ( RuntimeException $error ) {
	vst_assert_true( true, 'Mismatched PayPal response amount fails closed' );
}
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, $attempt );

$errors = new Fake_Checkout_Errors();
$runtime->validate_checkout_flow(
	array(
		'payment_method' => 'ppcp-gateway',
		'wc-ppcp-gateway-payment-token' => '123',
		'paypal_order_id' => 'EXISTING-ORDER',
	),
	$errors
);
vst_assert_true( in_array( 'paypal_usd_vault_unsupported', $errors->codes, true ), 'Checkout rejects saved PayPal tokens' );
vst_assert_true( in_array( 'paypal_usd_continuation_unsupported', $errors->codes, true ), 'Checkout rejects existing-order continuation' );

$runtime->complete_payment_attempt( $wc_order, $paypal_order );
vst_assert_same( null, WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY ), 'Successful matched order processing releases the session attempt' );

vst_finish_contract_suite( 'PayPal standard lifecycle' );
