<?php
/** Temporary test-only probe, request locale and fixture mail suppression. */
if ( 'local' !== wp_get_environment_type() ) { return; }
require_once __DIR__ . '/../support/settings-fixture-safety.php';
$vst_fixture_ledger = get_option( VST_Settings_Fixture_Safety::option_key( $vst_fixture_run ), [] );
if ( ! is_array( $vst_fixture_ledger ) || ( $vst_fixture_ledger['run'] ?? '' ) !== $vst_fixture_run || 'ready' !== ( $vst_fixture_ledger['phase'] ?? '' ) || time() >= ( $vst_fixture_ledger['expires'] ?? 0 ) ) { return; }
// No plugin-load filter, writer isolation or browser token injection is installed.
add_filter( 'pre_wp_mail', static function( $result, $mail ) use ( $vst_fixture_ledger ) {
 $id = absint( $_POST['order_id'] ?? ( $_POST['post_ID'] ?? 0 ) );
 if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && function_exists( 'wc_get_order' ) ) {
  foreach ( $vst_fixture_ledger['orders'] as $entry ) {
   if ( (int) $entry['id'] === $id && VST_Settings_Fixture_Safety::owned_order( wc_get_order( $id ), $entry, $vst_fixture_ledger['run'], $vst_fixture_ledger['email'], $vst_fixture_ledger['created_user_id'] ) ) { return true; }
  }
 }
 foreach ( (array) $mail['to'] as $recipient ) {
  if ( is_string( $recipient ) && false !== strpos( $recipient, $vst_fixture_ledger['email'] ) ) { return true; }
 }
 return $result;
}, PHP_INT_MAX, 2 );
// Language coverage changes only this explicitly selected admin request.
add_filter( 'determine_locale', static function( $locale ) use ( $vst_fixture_ledger ) {
 if ( current_user_can( 'manage_woocommerce' ) && ( $_GET['vst_fixture_locale'] ?? '' ) === $vst_fixture_ledger['run'] ) { return 'en_US'; }
 return $locale;
} );
// A bounded per-run counter proves React saves do not register duplicate AJAX handlers.
add_action( 'wp_ajax_yoohw_vietnam_store_tools_save_bacs_vietqr_settings', static function() use ( $vst_fixture_run ) {
 if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'yoohw_vietnam_store_tools_bacs_vietqr_settings' ) ) { return; }
 $key = VST_Settings_Fixture_Safety::option_key( $vst_fixture_run ); $ledger = get_option( $key, [] );
 if ( ( $ledger['run'] ?? '' ) !== $vst_fixture_run || 'ready' !== ( $ledger['phase'] ?? '' ) ) { return; }
 $ledger['transfer_save_count'] = (int) ( $ledger['transfer_save_count'] ?? 0 ) + 1;
 update_option( $key, $ledger, false );
}, 1 );
require __DIR__ . '/vst-settings-regressions-probe.php';
