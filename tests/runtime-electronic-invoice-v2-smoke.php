<?php
/** DB-backed electronic-invoice v2 smoke for a disposable wp-env installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args[0] ) || 'VST-58-SMOKE' !== $args[0] || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'This smoke requires an explicitly enabled disposable localhost WordPress installation.' );
}
require __DIR__ . '/support/assertions.php';
$expected_hpos = isset( $args[1] ) && 'hpos' === $args[1];
$actual_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
vst_assert_same( $expected_hpos, $actual_hpos, 'Expected WooCommerce order storage mode is active' );
$domain = 'Yoohw_Vietnam_Store_Tools_Electronic_Invoice';
$order = null;
$unsaved_order = null;
$pending_order = null;
$old_option = get_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, false );
try {
	update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, 'yes' );
	$unsaved_order = new WC_Order();
	$unsaved_order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'yes' );
	vst_assert_same( true, $domain::update_order_data( $unsaved_order, [ 'status' => 'adjusted' ], [ 'source' => 'old_connector' ] ), 'Legacy API accepts an unsaved order object' );
	vst_assert_true( $unsaved_order->get_id() > 0, 'Legacy update persists the unsaved order' );
	vst_assert_same( 'adjusted', $domain::get_order_data( $unsaved_order->get_id() )['status'], 'Unsaved object workflow survives DB reload' );
	vst_assert_same( '', wc_get_order( $unsaved_order->get_id() )->get_meta( $domain::META_REVISION, true ), 'Unsaved legacy object does not create a v2 revision' );
	$pending_order = wc_create_order();
	$pending_order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'yes' );
	vst_assert_same( true, $domain::update_order_data( $pending_order, [ 'status' => 'replaced' ], [ 'source' => 'old_connector' ] ), 'Legacy API preserves pending VAT request metadata on a persisted object' );
	vst_assert_same( 'yes', wc_get_order( $pending_order->get_id() )->get_meta( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, true ), 'Pending request metadata survives DB reload' );
	vst_assert_same( 'replaced', $domain::get_order_data( $pending_order->get_id() )['status'], 'Pending object workflow survives DB reload' );
	vst_assert_same( '', wc_get_order( $pending_order->get_id() )->get_meta( $domain::META_REVISION, true ), 'Persisted legacy object with pending metadata does not create a v2 revision' );
	$order = wc_create_order();
	$order->set_billing_email( 'buyer@example.test' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'yes' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_COMPANY_NAME, 'Example Company' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_TAX_CODE, '0123456789' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_COMPANY_ADDRESS, 'Ha Noi' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_EMAIL, 'invoice@example.test' );
	$order->save();
	$before = wc_get_order( $order->get_id() );
	$before_status = $before->get_status();
	$before_refunds = count( $before->get_refunds() );
	vst_assert_same( 0, $domain::get_order_data( $before )['workflow_revision'], 'Old order has zero revision' );
	vst_assert_same( [], $domain::get_order_documents( $before ), 'Old order has no document on read' );
	vst_assert_same( '', wc_get_order( $order->get_id() )->get_meta( $domain::META_REVISION, true ), 'Read did not persist revision' );
	$lock_name = '_yoohw_vst_einvoice_lock_' . $order->get_id();
	vst_assert_true( add_option( $lock_name, 'first-v2-writer|' . ( time() + 20 ), '', false ), 'First v2 writer lock fixture created' );
	$blocked = $domain::update_order_data( $order, [ 'status' => 'verified' ], [ 'source' => 'old_connector' ] );
	vst_assert_true( is_wp_error( $blocked ), 'Legacy writer cannot bypass first v2 writer lock' );
	vst_assert_same( 'requested', $domain::get_order_data( $order->get_id() )['status'], 'Blocked legacy write leaves projection unchanged' );
	delete_option( $lock_name );
	vst_assert_same( true, $domain::update_order_data( $order, [ 'status' => 'verified' ], [ 'source' => 'old_connector' ] ), 'Legacy write succeeds before v2 opt-in' );
	vst_assert_same( '', wc_get_order( $order->get_id() )->get_meta( $domain::META_REVISION, true ), 'Pre-v2 legacy write does not create revision' );
	$orders_admin = new Yoohw_Vietnam_Store_Tools_Order_Management();
	$orders_admin->handle_bulk_actions( 'https://localhost/wp-admin/', Yoohw_Vietnam_Store_Tools_Order_Management::ACTION_MARK_INVOICE_READY, [ $order->get_id() ] );
	vst_assert_same( 'ready', $domain::get_order_data( $order->get_id() )['status'], 'Bulk admin action marks complete invoice ready' );
	vst_assert_same( 1, $domain::get_order_data( $order->get_id() )['workflow_revision'], 'Bulk admin action advances revision once' );
	$orders_admin->handle_bulk_actions( 'https://localhost/wp-admin/', Yoohw_Vietnam_Store_Tools_Order_Management::ACTION_MARK_INVOICE_READY, [ $order->get_id() ] );
	vst_assert_same( 1, $domain::get_order_data( $order->get_id() )['workflow_revision'], 'Bulk admin action skips already-ready order' );
	$record = [ 'kind' => 'original', 'provider' => 'Manual provider', 'number' => 'INV-58', 'symbol' => 'SER-58', 'issued_at' => '2026-09-27T10:00:00Z' ];
	$first = $domain::record_order_document( $order, $record, [ 'expected_revision' => 1, 'source' => 'admin' ] );
	vst_assert_true( ! is_wp_error( $first ), 'Original document persists through WooCommerce CRUD' );
	$fresh = wc_get_order( $order->get_id() );
	$current = $domain::get_order_data( $fresh );
	vst_assert_same( 'issued', $current['status'], 'Current projection is issued' );
	vst_assert_same( 2, $current['workflow_revision'], 'Document advances revision' );
	vst_assert_same( 1, count( $domain::get_order_documents( $fresh ) ), 'Document survives DB reload' );
	$prior = $current['current_document_id'];
	$replacement = [ 'kind' => 'replacement', 'prior_document_id' => $prior, 'provider' => 'Manual provider', 'number' => 'INV-59', 'symbol' => 'SER-58', 'issued_at' => '2026-09-27T11:00:00Z' ];
	$second = $domain::record_order_document( $fresh, $replacement, [ 'expected_revision' => 2, 'expected_current_document_id' => $prior, 'source' => 'admin' ] );
	vst_assert_true( ! is_wp_error( $second ), 'Replacement persists' );
	vst_assert_true( is_wp_error( $domain::record_order_document( $fresh, $replacement, [ 'expected_revision' => 2, 'expected_current_document_id' => $prior ] ) ), 'Stale replacement rejected' );
	$legacy = $domain::update_order_data( $fresh, [ 'status' => 'adjusted', 'number' => 'LEGACY-EDIT' ], [ 'source' => 'integration' ] );
	vst_assert_true( ! is_wp_error( $legacy ), 'Legacy connector still writes after v2 opt-in' );
	$final = wc_get_order( $order->get_id() );
	vst_assert_same( 4, $domain::get_order_data( $final )['workflow_revision'], 'Legacy write advances revision' );
	vst_assert_same( 'INV-59', $domain::get_order_documents( $final )[1]['number'], 'Snapshot survives legacy edit' );
	vst_assert_same( $before_status, $final->get_status(), 'Workflow did not change Woo order status' );
	vst_assert_same( $before_refunds, count( $final->get_refunds() ), 'Workflow did not create refund' );
} finally {
	if ( $unsaved_order instanceof WC_Order && $unsaved_order->get_id() ) {
		$unsaved_order->delete( true );
	}
	if ( $pending_order instanceof WC_Order ) {
		$pending_order->delete( true );
	}
	if ( $order instanceof WC_Order ) {
		delete_option( '_yoohw_vst_einvoice_lock_' . $order->get_id() );
	}
	if ( $order instanceof WC_Order ) {
		$order->delete( true );
	}
	if ( false === $old_option ) {
		delete_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE );
	} else {
		update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, $old_option );
	}
}
vst_finish_contract_suite( 'DB-backed electronic invoice v2 ' . ( $actual_hpos ? 'HPOS' : 'legacy' ) );
