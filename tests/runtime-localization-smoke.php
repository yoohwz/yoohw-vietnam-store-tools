<?php
/**
 * Runtime translation smoke executed through `wp eval-file` in CI.
 *
 * @var array $args Positional arguments supplied by WP-CLI.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$expected = isset( $args[0] ) ? (string) $args[0] : '';

if ( '' === $expected ) {
	WP_CLI::error( 'Expected translation argument is required.' );
}

if ( ! did_action( 'init' ) ) {
	do_action( 'init' );
}

if ( 'vi' !== determine_locale() && ! switch_to_locale( 'vi' ) ) {
	WP_CLI::error( 'Unable to switch the runtime locale to vi.' );
}

$actual = __( 'Add rule', 'yoohw-vietnam-store-tools' );

if ( $actual !== $expected ) {
	WP_CLI::error( sprintf( 'Translation mismatch. Expected "%s", got "%s".', $expected, $actual ) );
}

$expected_fallbacks = [
	'Payment'      => isset( $args[1] ) && 'php-pack' === $args[1] ? 'Payment' : 'Thanh toán',
	'Unreconciled' => 'Chưa đối soát',
	'Dashboard'    => 'Bảng điều khiển',
	'Store health' => 'Sức khỏe cửa hàng',
];
foreach ( $expected_fallbacks as $source => $translated ) {
	if ( __( $source, 'yoohw-vietnam-store-tools' ) !== $translated ) {
		WP_CLI::error( 'Missing Vietnamese fallback for ' . $source );
	}
}
if ( 'Theo dõi đơn hàng Việt Nam' !== _x( 'Vietnam Order Tracking', 'block title', 'yoohw-vietnam-store-tools' ) ) {
	WP_CLI::error( 'Missing contextual Vietnamese fallback.' );
}

if ( 'LANGPACK_SENTINEL' === $expected ) {
	if ( 'Settings' !== __( 'Settings', 'yoohw-vietnam-store-tools' ) ) {
		WP_CLI::error( 'An intentionally unchanged language-pack entry lost priority.' );
	}
	if ( 'CONTEXT_SENTINEL' !== _x( 'None', 'Tax status', 'yoohw-vietnam-store-tools' ) ) {
		WP_CLI::error( 'Contextual language-pack entry lost priority.' );
	}
	$expected_plural = isset( $args[1] ) && 'php-pack' === $args[1] ? '%d services enabled' : 'PLURAL_SENTINEL';
	if ( $expected_plural !== _n( '%d service enabled', '%d services enabled', 2, 'yoohw-vietnam-store-tools' ) ) {
		WP_CLI::error( 'Plural language-pack entry lost priority.' );
	}
} else {
	if ( 'Không' !== _x( 'None', 'Tax status', 'yoohw-vietnam-store-tools' ) ) {
		WP_CLI::error( 'Missing contextual bundled translation.' );
	}
	if ( 'Đã bật %d dịch vụ' !== _n( '%d service enabled', '%d services enabled', 2, 'yoohw-vietnam-store-tools' ) ) {
		WP_CLI::error( 'Missing plural bundled translation.' );
	}
}

$block = WP_Block_Type_Registry::get_instance()->get_registered( 'yoohw-vietnam-store-tools/order-tracking' );
if ( ! $block || empty( $block->editor_script_handles ) ) {
	WP_CLI::error( 'Order tracking editor script is not registered.' );
}

$bundled_json = glob( YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'languages/yoohw-vietnam-store-tools-vi-*.json' );
if ( 1 !== count( $bundled_json ) ) {
	WP_CLI::error( 'Expected one bundled Vietnamese editor catalog.' );
}

if ( 'LANGPACK_SENTINEL' === $expected ) {
	$pack_path = WP_LANG_DIR . '/plugins/' . basename( $bundled_json[0] );
	$partial   = [
		'domain'      => 'messages',
		'locale_data' => [
			'messages' => [
				''                      => [ 'domain' => 'messages', 'lang' => 'vi' ],
				'Vietnam order tracking' => [ 'SCRIPT_LANGPACK_SENTINEL' ],
			],
		],
	];
	wp_mkdir_p( dirname( $pack_path ) );
	file_put_contents( $pack_path, wp_json_encode( $partial ) );
}

$script_json = load_script_textdomain( $block->editor_script_handles[0], 'yoohw-vietnam-store-tools', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'languages' );
$messages    = json_decode( $script_json, true );
if ( ! is_array( $messages ) || ! isset( $messages['locale_data']['messages'] ) ) {
	WP_CLI::error( 'Editor script translations did not load.' );
}
$strings = $messages['locale_data']['messages'];
if ( [ 'Khách hàng nhập mã đơn cùng email hoặc số điện thoại thanh toán để xem thông tin vận chuyển.' ] !== $strings['Customers enter an order number and their billing email or phone number to view shipment tracking.'] ) {
	WP_CLI::error( 'Editor script missing-key fallback failed.' );
}
if ( 'LANGPACK_SENTINEL' === $expected && [ 'SCRIPT_LANGPACK_SENTINEL' ] !== $strings['Vietnam order tracking'] ) {
	WP_CLI::error( 'Editor script language-pack priority failed.' );
}

if ( ! switch_to_locale( 'vi_VN' ) ) {
	WP_CLI::error( 'Unable to switch the runtime locale to vi_VN.' );
}
if ( 'Thanh toán' !== __( 'Payment', 'yoohw-vietnam-store-tools' ) || 'Thêm quy tắc' !== __( 'Add rule', 'yoohw-vietnam-store-tools' ) ) {
	WP_CLI::error( 'Vietnamese fallback failed after switching to vi_VN.' );
}
restore_previous_locale();

if ( ! switch_to_locale( 'en_US' ) || 'Payment' !== __( 'Payment', 'yoohw-vietnam-store-tools' ) ) {
	WP_CLI::error( 'Non-Vietnamese translation behavior changed.' );
}
restore_previous_locale();

WP_CLI::success( sprintf( 'Translation resolved as "%s" for locale %s.', $actual, determine_locale() ) );
