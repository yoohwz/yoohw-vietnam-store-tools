<?php
/** Failure injection against the actual cleanup driver; no WordPress/database access. */
if ( ! isset( $argv[1] ) ) {
 require __DIR__ . '/support/assertions.php';
 foreach ( [ 'sentinel', 'delete', 'restore', 'unknown-file', 'partial-file', 'pending-absent', 'pending-complete', 'retry-lock' ] as $scenario ) {
  $output = []; $status = 0;
  exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1', $output, $status );
  vst_assert_same( 0, $status, 'Actual cleanup failure/recovery contract: ' . $scenario . ' ' . implode( "\n", $output ) );
 }
 vst_finish_contract_suite( 'VST-90 recovery' ); return;
}
$scenario = $argv[1];
require __DIR__ . '/support/settings-fixture-safety.php';
$test_run = VST_Settings_Fixture_Safety::new_run(); putenv( 'VST_TEST_RUN=' . $test_run );
define( 'WP_CLI', true ); define( 'ARRAY_A', 'ARRAY_A' );
define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/' . $test_run ); mkdir( WPMU_PLUGIN_DIR, 0700 );
$auth_path = __DIR__ . '/fixtures/.vst90-' . $test_run . '.php';
$ledger_key = VST_Settings_Fixture_Safety::option_key( $test_run );
$test_ledger = [ 'run' => $test_run, 'email' => $test_run . '@example.test', 'created_user_id' => 9, 'user_delete_intent' => true, 'orders' => [], 'files' => [], 'sentinel' => null, 'phase' => 'ready', 'old' => [ 'test_restore' => [ 'exists' => true, 'value' => 'original' ] ], 'admin_meta' => [] ];
$options = [ $ledger_key => $test_ledger, 'vst90_settings_fixture_lock' => $test_run, 'test_restore' => 'changed' ];
$order = null;
if ( 'sentinel' === $scenario ) { $options[ $ledger_key ]['sentinel'] = 'changed-sentinel'; }
if ( 'delete' === $scenario ) {
 $options[ $ledger_key ]['orders'] = [ [ 'id' => 12, 'complete' => true ] ];
 $order = new class( $test_run ) {
  private $run; public function __construct( $run ) { $this->run = $run; }
  public function get_id() { return 12; } public function get_meta( $key ) { return $this->run; }
  public function get_billing_email() { return $this->run . '@example.test'; } public function get_customer_id() { return 9; }
  public function delete( $force ) { return false; }
 };
}
if ( in_array( $scenario, [ 'unknown-file', 'partial-file', 'pending-complete' ], true ) ) {
 file_put_contents( $auth_path, 'partial-file' === $scenario ? 'partial' : '<?php exit; ?>' ); chmod( $auth_path, 0600 );
}
if ( in_array( $scenario, [ 'partial-file', 'pending-absent', 'pending-complete' ], true ) ) {
 $options[ $ledger_key ]['write_intents'][ $auth_path ] = hash( 'sha256', '<?php exit; ?>' );
}
if ( 'retry-lock' === $scenario ) { unset( $options['vst90_settings_fixture_lock'] ); $options[ $ledger_key ]['phase'] = 'cleaning'; }
function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
function home_url() { return 'http://localhost'; }
function wp_get_environment_type() { return 'local'; }
function is_multisite() { return false; }
function add_filter() {}
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { global $options, $scenario; if ( 'restore' === $scenario && 'test_restore' === $key ) { return false; } $options[ $key ] = $value; return true; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { global $options; if ( array_key_exists( $key, $options ) ) { return false; } $options[ $key ] = $value; return true; }
function delete_option( $key ) { global $options; unset( $options[ $key ] ); return true; }
function get_user_by() { return false; }
function wc_get_order() { global $order; return $order; }
function wc_get_orders() { return []; }
$wpdb = new class {
 public $last_error = ''; public $prefix = 'wp_'; public $users = 'wp_users'; public $postmeta = 'wp_postmeta';
 public function prepare( $sql, $values ) { return $sql; } public function esc_like( $value ) { return $value; } public function get_var( $sql ) { return null; }
};
$args = [ 'VST-90-SETTINGS', 'cleanup' ]; $caught = null;
try { require __DIR__ . '/runtime-settings-regressions-fixture.php'; } catch ( RuntimeException $error ) { $caught = $error; }
try {
 $failure_expected = in_array( $scenario, [ 'sentinel', 'delete', 'restore', 'unknown-file', 'partial-file' ], true );
 if ( $failure_expected !== ( null !== $caught ) ) { throw new RuntimeException( 'Unexpected cleanup outcome.' ); }
 if ( $failure_expected ) {
  if ( ! isset( $options[ $ledger_key ], $options['vst90_settings_fixture_lock'] ) ) { throw new RuntimeException( 'Recovery ledger/lock lost.' ); }
  if ( 'original' === $options['test_restore'] ) { throw new RuntimeException( 'Mutation continued after earlier failure.' ); }
  if ( in_array( $scenario, [ 'unknown-file', 'partial-file' ], true ) && ! is_file( $auth_path ) ) { throw new RuntimeException( 'Unproven file was removed.' ); }
 } elseif ( isset( $options[ $ledger_key ] ) || isset( $options['vst90_settings_fixture_lock'] ) || file_exists( $auth_path ) || 'original' !== $options['test_restore'] ) { throw new RuntimeException( 'Successful recovery left residue.' ); }
} finally {
 // Only this mock's freshly generated exact paths; never Local fixture paths.
 if ( is_file( $auth_path ) ) { unlink( $auth_path ); } rmdir( WPMU_PLUGIN_DIR );
}
echo 'PASS mock: ' . $scenario . "\n";
