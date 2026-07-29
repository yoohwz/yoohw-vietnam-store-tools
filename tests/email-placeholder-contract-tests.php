<?php
/**
 * Standalone contract tests for custom WooCommerce email placeholders.
 *
 * Run with: php tests/email-placeholder-contract-tests.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

class WC_Email {
	public $id = '';
	public $customer_email = false;
	public $manual = false;
	public $title = '';
	public $description = '';
	public $email_group = '';
	public $template_html = '';
	public $template_plain = '';
	public $template_base = '';
	public $placeholders = [];
	public $object = null;
	public $recipient = '';
	public $test_settings = [];
	public $sent = [];

	public function __construct() {
		$this->placeholders = array_merge(
			[
				'{site_title}'   => 'Veevee Store',
				'{site_address}' => 'veevee.test',
				'{site_url}'     => 'veevee.test',
				'{store_email}'  => 'store@veevee.test',
			],
			$this->placeholders
		);
	}

	public function setup_locale() {}
	public function restore_locale() {}
	public function get_recipient() { return $this->recipient; }
	public function get_blogname() { return 'Veevee Store'; }
	public function get_from_address() { return 'store@veevee.test'; }
	public function is_enabled() { return true; }
	public function get_headers() { return ''; }
	public function get_attachments() { return []; }
	public function get_content() { return ''; }
	public function get_option_or_transient( $key, $default = '' ) {
		return array_key_exists( $key, $this->test_settings ) ? $this->test_settings[ $key ] : $default;
	}
	public function format_string( $value ) {
		return str_replace( array_keys( $this->placeholders ), array_values( $this->placeholders ), $value );
	}
	public function get_subject() {
		return $this->format_string( $this->get_option_or_transient( 'subject', $this->get_default_subject() ) );
	}
	public function get_heading() {
		return $this->format_string( $this->get_option_or_transient( 'heading', $this->get_default_heading() ) );
	}
	public function get_additional_content() {
		return $this->format_string( $this->get_option_or_transient( 'additional_content', $this->get_default_additional_content() ) );
	}
	public function send( $recipient, $subject ) {
		$this->sent = [
			'recipient'          => $recipient,
			'subject'            => $subject,
			'heading'            => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
		];

		return true;
	}
}

class WC_Order {
	private $number;

	public function __construct( $number ) {
		$this->number = $number;
	}

	public function get_billing_email() { return 'customer@veevee.test'; }
	public function get_date_created() { return new DateTimeImmutable( '2026-07-28 08:00:00' ); }
	public function get_order_number() { return $this->number; }
	public function get_currency() { return 'VND'; }
}

class Yoohw_Vietnam_Store_Tools_Shipping {
	public static function get_display_tracking_code( $provider, $tracking_code ) {
		unset( $provider );

		return $tracking_code;
	}
}

$test_orders = [
	13235 => new WC_Order( '13235' ),
	13236 => new WC_Order( '13236' ),
];

function wc_get_order( $order_id ) {
	global $test_orders;

	return isset( $test_orders[ $order_id ] ) ? $test_orders[ $order_id ] : false;
}

function __( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ) { return trim( (string) $value ); }
function wc_format_datetime( $value ) { return $value instanceof DateTimeInterface ? $value->format( 'd/m/Y H:i' ) : ''; }
function absint( $value ) { return abs( (int) $value ); }
function get_attached_file() { return ''; }
function home_url() { return 'https://veevee.test'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require dirname( __DIR__ ) . '/includes/emails/class-vietnam-commerce-kit-customer-shipping-tracking-email.php';
require dirname( __DIR__ ) . '/includes/emails/class-vietnam-commerce-kit-customer-electronic-invoice-email.php';

$failures  = [];
$assertions = 0;

function assert_email_contract( $expected, $actual, $label ) {
	global $assertions, $failures;

	++$assertions;

	if ( $expected !== $actual ) {
		$failures[] = $label . ': expected "' . $expected . '", got "' . $actual . '"';
	}
}

$shipping_email = new Yoohw_Vietnam_Store_Tools_Customer_Shipping_Tracking_Email();
$shipping_email->test_settings = [
	'subject'            => 'Order {order_number} at {site_title} ({site_address}/{site_url}): {shipment_status}',
	'heading'            => '{shipping_provider} - {tracking_code}',
	'additional_content' => '{store_email} | {tracking_url} | {order_date}',
];
$shipping_email->placeholders['{site_title}']   = '';
$shipping_email->placeholders['{site_address}'] = '';
$shipping_email->placeholders['{site_url}']     = '';
$shipping_email->placeholders['{store_email}']  = '';
$shipping_email->trigger(
	13235,
	[
		'provider'      => 'viettelpost',
		'tracking_code' => '147894428896',
		'tracking_url'  => 'https://example.test/147894428896',
		'status'        => 'Out for delivery',
	],
	[ 'name' => 'Viettel Post' ]
);

assert_email_contract( 'Order 13235 at Veevee Store (veevee.test/veevee.test): Out for delivery', $shipping_email->sent['subject'], 'Shipping subject placeholders' );
assert_email_contract( 'Viettel Post - 147894428896', $shipping_email->sent['heading'], 'Shipping heading placeholders' );
assert_email_contract( 'store@veevee.test | https://example.test/147894428896 | 28/07/2026 08:00', $shipping_email->sent['additional_content'], 'Shipping additional-content placeholders' );

$shipping_email->trigger(
	13236,
	[
		'provider'      => 'ghtk',
		'tracking_code' => '1005637986',
		'tracking_url'  => 'https://example.test/1005637986',
		'status'        => 'Delivered',
	],
	[ 'name' => 'GHTK' ]
);

assert_email_contract( 'Order 13236 at Veevee Store (veevee.test/veevee.test): Delivered', $shipping_email->sent['subject'], 'Shipping placeholders refresh between sends' );
assert_email_contract( 'GHTK - 1005637986', $shipping_email->sent['heading'], 'Shipping heading refresh between sends' );

$invoice_email = new Yoohw_Vietnam_Store_Tools_Customer_Electronic_Invoice_Email();
$invoice_email->test_settings = [
	'subject'            => 'Invoice {invoice_number}/{invoice_symbol} - order {order_number} - {site_title}',
	'heading'            => 'Invoice {invoice_number}',
	'additional_content' => '{store_email} | {order_date} | {invoice_symbol}',
];
$invoice_email->placeholders['{site_title}']  = '';
$invoice_email->placeholders['{store_email}'] = '';
$invoice_email->trigger(
	13235,
	[
		'number' => '00001235',
		'symbol' => '1C26TVV',
	]
);

assert_email_contract( 'Invoice 00001235/1C26TVV - order 13235 - Veevee Store', $invoice_email->sent['subject'], 'Invoice subject placeholders' );
assert_email_contract( 'Invoice 00001235', $invoice_email->sent['heading'], 'Invoice heading placeholders' );
assert_email_contract( 'store@veevee.test | 28/07/2026 08:00 | 1C26TVV', $invoice_email->sent['additional_content'], 'Invoice additional-content placeholders' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS: ' . $assertions . " email placeholder contract checks.\n";
