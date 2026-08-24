<?php
/**
 * Standalone contracts for the 1.1.5 architecture foundation.
 *
 * Run with: php tests/architecture-contract-tests.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function wp_salt( $scheme = 'auth' ) { return 'contract-test-' . $scheme; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }

class VST_Contract_Logger {
	public $events = [];

	public function log( $level, $message, $context ) {
		$this->events[] = compact( 'level', 'message', 'context' );
	}
}

$vst_contract_logger = null;

function wc_get_logger() {
	global $vst_contract_logger;
	return $vst_contract_logger;
}

require __DIR__ . '/support/assertions.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-security.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-logger.php';
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-phone-normalization.php';

$hash_a = Yoohw_Vietnam_Store_Tools_Security::hash_identifier( 'order-123', 'shipment_lookup' );
$hash_b = Yoohw_Vietnam_Store_Tools_Security::hash_identifier( 'order-123', 'shipment_lookup' );
$hash_c = Yoohw_Vietnam_Store_Tools_Security::hash_identifier( 'order-123', 'invoice_lookup' );

vst_assert_same( 64, strlen( $hash_a ), 'Opaque identifier uses SHA-256 output' );
vst_assert_same( $hash_a, $hash_b, 'Opaque identifier is deterministic within a purpose' );
vst_assert_true( $hash_a !== $hash_c, 'Opaque identifier is purpose-separated' );
vst_assert_same( '', Yoohw_Vietnam_Store_Tools_Security::hash_identifier( 'order-123', '' ), 'Opaque identifier requires an explicit purpose' );

$redacted = Yoohw_Vietnam_Store_Tools_Security::redact_sensitive_data(
	[
		'provider_id' => 'provider-a',
		'apiKey'      => 'api-secret',
		'nested'      => [
			'access_token' => 'token-secret',
			'order_id'     => 123,
		],
	]
);

vst_assert_same( 'provider-a', $redacted['provider_id'], 'Non-sensitive context is preserved' );
vst_assert_same( '[REDACTED]', $redacted['apiKey'], 'Camel-case API key is redacted' );
vst_assert_same( '[REDACTED]', $redacted['nested']['access_token'], 'Nested token is redacted' );
vst_assert_same( 123, $redacted['nested']['order_id'], 'Nested non-sensitive value is preserved' );

vst_assert_same( false, Yoohw_Vietnam_Store_Tools_Logger::log( 'info', 'Logger unavailable' ), 'Logger safely no-ops when unavailable' );
$vst_contract_logger = new VST_Contract_Logger();
vst_assert_same( true, Yoohw_Vietnam_Store_Tools_Logger::log( 'warning', 'Provider event failed', [ 'client_secret' => 'secret', 'provider_id' => 'provider-a' ] ), 'Logger accepts an available WooCommerce logger' );
vst_assert_same( '[REDACTED]', $vst_contract_logger->events[0]['context']['client_secret'], 'Logger redacts structured secrets' );
vst_assert_same( 'provider-a', $vst_contract_logger->events[0]['context']['provider_id'], 'Logger preserves safe structured context' );
vst_assert_same( 'yoohw-vietnam-store-tools', $vst_contract_logger->events[0]['context']['source'], 'Logger enforces the plugin source' );

$mobile = Yoohw_Vietnam_Store_Tools_Phone_Normalization::normalize_phone_number( '0987654321' );
$landline = Yoohw_Vietnam_Store_Tools_Phone_Normalization::normalize_phone_number( '02437654321' );

vst_assert_same( 'Viettel', $mobile['original_prefix_carrier'], 'Mobile result identifies the original prefix carrier' );
vst_assert_same( $mobile['original_prefix_carrier'], $mobile['carrier'], 'Legacy carrier result remains an alias' );
vst_assert_same( '', $landline['original_prefix_carrier'], 'Landline has no prefix carrier' );

$root      = dirname( __DIR__ );
$bootstrap = file_get_contents( $root . '/yoohw-vietnam-store-tools.php' );
$phone     = file_get_contents( $root . '/includes/class-vietnam-commerce-kit-phone-normalization.php' );
$docs      = file_get_contents( $root . '/docs/extension-contracts.md' );
$shipping  = file_get_contents( $root . '/includes/class-vietnam-commerce-kit-shipping.php' );
$tracking  = file_get_contents( $root . '/includes/class-vietnam-commerce-kit-shipment-tracking.php' );
$einvoice  = file_get_contents( $root . '/includes/class-vietnam-commerce-kit-electronic-invoice.php' );

$request_position  = strpos( $bootstrap, 'class-vietnam-commerce-kit-request-security.php' );
$security_position = strpos( $bootstrap, 'class-vietnam-commerce-kit-security.php' );
$logger_position   = strpos( $bootstrap, 'class-vietnam-commerce-kit-logger.php' );
$domain_position   = strpos( $bootstrap, 'class-vietnam-commerce-kit-admin-menu.php' );

vst_assert_true( $request_position < $security_position && $security_position < $logger_position && $logger_position < $domain_position, 'Shared helpers load before domain classes' );
vst_assert_true( false !== strpos( $phone, "'_phone_carrier', \$normalized['carrier']" ), 'Legacy phone carrier metadata remains unchanged' );

foreach (
	[
		[
			'source'  => $shipping,
			'symbols' => [
				'yoohw_vietnam_store_tools_shipping_providers',
				'function get_order_shipping_data(',
				'function update_order_shipping_data(',
			],
		],
		[
			'source'  => $tracking,
			'symbols' => [
				'yoohw_vietnam_store_tools_tracking_timeline_updated',
				'function get_timeline(',
			],
		],
		[
			'source'  => $einvoice,
			'symbols' => [
				'yoohw_vietnam_store_tools_einvoice_workflow_updated',
				'function get_order_history(',
			],
		],
	] as $contract_group
) {
	foreach ( $contract_group['symbols'] as $stable_symbol ) {
		vst_assert_true( false !== strpos( $contract_group['source'], $stable_symbol ), 'Runtime retains stable extension symbol ' . $stable_symbol );
		vst_assert_true( false !== strpos( $docs, str_replace( 'function ', '', $stable_symbol ) ), 'Extension documentation includes ' . $stable_symbol );
	}
}

vst_assert_true( false !== strpos( $docs, 'not stable extension APIs' ), 'Documentation keeps shared helpers outside the stable extension API' );

vst_finish_contract_suite( 'architecture foundation' );
