<?php
/** Standalone VST-50 payment and shipment domain contracts. */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/support/assertions.php';

$test_filters = [];
$test_actor_allowed = true;
$test_uuid = 0;
$test_orders = [];
$test_persisted = [];
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
function wc_get_order( $value ) { global $test_orders; return $value instanceof WC_Order ? $value : ( isset( $test_orders[ $value ] ) ? $test_orders[ $value ] : false ); }
function wc_get_orders( $args ) {
	global $test_orders;
	$key = $args['meta_query'][0]['key'];
	$found = [];
	foreach ( $test_orders as $id => $order ) {
		if ( array_key_exists( $key, $order->meta ) ) {
			$found[] = $id;
		}
	}
	return $found;
}
class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
class WC_Order {
	public $meta = [];
	public $saves = 0;
	private $id;
	public function __construct( $id = 50 ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_total() { return '100000'; }
	public function get_currency() { return 'VND'; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function read_meta_data( $force = false ) { global $test_persisted; if ( $force && isset( $test_persisted[ $this->id ] ) ) { $this->meta = $test_persisted[ $this->id ]; } }
	public function save() { global $test_orders, $test_persisted; ++$this->saves; $test_persisted[ $this->id ] = $this->meta; $test_orders[ $this->id ] = $this; }
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
vst_assert_true( is_wp_error( $reader::record_manual_observation( $payment, [ 'amount' => '100000.49', 'currency' => 'VND' ] ) ), 'Excess precision cannot be rounded into a manual match' );
vst_assert_true( is_wp_error( $reader::record_manual_observation( $payment, [ 'amount' => '100000', 'currency' => 'VND', 'observed_at' => '2026-02-31T00:00:00Z' ] ) ), 'Invalid calendar timestamp cannot be normalized into evidence' );
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
$rounded_proof = $proof;
$rounded_proof['amount'] = '100000.49';
vst_assert_true( is_wp_error( $reader::record_verified_evidence( $payment, 'bank', $rounded_proof ) ), 'Excess precision cannot become externally verified' );
vst_assert_true( is_wp_error( $reader::record_verified_evidence( $payment, 'manual', $proof ) ), 'Reserved manual source cannot produce external trust' );
$verified = $reader::record_verified_evidence( $payment, 'bank', $proof );
vst_assert_same( 'external_verified', $reader::get_order_data( $payment )['trust'], 'Registered proof yields external trust' );
$other_payment = new WC_Order( 51 );
vst_assert_true( is_wp_error( $reader::record_verified_evidence( $other_payment, 'bank', $proof ) ), 'One source transaction cannot verify a second order' );
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
$replacement = $exceptions::replace_shipment( $shipment, 'carrier', [ 'provider' => 'bogus', 'provider_name' => 'Spoof', 'tracking_code' => 'NEW', 'status_id' => 'created' ], [ 'expected_shipment_id' => $old_id ] );
vst_assert_true( ! is_wp_error( $replacement ), 'Explicit replacement succeeds' );
vst_assert_true( $replacement['id'] !== $old_id, 'Replacement advances identity' );
vst_assert_same( 'carrier', $shipment->get_meta( $shipping::META_PROVIDER ), 'Replacement uses registered provider' );
vst_assert_same( $old_id, $exceptions::get_exceptions( $shipment )[0]['parent_shipment_id'], 'Replacement records predecessor identity' );
vst_assert_true( is_wp_error( $shipping::update_order_shipping_data_for_shipment( $shipment, 'carrier', [ 'status_id' => 'delivered' ], $old_id ) ), 'Stale identity-aware shipping update fails' );
vst_assert_true( is_wp_error( $tracking::add_timeline_event( $shipment, [ 'status' => 'delivered', 'expected_shipment_id' => $old_id ] ) ), 'Stale timeline update fails' );
$tracking::delete_timeline_event( $shipment, $old_event['id'] );
vst_assert_same( 'created', $shipment->get_meta( $shipping::META_STATUS_ID ), 'Deleting predecessor event cannot restore stale status' );
$exceptions::record_exception( $shipment, [ 'type' => 'cancelled', 'expected_shipment_id' => $replacement['id'] ] );
vst_assert_true( is_wp_error( $tracking::add_timeline_event( $shipment, [ 'status' => 'delivered' ] ) ), 'Cancelled current shipment cannot receive status' );
vst_assert_same( 'cancelled', $shipment->get_meta( $shipping::META_STATUS_ID ), 'Cancellation remains current projection' );
$manual_shipping = ( new ReflectionClass( $shipping ) )->newInstanceWithoutConstructor();
$manual_update = new ReflectionMethod( $shipping, 'update_order_manual_shipping_data' );
vst_assert_true( is_wp_error( $manual_update->invoke( $manual_shipping, $shipment, [ 'tracking_code' => 'OLD-AGAIN', 'status_id' => 'manual' ] ) ), 'Manual shipping save rejects closed shipment' );
vst_assert_same( 'NEW', $shipment->get_meta( $shipping::META_TRACKING_CODE ), 'Rejected manual save preserves tracking code' );
vst_assert_same( 'cancelled', $shipment->get_meta( $shipping::META_STATUS_ID ), 'Rejected manual save preserves cancellation status' );
$tracking::delete_timeline_event( $shipment, 'missing-event' );
vst_assert_same( 'cancelled', $shipment->get_meta( $shipping::META_STATUS_ID ), 'Timeline deletion cannot reopen cancelled shipment' );

$legacy_timeline_order = new WC_Order();
$shipping::update_order_shipping_data( $legacy_timeline_order, 'carrier', [ 'tracking_code' => 'PRE-UPGRADE', 'status_id' => 'delivered' ] );
$legacy_timeline_order->update_meta_data( $tracking::META_TIMELINE, [ [ 'id' => 'old-event', 'status' => 'delivered', 'location' => '', 'note' => '', 'occurred_at' => '2026-09-01T00:00:00+00:00', 'created_at' => '2026-09-01T00:00:00+00:00', 'user_id' => 0 ] ] );
$legacy_timeline_order->save();
$legacy_id = $exceptions::get_current_shipment( $legacy_timeline_order )['id'];
$exceptions::replace_shipment( $legacy_timeline_order, 'carrier', [ 'tracking_code' => 'POST-UPGRADE', 'status_id' => 'created' ], [ 'expected_shipment_id' => $legacy_id ] );
$tracking::delete_timeline_event( $legacy_timeline_order, 'old-event' );
vst_assert_same( 'created', $legacy_timeline_order->get_meta( $shipping::META_STATUS_ID ), 'Unbound legacy event cannot restore predecessor after replacement' );

$stale_order = new WC_Order( 80 );
$shipping::update_order_shipping_data( $stale_order, 'carrier', [ 'tracking_code' => 'FIRST', 'status_id' => 'in_transit' ] );
$old_identity = $exceptions::get_current_shipment( $stale_order )['id'];
$fresh_order = new WC_Order( 80 );
$fresh_order->read_meta_data( true );
$new_identity = $exceptions::replace_shipment( $fresh_order, 'carrier', [ 'tracking_code' => 'SECOND', 'status_id' => 'created' ], [ 'expected_shipment_id' => $old_identity ] )['id'];
vst_assert_true( is_wp_error( $shipping::update_order_shipping_data_for_shipment( $stale_order, 'carrier', [ 'status_id' => 'delivered' ], $old_identity ) ), 'Stale order object cannot update replaced shipment' );
vst_assert_true( is_wp_error( $tracking::add_timeline_event( $stale_order, [ 'status' => 'delivered', 'expected_shipment_id' => $old_identity ] ) ), 'Stale order object cannot append predecessor event' );
vst_assert_true( is_wp_error( $exceptions::record_exception( $stale_order, [ 'type' => 'cancelled', 'expected_shipment_id' => $old_identity ] ) ), 'Stale order object cannot cancel predecessor' );
vst_assert_same( 'created', $test_persisted[80][ $shipping::META_STATUS_ID ], 'Stale writes preserve persisted successor status' );
$exceptions::record_exception( $fresh_order, [ 'type' => 'cancelled', 'expected_shipment_id' => $new_identity ] );
$stale_order->read_meta_data( true );
$before_create = $exceptions::get_current_shipment( $stale_order );
$create_action = new ReflectionMethod( $shipping, 'persist_shipment_action_result' );
$recreated = $create_action->invoke( $manual_shipping, $stale_order, 'carrier', [ 'tracking_code' => 'THIRD', 'status_id' => 'created' ], 'create', $before_create );
vst_assert_true( ! is_wp_error( $recreated ), 'Create after cancellation persists through replacement' );
vst_assert_same( $new_identity, $recreated['parent_id'], 'Create after cancellation links closed predecessor' );
vst_assert_same( 'THIRD', $test_persisted[80][ $shipping::META_TRACKING_CODE ], 'Replacement stores new provider tracking code' );

vst_finish_contract_suite( 'VST-50 domain' );
