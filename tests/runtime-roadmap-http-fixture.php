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
	'woocommerce_bacs_settings',
	'woocommerce_bacs_accounts',
	Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ADDRESS_FIELDS,
	Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_PHONE_NORMALIZATION,
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
	update_option( Yoohw_Vietnam_Store_Tools_Tax_Invoice::OPTION_ID, 'yes' );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes' ] );
	update_option( 'woocommerce_bacs_accounts', [ [ 'account_name' => 'VST-62 HTTP', 'account_number' => '123456789', 'bank_name' => 'Fixture Bank' ] ] );
	$product = new WC_Product_Simple();
	$product->set_name( 'VST-62 HTTP fixture' );
	$product->set_regular_price( '10000' );
	$product->set_virtual( true );
	$product->save();
	$classic = wp_insert_post( [ 'post_title' => 'VST-62 Classic Checkout', 'post_name' => 'vst62-classic-checkout', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[woocommerce_checkout]' ] );
	$blocks = wp_insert_post( [ 'post_title' => 'VST-62 Blocks Checkout', 'post_name' => 'vst62-blocks-checkout', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:woocommerce/checkout /-->' ] );
	update_option( 'woocommerce_checkout_page_id', $classic );
	$wards = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( '01' );
	$fixture = [ 'old' => $old, 'product_id' => $product->get_id(), 'classic_page_id' => $classic, 'blocks_page_id' => $blocks, 'classic_url' => get_permalink( $classic ), 'blocks_url' => get_permalink( $blocks ), 'ward' => (string) array_key_first( $wards ) ];
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
} else {
	foreach ( [ 'classic62@example.test', 'blocks62@example.test' ] as $email ) {
		foreach ( wc_get_orders( [ 'billing_email' => $email, 'limit' => -1 ] ) as $order ) {
			$order->delete( true );
		}
	}
	if ( isset( $fixture['product_id'] ) && wc_get_product( $fixture['product_id'] ) ) {
		wc_get_product( $fixture['product_id'] )->delete( true );
	}
	foreach ( [ 'classic_page_id', 'blocks_page_id' ] as $key ) {
		if ( isset( $fixture[ $key ] ) ) {
			wp_delete_post( $fixture[ $key ], true );
		}
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
