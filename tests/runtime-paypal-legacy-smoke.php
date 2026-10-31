<?php
/**
 * Real WC/PPCP integration with simulated PayPal transport in a disposable local DB.
 * wp eval-file tests/runtime-paypal-legacy-smoke.php
 * Requires the explicitly enabled fixtures/vst-paypal-legacy-runtime.php MU loader.
 */
if ( ! defined( 'VST_PPCP_LEGACY_LOCAL_TEST' ) || ! VST_PPCP_LEGACY_LOCAL_TEST || 0 !== strpos( DB_NAME, 'vst104_' ) ) {
	throw new RuntimeException( 'Disposable VST-104 fixture required.' );
}
require __DIR__ . '/support/assertions.php';

function vst104_runtime() {
	global $wp_filter;
	foreach ( $wp_filter['woocommerce_paypal_payments_modules']->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callable = $callback['function'];
			if ( is_array( $callable ) && $callable[0] instanceof Yoohw_Vietnam_Store_Tools_PayPal_Conversion ) {
				return $callable[0];
			}
		}
	}
	throw new RuntimeException( 'VST runtime not registered.' );
}

function vst104_http( $suffix = '' ) {
	return array_values( array_filter( get_option( 'vst104_http', array() ), static function ( $call ) use ( $suffix ) {
		return '' === $suffix || substr( $call['path'], -strlen( $suffix ) ) === $suffix;
	} ) );
}

function vst104_cart( $product_id ) {
	WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, null );
	WooCommerce\PayPalCommerce\PPCP::container()->get( 'session.handler' )->destroy_session_data();
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $product_id );
	WC()->cart->calculate_totals();
}

$runtime = vst104_runtime();
$container = WooCommerce\PayPalCommerce\PPCP::container();
vst_assert_same( array( 'reason' => 'COMPATIBLE', 'probe' => '', 'ready' => true ), $runtime->get_adapter_status(), 'Installed PPCP reaches compatibility without SdkV6Manager' );
vst_assert_same( false, $container->has( 'sdk-v6.manager' ), 'Actual built PPCP container uses legacy stack' );
vst_assert_true( $container->get( 'wcgateway.processor.refunds' ) instanceof Yoohw_Vietnam_Store_Tools_PayPal_USD_Refund_Processor, 'Only refund service is replaced' );

// Match WooCommerce's checkout AJAX request context in this CLI harness.
if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) { define( 'WOOCOMMERCE_CHECKOUT', true ); }
wp_set_current_user( 1 );
if ( ! WC()->session ) { WC()->initialize_session(); }
if ( ! WC()->cart ) { WC()->initialize_cart(); }
WC()->customer->set_billing_country( 'US' );
WC()->customer->set_billing_state( 'CA' );
WC()->customer->set_billing_postcode( '90210' );

$product = new WC_Product_Simple();
$product->set_name( 'VST-104 disposable virtual product' );
$product->set_regular_price( '135000' );
$product->set_virtual( true );
$product->set_status( 'publish' );
$product_id = $product->save();
$orders = array();
$data = array( 'payment_method' => 'ppcp-gateway', 'billing_first_name' => 'VST104', 'billing_last_name' => 'Fixture', 'billing_email' => 'vst104@example.test', 'billing_country' => 'US', 'billing_state' => 'CA', 'billing_postcode' => '90210', 'billing_city' => 'Beverly Hills', 'billing_address_1' => '123 Test Street', 'billing_phone' => '2025550100' );
try {
	$checkout_id = wc_get_page_id( 'checkout' );
	if ( $checkout_id < 1 ) {
		$checkout_id = wp_insert_post( array( 'post_title' => 'Checkout', 'post_name' => 'checkout', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[woocommerce_checkout]' ) );
		update_option( 'woocommerce_checkout_page_id', $checkout_id );
	}
	$GLOBALS['wp_query']->queried_object_id = $checkout_id;
	$GLOBALS['wp_query']->queried_object = get_post( $checkout_id );
	$GLOBALS['wp_query']->is_page = true;
	$GLOBALS['post'] = get_post( $checkout_id );
	$container->get( 'button.helper.context' )->init_context();
	$gateway = WC()->payment_gateways()->payment_gateways()['ppcp-gateway'];
	foreach ( array( 'classic', 'blocks' ) as $flow ) {
		vst104_cart( $product_id );
		update_option( 'vst104_http', array(), false );
		$_POST = array( 'ppcp-funding-source' => 'paypal' );
		vst_assert_true( isset( WC()->payment_gateways()->get_available_payment_gateways()['ppcp-gateway'] ), $flow . ' real gateway available ' . wp_json_encode( array( 'enabled' => $gateway->enabled, 'available' => $gateway->is_available(), 'checkout' => is_checkout(), 'pay' => is_checkout_pay_page(), 'received' => is_order_received_page(), 'status' => $runtime->get_adapter_status() ) ) );
		if ( 'classic' === $flow ) {
			$order_id = WC()->checkout()->create_order( $data );
			if ( is_wp_error( $order_id ) ) { throw new RuntimeException( $order_id->get_error_message() ); }
			$orders[] = $order_id;
			$result = $gateway->process_payment( $order_id );
			vst_assert_same( 'success', $result['result'] ?? '', 'Classic WC checkout order enters upstream PayPal redirect' );
		} else {
			$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
			$request->set_body( wp_json_encode( array( 'billing_address' => array( 'first_name' => 'VST104', 'last_name' => 'Fixture', 'email' => 'vst104@example.test', 'country' => 'US', 'state' => 'CA', 'postcode' => '90210', 'city' => 'Beverly Hills', 'address_1' => '123 Test Street', 'phone' => '2025550100' ), 'payment_method' => 'ppcp-gateway', 'payment_data' => array( array( 'key' => 'ppcp-funding-source', 'value' => 'paypal' ) ) ) ) );
			$response = rest_do_request( $request );
			$result = $response->get_data();
			vst_assert_same( 200, $response->get_status(), 'Blocks real Store API checkout response: ' . wp_json_encode( $result ) );
			$order_id = $result['order_id'] ?? 0;
			if ( ! $order_id ) { throw new RuntimeException( 'Store API did not create an order: ' . wp_json_encode( $result ) ); }
			$orders[] = $order_id;
			vst_assert_same( 'success', $result['payment_result']['payment_status'] ?? '', 'Blocks uses upstream legacy gateway redirect' );
		}
		$order = wc_get_order( $order_id );
		vst_assert_same( 'VND', $order->get_currency(), $flow . ' WC order currency preserved' );
		vst_assert_same( '135000.00', $order->get_total(), $flow . ' WC order total preserved' );
		$creates = vst104_http( '/v2/checkout/orders' );
		vst_assert_same( 1, count( $creates ), $flow . ' exactly one simulated PayPal create' );
		vst_assert_same( 'USD', $creates[0]['body']['purchase_units'][0]['amount']['currency_code'] ?? '', $flow . ' actual endpoint sends USD' );
		vst_assert_same( '5.40', $creates[0]['body']['purchase_units'][0]['amount']['value'] ?? '', $flow . ' actual endpoint sends locked USD total' );
		$attempt = WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY );
		vst_assert_same( 'payment_created', $attempt['state'] ?? '', $flow . ' create response locks the attempt' );
		$pp_id = $attempt['paypal_order_id'];
		$duplicate = $gateway->process_payment( $order_id );
		vst_assert_same( 'failure', $duplicate['result'] ?? '', $flow . ' repeated create is rejected' );
		vst_assert_same( 1, count( vst104_http( '/v2/checkout/orders' ) ), $flow . ' repeated create sends no second payment' );
		wc_clear_notices();
		$remote = get_option( 'vst104_order_' . $pp_id );
		$remote['status'] = 'APPROVED';
		update_option( 'vst104_order_' . $pp_id, $remote, false );
		// Model buyer approval only; use real upstream retrieval and processor afterwards.
		$container->get( 'session.handler' )->replace_order( $container->get( 'api.endpoint.order' )->order( $pp_id ) );
		$order->set_total( '160000' );
		try { $container->get( 'wcgateway.order-processor' )->process( $order ); vst_assert_true( false, $flow . ' changed total must reject before capture' ); }
		catch ( Exception $error ) { vst_assert_same( 0, count( vst104_http( '/capture' ) ), $flow . ' changed total sends no capture' ); }
		$order->set_total( '135000' );
		$order->save();
		update_option( 'vst104_fail_capture', true, false );
		try { $container->get( 'wcgateway.order-processor' )->process( $order ); vst_assert_true( false, $flow . ' failed capture must not mark paid' ); }
		catch ( Exception $error ) { vst_assert_same( false, wc_get_order( $order_id )->is_paid(), $flow . ' simulated declined capture does not mark paid' ); }
		delete_option( 'vst104_fail_capture' );
		$container->get( 'wcgateway.order-processor' )->process( $order );
		$order = wc_get_order( $order_id );
		vst_assert_true( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::validate_snapshot( $order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META ) ), $flow . ' valid persisted snapshot before capture' );
		vst_assert_true( $order->is_paid(), $flow . ' actual upstream processor completes simulated capture' );
		vst_assert_same( 2, count( vst104_http( '/capture' ) ), $flow . ' declined capture followed by one successful retry' );
		vst_assert_same( null, WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY ), $flow . ' attempt cleared after processor' );
		update_option( 'vst104_fail_refund', true, false );
		$failed = wc_create_refund( array( 'order_id' => $order_id, 'amount' => 25000, 'refund_payment' => true ) );
		vst_assert_true( is_wp_error( $failed ), $flow . ' actual Woo refund propagates transport failure' );
		vst_assert_same( '', wc_get_order( $order_id )->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META ), $flow . ' failed refund writes no successful conversion ledger' );
		delete_option( 'vst104_fail_refund' );
		$before_refunds = count( vst104_http( '/refund' ) );
		foreach ( array( 25000, 110000 ) as $amount ) {
			$refund = wc_create_refund( array( 'order_id' => $order_id, 'amount' => $amount, 'reason' => 'VST104 simulated refund', 'refund_payment' => true ) );
			vst_assert_same( false, is_wp_error( $refund ), $flow . ' WooCommerce automatic partial/full refund: ' . ( is_wp_error( $refund ) ? $refund->get_error_message() : 'success' ) );
		}
		$refunds = array_slice( vst104_http( '/refund' ), $before_refunds );
		vst_assert_same( array( '1.00', '4.40' ), array_column( array_column( array_column( $refunds, 'body' ), 'amount' ), 'value' ), $flow . ' cumulative refunds exactly exhaust captured USD' );
		vst_assert_same( 'VND', wc_get_order( $order_id )->get_currency(), $flow . ' refunded order remains VND' );
	}
} finally {
	foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { foreach ( $order->get_refunds() as $refund ) { $refund->delete( true ); } $order->delete( true ); } }
	$product->delete( true );
}
vst_finish_contract_suite( 'VST-104 installed PPCP Classic/Blocks simulated transport' );
