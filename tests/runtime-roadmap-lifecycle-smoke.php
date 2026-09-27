<?php
/** Cross-process deactivation/reactivation smoke for disposable wp-env only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args[0], $args[1], $args[2] ) || 'VST-62-LIFECYCLE' !== $args[0] || ! in_array( $args[1], [ 'prepare', 'inactive', 'active' ], true ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'This smoke requires an explicitly enabled disposable localhost WordPress installation.' );
}
require __DIR__ . '/support/assertions.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$phase = $args[1];
$mode = $args[2];
$actual_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
vst_assert_same( 'hpos' === $mode, $actual_hpos, 'Expected WooCommerce order storage mode is active' );
$fixture_option = '_vst62_lifecycle_fixture_' . $mode;
$fixture = get_option( $fixture_option, [] );
$keys = [
	'_yoohw_vietnam_store_tools_payment_reconciliation_history',
	'_yoohw_vietnam_store_tools_shipment_exception_history',
	'_yoohw_vietnam_store_tools_returns_lite',
	'_yoohw_vietnam_store_tools_einvoice_history',
	'_yoohw_vietnam_store_tools_einvoice_documents',
];

if ( 'prepare' === $phase ) {
	vst_assert_same( [], $fixture, 'No prior lifecycle fixture remains' );
	$old_invoice = get_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, false );
	update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, 'yes' );
	$product = new WC_Product_Simple();
	$product->set_name( 'VST-62 lifecycle fixture' );
	$product->set_regular_price( '10000' );
	$product->save();
	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_currency( 'VND' );
	$order->set_payment_method( 'bacs' );
	$order->set_total( 10000 );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, 'manual' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE, 'VST62-LIFE' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'yes' );
	$order->save();
	$payment = Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::record_manual_observation( $order, [ 'amount' => '10000', 'currency' => 'VND', 'observed_at' => gmdate( 'c' ), 'reference' => 'VST62-LIFE' ] );
	vst_assert_true( ! is_wp_error( $payment ), 'Payment history prepared' );
	$shipment_id = Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions::get_current_shipment( $order )['id'];
	$exception = Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions::record_exception( $order, [ 'type' => 'failed_handoff', 'expected_shipment_id' => $shipment_id ] );
	vst_assert_true( ! is_wp_error( $exception ), 'Shipment exception prepared' );
	$item_id = (int) array_key_first( $order->get_items( 'line_item' ) );
	$return = Yoohw_Vietnam_Store_Tools_Returns_Lite::create( $order, [ $item_id => 1 ], [ 'reason' => 'Lifecycle fixture' ], 0 );
	vst_assert_true( ! is_wp_error( $return ), 'Return history prepared' );
	$invoice = Yoohw_Vietnam_Store_Tools_Electronic_Invoice::update_order_data( $order, [ 'status' => 'verified' ], [ 'source' => 'fixture' ] );
	vst_assert_same( true, $invoice, 'Invoice history prepared' );
	$wards = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( '01' );
	$ward = (string) array_key_first( $wards );
	vst_assert_true( '' !== $ward, 'A current ward fixture exists' );
	$ward_only = new WC_Shipping_Zone();
	$ward_only->set_zone_name( 'VST-62 ward only ' . $mode );
	$ward_only->set_zone_order( 1 );
	$ward_only->save();
	$ward_only->add_location( '01:' . $ward, 'vck_ward' );
	$ward_only->save();
	$mixed = new WC_Shipping_Zone();
	$mixed->set_zone_name( 'VST-62 mixed ' . $mode );
	$mixed->set_zone_order( 2 );
	$mixed->save();
	$mixed->add_location( 'VN', 'country' );
	$mixed->add_location( '01:' . $ward, 'vck_ward' );
	$mixed->save();
	$order = wc_get_order( $order->get_id() );
	$snapshot = [];
	foreach ( $keys as $key ) {
		$snapshot[ $key ] = $order->get_meta( $key, true );
	}
	update_option( $fixture_option, [ 'order_id' => $order->get_id(), 'product_id' => $product->get_id(), 'old_invoice' => $old_invoice, 'ward_only_zone_id' => $ward_only->get_id(), 'mixed_zone_id' => $mixed->get_id(), 'ward' => $ward, 'snapshot' => $snapshot ], false );
} else {
	vst_assert_true( isset( $fixture['order_id'], $fixture['snapshot'] ), 'Fixture is available across WP-CLI processes' );
	$order = wc_get_order( $fixture['order_id'] );
	vst_assert_true( $order instanceof WC_Order, 'Order persists across plugin lifecycle' );
	foreach ( $fixture['snapshot'] as $key => $value ) {
		vst_assert_same( $value, $order->get_meta( $key, true ), 'Order metadata preserved: ' . $key );
	}
	if ( 'inactive' === $phase ) {
		vst_assert_same( false, is_plugin_active( 'yoohw-vietnam-store-tools/yoohw-vietnam-store-tools.php' ), 'Plugin is deactivated in this process' );
		$package = [ 'destination' => [ 'country' => 'VN', 'state' => 'VN:01', 'city' => $fixture['ward'], 'postcode' => '' ] ];
		$matched = WC_Shipping_Zones::get_zone_matching_package( $package );
		vst_assert_same( (int) $fixture['mixed_zone_id'], $matched->get_id(), 'Native country region remains eligible while ward-only zone does not match' );
	} else {
		vst_assert_same( true, is_plugin_active( 'yoohw-vietnam-store-tools/yoohw-vietnam-store-tools.php' ), 'Plugin is active again' );
		vst_assert_same( 1, count( Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::get_history( $order ) ), 'Payment history readable after activation' );
		vst_assert_same( 1, count( Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions::get_exceptions( $order ) ), 'Shipment history readable after activation' );
		vst_assert_same( 1, count( Yoohw_Vietnam_Store_Tools_Returns_Lite::get_returns( $order ) ), 'Return history readable after activation' );
		vst_assert_same( 'verified', Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_order_data( $order )['status'], 'Invoice projection readable after activation' );
		$shipment_option = Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_CUSTOMER_SHIPMENT_DISPLAY;
		$old_shipment_option = get_option( $shipment_option, false );
		update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, 'no' );
		update_option( $shipment_option, 'no' );
		vst_assert_same( 'verified', Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_order_data( $order )['status'], 'Invoice history remains readable when feature disabled' );
		vst_assert_same( 1, count( Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions::get_exceptions( $order ) ), 'Shipment history remains readable when customer display disabled' );
		if ( false === $old_shipment_option ) {
			delete_option( $shipment_option );
		} else {
			update_option( $shipment_option, $old_shipment_option );
		}
		foreach ( $fixture['snapshot'] as $key => $value ) {
			vst_assert_same( $value, wc_get_order( $order->get_id() )->get_meta( $key, true ), 'Reading after activation does not rewrite: ' . $key );
		}
		$order->delete( true );
		wc_get_product( $fixture['product_id'] )->delete( true );
		( new WC_Shipping_Zone( $fixture['ward_only_zone_id'] ) )->delete();
		( new WC_Shipping_Zone( $fixture['mixed_zone_id'] ) )->delete();
		if ( false === $fixture['old_invoice'] ) {
			delete_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE );
		} else {
			update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, $fixture['old_invoice'] );
		}
		delete_option( $fixture_option );
	}
}
vst_finish_contract_suite( 'DB-backed lifecycle ' . $mode . ' ' . $phase );
