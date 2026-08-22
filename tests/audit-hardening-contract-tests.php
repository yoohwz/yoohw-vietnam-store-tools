<?php
/**
 * Standalone contract tests for the 1.1.4 audit-hardening tranche.
 *
 * Run with: php tests/audit-hardening-contract-tests.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$test_filters    = [];
$test_transients = [];

function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) {
	global $test_filters;

	$args = array_slice( func_get_args(), 1 );

	return isset( $test_filters[ $hook ] ) ? call_user_func_array( $test_filters[ $hook ], $args ) : $value;
}
function absint( $value ) { return abs( (int) $value ); }
function __( $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : [] ); }
function wp_unslash( $value ) { return $value; }
function wp_salt( $scheme = 'auth' ) { return 'contract-test-' . $scheme; }
function get_transient( $key ) {
	global $test_transients;

	return isset( $test_transients[ $key ] ) ? $test_transients[ $key ]['value'] : false;
}
function set_transient( $key, $value, $expiration ) {
	global $test_transients;

	$test_transients[ $key ] = [ 'value' => $value, 'expiration' => $expiration ];

	return true;
}
function esc_url_raw( $value, $protocols = null ) {
	$value  = trim( (string) $value );
	$scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) );

	if ( '' === $value || ( is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ) ) {
		return '';
	}

	return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : '';
}
function wp_http_validate_url( $value ) {
	$scheme = strtolower( (string) parse_url( (string) $value, PHP_URL_SCHEME ) );

	return in_array( $scheme, [ 'http', 'https' ], true ) && false !== filter_var( $value, FILTER_VALIDATE_URL );
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

class WC_Order {
	public $meta = [];
	public $updates = [];
	public $saved = false;

	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; $this->updates[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save() { $this->saved = true; }
}

class Yoohw_Vietnam_Store_Tools_Admin_Menu {
	const OPTION_ELECTRONIC_INVOICE = 'electronic_invoice';

	public static $workflow_enabled = true;

	public static function is_feature_enabled() { return self::$workflow_enabled; }
}

require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-electronic-invoice.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-shipping.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-shipment-tracking.php';

$failures  = [];
$assertions = 0;

function assert_audit_contract( $expected, $actual, $label ) {
	global $assertions, $failures;

	++$assertions;

	if ( $expected !== $actual ) {
		$failures[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	}
}

function new_without_constructor( $class_name ) {
	$reflection = new ReflectionClass( $class_name );

	return $reflection->newInstanceWithoutConstructor();
}

function set_private_property( $object, $property_name, $value ) {
	$property = new ReflectionProperty( get_class( $object ), $property_name );
	$property->setAccessible( true );
	$property->setValue( $object, $value );
}

function invoke_private_method( $object, $method_name, array $args = [] ) {
	$method = new ReflectionMethod( get_class( $object ), $method_name );
	$method->setAccessible( true );

	return $method->invokeArgs( $object, $args );
}

$invoice = new_without_constructor( 'Yoohw_Vietnam_Store_Tools_Electronic_Invoice' );
$mimes   = [ 'jpg|jpeg' => 'image/jpeg' ];

assert_audit_contract( $mimes, $invoice->allow_invoice_upload_mimes( $mimes ), 'Invoice MIME types are unchanged outside an invoice upload' );

set_private_property( $invoice, 'handling_invoice_upload', 'pdf' );
$pdf_mimes = $invoice->allow_invoice_upload_mimes( $mimes );
assert_audit_contract( 'application/pdf', isset( $pdf_mimes['pdf'] ) ? $pdf_mimes['pdf'] : '', 'PDF MIME is enabled in the PDF invoice upload context' );
assert_audit_contract( false, isset( $pdf_mimes['xml'] ), 'XML MIME is not enabled during a PDF upload' );

set_private_property( $invoice, 'handling_invoice_upload', 'xml' );
$xml_mimes = $invoice->allow_invoice_upload_mimes( $mimes );
assert_audit_contract( 'application/xml', isset( $xml_mimes['xml'] ) ? $xml_mimes['xml'] : '', 'XML MIME is enabled in the XML invoice upload context' );
assert_audit_contract( false, isset( $xml_mimes['pdf'] ), 'PDF MIME is not enabled during an XML upload' );

Yoohw_Vietnam_Store_Tools_Admin_Menu::$workflow_enabled = false;
assert_audit_contract( $mimes, $invoice->allow_invoice_upload_mimes( $mimes ), 'Invoice MIME types remain unchanged when the workflow is disabled' );
Yoohw_Vietnam_Store_Tools_Admin_Menu::$workflow_enabled = true;

$valid_xml     = tempnam( sys_get_temp_dir(), 'vst-valid-xml-' );
$malformed_xml = tempnam( sys_get_temp_dir(), 'vst-bad-xml-' );
file_put_contents( $valid_xml, '<?xml version="1.0"?><invoice><number>1</number></invoice>' );
file_put_contents( $malformed_xml, '<invoice><number>1</invoice>' );
$normalized = $invoice->normalize_invoice_xml_filetype( [], $valid_xml, 'invoice.xml', [], 'text/plain' );
$rejected   = $invoice->normalize_invoice_xml_filetype( [], $malformed_xml, 'invoice.xml', [], 'text/plain' );
unlink( $valid_xml );
unlink( $malformed_xml );
assert_audit_contract( 'xml', isset( $normalized['ext'] ) ? $normalized['ext'] : '', 'Well-formed XML is normalized in invoice upload context' );
assert_audit_contract( [], $rejected, 'Malformed XML is rejected during filetype normalization' );

$shipping         = new_without_constructor( 'Yoohw_Vietnam_Store_Tools_Shipping' );
$update_method    = new ReflectionMethod( 'Yoohw_Vietnam_Store_Tools_Shipping', 'update_order_manual_shipping_data' );
$update_method->setAccessible( true );
$invalid_order    = new WC_Order();
$invalid_result   = $update_method->invoke( $shipping, $invalid_order, [ 'tracking_url' => 'ftp://example.com/track/123' ] );
assert_audit_contract( true, is_wp_error( $invalid_result ), 'Non-HTTP manual tracking URL returns an error' );
assert_audit_contract( 'yoohw_vietnam_store_tools_shipping_invalid_tracking_url', $invalid_result->get_error_code(), 'Invalid tracking URL uses the expected error code' );
assert_audit_contract( [], $invalid_order->updates, 'Invalid tracking URL is rejected before order metadata changes' );
assert_audit_contract( false, $invalid_order->saved, 'Invalid tracking URL does not save the order' );

$blank_order  = new WC_Order();
$blank_result = $update_method->invoke( $shipping, $blank_order, [ 'tracking_url' => '' ] );
assert_audit_contract( true, $blank_result, 'Blank manual tracking URL remains valid' );
assert_audit_contract( '', $blank_order->meta[ Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_URL ], 'Blank tracking URL is persisted for template generation' );

$valid_order  = new WC_Order();
$valid_result = $update_method->invoke( $shipping, $valid_order, [ 'tracking_url' => 'https://example.com/track/123' ] );
assert_audit_contract( true, $valid_result, 'HTTPS manual tracking URL is accepted' );
assert_audit_contract( 'https://example.com/track/123', $valid_order->meta[ Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_URL ], 'Validated tracking URL is persisted' );

$tracking = new_without_constructor( 'Yoohw_Vietnam_Store_Tools_Shipment_Tracking' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

assert_audit_contract( true, invoke_private_method( $tracking, 'allow_lookup_attempt' ), 'Lookup attempt 1 is allowed within the default limit' );
$transient_key    = (string) array_key_first( $test_transients );
$first_window_end = $test_transients[ $transient_key ]['value']['expires_at'];

for ( $attempt = 2; $attempt <= Yoohw_Vietnam_Store_Tools_Shipment_Tracking::LOOKUP_RATE_LIMIT; ++$attempt ) {
	assert_audit_contract( true, invoke_private_method( $tracking, 'allow_lookup_attempt' ), 'Lookup attempt ' . $attempt . ' is allowed within the default limit' );
}

assert_audit_contract( false, invoke_private_method( $tracking, 'allow_lookup_attempt' ), 'Lookup attempt above the default limit is blocked' );
assert_audit_contract( false, false !== strpos( $transient_key, $_SERVER['REMOTE_ADDR'] ), 'Rate-limit transient key does not persist the raw IP address' );
assert_audit_contract( true, 0 < $test_transients[ $transient_key ]['expiration'] && Yoohw_Vietnam_Store_Tools_Shipment_Tracking::LOOKUP_RATE_WINDOW >= $test_transients[ $transient_key ]['expiration'], 'Rate-limit transient remains within the bounded default window' );
assert_audit_contract( $first_window_end, $test_transients[ $transient_key ]['value']['expires_at'], 'Allowed attempts do not extend the fixed window end' );
assert_audit_contract( Yoohw_Vietnam_Store_Tools_Shipment_Tracking::LOOKUP_RATE_LIMIT, $test_transients[ $transient_key ]['value']['attempts'], 'Rate-limit state records attempts within the fixed window' );

$test_transients[ $transient_key ]['value']['expires_at'] = time() - 1;
assert_audit_contract( true, invoke_private_method( $tracking, 'allow_lookup_attempt' ), 'Expired fixed window starts a new lookup window' );
assert_audit_contract( 1, $test_transients[ $transient_key ]['value']['attempts'], 'New fixed window resets the attempt counter' );

$test_transients          = [];
$_SERVER['REMOTE_ADDR']   = 'not-an-ip';
assert_audit_contract( true, invoke_private_method( $tracking, 'allow_lookup_attempt' ), 'Missing validated requester identifier does not create a global throttle bucket' );
assert_audit_contract( [], $test_transients, 'Invalid requester address is not persisted' );

$test_filters['yoohw_vietnam_store_tools_tracking_lookup_rate_limit'] = function () {
	return [ 'limit' => 1, 'window' => 30 ];
};
$test_filters['yoohw_vietnam_store_tools_tracking_lookup_rate_limit_identifier'] = function () {
	return 'trusted-proxy-client';
};
assert_audit_contract( true, invoke_private_method( $tracking, 'allow_lookup_attempt' ), 'Filtered requester identifier is supported' );
assert_audit_contract( false, invoke_private_method( $tracking, 'allow_lookup_attempt' ), 'Filtered rate-limit settings are enforced' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS: ' . $assertions . " audit-hardening contract checks.\n";
