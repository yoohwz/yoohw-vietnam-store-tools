<?php
/** Actual Store API cart representations must retain PayPal without creating a quote. */
if ( ! defined( 'VST_PPCP_LEGACY_LOCAL_TEST' ) || ! VST_PPCP_LEGACY_LOCAL_TEST || 0 !== strpos( DB_NAME, 'vst104_' ) ) {
	throw new RuntimeException( 'Disposable VST-104 fixture required.' );
}
require __DIR__ . '/support/assertions.php';
wp_set_current_user( 1 );
if ( ! WC()->session ) { WC()->initialize_session(); }
if ( ! WC()->cart ) { WC()->initialize_cart(); }
WC()->cart->empty_cart();
WC()->session->set( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY, null );
$product = new WC_Product_Simple();
$product->set_name( 'VST104 cart fixture' ); $product->set_regular_price( '135000' ); $product->set_virtual( true );
$product_id = $product->save();
$coupon = new WC_Coupon(); $coupon->set_code( 'vst104-runtime-' . $product_id ); $coupon->set_discount_type( 'fixed_cart' ); $coupon->set_amount( '1000' ); $coupon->save();
try {
	WC()->cart->add_to_cart( $product_id ); WC()->cart->calculate_totals();
	update_option( 'vst104_http', array(), false );
	foreach ( array( 'cart' => array( 'GET', array() ), 'cart/update-customer' => array( 'POST', array( 'billing_address' => array( 'country' => 'US', 'state' => 'CA', 'postcode' => '90210' ) ) ), 'cart/apply-coupon' => array( 'POST', array( 'code' => $coupon->get_code() ) ), 'cart/remove-coupon' => array( 'POST', array( 'code' => $coupon->get_code() ) ) ) as $route => $spec ) {
		$request = new WP_REST_Request( $spec[0], '/wc/store/v1/' . $route );
		$request->set_header( 'Content-Type', 'application/json' ); $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body( wp_json_encode( $spec[1] ) );
		$response = rest_do_request( $request ); $data = $response->get_data();
		vst_assert_same( 200, $response->get_status(), $route . ' response: ' . ( $data['message'] ?? 'OK' ) );
		vst_assert_true( in_array( 'ppcp-gateway', $data['payment_methods'] ?? array(), true ), $route . ' advertises verified PayPal' );
		vst_assert_same( null, WC()->session->get( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::ATTEMPT_KEY ), $route . ' does not create/lock a quote' );
	}
	$payment_calls = array_filter( get_option( 'vst104_http', array() ), static function ( $call ) { return 'POST' === $call['method'] && 0 === strpos( $call['path'], '/v2/' ); } );
	vst_assert_same( array(), array_values( $payment_calls ), 'Cart representations send no PayPal payment requests' );
} finally {
	WC()->cart->empty_cart(); $coupon->delete( true ); $product->delete( true );
}
vst_finish_contract_suite( 'VST-104 real Store API cart' );
