<?php
/**
 * Standalone contracts for the PPCP VND/USD conversion boundary.
 *
 * Run with: php tests/paypal-vnd-usd-contract-tests.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

function __( $value ) { return $value; }
function get_option( $key, $default = false ) {
	return 'woocommerce-ppcp-version' === $key ? '4.1.3' : $default;
}
function get_woocommerce_currency() { return 'VND'; }
function is_checkout() { return false; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
function wc_format_decimal( $value ) { return (string) $value; }
function add_filter() {}
function add_action() {}
function is_wp_error( $value ) { return false; }
function wp_enqueue_script() {}
function wp_localize_script() {}
function wc_clean( $value ) { return $value; }

class WP_Error {
	public function __construct( $code = '', $message = '' ) {}
	public function get_error_message() { return ''; }
}

require __DIR__ . '/support/assertions.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-paypal-conversion.php';

$unavailable_runtime = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
vst_assert_same( array( 'core-module' ), $unavailable_runtime->register_ppcp_module( array( 'core-module' ) ), 'Compatible PPCP version without required services does not append the adapter module' );
$invalid_settings = Yoohw_Vietnam_Store_Tools_PayPal_Conversion::sanitize_settings(
	array(
		'yoohw_vietnam_store_tools_paypal_vnd_usd_enabled' => 'yes',
		'yoohw_vietnam_store_tools_paypal_vnd_usd_rate'    => '0',
	)
);
vst_assert_same( 'no', $invalid_settings['yoohw_vietnam_store_tools_paypal_vnd_usd_enabled'], 'Invalid manual rate disables conversion during settings sanitization' );
vst_assert_same( '0', $invalid_settings['yoohw_vietnam_store_tools_paypal_vnd_usd_rate'], 'Settings sanitization preserves the invalid rate for administrator correction' );

vst_assert_true( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::is_valid_rate( '25000' ), 'Accepts an integer VND/USD rate' );
vst_assert_true( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::is_valid_rate( '25000.125' ), 'Accepts a bounded decimal VND/USD rate' );
vst_assert_same( false, Yoohw_Vietnam_Store_Tools_PayPal_Conversion::is_valid_rate( '0' ), 'Rejects a zero exchange rate' );
vst_assert_same( false, Yoohw_Vietnam_Store_Tools_PayPal_Conversion::is_valid_rate( '25000.12345' ), 'Rejects excess rate precision' );
vst_assert_same( '10.00', Yoohw_Vietnam_Store_Tools_PayPal_Conversion::convert_decimal_to_usd( '250000', '25000' ), 'Converts VND into two-decimal USD' );
vst_assert_same( '0.01', Yoohw_Vietnam_Store_Tools_PayPal_Conversion::convert_decimal_to_usd( '2', '300' ), 'Uses deterministic half-up fixed-point rounding' );

$payload = array(
	'purchase_units' => array(
		array(
			'amount' => array(
				'currency_code' => 'VND',
				'value'         => '135000',
				'breakdown'     => array(
					'item_total' => array( 'currency_code' => 'VND', 'value' => '100000' ),
					'shipping'   => array( 'currency_code' => 'VND', 'value' => '20000' ),
					'tax_total'  => array( 'currency_code' => 'VND', 'value' => '15000' ),
				),
			),
			'items'  => array(
				array(
					'name'        => 'Quantity-aware item',
					'quantity'    => '2',
					'unit_amount' => array( 'currency_code' => 'VND', 'value' => '50000' ),
					'tax'         => array( 'currency_code' => 'VND', 'value' => '7500' ),
				),
			),
		),
	),
);

$converted = Yoohw_Vietnam_Store_Tools_PayPal_Conversion::convert_purchase_units( $payload, '25000' );
$unit      = $converted['purchase_units'][0];

vst_assert_same( 'USD', $unit['amount']['currency_code'], 'Final PayPal amount is USD' );
vst_assert_same( '4.00', $unit['amount']['breakdown']['item_total']['value'], 'Item total is derived from unit amount times quantity' );
vst_assert_same( '0.60', $unit['amount']['breakdown']['tax_total']['value'], 'Tax total is derived from per-item tax times quantity' );
vst_assert_same( '5.40', $unit['amount']['value'], 'Final total is derived from the reconciled breakdown' );

$rounding_payload = array(
	'purchase_units' => array(
		array(
			'amount' => array(
				'currency_code' => 'VND',
				'value'         => '903',
				'breakdown'     => array(
					'item_total' => array( 'currency_code' => 'VND', 'value' => '602' ),
					'shipping'   => array( 'currency_code' => 'VND', 'value' => '301' ),
				),
			),
			'items'  => array(
				array(
					'name'        => 'Quantity constraint',
					'quantity'    => '2',
					'unit_amount' => array( 'currency_code' => 'VND', 'value' => '301' ),
				),
			),
		),
	),
);
$rounded          = Yoohw_Vietnam_Store_Tools_PayPal_Conversion::convert_purchase_units( $rounding_payload, '300' );
$rounded_unit     = $rounded['purchase_units'][0];
vst_assert_same( '1.00', $rounded_unit['items'][0]['unit_amount']['value'], 'Keeps quantity-derived item summary aligned with its leaf' );
vst_assert_same( '2.00', $rounded_unit['amount']['breakdown']['item_total']['value'], 'Derives item summary after leaf allocation' );
vst_assert_same( '1.01', $rounded_unit['amount']['breakdown']['shipping']['value'], 'Allocates residual cent only to an eligible leaf' );
vst_assert_same( '3.01', $rounded_unit['amount']['value'], 'Canonical tree matches the directly rounded source total' );

$unsolved = $rounding_payload;
unset( $unsolved['purchase_units'][0]['amount']['breakdown']['shipping'] );
$unsolved['purchase_units'][0]['amount']['value'] = '602';
try {
	Yoohw_Vietnam_Store_Tools_PayPal_Conversion::convert_purchase_units( $unsolved, '300' );
	vst_assert_true( false, 'Quantity-constrained cent mismatch must fail closed' );
} catch ( InvalidArgumentException $error ) {
	vst_assert_true( true, 'Quantity-constrained cent mismatch fails closed' );
}

$unsafe = $payload;
$unsafe['purchase_units'][0]['amount']['currency_code'] = 'EUR';
try {
	Yoohw_Vietnam_Store_Tools_PayPal_Conversion::convert_purchase_units( $unsafe, '25000' );
	vst_assert_true( false, 'Unsupported currency must fail closed' );
} catch ( InvalidArgumentException $error ) {
	vst_assert_true( true, 'Unsupported currency fails closed' );
}

$unknown = $payload;
$unknown['purchase_units'][0]['platform_fee'] = array( 'currency_code' => 'VND', 'value' => '1000' );
try {
	Yoohw_Vietnam_Store_Tools_PayPal_Conversion::convert_purchase_units( $unknown, '25000' );
	vst_assert_true( false, 'Unknown monetary node must fail closed' );
} catch ( InvalidArgumentException $error ) {
	vst_assert_true( true, 'Unknown monetary node fails closed' );
}

$snapshot = array(
	'schema'          => 1,
	'gateway'         => 'ppcp-gateway',
	'rate'            => '25000',
	'source'          => 'manual',
	'locked_at'       => '2026-09-19T00:00:00Z',
	'created_at'      => '2026-09-19T00:01:00Z',
	'vnd_total'       => '100000',
	'usd_total'       => '4.00',
	'paypal_order_id' => 'PAYPAL-ORDER-1',
	'payload'         => array(
		'intent'         => 'CAPTURE',
		'purchase_units' => array(
			array(
				'reference_id' => 'default',
				'amount'       => array( 'currency_code' => 'USD', 'value' => '4.00' ),
			),
		),
	),
);
vst_assert_true( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::validate_snapshot( $snapshot ), 'Accepts a complete immutable conversion snapshot' );

$refunds = array();
$first   = Yoohw_Vietnam_Store_Tools_PayPal_Conversion::calculate_refund_usd_cents( $snapshot, $refunds, '33333', 400 );
$refunds[] = array( 'vnd_amount' => '33333', 'usd_cents' => $first );
$second    = Yoohw_Vietnam_Store_Tools_PayPal_Conversion::calculate_refund_usd_cents( $snapshot, $refunds, '33333', 400 );
$refunds[] = array( 'vnd_amount' => '33333', 'usd_cents' => $second );
$third     = Yoohw_Vietnam_Store_Tools_PayPal_Conversion::calculate_refund_usd_cents( $snapshot, $refunds, '33334', 400 );
vst_assert_same( 400, $first + $second + $third, 'Cumulative partial refunds close exactly to the captured snapshot total' );
vst_assert_same( 133, $third, 'Final refund consumes the exact remaining canonical cents' );

$invalid_snapshot              = $snapshot;
$invalid_snapshot['usd_total'] = '4.01';
vst_assert_same( false, Yoohw_Vietnam_Store_Tools_PayPal_Conversion::validate_snapshot( $invalid_snapshot ), 'Rejects a snapshot whose total diverges from the frozen payload' );

$root    = dirname( __DIR__ );
$runtime = file_get_contents( $root . '/includes/class-vietnam-commerce-kit-paypal-conversion.php' );
$adapter = file_get_contents( $root . '/includes/class-vietnam-commerce-kit-paypal-ppcp-adapter.php' );
$script  = file_get_contents( $root . '/assets/js/frontend/paypal-vnd-usd.js' );
$admin   = file_get_contents( $root . '/includes/class-vietnam-commerce-kit-admin-menu.php' );

vst_assert_true( false !== strpos( $runtime, "'ppcp_patch_order_request_body_data'" ), 'Runtime guards the mandatory PPCP amount patch boundary' );
vst_assert_true( false !== strpos( $runtime, "'woocommerce_paypal_payments_modules'" ), 'Runtime registers the lazy PPCP module filter' );
vst_assert_true( false !== strpos( $runtime, "'woocommerce_paypal_payments_use_place_order_button'" ), 'Runtime constrains conversion to the normal place-order flow' );
vst_assert_true( false !== strpos( $runtime, "'woocommerce_paypal_payments_buttons_disabled'" ), 'Runtime disables unsupported express button flows' );
vst_assert_true( false !== strpos( $runtime, "'yoohw_vietnam_store_tools_paypal_conversion_settings'" ), 'Conversion settings are owned by Vietnam Store Toolkit' );
vst_assert_same( false, false !== strpos( $runtime, 'woocommerce_settings_api_form_fields_ppcp-gateway' ), 'Runtime does not inject fields into the PPCP React settings screen' );
vst_assert_true( false !== strpos( $runtime, "'woocommerce-ppcp-data-settings'" ), 'Runtime reads the authoritative PPCP data settings option' );
vst_assert_true( false !== strpos( $admin, "paypal_conversion[rate]" ) && false !== strpos( $admin, 'sanitize_settings' ), 'Toolkit admin page renders and sanitizes its PayPal conversion settings' );
vst_assert_same( false, false !== strpos( $runtime, "'woocommerce_checkout_create_order'" ), 'Snapshot persistence requires PayPal-order linkage rather than an unbound checkout hook' );
vst_assert_true( false !== strpos( $runtime, "'wp_enqueue_scripts', array( \$this, 'prepare_checkout_attempt' ), 1" ), 'Server-owned quote is ready before PPCP enqueues SDK v6 data' );
vst_assert_true( false !== strpos( $runtime, 'SdkV6\\\\Blocks\\\\V6PaymentMethod' ), 'Runtime capability-checks the PPCP 4.1.3 Blocks contract lazily' );
vst_assert_true( false !== strpos( $adapter, "'wcgateway.processor.refunds'" ), 'Adapter replaces only PPCP refund processing service' );
vst_assert_true( false !== strpos( $adapter, "'sdk-v6.manager'" ), 'Adapter synchronizes PPCP SDK v6 data through its manager service' );
vst_assert_true( false !== strpos( $script, 'gatewayId' ) && false !== strpos( $script, 'payment_method' ) && false !== strpos( $script, 'paymentStore' ), 'Frontend disclosure is scoped through Classic and Blocks payment selection' );
vst_assert_true( false !== strpos( $script, 'yoohw_paypal_usd_quote' ), 'Frontend refreshes its server-owned quote after cart total changes' );
vst_assert_true( false !== strpos( $script, 'checkout_place_order_' ) && false !== strpos( $script, 'wc-block-components-checkout-place-order-button' ), 'Classic and Blocks place-order controls wait for a current server quote' );

vst_finish_contract_suite( 'PayPal VND/USD conversion' );
