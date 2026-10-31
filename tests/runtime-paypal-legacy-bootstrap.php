<?php
/** Fresh-request stack policy checks; run only in the isolated VST-104 fixture. */
if ( ! defined( 'VST_PPCP_LEGACY_LOCAL_TEST' ) || ! VST_PPCP_LEGACY_LOCAL_TEST || 0 !== strpos( DB_NAME, 'vst104_' ) ) {
	throw new RuntimeException( 'Disposable VST-104 fixture required.' );
}
require __DIR__ . '/support/assertions.php';
$runtime = null;
foreach ( $GLOBALS['wp_filter']['woocommerce_paypal_payments_modules']->callbacks as $callbacks ) {
	foreach ( $callbacks as $callback ) {
		$f = $callback['function'];
		if ( is_array( $f ) && $f[0] instanceof Yoohw_Vietnam_Store_Tools_PayPal_Conversion ) { $runtime = $f[0]; }
	}
}
if ( ! $runtime ) { throw new RuntimeException( 'Missing VST runtime.' ); }
$scenario = $args[0] ?? 'enabled';
$container = WooCommerce\PayPalCommerce\PPCP::container();
$stack_v6 = in_array( $scenario, array( 'disabled', 'usd', 'force-v6', 'missing-flag' ), true );
vst_assert_same( $stack_v6, $container->has( 'sdk-v6.manager' ), $scenario . ' actual selected SDK stack' );
$incompatible = in_array( $scenario, array( 'force-v6', 'missing-flag' ), true );
vst_assert_same( $incompatible ? 'INCOMPATIBLE_CHECKOUT_STACK' : 'COMPATIBLE', $runtime->get_adapter_status()['reason'], $scenario . ' bounded readiness diagnostic' );
vst_assert_true( $container->get( 'wcgateway.processor.refunds' ) instanceof Yoohw_Vietnam_Store_Tools_PayPal_USD_Refund_Processor, $scenario . ' historical refund adapter still available' );
$gateway = WC()->payment_gateways()->payment_gateways()['ppcp-gateway'];
vst_assert_true( $gateway->supports( 'refunds' ), $scenario . ' refund readiness independent of checkout stack' );
vst_assert_same( true, get_option( 'woocommerce-ppcp-sdk-v6-eligible' ) === 'yes', $scenario . ' merchant SDK preference is unchanged' );
if ( $incompatible || in_array( $scenario, array( 'invalid-rate', 'authorize' ), true ) ) {
	vst_assert_same( false, $runtime->force_place_order_button( false ), $scenario . ' does not activate converted checkout' );
	vst_assert_same( array(), $runtime->filter_available_gateways( array( 'ppcp-gateway' => $gateway ) ), $scenario . ' converted gateway unavailable' );
}
vst_finish_contract_suite( 'VST-104 bootstrap ' . $scenario );
