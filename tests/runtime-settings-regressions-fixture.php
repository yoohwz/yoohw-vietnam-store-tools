<?php
/** Explicit, per-run Local/disposable settings fixture with recoverable ownership. */
require_once __DIR__ . '/support/settings-fixture-safety.php';
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'VST-90-SETTINGS' !== ( $args[0] ?? '' ) || ! in_array( $args[1] ?? '', [ 'prepare', 'cleanup', 'verify', 'inventory' ], true ) || ! VST_Settings_Fixture_Safety::allowed_environment( wp_get_environment_type(), $host, getenv( 'VST_TEST_HOST' ) ) ) { throw new RuntimeException( 'Explicit Local/disposable fixture required.' ); }
require_once __DIR__ . '/support/assertions.php';
require_once __DIR__ . '/support/settings-fixture-inventory.php';
$run = getenv( 'VST_TEST_RUN' );
$fixture_key = VST_Settings_Fixture_Safety::option_key( $run );
$lock_key = 'vst90_settings_fixture_lock';
$path = __DIR__ . '/fixtures/.vst90-' . $run . '.php';
$mu_path = WPMU_PLUGIN_DIR . '/vst90-' . $run . '.php';
$fixture = get_option( $fixture_key, [] );
if ( 'inventory' === $args[1] ) {
 $inventory = vst_fixture_inventory( $run . '@example.test' ); vst_fixture_assert_zero( $inventory );
 echo wp_json_encode( [ 'inventory' => $inventory, 'sentinel' => vst_fixture_sentinel(), 'ci_loaded' => class_exists( 'YoOhw_COS_Customers', false ), 'hpos' => Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ] ) . "\n"; return;
}
// The Human-declared stack excludes this integration; never change or hide its activation.
if ( ! VST_Settings_Fixture_Safety::integration_inactive( get_option( 'active_plugins', [] ), is_multisite() ? get_site_option( 'active_sitewide_plugins', [] ) : [] ) || class_exists( 'YoOhw_COS_Customers', false ) ) { throw new RuntimeException( 'Customer Intelligence must be inactive in the declared fixture baseline.' ); }
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
$save_ledger = static function() use ( &$fixture, $fixture_key ) { update_option( $fixture_key, $fixture, false ); if ( get_option( $fixture_key ) !== $fixture ) { throw new RuntimeException( 'Fixture ownership registration failed; stop before further mutations.' ); } };
$fail_stage = static function( $stage ) { if ( getenv( 'VST_TEST_FAIL_AFTER' ) === $stage ) { throw new RuntimeException( 'Requested fixture partial-prepare interruption: ' . $stage ); } };
$write_auth = static function( $data, $exclusive = false ) use ( $path, &$fixture, $save_ledger ) {
 if ( ! $exclusive && ( is_link( $path ) || ! is_file( $path ) || hash_file( 'sha256', $path ) !== ( $fixture['files'][ $path ] ?? null ) ) ) { throw new RuntimeException( 'Private credential ownership changed; rewrite refused.' ); }
 $content = "<?php exit; ?>\n" . wp_json_encode( $data );
 $fixture['write_intents'][ $path ] = hash( 'sha256', $content ); $save_ledger();
 $fail_stage = getenv( 'VST_TEST_FAIL_AFTER' );
 if ( 'auth-intent' === $fail_stage ) { throw new RuntimeException( 'Requested interruption before auth I/O.' ); }
 $handle = fopen( $path, $exclusive ? 'x' : 'r+' );
 if ( ! $handle ) { throw new RuntimeException( 'Cannot write owned private fixture file.' ); }
 chmod( $path, 0600 );
 if ( 'auth-partial' === $fail_stage ) { fwrite( $handle, substr( $content, 0, 10 ) ); fclose( $handle ); throw new RuntimeException( 'Requested interruption during auth I/O.' ); }
 if ( ! $exclusive ) { ftruncate( $handle, 0 ); }
 if ( strlen( $content ) !== fwrite( $handle, $content ) ) { fclose( $handle ); throw new RuntimeException( 'Incomplete private fixture write.' ); }
 fclose( $handle ); $fixture['files'][ $path ] = hash( 'sha256', $content ); unset( $fixture['write_intents'][ $path ] ); $save_ledger();
};
if ( 'prepare' === $args[1] ) {
 if ( $fixture || file_exists( $path ) || is_link( $path ) || file_exists( $mu_path ) || is_link( $mu_path ) || get_option( $lock_key, false ) ) { throw new RuntimeException( 'Existing fixture/lock/path must be recovered, never overwritten.' ); }
 $email = $run . '@example.test';
 $inventory = vst_fixture_inventory( $email ); vst_fixture_assert_zero( $inventory );
 $options = [ 'active_plugins', 'recently_activated', 'woocommerce_custom_orders_table_enabled', 'woocommerce_coming_soon', 'woocommerce_store_pages_only', 'woocommerce_currency', 'woocommerce_bacs_settings', 'woocommerce_bacs_accounts', Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ADDRESS_FIELDS, Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_PHONE_NORMALIZATION, Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_CUSTOMER_SHIPMENT_DISPLAY, Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ORDER_MANAGEMENT, Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, Yoohw_Vietnam_Store_Tools_Tax_Invoice::OPTION_ID, Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SETTINGS_OPTION ];
 $old = [];
 foreach ( $options as $key ) { $old[ $key ] = VST_Settings_Fixture_Safety::snapshot_option( $key ); }
 $fixture = [ 'run' => $run, 'email' => $email, 'expires' => time() + 14400, 'orders' => [], 'files' => [], 'old' => $old, 'preflight' => $inventory, 'sentinel' => vst_fixture_sentinel(), 'hpos' => Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'phase' => 'creating', 'admin_id' => get_current_user_id(), 'admin_meta' => [] ];
 foreach ( [ 'closedpostboxes_woocommerce_page_wc-orders', 'metaboxhidden_woocommerce_page_wc-orders', 'closedpostboxes_shop_order', 'metaboxhidden_shop_order' ] as $key ) { $fixture['admin_meta'][ $key ] = [ 'exists' => metadata_exists( 'user', $fixture['admin_id'], $key ), 'value' => get_user_meta( $fixture['admin_id'], $key, true ) ]; }
 if ( ! add_option( $lock_key, $run, '', false ) ) { throw new RuntimeException( 'Concurrent settings fixture refused.' ); }
 if ( ! add_option( $fixture_key, $fixture, '', false ) ) { delete_option( $lock_key ); throw new RuntimeException( 'Cannot register fixture ownership.' ); }
 $fail_stage( 'ledger' );
 $password = wp_generate_password( 32, true, true );
 $user_id = wp_insert_user( [ 'user_login' => $run, 'user_pass' => $password, 'user_email' => $email, 'role' => 'shop_manager' ] );
 if ( is_wp_error( $user_id ) ) { throw new RuntimeException( 'Fixture user creation failed; recover ledger.' ); }
 $fixture['created_user_id'] = $user_id; $save_ledger(); $fail_stage( 'user' );
 $auth = [ 'run' => $run, 'base' => home_url(), 'orders' => [], 'hpos' => $fixture['hpos'], 'login' => $run, 'password' => $password, 'email' => $email, 'userId' => $user_id, 'tracking' => $run . '-TRACK', 'history' => $run . '-HISTORY' ];
 $write_auth( $auth, true );
 wp_mkdir_p( WPMU_PLUGIN_DIR );
 $loader = '<?php $vst_fixture_run = ' . var_export( $run, true ) . '; require ' . var_export( __DIR__ . '/fixtures/vst-settings-runtime.php', true ) . ';';
 $fixture['write_intents'][ $mu_path ] = hash( 'sha256', $loader ); $save_ledger(); $fail_stage( 'mu-intent' );
 $handle = fopen( $mu_path, 'x' ); if ( ! $handle ) { throw new RuntimeException( 'MU path collision; recover ledger.' ); } chmod( $mu_path, 0600 ); $written = fwrite( $handle, $loader ); fclose( $handle ); if ( $written !== strlen( $loader ) ) { throw new RuntimeException( 'Incomplete temporary MU write; stop for recovery.' ); }
 $fixture['files'][ $mu_path ] = hash( 'sha256', $loader ); unset( $fixture['write_intents'][ $mu_path ] ); $save_ledger(); $fail_stage( 'files' );
 foreach ( [ false, true ] as $tracking ) {
  $order = wc_create_order( [ 'customer_id' => $user_id, 'created_via' => $run ] );
  if ( is_wp_error( $order ) ) { throw new RuntimeException( 'Fixture order creation failed.' ); }
  $fail_stage( 'order-unregistered' );
  $fixture['orders'][] = [ 'id' => $order->get_id(), 'complete' => false ]; $save_ledger();
  $order->set_currency( 'VND' ); $order->set_payment_method( 'bacs' ); $order->set_total( '250000' ); $order->set_billing_email( $email );
  $order->update_meta_data( '_vst90_fixture_namespace', $run );
  $order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, 'ghn' );
  $order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'yes' );
  $order->update_meta_data( Yoohw_Vietnam_Store_Tools_Electronic_Invoice::META_NUMBER, $auth['history'] );
  if ( $tracking ) { $order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE, $auth['tracking'] ); }
  $order->save(); $fixture['orders'][ count( $fixture['orders'] ) - 1 ]['complete'] = true; $save_ledger();
  if ( $tracking ) { $shipment = Yoohw_Vietnam_Store_Tools_Shipment_Identity::get_current_shipment( $order ); VST_Settings_Fixture_Safety::require_same( true, Yoohw_Vietnam_Store_Tools_Shipment_Tracking::add_timeline_event( $order, [ 'status' => 'in_transit', 'occurred_at' => gmdate( 'Y-m-d\TH:i' ), 'expected_shipment_id' => $shipment['id'] ] ), 'Owned fixture timeline created' ); }
  $auth['orders'][] = $order->get_id(); $fail_stage( 'order' . count( $auth['orders'] ) );
 }
 $bacs = get_option( 'woocommerce_bacs_settings', [] ); $bacs = is_array( $bacs ) ? $bacs : [];
 $bacs['vst90_unknown_extension'] = [ 'preserve' => $run ]; update_option( 'woocommerce_bacs_settings', $bacs ); update_option( 'woocommerce_currency', 'VND' );
 update_option( 'woocommerce_bacs_accounts', [ [ 'account_name' => $run, 'account_number' => '123456789', 'bank_name' => 'BIDV', 'sort_code' => '970436', 'iban' => '', 'bic' => '970418' ] ] );
 $fail_stage( 'options' );
 $fixture['phase'] = 'ready'; $save_ledger(); $write_auth( $auth );
 echo 'Prepared unique fixture namespace: ' . $run . "\n";
} else {
 if ( $fixture && ( $fixture['run'] ?? '' ) === $run && 'cleaning' === ( $fixture['phase'] ?? '' ) && false === get_option( $lock_key, false ) ) { if ( ! add_option( $lock_key, $run, '', false ) ) { throw new RuntimeException( 'Cannot reacquire interrupted cleanup lock.' ); } }
 if ( ! $fixture || ( $fixture['run'] ?? '' ) !== $run || get_option( $lock_key ) !== $run ) { throw new RuntimeException( 'Fixture registry/lock ownership mismatch.' ); }
 // Validate every owned artifact before any deletion or restoration.
 $owned_files = $fixture['files'] + ( $fixture['write_intents'] ?? [] );
 foreach ( $owned_files as $file => $digest ) { if ( ! in_array( $file, [ $path, $mu_path ], true ) ) { throw new RuntimeException( 'Foreign private path; cleanup refused.' ); } if ( ! file_exists( $file ) && ! is_link( $file ) && ( in_array( $file, $fixture['file_delete_intents'] ?? [], true ) || isset( $fixture['write_intents'][ $file ] ) ) ) { continue; } if ( ! is_file( $file ) || is_link( $file ) || ! in_array( hash_file( 'sha256', $file ), [ $digest, $fixture['write_intents'][ $file ] ?? $digest ], true ) ) { throw new RuntimeException( 'Owned private artifact changed; cleanup refused.' ); } }
 foreach ( [ $path, $mu_path ] as $file ) { if ( ! isset( $owned_files[ $file ] ) && ( file_exists( $file ) || is_link( $file ) ) ) { throw new RuntimeException( 'Unregistered private path; recovery ledger retained.' ); } }
 $user = isset( $fixture['created_user_id'] ) ? get_user_by( 'id', $fixture['created_user_id'] ) : null;
 if ( isset( $fixture['created_user_id'] ) && ! ( ! $user && ! empty( $fixture['user_delete_intent'] ) ) && ! VST_Settings_Fixture_Safety::owned_user( $user, $fixture ) ) { throw new RuntimeException( 'Fixture user ownership mismatch; cleanup refused.' ); }
 foreach ( $fixture['orders'] as $entry ) {
  $order = wc_get_order( $entry['id'] );
  if ( ! $order && in_array( $entry['id'], $fixture['order_delete_intents'] ?? [], true ) ) { continue; }
  $owned = VST_Settings_Fixture_Safety::owned_order( $order, $entry, $run, $fixture['email'], $fixture['created_user_id'] );
  if ( ! $owned && empty( $entry['complete'] ) && $order ) { $owned = $order->get_created_via() === $run && (int) $order->get_customer_id() === (int) $fixture['created_user_id'] && in_array( $order->get_billing_email(), [ '', $fixture['email'] ], true ) && in_array( $order->get_meta( '_vst90_fixture_namespace' ), [ '', $run ], true ); }
  if ( ! $owned ) { throw new RuntimeException( 'Fixture order ownership mismatch; cleanup refused.' ); }
 }
 VST_Settings_Fixture_Safety::require_same( $fixture['sentinel'], vst_fixture_sentinel(), 'Read-only sentinel unchanged before cleanup' );
 $ids = array_column( $fixture['orders'], 'id' ); $inventory = vst_fixture_inventory( $fixture['email'], $ids, $fixture['created_user_id'] ?? 0 );
 VST_Settings_Fixture_Safety::require_same( $user ? 1 : 0, $inventory['wp_users'], 'Every identity user is registered before cleanup' );
 $registered_orders = 0; foreach ( $fixture['orders'] as $entry ) { if ( wc_get_order( $entry['id'] ) ) { ++$registered_orders; } }
 VST_Settings_Fixture_Safety::require_same( $registered_orders, $inventory['orders'], 'Every identity order is registered before cleanup' );
 foreach ( $inventory as $key => $count ) { if ( ( 0 === strpos( $key, 'ci_' ) || 0 === strpos( $key, 'external_' ) ) && $count ) { throw new RuntimeException( 'Integration residual; no erasure/purge authorized.' ); } }
 if ( 'cleanup' === $args[1] ) {
   $fixture['phase'] = 'cleaning'; $save_ledger();
  foreach ( $fixture['orders'] as $entry ) { $order = wc_get_order( $entry['id'] ); if ( $order ) { $fixture['order_delete_intents'][] = $entry['id']; $save_ledger(); $order->delete( true ); } VST_Settings_Fixture_Safety::require_same( false, (bool) wc_get_order( $entry['id'] ), 'Owned order deleted' ); }
  if ( $user ) { $fixture['user_delete_intent'] = true; $save_ledger(); WP_Session_Tokens::get_instance( $user->ID )->destroy_all(); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user->ID ); VST_Settings_Fixture_Safety::require_same( false, (bool) get_user_by( 'id', $user->ID ), 'Owned user and sessions deleted' ); }
  foreach ( $fixture['old'] as $key => $snapshot ) { if ( $snapshot['exists'] ) { update_option( $key, $snapshot['value'] ); } else { delete_option( $key ); } $missing = new stdClass(); $value = get_option( $key, $missing ); VST_Settings_Fixture_Safety::require_same( $snapshot['exists'], $value !== $missing, 'Option existence restored: ' . $key ); if ( $snapshot['exists'] ) { VST_Settings_Fixture_Safety::require_same( $snapshot['value'], $value, 'Option value restored: ' . $key ); } }
  foreach ( $fixture['admin_meta'] as $key => $snapshot ) { if ( $snapshot['exists'] ) { update_user_meta( $fixture['admin_id'], $key, $snapshot['value'] ); } else { delete_user_meta( $fixture['admin_id'], $key ); } VST_Settings_Fixture_Safety::require_same( $snapshot['exists'], metadata_exists( 'user', $fixture['admin_id'], $key ), 'Admin preference existence restored' ); if ( $snapshot['exists'] ) { VST_Settings_Fixture_Safety::require_same( $snapshot['value'], get_user_meta( $fixture['admin_id'], $key, true ), 'Admin preference value restored' ); } }
  vst_fixture_assert_zero( vst_fixture_inventory( $fixture['email'], $ids, $fixture['created_user_id'] ?? 0 ) );
  VST_Settings_Fixture_Safety::require_same( $fixture['sentinel'], vst_fixture_sentinel(), 'Read-only sentinel unchanged after cleanup' );
  foreach ( $owned_files as $file => $digest ) { if ( file_exists( $file ) ) { $fixture['file_delete_intents'][] = $file; $save_ledger(); unlink( $file ); } VST_Settings_Fixture_Safety::require_same( false, file_exists( $file ), 'Owned private file removed' ); }
  foreach ( [ $path, $mu_path ] as $file ) { VST_Settings_Fixture_Safety::require_same( false, file_exists( $file ) || is_link( $file ), 'Exact private path absent' ); }
  delete_option( $lock_key ); VST_Settings_Fixture_Safety::require_same( false, get_option( $lock_key, false ), 'Owned lock removed before ledger finalization' );
  delete_option( $fixture_key );
  VST_Settings_Fixture_Safety::require_same( false, get_option( $fixture_key, false ), 'Unique registry removed' ); VST_Settings_Fixture_Safety::require_same( false, get_option( $lock_key, false ), 'Owned lock removed' );
 }
}
vst_finish_contract_suite( 'VST-90 settings fixture ' . $args[1] );
