<?php
/**
 * DB-backed migration smoke for an isolated disposable WordPress installation.
 * Run once per order storage mode: VST_STORE_HEALTH_SMOKE=1 wp eval-file ... --user=1
 * Never run against a merchant database. Fixtures are removed in finally.
 */
if ( '1' !== getenv( 'VST_STORE_HEALTH_SMOKE' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || '127.0.0.1' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'This smoke requires an explicitly enabled disposable localhost WordPress installation.' );
}
require __DIR__ . '/support/assertions.php';
$engine = new Yoohw_Vietnam_Store_Tools_DevVN_Migration_Tools();
$call = static function ( $method, ...$args ) use ( $engine ) {
	$reflection = new ReflectionMethod( $engine, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $engine, $args );
};
$orders = [];
$users = [];
try {
	$zero = $call( 'get_ajax_migration_status' );
	vst_assert_same( 0, $zero['remaining'], 'Empty installation has no safe migration data' );
	// Use a current ward without guessing a deprecated administrative mapping.
	$map = $call( 'get_ward_to_province_map' );
	$safe = null;
	foreach ( $map as $ward => $province ) {
		$row = [ 'country' => 'VN', 'state' => '', 'city' => (string) $ward, 'address_2' => '' ];
		if ( $call( 'get_address_migration', $row ) ) { $safe = $row; break; }
	}
	vst_assert_true( null !== $safe, 'Exact-safe fixture found in authoritative dataset' );
	$review = [ 'country' => 'VN', 'state' => '', 'city' => '99999', 'address_2' => '' ];
	foreach ( [ $safe, $review ] as $index => $address ) {
		$order = wc_create_order();
		$order->set_address( $address, 'billing' );
		$order->save();
		$orders[] = $order->get_id();
		$user_id = wp_insert_user( [ 'user_login' => 'vst54-fixture-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'customer' ] );
		$users[] = $user_id;
		foreach ( $address as $field => $value ) { update_user_meta( $user_id, 'billing_' . $field, $value ); }
	}
	$shipment = wc_create_order();
	$shipment->update_meta_data( '_ghtk_ordercode', 'VST54.123456' );
	$shipment->save();
	$orders[] = $shipment->get_id();
	$writes = [];
	$queries = [];
	$monitor = static function ( $sql ) use ( &$writes, &$queries ) {
		$queries[] = $sql;
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i', $sql ) ) { $writes[] = $sql; }
		return $sql;
	};
	add_filter( 'query', $monitor );
	$before = $call( 'get_ajax_migration_status' );
	$dry = $engine->run_dry_run();
	remove_filter( 'query', $monitor );
	vst_assert_same( [], $writes, 'Both scan paths execute zero SQL writes' );
	vst_assert_same( 1, $before['addressesSafe'], 'One safe order row' );
	vst_assert_same( 1, $before['addressesReview'], 'One manual-review order row' );
	vst_assert_same( 1, $before['customerAddressesSafe'], 'One safe customer row' );
	vst_assert_same( 1, $before['customerAddressesReview'], 'One manual-review customer row' );
	vst_assert_same( 1, $before['trackingRemaining'], 'One shipment needing sync' );
	vst_assert_true( false !== strpos( $dry, '99999' ), 'Bounded scanner examples are exposed' );
	$queries = [];
	$writes = [];
	add_filter( 'query', $monitor );
	ob_start();
	( new Yoohw_Vietnam_Store_Tools_Store_Health() )->render_page();
	$html = ob_get_clean();
	$menu = new Yoohw_Vietnam_Store_Tools_Admin_Menu();
	$groups = new ReflectionMethod( $menu, 'get_setting_groups' );
	$groups->setAccessible( true );
	$groups->invoke( $menu );
	remove_filter( 'query', $monitor );
	vst_assert_same( [], $writes, 'Health render executes zero SQL writes' );
	vst_assert_true( false !== strpos( $html, esc_html__( 'Not scanned. Run a scan to see legacy data readiness.', 'yoohw-vietnam-store-tools' ) ), 'Normal render does not scan' );
	vst_assert_true( false === strpos( implode( '\n', $queries ), 'REGEXP' ), 'No migration corpus query on render' );
	$original_order = wc_get_order( $orders[1] )->get_address( 'billing' );
	$original_customer = get_user_meta( $users[1] );
	$result = $engine->run_migration();
	$after = $call( 'get_ajax_migration_status' );
	vst_assert_same( 0, $after['remaining'], 'All three exact-safe domains complete' );
	vst_assert_same( 1, $after['addressesReview'], 'Manual order still needs review' );
	vst_assert_same( 1, $after['customerAddressesReview'], 'Manual customer still needs review' );
	vst_assert_same( $original_order, wc_get_order( $orders[1] )->get_address( 'billing' ), 'Manual order address unchanged' );
	vst_assert_same( $original_customer, get_user_meta( $users[1] ), 'Manual customer metadata unchanged' );
	$migrated = wc_get_order( $orders[0] );
	foreach ( [ 'state', 'city', 'address_2' ] as $field ) {
		vst_assert_true( $migrated->meta_exists( '_vck_legacy_devvn_billing_' . $field ), 'Order backup exists including empty fields' );
		vst_assert_same( $safe[ $field ], $migrated->get_meta( '_vck_legacy_devvn_billing_' . $field ), 'Order original value retained' );
		vst_assert_same( $safe[ $field ], get_user_meta( $users[0], '_vck_legacy_devvn_user_billing_' . $field, true ), 'Customer original value retained' );
	}
	vst_assert_same( 'ghtk', wc_get_order( $orders[2] )->get_meta( '_vck_shipping_provider' ), 'Shipment normalized through existing engine' );
	vst_assert_same( '123456', wc_get_order( $orders[2] )->get_meta( '_vck_shipping_tracking_code' ), 'Shipment tracking retained' );
	vst_assert_same( $after, $call( 'get_ajax_migration_status' ), 'Post-migration scan is repeatable' );
	$tools = $engine->register_tools( [] );
	vst_assert_same( [ $engine, 'run_dry_run' ], $tools['yoohw_vietnam_store_tools_devvn_migration_dry_run']['callback'], 'Status scan compatibility retained' );
	vst_assert_same( [ $engine, 'run_migration' ], $tools['yoohw_vietnam_store_tools_devvn_migration']['callback'], 'Status migration compatibility retained' );
} finally {
	foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) { wp_delete_user( $id ); }
}
vst_finish_contract_suite( 'DB-backed store-health ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy' ) );
