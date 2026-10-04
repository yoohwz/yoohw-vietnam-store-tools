<?php
/** Installed only by the explicitly authorized Local/disposable test fixture. */
add_action( 'wp_ajax_vst85_settings_probe', static function() use ( $vst_fixture_run ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( [], 403 ); }
	check_ajax_referer( 'yoohw_vietnam_store_tools_save_features' );
	$fixture = get_option( VST_Settings_Fixture_Safety::option_key( $vst_fixture_run ), [] );
	if ( ! $fixture || 'ready' !== ( $fixture['phase'] ?? '' ) ) { wp_send_json_error( [], 404 ); }
	$classes = [ 'Yoohw_Vietnam_Store_Tools_Address_Fields', 'Yoohw_Vietnam_Store_Tools_Blocks_Integration', 'Yoohw_Vietnam_Store_Tools_Admin_Address_Fields', 'Yoohw_Vietnam_Store_Tools_Admin_Order_Fields', 'Yoohw_Vietnam_Store_Tools_Phone_Normalization', 'Yoohw_Vietnam_Store_Tools_Order_Management' ];
	$counts = array_fill_keys( $classes, 0 );
	$paypal = null;
	global $wp_filter;
	foreach ( $wp_filter as $hook ) {
		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$f = $callback['function'];
				if ( ! is_array( $f ) || ! is_object( $f[0] ) ) { continue; }
				$class = get_class( $f[0] );
				if ( isset( $counts[ $class ] ) ) { ++$counts[ $class ]; }
				if ( $f[0] instanceof Yoohw_Vietnam_Store_Tools_PayPal_Conversion ) { $paypal = $f[0]; }
			}
		}
	}
	$order = wc_get_order( $fixture['orders'][1]['id'] );
	ob_start(); ( new Yoohw_Vietnam_Store_Tools_Shipping() )->render_frontend_order_tracking( $order->get_id() ); $details = ob_get_clean();
	ob_start(); ( new Yoohw_Vietnam_Store_Tools_Shipment_Tracking() )->render_order_timeline( $order->get_id() ); $timeline = ob_get_clean();
	$tax = new Yoohw_Vietnam_Store_Tools_Tax_Invoice();
	ob_start(); $tax->render_checkout_fields(); $vat = ob_get_clean();
	$qr = new ReflectionMethod( Yoohw_Vietnam_Store_Tools_BACS_VietQR::class, 'get_vietqr_payment_accounts' );
	if ( PHP_VERSION_ID < 80100 ) { $qr->setAccessible( true ); }
	$payment = new Yoohw_Vietnam_Store_Tools_BACS_VietQR();
	$accounts = $qr->invoke( $payment, $order );
	ob_start(); $payment->render_thankyou_vietqr( $order->get_id() ); $frontend_qr = ob_get_clean();
	ob_start(); $payment->render_admin_order_metabox( $order ); $admin_qr = ob_get_clean();
	$email_order = clone $order;
	$email_order->set_status( 'on-hold' );
	ob_start(); $payment->render_email_vietqr( $email_order, false, false ); $email_qr = ob_get_clean();
	$order->set_currency( 'USD' ); $non_vnd = $qr->invoke( $payment, $order );
	$order->set_payment_method( 'cod' ); $non_bacs = $qr->invoke( $payment, $order );
	require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
	require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-payment-gateways.php';
	$payment_settings = new WC_Settings_Payment_Gateways();
	$options = [];
	foreach ( $fixture['old'] as $name => $unused ) { $options[ $name ] = get_option( $name, false ); }
	wp_send_json_success( [ 'fixture_mail_blocked' => true === apply_filters( 'pre_wp_mail', null, [ 'to' => [ $fixture['email'] ] ] ), 'ci_loaded' => class_exists( 'YoOhw_COS_Customers', false ), 'bacs_react' => $payment_settings->should_render_react_section( 'bacs' ), 'orders' => array_map( static function( $id ) { $o = wc_get_order( $id ); return [ 'id' => $id, 'first_name' => $o->get_billing_first_name(), 'tracking_code' => $o->get_meta( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE ) ]; }, array_column( $fixture['orders'], 'id' ) ), 'rest_nonce' => wp_create_nonce( 'wp_rest' ), 'counts' => $counts, 'options' => $options, 'shipment_details' => '' !== $details, 'shipment_timeline' => '' !== $timeline, 'vat_fields' => '' !== $vat, 'invoice_number' => Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_order_data( $order )['number'], 'invoice_save_hook' => false !== has_action( 'admin_post_' . Yoohw_Vietnam_Store_Tools_Electronic_Invoice::ACTION_SAVE ), 'public_lookup_enabled' => get_option( Yoohw_Vietnam_Store_Tools_Shipment_Tracking::OPTION_LOOKUP_ENABLED, 'yes' ), 'paypal' => $paypal->get_adapter_status(), 'paypal_force_place_order' => $paypal->force_place_order_button( false ), 'frontend_qr' => false !== strpos( $frontend_qr, 'vck-vietqr-payment' ), 'admin_qr' => false !== strpos( $admin_qr, 'vck-vietqr-payment' ), 'email_qr' => false !== strpos( $email_qr, 'img.vietqr.io' ), 'qr' => $accounts, 'non_vnd' => $non_vnd, 'non_bacs' => $non_bacs ] );
} );
