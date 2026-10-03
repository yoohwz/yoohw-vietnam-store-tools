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
	$stock_before = wc_get_product( $product->get_id() )->get_stock_quantity();
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

	vst_assert_same( false, class_exists( 'Yoohw_Vietnam_Store_Tools_Returns_Lite', false ), 'Returns Lite domain is not bootstrapped' );
	vst_assert_same( false, class_exists( 'Yoohw_Vietnam_Store_Tools_Returns_Lite_Admin', false ), 'Returns Lite admin is not bootstrapped' );
	vst_assert_same( false, has_action( 'admin_post_yoohw_vietnam_store_tools_return_action' ), 'Return action is not registered' );
	require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
	require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-payment-gateways.php';
	$payment_settings = new WC_Settings_Payment_Gateways();
	vst_assert_same( false, $payment_settings->should_render_react_section( 'bacs' ), 'BACS uses native extension settings' );
	foreach ( [ 'main', 'offline', 'cod', 'cheque' ] as $section ) {
		vst_assert_same( true, $payment_settings->should_render_react_section( $section ), 'React preserved for ' . $section );
	}
	$gateway = WC()->payment_gateways()->payment_gateways()['bacs'];
	vst_assert_same( 'WC_Gateway_BACS', get_class( $gateway ), 'Native BACS registry remains unchanged' );
	vst_assert_true( isset( $gateway->get_form_fields()[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED ] ), 'Native filter contains VietQR setting' );
	vst_assert_true( preg_match( '/<input[^>]*name="yoohw_vietnam_store_tools_shipping\[tracking_code\]"[^>]*required/', $shipping_html ) === 0, 'Tracking input cannot constrain parent order form' );
	$payment = new Yoohw_Vietnam_Store_Tools_BACS_VietQR();
	vst_assert_same( false, method_exists( $payment, 'render_payment_link_metabox' ), 'Payment link renderer is absent' );
	vst_assert_same( false, has_action( 'add_meta_boxes', [ $payment, 'add_payment_link_metabox' ] ), 'Payment link metabox is not registered' );
	ob_start();
	$payment->render_admin_order_metabox( $order );
	$vietqr_html = ob_get_clean();
	vst_assert_true( false !== strpos( $vietqr_html, 'vck-vietqr-payment' ) && false !== strpos( $vietqr_html, '123456789' ), 'VietQR admin details retain configured account' );
	vst_assert_true( false === strpos( $vietqr_html, 'vck-payment-link' ) && false === strpos( $vietqr_html, 'vck-payment-copy' ) && false === strpos( $vietqr_html, 'Payment link and instructions' ), 'VietQR admin has no payment-link UI' );
	vst_assert_same( [], Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::get_history( $order ), 'VietQR rendering is not reconciliation evidence' );
	$historical_returns = [ [ 'id' => 'historical-staging-return', 'revision' => 2 ] ];
	$order->update_meta_data( '_yoohw_vietnam_store_tools_returns_lite', $historical_returns );
	$order->save();

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

	$invoice = 'Yoohw_Vietnam_Store_Tools_Electronic_Invoice';
	vst_assert_same( true, $invoice::update_order_data( $order, [ 'status' => 'verified' ], [ 'source' => 'fixture_legacy' ] ), 'Legacy invoice API works alongside other ledgers' );
	$invoice_order = wc_get_order( $order->get_id() );
	$document = $invoice::record_order_document( $invoice_order, [ 'kind' => 'original', 'provider' => 'Fixture provider', 'number' => 'VST62-INV', 'symbol' => 'VST62', 'issued_at' => gmdate( 'c' ) ], [ 'expected_revision' => 0, 'source' => 'fixture' ] );
	vst_assert_true( ! is_wp_error( $document ), 'Original invoice snapshot persists' );
	$reloaded = wc_get_order( $order->get_id() );
	vst_assert_same( 1, count( $invoice::get_order_documents( $reloaded ) ), 'Invoice document survives reload' );
	vst_assert_same( 3, count( $reconciliation::get_history( $reloaded ) ), 'Payment history survives invoice write' );
	vst_assert_same( [ [ 'id' => 'historical', 'type' => 'delivery_failed' ] ], $reloaded->get_meta( $exception_history, true ), 'Historical exception meta survives unrelated writes' );
	vst_assert_same( $historical_returns, $reloaded->get_meta( '_yoohw_vietnam_store_tools_returns_lite', true ), 'Historical Returns Lite meta survives unrelated writes' );
	vst_assert_same( $before_status, $reloaded->get_status(), 'Operational workflows leave WooCommerce status unchanged' );
	vst_assert_same( $before_total, $reloaded->get_total(), 'Operational workflows leave order total unchanged' );
	vst_assert_same( $before_paid, $reloaded->get_date_paid(), 'Operational workflows leave paid date unchanged' );
	vst_assert_same( $before_transaction, $reloaded->get_transaction_id(), 'Operational workflows leave transaction ID unchanged' );
	vst_assert_same( $before_refunds, count( $reloaded->get_refunds() ), 'Operational workflows create no refund' );
	vst_assert_same( $stock_before, wc_get_product( $product->get_id() )->get_stock_quantity(), 'VST operational actions leave stock unchanged' );

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
