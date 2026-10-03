<?php
/** Opt-in fixture for native settings and next-request feature regressions. */
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ( $args[0] ?? '' ) !== 'VST-85-SETTINGS' || ! in_array( $args[1] ?? '', [ 'prepare', 'cleanup' ], true ) || ( ! in_array( $host, [ 'localhost', '127.0.0.1' ], true ) && ( 'local' !== wp_get_environment_type() || getenv( 'VST_TEST_HOST' ) !== $host ) ) ) {
	throw new RuntimeException( 'Explicit disposable/local settings fixture required.' );
}
require __DIR__ . '/support/assertions.php';
$fixture_key = 'vst85_settings_test_fixture';
$path = __DIR__ . '/fixtures/.vst85-settings.json';
$mu_path = WPMU_PLUGIN_DIR . '/vst85-settings-probe.php';
$fixture = get_option( $fixture_key, [] );
if ( 'prepare' === $args[1] ) {
	vst_assert_same( [], $fixture, 'No previous settings fixture remains' );
	if ( $fixture || file_exists( $mu_path ) ) { throw new RuntimeException( 'Existing fixture must be cleaned up first.' ); }
	$options = [ 'woocommerce_currency', 'woocommerce_bacs_settings', 'woocommerce_bacs_accounts', Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ADDRESS_FIELDS, Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_PHONE_NORMALIZATION, Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_CUSTOMER_SHIPMENT_DISPLAY, Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ORDER_MANAGEMENT, Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE, Yoohw_Vietnam_Store_Tools_Tax_Invoice::OPTION_ID, Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SETTINGS_OPTION ];
	$old = [];
	foreach ( $options as $option ) { $old[ $option ] = get_option( $option, false ); }
	$fixture = [ 'old' => $old, 'orders' => [], 'user_id' => get_current_user_id() ];
	update_option( $fixture_key, $fixture, false );
	foreach ( [ false, true ] as $tracking ) {
		$order = wc_create_order();
		$order->set_currency( 'VND' );
		$order->set_payment_method( 'bacs' );
		$order->set_total( '250000' );
		$order->set_billing_email( 'vst85-order@example.test' );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, 'ghn' );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'yes' );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Electronic_Invoice::META_NUMBER, 'VST85-HISTORY' );
		if ( $tracking ) { $order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE, 'VST85-TRACK' ); }
		$order->save();
		$fixture['orders'][] = $order->get_id();
		update_option( $fixture_key, $fixture, false );
		if ( $tracking ) {
			$shipment = Yoohw_Vietnam_Store_Tools_Shipment_Identity::get_current_shipment( $order );
			vst_assert_same( true, Yoohw_Vietnam_Store_Tools_Shipment_Tracking::add_timeline_event( $order, [ 'status' => 'in_transit', 'occurred_at' => gmdate( 'Y-m-d\TH:i' ), 'expected_shipment_id' => $shipment['id'] ] ), 'Fixture timeline created' );
		}
	}
	update_option( 'woocommerce_currency', 'VND' );
	update_option( 'woocommerce_bacs_accounts', [ [ 'account_name' => 'VST85 fixture', 'account_number' => '123456789', 'bank_name' => 'Fixture Bank', 'sort_code' => '970436', 'iban' => '', 'bic' => '' ] ] );
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents( $mu_path, '<?php require WP_PLUGIN_DIR . "/' . dirname( YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_BASENAME ) . '/tests/fixtures/vst-settings-regressions-probe.php";' );
	$login = 'vst85-' . strtolower( wp_generate_password( 12, false, false ) );
	$password = wp_generate_password( 32, true, true );
	$user_id = wp_insert_user( [ 'user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.test', 'role' => 'shop_manager' ] );
	if ( is_wp_error( $user_id ) ) { throw new RuntimeException( 'Could not create fixture user.' ); }
	$fixture['created_user_id'] = $user_id;
	update_option( $fixture_key, $fixture, false );
	file_put_contents( $path, wp_json_encode( [ 'base' => home_url(), 'orders' => $fixture['orders'], 'hpos' => Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'login' => $login, 'password' => $password ] ) );
	chmod( $path, 0600 );
} else {
	foreach ( $fixture['orders'] ?? [] as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	foreach ( $fixture['old'] ?? [] as $option => $value ) {
		if ( false === $value ) { delete_option( $option ); } else { update_option( $option, $value ); }
		vst_assert_same( $value, get_option( $option, false ), 'Original setting restored: ' . $option );
	}
	if ( isset( $fixture['session_token'] ) ) { WP_Session_Tokens::get_instance( $fixture['user_id'] )->destroy( $fixture['session_token'] ); }
	if ( isset( $fixture['created_user_id'] ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $fixture['created_user_id'] ); }
	if ( file_exists( $path ) ) { unlink( $path ); }
	if ( file_exists( $mu_path ) ) { unlink( $mu_path ); }
	delete_option( $fixture_key );
}
vst_finish_contract_suite( 'VST-85 settings fixture ' . $args[1] );
