<?php
/** VST-92 domain mutations only on owned, disposable VST-90 fixture orders. */
require_once __DIR__ . '/support/settings-fixture-safety.php';
$run = getenv( 'VST_TEST_RUN' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'VST-92-PAYMENT' !== ( $args[0] ?? '' ) || ! VST_Settings_Fixture_Safety::allowed_environment( wp_get_environment_type(), wp_parse_url( home_url(), PHP_URL_HOST ), getenv( 'VST_TEST_HOST' ) ) ) {
	throw new RuntimeException( 'Explicit Local/disposable payment fixture required.' );
}
require __DIR__ . '/support/assertions.php';
$ledger = get_option( VST_Settings_Fixture_Safety::option_key( $run ), [] );
$option = Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_PAYMENT_RECONCILIATION;
if ( 'ready' !== ( $ledger['phase'] ?? '' ) || get_option( 'vst90_settings_fixture_lock' ) !== $run || ! isset( $ledger['old'][ $option ] ) ) {
	throw new RuntimeException( 'Owned ready fixture and option restoration snapshot required.' );
}
$orders = [];
foreach ( $ledger['orders'] as $entry ) {
	$order = wc_get_order( $entry['id'] );
	if ( ! VST_Settings_Fixture_Safety::owned_order( $order, $entry, $run, $ledger['email'], $ledger['created_user_id'] ) ) { throw new RuntimeException( 'Fixture order ownership mismatch.' ); }
	$orders[] = $order;
}
if ( count( $orders ) !== 2 ) { throw new RuntimeException( 'Two owned fixture orders required.' ); }
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
$domain = Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::class;
$proof = [ 'amount' => '250000', 'currency' => 'VND', 'observed_at' => '2026-10-04T00:00:00Z', 'transaction_id' => $run ];
$provider_calls = 0;
$provider = static function( $sources ) use ( &$provider_calls ) {
	$sources['vst92'] = static function( $order, $evidence ) use ( &$provider_calls ) { ++$provider_calls; return $evidence; };
	return $sources;
};
add_filter( 'yoohw_vietnam_store_tools_payment_evidence_sources', $provider );
$admin = new Yoohw_Vietnam_Store_Tools_Payment_Reconciliation_Admin();
$assert_disabled = static function( $order, $entry_id ) use ( $domain, $proof ) {
	$order = wc_get_order( $order->get_id() );
	$meta = serialize( array_map( static function( $item ) { return $item->get_data(); }, $order->get_meta_data() ) );
	$history = serialize( $domain::get_history( $order ) );
	$data = $domain::get_order_data( $order );
	$operations = [
		$domain::record_manual_observation( $order, [ 'amount' => '250000', 'currency' => 'VND' ] ),
		$domain::match_manual_observation( $order, $entry_id ),
		$domain::reverse_entry( $order, $entry_id ),
		$domain::record_verified_evidence( $order, 'vst92', $proof ),
	];
	foreach ( $operations as $result ) {
		vst_assert_true( is_wp_error( $result ), 'Disabled domain mutation returns WP_Error' );
		if ( ! is_wp_error( $result ) ) { throw new RuntimeException( 'Disabled mutation unexpectedly succeeded.' ); }
		vst_assert_same( 'yoohw_vietnam_store_tools_payment_feature_disabled', $result->get_error_code(), 'Disabled domain error is bounded' );
	}
	$persisted = wc_get_order( $order->get_id() );
	vst_assert_same( $meta, serialize( array_map( static function( $item ) { return $item->get_data(); }, $persisted->get_meta_data() ) ), 'All persisted order metadata bytes unchanged while OFF' );
	vst_assert_same( $history, serialize( $domain::get_history( $persisted ) ), 'Read history bytes retained while OFF' );
	vst_assert_same( $data, $domain::get_order_data( $persisted ), 'Projection available while OFF' );
};
try {
	foreach ( [ null, 'no' ] as $value ) {
		if ( null === $value ) { delete_option( $option ); } else { update_option( $option, $value ); }
		vst_assert_same( false, $domain::is_enabled(), 'Existing-install absent/no defaults OFF' );
		foreach ( $orders as $order ) { $assert_disabled( $order, 'stale' ); }
	}
	vst_assert_same( 0, $provider_calls, 'Disabled feature never invokes external provider' );
	update_option( $option, 'yes' );
	$order = $orders[0];
	$observation = $domain::record_manual_observation( $order, $proof );
	vst_assert_true( ! is_wp_error( $observation ), 'Enabled manual observation records' );
	if ( is_wp_error( $observation ) ) { throw new RuntimeException( 'Observation failed.' ); }
	$match = $domain::match_manual_observation( $order, $observation['id'] );
	vst_assert_true( ! is_wp_error( $match ), 'Enabled exact match succeeds' );
	vst_assert_same( 'reconciled', $domain::get_order_data( $order )['state'], 'Enabled manual state reconciled' );
	$partial = $domain::record_manual_observation( $order, [ 'amount' => '100', 'currency' => 'VND' ] );
	vst_assert_true( is_wp_error( $domain::match_manual_observation( $order, $partial['id'] ) ), 'Partial amount never matches' );
	$foreign = $domain::record_manual_observation( $order, [ 'amount' => '250000', 'currency' => 'USD' ] );
	vst_assert_true( is_wp_error( $domain::match_manual_observation( $order, $foreign['id'] ) ), 'Foreign currency never matches' );
	$verified = $domain::record_verified_evidence( $orders[1], 'vst92', $proof );
	vst_assert_true( ! is_wp_error( $verified ), 'Enabled registered external provider records verified evidence' );
	if ( is_wp_error( $verified ) ) { throw new RuntimeException( 'External evidence failed.' ); }
	vst_assert_same( 'external_verified', $domain::get_order_data( $orders[1] )['trust'], 'External trust projects on read' );
	vst_assert_same( $verified['id'], $domain::record_verified_evidence( $orders[1], 'vst92', $proof )['id'], 'Enabled identical replay is idempotent' );
	vst_assert_true( is_wp_error( $domain::record_verified_evidence( $order, 'vst92', $proof ) ), 'Enabled cross-order transaction reuse rejected' );
	foreach ( [ null, 'no' ] as $value ) {
		if ( null === $value ) { delete_option( $option ); } else { update_option( $option, $value ); }
		$assert_disabled( $order, $observation['id'] );
		$assert_disabled( $orders[1], $verified['id'] );
		ob_start(); $admin->render_metabox( $order ); $html = ob_get_clean();
		vst_assert_true( false !== strpos( $html, 'vck-payment-reconciliation' ), 'Existing history rendered while OFF' );
		vst_assert_same( false, false !== strpos( $html, 'name="vck_payment_' ), 'Existing history contains no mutation inputs while OFF' );
	}
	update_option( $option, 'yes' );
	vst_assert_true( ! is_wp_error( $domain::reverse_entry( $order, $match['id'] ) ), 'Re-enable restores reversal against retained history' );
	$url = Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_email_settings_url();
	$registered = '';
	foreach ( WC()->mailer()->get_emails() as $key => $email ) {
		if ( $email instanceof Yoohw_Vietnam_Store_Tools_Customer_Electronic_Invoice_Email ) { $registered = strtolower( $key ); }
	}
	parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
	vst_assert_same( $registered, $query['section'], 'Invoice settings URL matches actual WooCommerce registered email section' );
} finally {
	remove_filter( 'yoohw_vietnam_store_tools_payment_evidence_sources', $provider );
	// The enclosing fixture cleanup owns exact baseline restoration, even on failure.
	update_option( $option, 'no' );
}
vst_finish_contract_suite( 'VST-92 payment toggle ' . ( $ledger['hpos'] ? 'HPOS' : 'legacy' ) );
