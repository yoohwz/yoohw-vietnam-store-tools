<?php
/** Read-only, bounded inventory of known fixture identities and the approved Local sentinel. */
function vst_fixture_rows( $table, $where, $values = [] ) {
 global $wpdb;
 $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
 if ( $wpdb->last_error ) { throw new RuntimeException( 'Fixture table inventory failed.' ); }
 if ( $exists !== $table ) {
  if ( strpos( $table, $wpdb->prefix . 'yoohw_cos_' ) === 0 && in_array( 'yoohw-customer-intelligence/yoohw-customer-intelligence.php', (array) get_option( 'active_plugins', [] ), true ) ) { throw new RuntimeException( 'Active integration inventory schema unavailable.' ); }
  return [];
 }
 $sql = "SELECT * FROM `{$table}` WHERE {$where}";
 $rows = $wpdb->get_results( $values ? $wpdb->prepare( $sql, $values ) : $sql, ARRAY_A );
 if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Fixture identity inventory failed.' ); }
 // Stable fingerprints without exposing personal fields in evidence.
 usort( $rows, static function( $a, $b ) { return strcmp( serialize( $a ), serialize( $b ) ); } );
 return $rows;
}
function vst_fixture_inventory( $email, $ids = [], $user_id = 0 ) {
 global $wpdb;
 $order_ids = wc_get_orders( [ 'billing_email' => $email, 'limit' => 10001, 'return' => 'ids' ] );
 $order_ids = array_merge( $order_ids, wc_get_orders( [ 'created_via' => strstr( $email, '@', true ), 'limit' => 10001, 'return' => 'ids' ] ) );
 if ( $user_id ) { $order_ids = array_merge( $order_ids, wc_get_orders( [ 'customer_id' => $user_id, 'limit' => 10001, 'return' => 'ids' ] ) ); }
 $result = [ 'wp_users' => count( vst_fixture_rows( $wpdb->users, 'user_email=%s OR user_login=%s', [ $email, strstr( $email, '@', true ) ] ) ), 'orders' => count( array_unique( array_map( 'intval', $order_ids ) ) ) ];
 $result['wc_customer_lookup'] = count( vst_fixture_rows( $wpdb->prefix . 'wc_customer_lookup', 'email=%s', [ $email ] ) );
 $profiles = vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_customers', 'email=%s' . ( $user_id ? ' OR wp_user_id=%d' : '' ), $user_id ? [ $email, $user_id ] : [ $email ] );
 $profile_ids = array_map( 'intval', array_column( $profiles, 'id' ) );
 $result['ci_profiles'] = count( $profiles );
 foreach ( [ 'events', 'notes', 'tasks', 'customer_tags', 'customer_segments', 'customer_order_facts' ] as $kind ) {
  $clauses = []; $values = [];
  foreach ( $profile_ids as $id ) { $clauses[] = 'customer_id=%d'; $values[] = $id; }
  if ( $user_id && in_array( $kind, [ 'events', 'notes' ], true ) ) { $clauses[] = 'wp_user_id=%d'; $values[] = $user_id; }
  if ( $ids && 'customer_order_facts' === $kind ) { foreach ( $ids as $id ) { $clauses[] = 'order_id=%d'; $values[] = $id; } }
  if ( $ids && 'events' === $kind ) { foreach ( $ids as $id ) { $clauses[] = "(object_type='order' AND object_id=%s)"; $values[] = (string) $id; } }
  $result[ 'ci_' . $kind ] = $clauses ? count( vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_' . $kind, '(' . implode( ' OR ', $clauses ) . ')', $values ) ) : 0;
 }
 $result['ci_notification_log'] = $user_id ? count( vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_notification_log', 'recipient_user_id=%d', [ $user_id ] ) ) : 0;
 $result['ci_migration_issues'] = 0;
 foreach ( $ids as $id ) { $result['ci_migration_issues'] += count( vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_migration_issues', "object_type='order' AND object_id=%d", [ $id ] ) ); }
 // Read only known Local integration references to the exact created IDs.
 $result['external_loyalty_log'] = 0; $result['external_order_queue_audit'] = 0;
 $clauses = []; $values = [];
 if ( $user_id ) { $clauses[] = 'user_id=%d'; $values[] = $user_id; }
 foreach ( $ids as $id ) { $clauses[] = 'order_id=%d'; $values[] = $id; }
 if ( $clauses ) { $result['external_loyalty_log'] = count( vst_fixture_rows( $wpdb->prefix . 'yo_loyalty_points_log', implode( ' OR ', $clauses ), $values ) ); }
 foreach ( $ids as $id ) {
  $result['external_order_queue_audit'] += count( vst_fixture_rows( $wpdb->postmeta, '(meta_key IN (%s,%s) AND meta_value=%s) OR (meta_key IN (%s,%s) AND meta_value LIKE %s)', [ '_wcaoa_queue_order_id', '_wcaoa_primary_order_id', (string) $id, '_wcaoa_order_ids', '_wcaoa_created_order_ids', '%' . $wpdb->esc_like( 'i:' . $id . ';' ) . '%' ] ) );
 }
 $secret = get_option( 'yoohw_cos_privacy_suppression_secret', null );
 $result['ci_suppression'] = 0;
 if ( null === $secret ) {
  if ( vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_privacy_suppression', '1=1 LIMIT 1' ) ) { throw new RuntimeException( 'Suppression receipt secret unavailable; inventory refused.' ); }
 } else {
  if ( ! is_string( $secret ) || ! preg_match( '/^[a-f0-9]{64}$/D', $secret ) ) { throw new RuntimeException( 'Cannot inventory suppression receipts safely.' ); }
  if ( vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_privacy_suppression', 'hash_version<>1 LIMIT 1' ) ) { throw new RuntimeException( 'Unknown suppression hash version; inventory refused.' ); }
  foreach ( [ 'email' => $email ] + ( $user_id ? [ 'user' => (string) $user_id ] : [] ) as $kind => $value ) {
   $digest = hash_hmac( 'sha256', $kind . '|' . $value, hex2bin( $secret ) );
   $result['ci_suppression'] += count( vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_privacy_suppression', 'identity_kind=%s AND identity_digest=%s', [ $kind, $digest ] ) );
  }
 }
 return $result;
}
function vst_fixture_sentinel() {
 if ( 'veeveestore.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { return null; }
 global $wpdb;
 $rows = [];
 // Human-preserved Local sentinels: original profile and aborted-run synthetic residue.
 foreach ( [ 1330, 1331 ] as $profile_id ) {
  $rows[ $profile_id ] = [ 'profile' => vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_customers', 'id=%d', [ $profile_id ] ) ];
  foreach ( [ 'events', 'notes', 'tasks', 'customer_tags', 'customer_segments', 'customer_order_facts' ] as $kind ) {
   $rows[ $profile_id ][ $kind ] = vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_' . $kind, 'customer_id=%d', [ $profile_id ] );
  }
  foreach ( $rows[ $profile_id ]['tasks'] as $task ) { $rows[ $profile_id ]['notifications'][ $task['id'] ] = vst_fixture_rows( $wpdb->prefix . 'yoohw_cos_notification_log', 'task_id=%d', [ $task['id'] ] ); }
 }
 $rows['historical_ledger'] = get_option( 'vst90_settings_fixture_vst90-699f04d036a63d68e50fbc06', null );
 return hash( 'sha256', serialize( $rows ) );
}
function vst_fixture_assert_zero( $inventory ) {
 foreach ( $inventory as $count ) { if ( 0 !== $count ) { throw new RuntimeException( 'Fixture identity collision or dependent residual; no purge authorized.' ); } }
}
