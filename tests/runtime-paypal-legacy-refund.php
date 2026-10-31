<?php
/** Real WooCommerce automatic refunds of historical converted orders, simulated transport. */
if ( ! defined( 'VST_PPCP_LEGACY_LOCAL_TEST' ) || ! VST_PPCP_LEGACY_LOCAL_TEST || 0 !== strpos( DB_NAME, 'vst104_' ) ) {
	throw new RuntimeException( 'Disposable VST-104 fixture required.' );
}
require __DIR__ . '/support/assertions.php';
$pp_id = 'VST104-90000';
$snapshot = array(
	'schema' => 1, 'gateway' => 'ppcp-gateway', 'source' => 'manual', 'rate' => '25000',
	'locked_at' => gmdate( 'c' ), 'created_at' => gmdate( 'c' ), 'vnd_total' => '135000', 'usd_total' => '5.40', 'paypal_order_id' => $pp_id,
	'payload' => array( 'intent' => 'CAPTURE', 'purchase_units' => array( array( 'reference_id' => 'default', 'amount' => array( 'currency_code' => 'USD', 'value' => '5.40' ) ) ) ),
);
$remote = array( 'id' => $pp_id, 'intent' => 'CAPTURE', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'reference_id' => 'default', 'amount' => array( 'currency_code' => 'USD', 'value' => '5.40' ), 'payments' => array( 'captures' => array( array( 'id' => 'CAPTURE-' . $pp_id, 'status' => 'COMPLETED', 'final_capture' => true, 'amount' => array( 'currency_code' => 'USD', 'value' => '5.40' ) ) ) ) ) ) );
update_option( 'vst104_order_' . $pp_id, $remote, false );
update_option( 'vst104_http', array(), false );
$order = wc_create_order();
$order->set_currency( 'VND' );
$order->set_total( '135000' );
$order->set_payment_method( 'ppcp-gateway' );
$order->update_meta_data( '_ppcp_paypal_order_id', $pp_id );
$order->update_meta_data( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META, $snapshot );
$order->save();
try {
	$gateway = WC()->payment_gateways()->payment_gateways()['ppcp-gateway'];
	vst_assert_true( $gateway->process_refund( $order->get_id(), 25000 ), 'Historical converted refund works in fresh request regardless of toggle/store currency' );
	$http = array_values( array_filter( get_option( 'vst104_http', array() ), static function ( $call ) { return substr( $call['path'], -7 ) === '/refund'; } ) );
	$last = end( $http );
	vst_assert_same( array( 'currency_code' => 'USD', 'value' => '1.00' ), $last['body']['amount'], 'Historical refund uses locked rate, not current store currency/settings' );
	$changed = $remote;
	$changed['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] = 'EUR';
	update_option( 'vst104_order_' . $pp_id, $changed, false );
	$before = count( get_option( 'vst104_http', array() ) );
	vst_assert_same( false, $gateway->process_refund( $order->get_id(), 25000 ), 'Wrong capture currency fails closed in real processor' );
	vst_assert_same( $before + 1, count( get_option( 'vst104_http', array() ) ), 'Mismatch performs read only and sends no refund' );
	update_option( 'vst104_order_' . $pp_id, $remote, false );
	// Simulate an unavailable adapter and exercise WooCommerce's real dispatch guard.
	$unavailable = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	$before = count( get_option( 'vst104_http', array() ) );
	$result = wc_refund_payment( $order, 25000, 'Unavailable adapter' );
	vst_assert_true( is_wp_error( $result ), 'Unverified adapter blocks WooCommerce automatic refund dispatch' );
	vst_assert_same( $before, count( get_option( 'vst104_http', array() ) ), 'Unverified adapter sends no HTTP' );
	$manual = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 1000, 'refund_payment' => false ) );
	vst_assert_same( false, is_wp_error( $manual ), 'Manual accounting refund remains available' );
} finally {
	foreach ( $order->get_refunds() as $refund ) { $refund->delete( true ); }
	$order->delete( true );
	delete_option( 'vst104_order_' . $pp_id );
}
vst_finish_contract_suite( 'VST-104 historical refunds ' . ( $args[0] ?? '' ) );
