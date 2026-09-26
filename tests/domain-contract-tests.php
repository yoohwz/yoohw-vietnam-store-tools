<?php
/** Standalone VST-50 payment and shipment domain contracts. */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/support/assertions.php';

$test_filters = [];
$test_actor_allowed = true;
$test_uuid = 0;
function __( $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, (array) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_generate_uuid4() { global $test_uuid; return sprintf( '00000000-0000-4000-8000-%012d', ++$test_uuid ); }
function apply_filters( $hook, $value ) { global $test_filters; return isset( $test_filters[ $hook ] ) ? $test_filters[ $hook ] : $value; }
function do_action() {}
function current_user_can() { global $test_actor_allowed; return $test_actor_allowed; }
function get_current_user_id() { return 7; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wc_format_decimal( $value, $decimals = 0 ) { return number_format( (float) $value, $decimals, '.', '' ); }
function wc_get_price_decimals() { return 0; }
function wc_get_order( $value ) { return $value instanceof WC_Order ? $value : false; }
class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
class WC_Order {
	public $meta = [];
	public $saves = 0;
	public function get_id() { return 50; }
	public function get_total() { return '100000'; }
	public function get_currency() { return 'VND'; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save() { ++$this->saves; }
}
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-shipping.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-fulfillment-exceptions.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-shipment-tracking.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-payment-reconciliation.php';

$payment = new WC_Order();
$reader = 'Yoohw_Vietnam_Store_Tools_Payment_Reconciliation';
vst_assert_same( 'unreconciled', $reader::get_order_data( $payment )['state'], 'Old order has deterministic payment default' );
vst_assert_same( 0, $payment->saves, 'Read does not migrate old payment order' );
$observation = $reader::record_manual_observation( $payment, [ 'amount' => '100000', 'currency' => 'VND', 'reference' => 'QR instruction', 'trust' => 'external_verified' ] );
vst_assert_same( 'manual', $observation['trust'], 'Caller trust flag cannot promote manual evidence' );
vst_assert_same( '', $observation['transaction_id'], 'Manual reference is not provider transaction ID' );
$match = $reader::match_manual_observation( $payment, $observation['id'] );
vst_assert_same( 'reconciled', $reader::get_order_data( $payment )['state'], 'Exact manual match reconciles' );
vst_assert_same( 'manual', $reader::get_order_data( $payment )['trust'], 'Manual match stays manual on read' );
$reader::reverse_entry( $payment, $match['id'] );
vst_assert_same( 'recorded', $reader::get_order_data( $payment )['state'], 'Reversal recomputes active observation' );
$partial = $reader::record_manual_observation( $payment, [ 'amount' => '50000', 'currency' => 'VND' ] );
vst_assert_true( is_wp_error( $reader::match_manual_observation( $payment, $partial['id'] ) ), 'Partial observation cannot reconcile' );
$wrong_currency = $reader::record_manual_observation( $payment, [ 'amount' => '100000', 'currency' => 'USD' ] );
vst_assert_true( is_wp_error( $reader::match_manual_observation( $payment, $wrong_currency['id'] ) ), 'Currency mismatch cannot reconcile' );
$correction = $reader::record_manual_observation( $payment, [ 'amount' => '100000', 'currency' => 'VND' ], [ 'supersedes' => $partial['id'] ] );
vst_assert_same( $partial['id'], $correction['supersedes'], 'Correction links to prior observation' );
vst_assert_true( is_wp_error( $reader::match_manual_observation( $payment, $partial['id'] ) ), 'Superseded observation cannot match' );
$test_actor_allowed = false;
vst_assert_true( is_wp_error( $reader::record_manual_observation( $payment, [ 'amount' => '100000', 'currency' => 'VND' ] ) ), 'Manual write requires order capability' );
$test_actor_allowed = true;
vst_assert_true( is_wp_error( $reader::record_verified_evidence( $payment, 'bank', [ 'verified' => true ] ) ), 'Unregistered source cannot verify' );
$test_filters['yoohw_vietnam_store_tools_payment_evidence_sources'] = [ 'bank' => static function ( $order, $evidence ) { return $evidence; } ];
$proof = [ 'amount' => '100000', 'currency' => 'VND', 'transaction_id' => 'TX-1', 'observed_at' => '2026-09-26T00:00:00Z' ];
vst_assert_true( is_wp_error( $reader::record_verified_evidence( $payment, 'manual', $proof ) ), 'Reserved manual source cannot produce external trust' );
$verified = $reader::record_verified_evidence( $payment, 'bank', $proof );
vst_assert_same( 'external_verified', $reader::get_order_data( $payment )['trust'], 'Registered proof yields external trust' );
vst_assert_same( $verified['id'], $reader::record_verified_evidence( $payment, 'bank', $proof )['id'], 'Identical transaction is idempotent' );
$conflict = $proof;
$conflict['observed_at'] = '2026-09-26T01:00:00Z';
vst_assert_true( is_wp_error( $reader::record_verified_evidence( $payment, 'bank', $conflict ) ), 'Conflicting transaction replay fails' );
$reader::reverse_entry( $payment, $verified['id'] );
vst_assert_same( 'manual', $reader::get_order_data( $payment )['trust'], 'External reversal cannot upgrade manual evidence' );
vst_assert_true( is_wp_error( $reader::record_verified_evidence( $payment, 'bank', $proof ) ), 'Reversed transaction cannot be silently reactivated' );

$test_filters['yoohw_vietnam_store_tools_shipping_providers'] = [ 'carrier' => [ 'id' => 'carrier', 'name' => 'Carrier' ] ];
$shipment = new WC_Order();
$shipping = 'Yoohw_Vietnam_Store_Tools_Shipping';
$exceptions = 'Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions';
$tracking = 'Yoohw_Vietnam_Store_Tools_Shipment_Tracking';
$shipping::update_order_shipping_data( $shipment, 'carrier', [ 'tracking_code' => 'OLD', 'status_id' => 'in_transit' ] );
$legacy = $exceptions::get_current_shipment( $shipment );
vst_assert_same( 'legacy:50', $legacy['id'], 'Existing shipping projection has virtual identity' );
vst_assert_same( '', $shipment->get_meta( $exceptions::META_CURRENT_ID ), 'Legacy read does not persist identity' );
$shipping::update_order_shipping_data( $shipment, 'carrier', [ 'tracking_code' => 'CORRECTED' ] );
vst_assert_same( 'legacy:50', $exceptions::get_current_shipment( $shipment )['id'], 'Legacy tracking correction does not replace shipment' );
vst_assert_same( '', $shipment->get_meta( $exceptions::META_HISTORY ), 'Legacy projection write creates no exception history' );
$tracking::add_timeline_event( $shipment, [ 'status' => 'in_transit' ] );
$old_event = $tracking::get_timeline( $shipment )[0];
vst_assert_same( [ 'id', 'status', 'location', 'note', 'occurred_at', 'created_at', 'user_id' ], array_keys( $old_event ), 'Timeline event shape remains unchanged' );
$old_id = $exceptions::get_current_shipment( $shipment )['id'];
$replacement = $exceptions::replace_shipment( $shipment, 'carrier', [ 'tracking_code' => 'NEW', 'status_id' => 'created' ], [ 'expected_shipment_id' => $old_id ] );
vst_assert_true( ! is_wp_error( $replacement ), 'Explicit replacement succeeds' );
vst_assert_true( $replacement['id'] !== $old_id, 'Replacement advances identity' );
vst_assert_same( $old_id, $exceptions::get_exceptions( $shipment )[0]['parent_shipment_id'], 'Replacement records predecessor identity' );
vst_assert_true( is_wp_error( $shipping::update_order_shipping_data_for_shipment( $shipment, 'carrier', [ 'status_id' => 'delivered' ], $old_id ) ), 'Stale identity-aware shipping update fails' );
vst_assert_true( is_wp_error( $tracking::add_timeline_event( $shipment, [ 'status' => 'delivered', 'expected_shipment_id' => $old_id ] ) ), 'Stale timeline update fails' );
$tracking::delete_timeline_event( $shipment, $old_event['id'] );
vst_assert_same( 'created', $shipment->get_meta( $shipping::META_STATUS_ID ), 'Deleting predecessor event cannot restore stale status' );
$exceptions::record_exception( $shipment, [ 'type' => 'cancelled', 'expected_shipment_id' => $replacement['id'] ] );
vst_assert_true( is_wp_error( $tracking::add_timeline_event( $shipment, [ 'status' => 'delivered' ] ) ), 'Cancelled current shipment cannot receive status' );
vst_assert_same( 'cancelled', $shipment->get_meta( $shipping::META_STATUS_ID ), 'Cancellation remains current projection' );
$tracking::delete_timeline_event( $shipment, 'missing-event' );
vst_assert_same( 'cancelled', $shipment->get_meta( $shipping::META_STATUS_ID ), 'Timeline deletion cannot reopen cancelled shipment' );

$legacy_timeline_order = new WC_Order();
$shipping::update_order_shipping_data( $legacy_timeline_order, 'carrier', [ 'tracking_code' => 'PRE-UPGRADE', 'status_id' => 'delivered' ] );
$legacy_timeline_order->update_meta_data( $tracking::META_TIMELINE, [ [ 'id' => 'old-event', 'status' => 'delivered', 'location' => '', 'note' => '', 'occurred_at' => '2026-09-01T00:00:00+00:00', 'created_at' => '2026-09-01T00:00:00+00:00', 'user_id' => 0 ] ] );
$legacy_id = $exceptions::get_current_shipment( $legacy_timeline_order )['id'];
$exceptions::replace_shipment( $legacy_timeline_order, 'carrier', [ 'tracking_code' => 'POST-UPGRADE', 'status_id' => 'created' ], [ 'expected_shipment_id' => $legacy_id ] );
$tracking::delete_timeline_event( $legacy_timeline_order, 'old-event' );
vst_assert_same( 'created', $legacy_timeline_order->get_meta( $shipping::META_STATUS_ID ), 'Unbound legacy event cannot restore predecessor after replacement' );

vst_finish_contract_suite( 'VST-50 domain' );
