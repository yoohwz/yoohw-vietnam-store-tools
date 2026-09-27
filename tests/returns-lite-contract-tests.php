<?php
/** Standalone Returns Lite allocation, lifecycle and lock contracts. */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/support/assertions.php';

$vst_orders = [];
$vst_uuid = 0;
$vst_can_edit = true;
function __( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function current_user_can() { global $vst_can_edit; return $vst_can_edit; }
function get_current_user_id() { return 9; }
function wp_generate_uuid4() { global $vst_uuid; return sprintf( '00000000-0000-4000-8000-%012d', ++$vst_uuid ); }
function wp_is_uuid( $value ) { return (bool) preg_match( '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
class WC_Product {
	public function get_sku() { return 'SKU'; }
}
class WC_Order_Item_Product {
	private $quantity;
	public function __construct( $quantity ) { $this->quantity = $quantity; }
	public function get_quantity() { return $this->quantity; }
	public function set_quantity( $quantity ) { $this->quantity = $quantity; }
	public function get_product_id() { return 100; }
	public function get_variation_id() { return 0; }
	public function get_name() { return 'Product'; }
	public function get_product() { return new WC_Product(); }
}
class WC_Order_Refund {
	private $id;
	private $parent;
	public function __construct( $id, $parent ) { $this->id = $id; $this->parent = $parent; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
}
class WC_Order {
	private $id;
	public $meta = [];
	public $items = [];
	public $refunds = [];
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function read_meta_data() { global $vst_orders; $this->meta = $vst_orders[ $this->id ]->meta; }
	public function save() { global $vst_orders; $vst_orders[ $this->id ]->meta = $this->meta; }
	public function get_items() { return $this->items; }
	public function get_refunds() { return $this->refunds; }
}
function wc_get_order( $order ) {
	global $vst_orders;
	$id = $order instanceof WC_Order ? $order->get_id() : (int) $order;
	if ( ! isset( $vst_orders[ $id ] ) ) { return false; }
	return clone $vst_orders[ $id ];
}
class Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions {
	const META_LEGACY_ID = 'legacy-id';
	public static $shipment = [ 'id' => 'legacy:5', 'data' => [ 'provider' => 'manual', 'tracking_code' => 'X' ] ];
	public static $exceptions = [];
	public static function get_current_shipment() { return self::$shipment; }
	public static function get_exceptions() { return self::$exceptions; }
}
class VST_Test_WPDB {
	public $options = 'wp_options';
	public $rows = [];
	public function prepare( $sql, ...$values ) {
		foreach ( $values as $value ) { $sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", $value ) . "'", $sql, 1 ); }
		return $sql;
	}
	public function query( $sql ) {
		if ( preg_match( "/INSERT IGNORE.*VALUES \\('([^']+)', '([^']+)', 'off'\\)/", $sql, $m ) ) {
			if ( isset( $this->rows[ $m[1] ] ) ) { return 0; }
			$this->rows[ $m[1] ] = $m[2];
			return 1;
		}
		if ( preg_match( "/UPDATE .*SET option_value = '([^']+)' WHERE option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
			if ( ! isset( $this->rows[ $m[2] ] ) || $this->rows[ $m[2] ] !== $m[3] ) { return 0; }
			$this->rows[ $m[2] ] = $m[1];
			return 1;
		}
		if ( preg_match( "/DELETE .*WHERE option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
			if ( ! isset( $this->rows[ $m[1] ] ) || $this->rows[ $m[1] ] !== $m[2] ) { return 0; }
			unset( $this->rows[ $m[1] ] );
			return 1;
		}
		throw new Exception( $sql );
	}
	public function get_var( $sql ) {
		if ( ! preg_match( "/WHERE option_name = '([^']+)'/", $sql, $m ) ) { throw new Exception( $sql ); }
		return isset( $this->rows[ $m[1] ] ) ? $this->rows[ $m[1] ] : null;
	}
}
$wpdb = new VST_Test_WPDB();
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-returns-lite.php';
$returns = 'Yoohw_Vietnam_Store_Tools_Returns_Lite';
$order = new WC_Order( 5 );
$order->items = [ 11 => new WC_Order_Item_Product( 3 ), 12 => new WC_Order_Item_Product( 2 ) ];
$order->refunds = [ new WC_Order_Refund( 50, 5 ) ];
$vst_orders[5] = $order;
$original_meta = $order->meta;
vst_assert_same( [], $returns::get_returns( $order ), 'No returns read as empty' );
vst_assert_same( $original_meta, $order->meta, 'No write on read' );

$lock1 = $returns::acquire_lock( 5 );
vst_assert_true( ! is_wp_error( $lock1 ), 'First contender acquires' );
vst_assert_true( is_wp_error( $returns::acquire_lock( 5 ) ), 'Second contender loses without overwriting' );
vst_assert_true( $returns::owns_lock( 5, $lock1 ), 'Active owner remains' );
$name = $returns::lock_name( 5 );
$wpdb->rows[ $name ] = 'expired|' . ( time() - 1 );
$lock2 = $returns::acquire_lock( 5 );
vst_assert_true( ! is_wp_error( $lock2 ), 'Expired lock can be taken over' );
vst_assert_true( is_wp_error( $returns::acquire_lock( 5 ) ), 'Expired takeover has one winner' );
$returns::release_lock( 5, $lock1 );
vst_assert_true( $returns::owns_lock( 5, $lock2 ), 'Old owner cannot release new lock' );
$returns::release_lock( 5, $lock2 );

$first = $returns::create( $order, [ 11 => 2, 12 => 1 ], [ 'reason' => 'Wrong size' ], 0 );
vst_assert_true( ! is_wp_error( $first ), 'Multiple product items can be returned' );
vst_assert_same( 'legacy:5', $returns::get_returns( $order )[0]['outbound_shipment']['id'], 'Virtual legacy shipment is only a snapshot' );
vst_assert_same( '', $vst_orders[5]->get_meta( 'shipment-id' ), 'Return does not materialize shipment identity' );
vst_assert_true( is_wp_error( $returns::create( $order, [ 11 => 1 ], [ 'reason' => 'Late form' ], 0 ) ), 'Stale ledger revision fails' );
vst_assert_true( is_wp_error( $returns::create( $order, [ 11 => 2 ], [ 'reason' => 'Over' ], 1 ) ), 'Allocation cannot exceed ordered quantity' );
vst_assert_true( is_wp_error( $returns::create( $order, [ 99 => 1 ], [ 'reason' => 'Bad' ], 1 ) ), 'Foreign item rejected' );
vst_assert_true( is_wp_error( $returns::create( $order, [ 11 => 0 ], [ 'reason' => 'Bad' ], 1 ) ), 'Zero quantity rejected' );
$id = $first['return_id'];
$received = $returns::mutate( $order, $id, 1, 'transition', [ 'state' => 'received' ] );
vst_assert_true( ! is_wp_error( $received ), 'Goods may be marked received' );
vst_assert_true( is_wp_error( $returns::mutate( $order, $id, 1, 'transition', [ 'state' => 'closed' ] ) ), 'Stale return revision fails' );
vst_assert_true( is_wp_error( $returns::mutate( $order, $id, 2, 'correct', [ 'items' => [ 11 => 1 ] ] ) ), 'Received items are frozen' );
$linked = $returns::mutate( $order, $id, 2, 'link_refund', [ 'refund_id' => 50 ] );
vst_assert_same( [ 50 ], $returns::get_returns( $order )[0]['refund_ids'], 'Existing refund is informationally linked' );
vst_assert_true( is_wp_error( $returns::create( $order, [ 11 => 2 ], [ 'reason' => 'Still over' ], 3 ) ), 'Refund link does not change capacity' );
$vst_orders[5]->refunds = [];
vst_assert_same( [ 50 ], $returns::get_returns( $order )[0]['refund_ids'], 'Missing refund remains referenced' );
$voided = $returns::mutate( $order, $id, 3, 'transition', [ 'state' => 'cancelled', 'note' => 'Administrative void' ] );
vst_assert_true( ! is_wp_error( $voided ), 'Received return may be voided' );
vst_assert_same( 4, count( $returns::get_ledger( $order )['events'] ), 'Voiding preserves audit events' );
vst_assert_true( is_wp_error( $returns::mutate( $order, $id, 4, 'correct', [ 'items' => [ 11 => 1 ] ] ) ), 'Voided items are frozen' );
$second = $returns::create( $order, [ 11 => 3 ], [ 'reason' => 'New return' ], 4 );
vst_assert_true( ! is_wp_error( $second ), 'Voiding releases allocation' );
$closed = $returns::mutate( $order, $second['return_id'], 1, 'transition', [ 'state' => 'closed' ] );
vst_assert_true( ! is_wp_error( $closed ), 'Open return can close' );
vst_assert_true( is_wp_error( $returns::create( $order, [ 11 => 1 ], [ 'reason' => 'Over' ], 6 ) ), 'Closed return continues consuming allocation' );
vst_assert_true( is_wp_error( $returns::mutate( $order, $second['return_id'], 2, 'correct', [ 'items' => [ 11 => 1 ] ] ) ), 'Closed items remain frozen' );
vst_assert_same( '', $vst_orders[5]->get_meta( 'tracking-timeline' ), 'Returns never write the tracking timeline' );
vst_assert_same( '', $vst_orders[5]->get_meta( 'order-status' ), 'Returns never write order status' );
vst_assert_same( '', $vst_orders[5]->get_meta( 'stock' ), 'Returns never write inventory state' );
vst_assert_same( [], $vst_orders[5]->refunds, 'Returns do not create WooCommerce refund objects' );
$vst_can_edit = false;
vst_assert_true( is_wp_error( $returns::create( $order, [ 12 => 1 ], [ 'reason' => 'Denied' ], 6 ) ), 'Per-order capability enforced' );
vst_finish_contract_suite( 'VST-56 Returns Lite' );
