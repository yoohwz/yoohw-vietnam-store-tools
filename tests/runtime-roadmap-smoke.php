<?php
/** Integrated roadmap smoke for a disposable wp-env installation only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args[0] ) || 'VST-62-SMOKE' !== $args[0] || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'This smoke requires an explicitly enabled disposable localhost WordPress installation.' );
}
require __DIR__ . '/support/assertions.php';

$expected_hpos = isset( $args[1] ) && 'hpos' === $args[1];
$actual_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
vst_assert_same( $expected_hpos, $actual_hpos, 'Expected WooCommerce order storage mode is active' );

$old_accounts = get_option( 'woocommerce_bacs_accounts', false );
$old_settings = get_option( 'woocommerce_bacs_settings', false );
$old_invoice = get_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, false );
$product = null;
$order = null;
$extra_orders = [];
try {
	update_option( 'woocommerce_bacs_accounts', [ [ 'account_name' => 'VST fixture', 'account_number' => '123456789', 'bank_name' => 'Fixture Bank', 'sort_code' => '970436' ] ] );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED => 'yes', Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_INCLUDE_AMOUNT => 'yes' ] );
	update_option( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, 'yes' );
	$product = new WC_Product_Simple();
	$product->set_name( 'VST-62 fixture' );
	$product->set_regular_price( '10000' );
	$product->save();
	$order = wc_create_order();
	$order->add_product( $product, 3 );
	$order->calculate_totals();
	$order->set_currency( 'VND' );
	$order->set_payment_method( 'bacs' );
	$order->set_billing_email( 'fixture@example.test' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, 'manual' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE, 'VST62-TRACK' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'yes' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_COMPANY_NAME, 'Fixture Company' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_TAX_CODE, '0123456789' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_COMPANY_ADDRESS, 'Ha Noi' );
	$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_EMAIL, 'invoice@example.test' );
	$order->save();
	$item_id = (int) array_key_first( $order->get_items( 'line_item' ) );
	$before_status = $order->get_status();
	$before_total = $order->get_total();
	vst_assert_true( (float) $before_total > 0, 'Fixture has a positive payable total' );
	$before_paid = $order->get_date_paid();
	$before_transaction = $order->get_transaction_id();
	$before_refunds = count( $order->get_refunds() );
	$shipping_admin = new Yoohw_Vietnam_Store_Tools_Shipping();
	ob_start();
	$shipping_admin->render_admin_order_metabox( $order );
	$shipping_html = ob_get_clean();
	vst_assert_true( false === strpos( $shipping_html, 'Fulfillment exceptions' ) && false === strpos( $shipping_html, 'yoohw_vietnam_store_tools_record_exception' ) && false === strpos( $shipping_html, 'exception_type' ), 'Rendered shipping admin has no exception heading or action form' );

	$payment = new Yoohw_Vietnam_Store_Tools_BACS_VietQR();
	ob_start();
	$payment->render_payment_link_metabox( $order );
	$payment_html = ob_get_clean();
	$url = $order->get_checkout_payment_url();
	vst_assert_true( false !== strpos( $payment_html, esc_attr( $url ) ), 'Admin renders native order-pay URL' );
	vst_assert_true( false !== strpos( $payment_html, '123456789' ), 'BACS instructions use configured account' );
	vst_assert_same( [], Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::get_history( $order ), 'Link and QR preparation are not evidence' );
	$old_key = $order->get_order_key();
	$order->set_order_key( wc_generate_order_key() );
	$order->save();
	vst_assert_true( $old_key !== $order->get_order_key() && $url !== $order->get_checkout_payment_url(), 'Regenerated key changes native URL' );

	$reconciliation = 'Yoohw_Vietnam_Store_Tools_Payment_Reconciliation';
	$observation = $reconciliation::record_manual_observation( $order, [ 'amount' => $before_total, 'currency' => 'VND', 'observed_at' => gmdate( 'c' ), 'reference' => 'VST62-REF' ] );
	if ( is_wp_error( $observation ) ) {
		throw new RuntimeException( 'Manual observation fixture failed: ' . $observation->get_error_code() );
	}
	vst_assert_true( ! is_wp_error( $observation ), 'Manual observation persists' );
	$match = $reconciliation::match_manual_observation( $order, $observation['id'] );
	vst_assert_true( ! is_wp_error( $match ), 'Full-order manual match persists' );
	vst_assert_same( 'manual', $reconciliation::get_order_data( wc_get_order( $order->get_id() ) )['trust'], 'Manual match is not external verification' );
	$reversal = $reconciliation::reverse_entry( $order, $match['id'] );
	vst_assert_true( ! is_wp_error( $reversal ), 'Manual match can be reversed' );
	vst_assert_same( 'recorded', $reconciliation::get_order_data( wc_get_order( $order->get_id() ) )['state'], 'Reversal restores recorded observation' );

	$identity = 'Yoohw_Vietnam_Store_Tools_Shipment_Identity';
	$virtual = $identity::get_current_shipment( $order )['id'];
	vst_assert_same( 'legacy:' . $order->get_id(), $virtual, 'Existing shipment has virtual identity' );
	$timeline = Yoohw_Vietnam_Store_Tools_Shipment_Tracking::add_timeline_event( $order, [ 'status' => 'in_transit', 'occurred_at' => gmdate( 'Y-m-d\TH:i' ), 'expected_shipment_id' => $virtual ] );
	vst_assert_same( true, $timeline, 'Tracking timeline accepts current shipment' );
	$current = $identity::get_current_shipment( wc_get_order( $order->get_id() ) )['id'];
	vst_assert_true( '' !== $current && $current !== $virtual, 'Timeline materializes current shipment identity' );
	vst_assert_true( is_wp_error( $identity::assert_current( $order, $virtual ) ), 'Stale shipment identity is rejected' );
	vst_assert_same( true, $identity::assert_current( $order, $current ), 'Current shipment identity remains valid' );
	$exception_history = '_yoohw_vietnam_store_tools_shipment_exception_history';
	$order->update_meta_data( $exception_history, [ [ 'id' => 'historical', 'type' => 'delivery_failed' ] ] );
	$order->save();

	$returns = 'Yoohw_Vietnam_Store_Tools_Returns_Lite';
	$return = $returns::create( $order, [ $item_id => 1 ], [ 'reason' => 'Fixture return' ], 0 );
	vst_assert_true( ! is_wp_error( $return ), 'Return on same order persists' );
	vst_assert_true( is_wp_error( $returns::create( $order, [ $item_id => 3 ], [ 'reason' => 'Excess' ], 1 ) ), 'Quantity allocation rejects excess' );

	$invoice = 'Yoohw_Vietnam_Store_Tools_Electronic_Invoice';
	vst_assert_same( true, $invoice::update_order_data( $order, [ 'status' => 'verified' ], [ 'source' => 'fixture_legacy' ] ), 'Legacy invoice API works alongside other ledgers' );
	$invoice_order = wc_get_order( $order->get_id() );
	$document = $invoice::record_order_document( $invoice_order, [ 'kind' => 'original', 'provider' => 'Fixture provider', 'number' => 'VST62-INV', 'symbol' => 'VST62', 'issued_at' => gmdate( 'c' ) ], [ 'expected_revision' => 0, 'source' => 'fixture' ] );
	vst_assert_true( ! is_wp_error( $document ), 'Original invoice snapshot persists' );
	$reloaded = wc_get_order( $order->get_id() );
	vst_assert_same( 1, count( $invoice::get_order_documents( $reloaded ) ), 'Invoice document survives reload' );
	vst_assert_same( 3, count( $reconciliation::get_history( $reloaded ) ), 'Payment history survives invoice write' );
	vst_assert_same( [ [ 'id' => 'historical', 'type' => 'delivery_failed' ] ], $reloaded->get_meta( $exception_history, true ), 'Historical exception meta survives unrelated writes' );
	vst_assert_same( 1, count( $returns::get_returns( $reloaded ) ), 'Return survives invoice write' );
	vst_assert_same( $before_status, $reloaded->get_status(), 'Operational workflows leave WooCommerce status unchanged' );
	vst_assert_same( $before_total, $reloaded->get_total(), 'Operational workflows leave order total unchanged' );
	vst_assert_same( $before_paid, $reloaded->get_date_paid(), 'Operational workflows leave paid date unchanged' );
	vst_assert_same( $before_transaction, $reloaded->get_transaction_id(), 'Operational workflows leave transaction ID unchanged' );
	vst_assert_same( $before_refunds, count( $reloaded->get_refunds() ), 'Operational workflows create no refund' );
	vst_assert_same( '', $reloaded->get_meta( '_yoohw_vietnam_store_tools_payment_link', true ), 'No VST payment-link metadata' );
	$stock_before = wc_get_product( $product->get_id() )->get_stock_quantity();
	$admin_id = get_current_user_id();
	wp_set_current_user( 0 );
	ob_start();
	$payment->render_payment_link_metabox( $reloaded );
	$unauthorized_html = ob_get_clean();
	wp_set_current_user( $admin_id );
	vst_assert_same( '', $unauthorized_html, 'Order payment link is hidden without edit_shop_order' );
	vst_assert_same( $stock_before, wc_get_product( $product->get_id() )->get_stock_quantity(), 'VST operational actions leave stock unchanged' );

	foreach ( [ 'pending', 'failed', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded' ] as $status ) {
		$state_order = wc_create_order();
		$extra_orders[] = $state_order;
		$state_order->set_currency( 'VND' );
		$state_order->set_payment_method( 'bacs' );
		$state_order->set_total( 10000 );
		$state_order->set_status( $status );
		$state_order->save();
		$state_paid = $state_order->get_date_paid();
		$state_before = [ $state_order->get_status(), $state_paid ? $state_paid->getTimestamp() : null, $state_order->get_transaction_id(), count( $state_order->get_refunds() ) ];
		ob_start();
		$payment->render_payment_link_metabox( $state_order );
		$html = ob_get_clean();
		$has_url = false !== strpos( $html, esc_attr( $state_order->get_checkout_payment_url() ) );
		vst_assert_same( in_array( $status, [ 'pending', 'failed' ], true ), $has_url, 'Native payment URL eligibility: ' . $status );
		vst_assert_same( [], $reconciliation::get_history( $state_order ), 'Admin view is not payment evidence: ' . $status );
		$after = wc_get_order( $state_order->get_id() );
		$after_paid = $after->get_date_paid();
		vst_assert_same( $state_before, [ $after->get_status(), $after_paid ? $after_paid->getTimestamp() : null, $after->get_transaction_id(), count( $after->get_refunds() ) ], 'Admin view preserves WooCommerce state: ' . $status );
	}
	$zero = wc_create_order();
	$extra_orders[] = $zero;
	$zero->set_currency( 'VND' );
	$zero->set_payment_method( 'bacs' );
	$zero->set_total( 0 );
	$zero->save();
	ob_start();
	$payment->render_payment_link_metabox( $zero );
	$zero_html = ob_get_clean();
	vst_assert_true( false === strpos( $zero_html, esc_attr( $zero->get_checkout_payment_url() ) ), 'Zero-total order has no payment link' );
	vst_assert_same( [], $reconciliation::get_history( $zero ), 'Zero-total order has no payment evidence' );

	$bacs_order = wc_create_order();
	$extra_orders[] = $bacs_order;
	$bacs_order->set_currency( 'VND' );
	$bacs_order->set_payment_method( 'bacs' );
	$bacs_order->set_total( 10000 );
	$bacs_order->save();
	$gateway = new WC_Gateway_BACS();
	$bacs_result = $gateway->process_payment( $bacs_order->get_id() );
	vst_assert_same( 'success', $bacs_result['result'], 'Native WooCommerce BACS gateway accepts positive order' );
	$bacs_persisted = wc_get_order( $bacs_order->get_id() );
	vst_assert_same( 'on-hold', $bacs_persisted->get_status(), 'Native BACS puts order on hold' );
	vst_assert_same( [], $reconciliation::get_history( $bacs_persisted ), 'Native BACS submission is not reconciliation evidence' );
	vst_assert_same( null, $bacs_persisted->get_date_paid(), 'Native BACS does not mark order paid' );
} finally {
	foreach ( $extra_orders as $extra_order ) {
		$extra_order->delete( true );
	}
	if ( $order instanceof WC_Order ) {
		$order->delete( true );
	}
	if ( $product instanceof WC_Product ) {
		$product->delete( true );
	}
	foreach ( [ 'woocommerce_bacs_accounts' => $old_accounts, 'woocommerce_bacs_settings' => $old_settings, Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE => $old_invoice ] as $name => $old ) {
		if ( false === $old ) {
			delete_option( $name );
		} else {
			update_option( $name, $old );
		}
	}
}
vst_finish_contract_suite( 'DB-backed combined roadmap ' . ( $actual_hpos ? 'HPOS' : 'legacy' ) );
