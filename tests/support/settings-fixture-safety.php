<?php
/** Test-only fixture identity and ownership predicates, independent of plugin runtime. */
final class VST_Settings_Fixture_Safety {
 public static function require_same( $expected, $actual, $label ) {
  vst_assert_same( $expected, $actual, $label );
  if ( $expected !== $actual ) { throw new RuntimeException( $label . '; recovery ledger retained.' ); }
 }
 public static function valid_run( $run ) { return is_string( $run ) && 1 === preg_match( '/^vst90-[a-f0-9]{24}$/D', $run ); }
 public static function new_run() { return 'vst90-' . bin2hex( random_bytes( 12 ) ); }
 public static function option_key( $run ) {
  if ( ! self::valid_run( $run ) ) { throw new RuntimeException( 'Invalid fixture namespace.' ); }
  return 'vst90_settings_fixture_' . $run;
 }
 public static function allowed_environment( $environment, $host, $requested_host ) {
  return 'local' === $environment && is_string( $host ) && '' !== $host && ( in_array( $host, [ 'localhost', '127.0.0.1' ], true ) || $requested_host === $host );
 }
 public static function snapshot_option( $key ) {
  $missing = new stdClass(); $value = get_option( $key, $missing );
  return [ 'exists' => $value !== $missing, 'value' => $value !== $missing ? $value : null ];
 }
 public static function owned_order( $order, $entry, $run, $email, $user_id ) {
  return $order && (int) $entry['id'] === (int) $order->get_id()
   && $run === $order->get_meta( '_vst90_fixture_namespace' )
   && $email === $order->get_billing_email() && (int) $user_id === (int) $order->get_customer_id();
 }
 public static function owned_user( $user, $ledger ) {
  return $user && (int) $user->ID === (int) $ledger['created_user_id']
   && $user->user_login === $ledger['run'] && $user->user_email === $ledger['email'];
 }
 public static function integration_inactive( $active, $network = [] ) {
  $slug = 'yoohw-customer-intelligence/yoohw-customer-intelligence.php';
  return is_array( $active ) && is_array( $network ) && ! in_array( $slug, $active, true ) && ! array_key_exists( $slug, $network );
 }
}
