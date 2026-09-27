<?php
/** DB-backed admin action smoke for a disposable wp-env installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args[0], $args[1], $args[2] ) || 'VST-58-ADMIN' !== $args[0] || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'This smoke requires an explicitly enabled disposable localhost WordPress installation.' );
}
require __DIR__ . '/support/assertions.php';
$expected_hpos = 'hpos' === $args[1];
$mode = $args[2];
if ( ! in_array( $mode, [ 'save', 'document' ], true ) ) {
	throw new RuntimeException( 'Unknown admin smoke mode.' );
}
vst_assert_same( $expected_hpos, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'Expected WooCommerce order storage mode is active' );
$domain = 'Yoohw_Vietnam_Store_Tools_Electronic_Invoice';
$old_option = get_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, false );
update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, 'yes' );
$order = wc_create_order();
$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'yes' );
$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_COMPANY_NAME, 'Example Company' );
$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_TAX_CODE, '0123456789' );
$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_COMPANY_ADDRESS, 'Ha Noi' );
$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_EMAIL, 'invoice@example.test' );
$order->save();
if ( 'document' === $mode ) {
	$ready = $domain::update_order_data( $order, [ 'status' => 'ready' ], [ 'v2_strict' => true, 'expected_revision' => 0 ] );
	vst_assert_true( ! is_wp_error( $ready ), 'Document fixture is ready' );
}
$order_id = $order->get_id();
register_shutdown_function( function () use ( $domain, $order_id, $old_option, $mode, $expected_hpos ) {
	try {
		$fresh = wc_get_order( $order_id );
		$data = $domain::get_order_data( $fresh );
		if ( 'save' === $mode ) {
			vst_assert_same( 'ready', $data['status'], 'Admin save persisted ready status' );
			vst_assert_same( 1, $data['workflow_revision'], 'Admin save advanced revision' );
		} else {
			vst_assert_same( 'issued', $data['status'], 'Admin document action projected issued status' );
			vst_assert_same( 2, $data['workflow_revision'], 'Admin document action advanced revision' );
			vst_assert_same( 1, count( $domain::get_order_documents( $fresh ) ), 'Admin document action persisted snapshot' );
		}
		vst_finish_contract_suite( 'DB-backed electronic invoice admin ' . $mode . ' ' . ( $expected_hpos ? 'HPOS' : 'legacy' ) );
	} finally {
		wc_get_order( $order_id )->delete( true );
		if ( false === $old_option ) {
			delete_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE );
		} else {
			update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, $old_option );
		}
	}
} );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
	'order_id' => (string) $order_id,
	'vck_einvoice_expected_revision' => 'document' === $mode ? '1' : '0',
];
if ( 'save' === $mode ) {
	$_POST['vck_einvoice_status'] = 'ready';
	$_POST['yoohw_vietnam_store_tools_einvoice_nonce'] = wp_create_nonce( $domain::ACTION_SAVE . '_' . $order_id );
	$_REQUEST = $_POST;
	( new $domain() )->handle_save_action();
} else {
	$_POST['vck_einvoice_document_kind'] = 'original';
	$_POST['vck_einvoice_provider'] = 'Manual provider';
	$_POST['vck_einvoice_number'] = 'ADMIN-58';
	$_POST['vck_einvoice_symbol'] = 'SER-58';
	$_POST['vck_einvoice_issued_at'] = '2026-09-27T10:00';
	$_POST['yoohw_vietnam_store_tools_einvoice_nonce'] = wp_create_nonce( $domain::ACTION_RECORD_DOCUMENT . '_' . $order_id );
	$_REQUEST = $_POST;
	( new $domain() )->handle_record_document_action();
}
throw new RuntimeException( 'Admin action did not redirect and exit.' );
