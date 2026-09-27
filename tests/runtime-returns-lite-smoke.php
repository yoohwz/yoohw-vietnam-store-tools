<?php
/** DB-backed Returns Lite smoke for a disposable wp-env installation only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args[0] ) || 'VST-56-SMOKE' !== $args[0] || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'This smoke requires an explicitly enabled disposable localhost WordPress installation.' );
}
require __DIR__ . '/support/assertions.php';
$expected_hpos = isset( $args[1] ) && 'hpos' === $args[1];
$actual_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
vst_assert_same( $expected_hpos, $actual_hpos, 'Expected WooCommerce order storage mode is active' );
$product = null;
$order = null;
try {
	$product = new WC_Product_Simple();
	$product->set_name( 'VST-56 fixture' );
	$product->set_regular_price( '10000' );
	$product->save();
	$order = wc_create_order();
	$order->add_product( $product, 3 );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, 'manual' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE, 'VST56-TRACK' );
	$order->save();
	$item_id = (int) array_key_first( $order->get_items( 'line_item' ) );
	$domain = 'Yoohw_Vietnam_Store_Tools_Returns_Lite';
	$identity = 'Yoohw_Vietnam_Store_Tools_Shipment_Identity';
	$timeline_key = Yoohw_Vietnam_Store_Tools_Shipment_Tracking::META_TIMELINE;
	$before_status = $order->get_status();
	$before_stock = $product->get_stock_quantity();
	$before_timeline = $order->get_meta( $timeline_key, true );
	$before_refunds = count( $order->get_refunds() );
	$before_meta = $order->get_meta( $domain::META_LEDGER, true );
	vst_assert_same( [], $domain::get_returns( $order ), 'No-history order reads empty' );
	vst_assert_same( $before_meta, wc_get_order( $order->get_id() )->get_meta( $domain::META_LEDGER, true ), 'Read performs no write' );
	$virtual = $identity::get_current_shipment( $order )['id'];
	vst_assert_same( 'legacy:' . $order->get_id(), $virtual, 'Legacy shipment is virtual' );
	$first = $domain::create( $order, [ $item_id => 2 ], [ 'reason' => 'Fixture return' ], 0 );
	vst_assert_true( ! is_wp_error( $first ), 'Return saved through WooCommerce CRUD' );
	vst_assert_same( '', wc_get_order( $order->get_id() )->get_meta( $identity::META_CURRENT_ID, true ), 'Return did not materialize virtual shipment' );
	$stale = wc_get_order( $order->get_id() );
	$second = $domain::create( $order, [ $item_id => 1 ], [ 'reason' => 'Second return' ], 1 );
	vst_assert_true( ! is_wp_error( $second ), 'Remaining quantity can be allocated' );
	vst_assert_true( is_wp_error( $domain::create( $stale, [ $item_id => 1 ], [ 'reason' => 'Over-allocated' ], 2 ) ), 'Locked persisted re-read rejects concurrent over-allocation' );
	vst_assert_true( is_wp_error( $domain::mutate( $stale, $first['return_id'], 0, 'transition', [ 'state' => 'received' ] ) ), 'Stale return revision rejected after lock' );
	$void = $domain::mutate( $order, $first['return_id'], 1, 'transition', [ 'state' => 'cancelled', 'note' => 'Fixture void' ] );
	vst_assert_true( ! is_wp_error( $void ), 'Cancellation releases allocation' );
	$third = $domain::create( $order, [ $item_id => 2 ], [ 'reason' => 'Reallocated' ], 3 );
	vst_assert_true( ! is_wp_error( $third ), 'Released quantity can be allocated again' );
	$historical = $domain::get_ledger( $order );
	$historical['revision']++;
	$historical['events'][] = [ 'return_id' => $second['return_id'], 'return_revision' => 2, 'changes' => [ 'returned_exception_id' => 'historical-event' ], 'occurred_at' => gmdate( 'c' ) ];
	$order->update_meta_data( $domain::META_LEDGER, $historical );
	$order->save();
	vst_assert_same( 'historical-event', $domain::get_returns( $order )[1]['returned_exception_id'], 'Historical return field remains readable' );
	$correction = $domain::mutate( $order, $second['return_id'], 2, 'correct', [ 'reason' => 'Updated' ] );
	vst_assert_true( ! is_wp_error( $correction ), 'Historical return can be edited without exception lookup' );
	$persisted = wc_get_order( $order->get_id() );
	vst_assert_same( 3, count( $domain::get_returns( $persisted ) ), 'Records survive DB-backed reload' );
	vst_assert_same( $before_status, $persisted->get_status(), 'Return did not change order status' );
	vst_assert_same( $before_timeline, $persisted->get_meta( $timeline_key, true ), 'No customer timeline event was added' );
	vst_assert_same( $before_refunds, count( $persisted->get_refunds() ), 'No refund was created' );
	vst_assert_same( $before_stock, wc_get_product( $product->get_id() )->get_stock_quantity(), 'No stock was changed' );
} finally {
	if ( $order instanceof WC_Order ) {
		$order->delete( true );
	}
	if ( $product instanceof WC_Product ) {
		$product->delete( true );
	}
}
vst_finish_contract_suite( 'DB-backed Returns Lite ' . ( $actual_hpos ? 'HPOS' : 'legacy' ) );
