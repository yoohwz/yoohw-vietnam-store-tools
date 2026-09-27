<?php
/** Fixture controller for disposable exact-candidate HTTP checkout checks. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args[0], $args[1] ) || 'VST-62-HTTP' !== $args[0] || ! in_array( $args[1], [ 'prepare', 'blocks', 'verify', 'cleanup' ], true ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'This fixture requires an explicitly enabled disposable localhost WordPress installation.' );
}
require __DIR__ . '/support/assertions.php';
$phase = $args[1];
$path = __DIR__ . '/fixtures/.vst62-http-fixture.json';
$fixture = file_exists( $path ) ? json_decode( file_get_contents( $path ), true ) : [];
$settings = [
	'woocommerce_checkout_page_id',
	'woocommerce_myaccount_page_id',
	'woocommerce_bacs_settings',
	'woocommerce_bacs_accounts',
	Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ADDRESS_FIELDS,
	Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_PHONE_NORMALIZATION,
	Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_CUSTOMER_SHIPMENT_DISPLAY,
	Yoohw_Vietnam_Store_Tools_Tax_Invoice::OPTION_ID,
];
if ( 'prepare' === $phase ) {
	vst_assert_same( [], $fixture, 'No previous HTTP fixture remains' );
	$old = [];
	foreach ( $settings as $name ) {
		$old[ $name ] = get_option( $name, false );
	}
	update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ADDRESS_FIELDS, 'yes' );
	update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_PHONE_NORMALIZATION, 'yes' );
	update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_CUSTOMER_SHIPMENT_DISPLAY, 'yes' );
	update_option( Yoohw_Vietnam_Store_Tools_Tax_Invoice::OPTION_ID, 'yes' );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes' ] );
	update_option( 'woocommerce_bacs_accounts', [ [ 'account_name' => 'VST-62 HTTP', 'account_number' => '123456789', 'bank_name' => 'Fixture Bank' ] ] );
	$product = new WC_Product_Simple();
	$product->set_name( 'VST-62 HTTP fixture' );
	$product->set_regular_price( '10000' );
	$product->set_virtual( true );
	$product->save();
	$classic = wp_insert_post( [ 'post_title' => 'VST-62 Classic Checkout', 'post_name' => 'vst62-classic-checkout', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[woocommerce_checkout]' ] );
	$block_content = ( new ReflectionMethod( WC_Install::class, 'get_checkout_block_content' ) )->invoke( null );
	$blocks = wp_insert_post( [ 'post_title' => 'VST-62 Blocks Checkout', 'post_name' => 'vst62-blocks-checkout', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $block_content ] );
	$account = wp_insert_post( [ 'post_title' => 'VST-62 My Account', 'post_name' => 'vst62-my-account', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[woocommerce_my_account]' ] );
	update_option( 'woocommerce_checkout_page_id', $classic );
	update_option( 'woocommerce_myaccount_page_id', $account );
	$customer_id = wp_insert_user( [ 'user_login' => 'vst62-http-customer', 'user_email' => 'pay62@example.test', 'user_pass' => wp_generate_password( 32 ), 'role' => 'customer' ] );
	vst_assert_true( ! is_wp_error( $customer_id ), 'HTTP customer created' );
	$pay_order = wc_create_order( [ 'customer_id' => $customer_id ] );
	$pay_order->add_product( $product, 1 );
	$pay_order->set_billing_email( 'pay62@example.test' );
	$pay_order->set_billing_country( 'VN' );
	$pay_order->set_payment_method( 'bacs' );
	$pay_order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, 'manual' );
	$pay_order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE, 'VST62-HTTP-TRACK' );
	$pay_order->calculate_totals();
	$pay_order->save();
	$shipment = Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions::get_current_shipment( $pay_order );
	$timeline = Yoohw_Vietnam_Store_Tools_Shipment_Tracking::add_timeline_event( $pay_order, [ 'status' => 'in_transit', 'occurred_at' => gmdate( 'Y-m-d\TH:i' ), 'expected_shipment_id' => $shipment['id'] ] );
	vst_assert_same( true, $timeline, 'Customer order tracking timeline created' );
	$wards = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( '01' );
	$fixture = [ 'old' => $old, 'product_id' => $product->get_id(), 'classic_page_id' => $classic, 'blocks_page_id' => $blocks, 'account_page_id' => $account, 'classic_url' => get_permalink( $classic ), 'blocks_url' => get_permalink( $blocks ), 'account_order_url' => wc_get_endpoint_url( 'view-order', $pay_order->get_id(), get_permalink( $account ) ), 'account_address_url' => wc_get_endpoint_url( 'edit-address', 'billing', get_permalink( $account ) ), 'ward' => (string) array_key_first( $wards ), 'customer_id' => $customer_id, 'pay_order_id' => $pay_order->get_id(), 'pay_url' => $pay_order->get_checkout_payment_url(), 'auth_cookie_name' => LOGGED_IN_COOKIE, 'auth_cookie' => wp_generate_auth_cookie( $customer_id, time() + HOUR_IN_SECONDS, 'logged_in' ) ];
	file_put_contents( $path, wp_json_encode( $fixture ) );
	vst_assert_true( $classic > 0 && $blocks > 0 && $product->get_id() > 0 && '' !== $fixture['ward'], 'HTTP fixture pages, product and ward created' );
} elseif ( 'blocks' === $phase ) {
	vst_assert_true( isset( $fixture['blocks_page_id'] ), 'HTTP fixture exists' );
	update_option( 'woocommerce_checkout_page_id', $fixture['blocks_page_id'] );
} elseif ( 'verify' === $phase ) {
	foreach ( [ 'classic62@example.test', 'blocks62@example.test' ] as $email ) {
		$orders = wc_get_orders( [ 'billing_email' => $email, 'limit' => 2 ] );
		vst_assert_same( 1, count( $orders ), 'Exactly one HTTP checkout order: ' . $email );
		if ( ! $orders ) {
			continue;
		}
		$order = $orders[0];
		vst_assert_same( '01', $order->get_billing_state(), 'Province persisted: ' . $email );
		vst_assert_same( $fixture['ward'], $order->get_billing_city(), 'Ward persisted: ' . $email );
		vst_assert_same( '0901234567', $order->get_billing_phone(), 'Phone persisted: ' . $email );
		vst_assert_same( 'yes', $order->get_meta( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, true ), 'VAT request persisted: ' . $email );
		vst_assert_same( '0123456789', $order->get_meta( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_TAX_CODE, true ), 'VAT tax code persisted: ' . $email );
		vst_assert_same( 'bacs', $order->get_payment_method(), 'Native BACS persisted: ' . $email );
		vst_assert_same( 'on-hold', $order->get_status(), 'Native BACS order status: ' . $email );
		vst_assert_same( [], Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::get_history( $order ), 'HTTP payment is not reconciliation evidence: ' . $email );
	}
	$pay_order = wc_get_order( $fixture['pay_order_id'] );
	vst_assert_same( 'on-hold', $pay_order->get_status(), 'Native order-pay BACS status' );
	vst_assert_same( null, $pay_order->get_date_paid(), 'Native order-pay does not set paid date' );
	vst_assert_same( [], Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::get_history( $pay_order ), 'Native order-pay is not reconciliation evidence' );
} else {
	foreach ( [ 'classic62@example.test', 'blocks62@example.test' ] as $email ) {
		foreach ( wc_get_orders( [ 'billing_email' => $email, 'limit' => -1 ] ) as $order ) {
			$order->delete( true );
		}
	}
	if ( isset( $fixture['product_id'] ) && wc_get_product( $fixture['product_id'] ) ) {
		wc_get_product( $fixture['product_id'] )->delete( true );
	}
	foreach ( [ 'classic_page_id', 'blocks_page_id', 'account_page_id' ] as $key ) {
		if ( isset( $fixture[ $key ] ) ) {
			wp_delete_post( $fixture[ $key ], true );
		}
	}
	if ( isset( $fixture['pay_order_id'] ) && wc_get_order( $fixture['pay_order_id'] ) ) {
		wc_get_order( $fixture['pay_order_id'] )->delete( true );
	}
	if ( isset( $fixture['customer_id'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $fixture['customer_id'] );
	}
	foreach ( $fixture['old'] ?? [] as $name => $value ) {
		if ( false === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}
	@unlink( $path );
}
vst_finish_contract_suite( 'HTTP checkout fixture ' . $phase );
