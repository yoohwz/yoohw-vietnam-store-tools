<?php
/** Standalone security/ownership contracts for the Local-only fixture. */
require __DIR__ . '/support/assertions.php';
require __DIR__ . '/support/settings-fixture-safety.php';
function get_option( $key, $default = false ) { $options = [ 'false' => false, 'empty' => '', 'array' => [ 'nested' => false ] ]; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
foreach ( [ 'absent' => [ 'exists' => false, 'value' => null ], 'false' => [ 'exists' => true, 'value' => false ], 'empty' => [ 'exists' => true, 'value' => '' ], 'array' => [ 'exists' => true, 'value' => [ 'nested' => false ] ] ] as $key => $expected ) { vst_assert_same( $expected, VST_Settings_Fixture_Safety::snapshot_option( $key ), 'Option snapshot preserves existence and false/empty/nested value: ' . $key ); }
$run = VST_Settings_Fixture_Safety::new_run();
vst_assert_true( VST_Settings_Fixture_Safety::valid_run( $run ), 'Cryptographic namespace accepted' );
vst_assert_true( $run !== VST_Settings_Fixture_Safety::new_run(), 'Distinct per-run identities' );
foreach ( [ '', '../vst90', 'vst85-order', 'vst90-' . str_repeat( 'a', 23 ), 'vst90-' . str_repeat( 'a', 25 ), [], null ] as $invalid ) { vst_assert_same( false, VST_Settings_Fixture_Safety::valid_run( $invalid ), 'Malformed namespace refused' ); }
$ledger = [ 'run' => $run, 'created_user_id' => 9, 'email' => $run . '@example.test' ];
foreach ( [ 'production', 'staging', 'development', '' ] as $environment ) { vst_assert_same( false, VST_Settings_Fixture_Safety::allowed_environment( $environment, 'veeveestore.local', 'veeveestore.local' ), 'Fixture cannot mutate outside explicit Local context' ); }
vst_assert_same( false, VST_Settings_Fixture_Safety::allowed_environment( 'local', 'other.local', 'veeveestore.local' ), 'Shared Local host must be explicitly selected' );
vst_assert_same( true, VST_Settings_Fixture_Safety::allowed_environment( 'local', 'veeveestore.local', 'veeveestore.local' ), 'Explicit shared Local host accepted' );
vst_assert_same( true, VST_Settings_Fixture_Safety::allowed_environment( 'local', 'localhost', false ), 'Disposable localhost accepted' );
$runtime = file_get_contents( __DIR__ . '/fixtures/vst-settings-runtime.php' );
vst_assert_same( false, strpos( $runtime, 'option_active_plugins' ), 'No plugin-load isolation filter in fixture runtime' );
vst_assert_same( false, strpos( $runtime, 'vst_fixture_token' ), 'No browser authority injection remains' );
$slug = 'yoohw-customer-intelligence/yoohw-customer-intelligence.php';
vst_assert_same( true, VST_Settings_Fixture_Safety::integration_inactive( [] ), 'Explicit inactive integration baseline accepted' );
vst_assert_same( false, VST_Settings_Fixture_Safety::integration_inactive( [ $slug ] ), 'Active integration rejected even if CLI skips loading it' );
vst_assert_same( false, VST_Settings_Fixture_Safety::integration_inactive( [], [ $slug => 1 ] ), 'Network active integration rejected' );
vst_assert_same( false, VST_Settings_Fixture_Safety::integration_inactive( false ), 'Malformed activation baseline rejected' );
$user = (object) [ 'ID' => 9, 'user_login' => $run, 'user_email' => $ledger['email'] ];
vst_assert_same( true, VST_Settings_Fixture_Safety::owned_user( $user, $ledger ), 'Exact created user owned' );
foreach ( [ 'ID' => 1330, 'user_login' => 'other', 'user_email' => 'old@example.test' ] as $key => $value ) { $foreign = clone $user; $foreign->$key = $value; vst_assert_same( false, VST_Settings_Fixture_Safety::owned_user( $foreign, $ledger ), 'Changed user identity refuses deletion' ); }
$order = new class( $run, $ledger['email'] ) {
 public $marker; public $email; public $id = 12; public $user = 9;
 public function __construct( $marker, $email ) { $this->marker = $marker; $this->email = $email; }
 public function get_id() { return $this->id; } public function get_meta( $key ) { return $this->marker; } public function get_billing_email() { return $this->email; } public function get_customer_id() { return $this->user; }
};
vst_assert_same( true, VST_Settings_Fixture_Safety::owned_order( $order, [ 'id' => 12 ], $run, $ledger['email'], 9 ), 'Marker and identity prove order ownership' );
foreach ( [ 'marker' => 'other', 'email' => 'old@example.test', 'user' => 10, 'id' => 1330 ] as $key => $value ) { $foreign = clone $order; $foreign->$key = $value; vst_assert_same( false, VST_Settings_Fixture_Safety::owned_order( $foreign, [ 'id' => 12 ], $run, $ledger['email'], 9 ), 'Changed order marker/identity refuses deletion' ); }
vst_finish_contract_suite( 'VST-90 fixture safety' );
