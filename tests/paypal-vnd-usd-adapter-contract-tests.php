<?php
/**
 * PPCP 4.1.3 service-extension contracts for PayPal VND/USD conversion.
 *
 * Run with: php tests/paypal-vnd-usd-adapter-contract-tests.php
 */

namespace WooCommerce\PayPalCommerce\Vendor\Psr\Log {
	interface LoggerInterface {}
	final class NullLogger implements LoggerInterface {}
}

namespace WooCommerce\PayPalCommerce\Vendor\Psr\Container {
	interface ContainerInterface {
		public function get( string $id );
		public function has( string $id ): bool;
	}
}

namespace WooCommerce\PayPalCommerce\Vendor\Inpsyde\Modularity\Module {
	interface ExtendingModule {
		public function id(): string;
		public function extensions(): array;
	}
}

namespace WooCommerce\PayPalCommerce\ApiClient\Exception {
	class RuntimeException extends \RuntimeException {}
}

namespace WooCommerce\PayPalCommerce\ApiClient\Entity {
	class Money {
		private $value;
		private $currency;
		public function __construct( float $value, string $currency ) {
			$this->value = $value;
			$this->currency = $currency;
		}
		public function value(): float { return $this->value; }
		public function currency_code(): string { return $this->currency; }
	}

	class Amount {
		private $money;
		public function __construct( Money $money ) { $this->money = $money; }
		public function value(): float { return $this->money->value(); }
		public function currency_code(): string { return $this->money->currency_code(); }
	}

	class Capture {
		private $id;
		private $amount;
		public function __construct( string $id, Amount $amount ) {
			$this->id = $id;
			$this->amount = $amount;
		}
		public function id(): string { return $this->id; }
		public function amount(): Amount { return $this->amount; }
		public function invoice_id(): string { return ''; }
	}

	class Payments {
		private $captures;
		public function __construct( array $captures ) { $this->captures = $captures; }
		public function captures(): array { return $this->captures; }
	}

	class Order {
		private $id;
		private $payments;
		public function __construct( string $id, Payments $payments ) {
			$this->id = $id;
			$this->payments = $payments;
		}
		public function id(): string { return $this->id; }
		public function test_payments(): Payments { return $this->payments; }
	}

	class RefundCapture {
		public $amount;
		public function __construct( $capture, string $invoice_id, string $reason = '', ?Amount $amount = null ) {
			unset( $capture, $invoice_id, $reason );
			$this->amount = $amount;
		}
	}
}

namespace WooCommerce\PayPalCommerce\ApiClient\Endpoint {
	class OrderEndpoint {}
	class PaymentsEndpoint {
		public $last_refund;
		public function refund( $refund ): string {
			$this->last_refund = $refund;
			return 'REFUND-1';
		}
	}
}

namespace WooCommerce\PayPalCommerce\WcGateway\Helper {
	class RefundFeesUpdater {}
}

namespace WooCommerce\PayPalCommerce\WcGateway\Processor {
	use WooCommerce\PayPalCommerce\ApiClient\Endpoint\OrderEndpoint;
	use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentsEndpoint;
	use WooCommerce\PayPalCommerce\ApiClient\Entity\Order;
	use WooCommerce\PayPalCommerce\Vendor\Psr\Log\LoggerInterface;
	use WooCommerce\PayPalCommerce\WcGateway\Helper\RefundFeesUpdater;

	class RefundProcessor {
		public function __construct( OrderEndpoint $orders, PaymentsEndpoint $payments, RefundFeesUpdater $fees, string $prefix, LoggerInterface $logger ) {
			unset( $orders, $payments, $fees, $prefix, $logger );
		}
		public function refund( Order $order, \WC_Order $wc_order, float $amount, string $reason = '' ): string {
			unset( $order, $wc_order, $amount, $reason );
			return 'parent-refund';
		}
		protected function get_payments( Order $order ) {
			return $order->test_payments();
		}
	}
}

namespace WooCommerce\PayPalCommerce\SdkV6\Assets {
	class SdkV6Manager {
		public function __construct( $a01, $a02, $a03, $a04, $a05, $a06, $a07, $a08, $a09, $a10, $a11, $a12, $a13, $a14, $a15, $a16, $a17, $a18, $a19, $a20, $a21, $a22, $a23, $a24 ) {
			unset( $a01, $a02, $a03, $a04, $a05, $a06, $a07, $a08, $a09, $a10, $a11, $a12, $a13, $a14, $a15, $a16, $a17, $a18, $a19, $a20, $a21, $a22, $a23, $a24 );
		}
		public function script_data(): array { return array( 'currency' => 'VND', 'amount' => '100000' ); }
	}
}

namespace WooCommerce\PayPalCommerce\SdkV6\Blocks {
	class V6PaymentMethod {}
}

namespace {
	use WooCommerce\PayPalCommerce\ApiClient\Endpoint\OrderEndpoint;
	use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentsEndpoint;
	use WooCommerce\PayPalCommerce\ApiClient\Entity\Amount;
	use WooCommerce\PayPalCommerce\ApiClient\Entity\Capture;
	use WooCommerce\PayPalCommerce\ApiClient\Entity\Money;
	use WooCommerce\PayPalCommerce\ApiClient\Entity\Order;
	use WooCommerce\PayPalCommerce\ApiClient\Entity\Payments;
	use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
	use WooCommerce\PayPalCommerce\Vendor\Psr\Log\NullLogger;
	use WooCommerce\PayPalCommerce\WcGateway\Helper\RefundFeesUpdater;

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	function __( $value ) { return $value; }
	function get_woocommerce_currency() { return 'VND'; }
	$GLOBALS['vst_is_checkout'] = true;
	$GLOBALS['vst_ppcp_version'] = '4.1.3';
	function is_checkout() { return $GLOBALS['vst_is_checkout']; }
	function is_order_received_page() { return false; }
	function is_checkout_pay_page() { return false; }
	function get_option( $key, $default = false ) {
		if ( 'yoohw_vietnam_store_tools_paypal_conversion_settings' === $key ) {
			return array(
				'yoohw_vietnam_store_tools_paypal_vnd_usd_enabled' => 'yes',
				'yoohw_vietnam_store_tools_paypal_vnd_usd_rate' => '25000',
			);
		}
		if ( 'woocommerce-ppcp-data-settings' === $key ) {
			return array( 'authorize_only' => false );
		}
		if ( 'woocommerce-ppcp-version' === $key ) { return $GLOBALS['vst_ppcp_version']; }
		return $default;
	}
	function add_filter() {}
	function add_action() {}

	final class Fake_PayPal_Session {
		public function get( $key ) {
			unset( $key );
			return array(
				'schema' => 1,
				'token' => 'quote-token',
				'rate' => '25000',
				'fingerprint' => 'fingerprint',
				'expires_at' => time() + 3600,
				'state' => 'quote',
				'preview_usd_total' => '4.00',
			);
		}
	}

	function WC() {
		return (object) array( 'session' => new Fake_PayPal_Session() );
	}

	class WC_Order {
		private $meta;
		public function __construct( array $meta = array() ) { $this->meta = $meta; }
		public function get_meta( $key, $single = true ) { unset( $single ); return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function save_meta_data() {}
		public function get_payment_method(): string { return 'ppcp-gateway'; }
		public function get_order_number(): string { return '1001'; }
	}

	final class Fake_PPCP_Settings {
		public function enable_pay_now() { return false; }
		public function save_paypal_and_venmo() { return false; }
		public function save_card_details() { return false; }
		public function three_d_secure_enum() { return 'no-3d-secure'; }
		public function merchant_country() { return 'VN'; }
	}

	final class Fake_PPCP_Container implements ContainerInterface {
		public $payments;
		public function __construct() { $this->payments = new PaymentsEndpoint(); }
		public function get( string $id ) {
			if ( 'settings.settings-provider' === $id ) { return new Fake_PPCP_Settings(); }
			if ( 'api.endpoint.order' === $id ) { return new OrderEndpoint(); }
			if ( 'api.endpoint.payments' === $id ) { return $this->payments; }
			if ( 'wcgateway.helper.refund-fees-updater' === $id ) { return new RefundFeesUpdater(); }
			if ( 'api.prefix' === $id ) { return 'TEST-'; }
			if ( 'woocommerce.logger.woocommerce' === $id ) { return new NullLogger(); }
			return null;
		}
		public function has( string $id ): bool { unset( $id ); return false; }
	}

	require __DIR__ . '/support/assertions.php';
	require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-paypal-conversion.php';

	$runtime    = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	$registered = $runtime->register_ppcp_module( array() );
	vst_assert_same( 1, count( $registered ), 'Compatible PPCP types register exactly one lazy adapter module' );
	$GLOBALS['vst_ppcp_version'] = '4.2.0';
	$incompatible_runtime = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	$incompatible_modules = $incompatible_runtime->register_ppcp_module( array( 'core-module' ) );
	vst_assert_same( array( 'core-module' ), $incompatible_modules, 'An unverified PPCP version does not append the conversion adapter module' );
	$incompatible_gateways = $incompatible_runtime->filter_available_gateways( array( 'ppcp-gateway' => (object) array(), 'bacs' => (object) array() ) );
	vst_assert_true( isset( $incompatible_gateways['bacs'] ) && ! isset( $incompatible_gateways['ppcp-gateway'] ), 'Incompatible conversion fails closed without affecting non-PayPal checkout boot' );
	$GLOBALS['vst_ppcp_version'] = '4.1.3';
	$module     = $registered[0];
	$extensions = $module->extensions();
	vst_assert_true( isset( $extensions['wcgateway.processor.refunds'] ), 'Registers the PPCP 4.1.3 refund service extension' );
	vst_assert_true( isset( $extensions['sdk-v6.manager'] ), 'Registers the PPCP 4.1.3 SDK v6 manager extension' );

	$container = new Fake_PPCP_Container();
	$manager   = $extensions['sdk-v6.manager']( null, $container );
	$data      = $manager->script_data();
	vst_assert_same( 'USD', $data['currency'], 'SDK v6 manager publishes USD without changing the store currency' );
	vst_assert_same( '4.00', $data['amount'], 'SDK v6 manager publishes the server-owned quote amount' );
	$GLOBALS['vst_is_checkout'] = false;
	$non_checkout_data = $manager->script_data();
	vst_assert_same( 'VND', $non_checkout_data['currency'], 'SDK v6 conversion data remains scoped to the normal checkout' );
	$GLOBALS['vst_is_checkout'] = true;

	$snapshot = array(
		'schema' => 1,
		'gateway' => 'ppcp-gateway',
		'rate' => '25000',
		'source' => 'manual',
		'locked_at' => '2026-09-19T00:00:00Z',
		'created_at' => '2026-09-19T00:01:00Z',
		'vnd_total' => '100000',
		'usd_total' => '4.00',
		'paypal_order_id' => 'PAYPAL-1',
		'payload' => array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => 'default',
					'amount'       => array( 'currency_code' => 'USD', 'value' => '4.00' ),
				),
			),
		),
	);
	$wc_order = new WC_Order( array( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META => $snapshot ) );
	$capture  = new Capture( 'CAPTURE-1', new Amount( new Money( 4.0, 'USD' ) ) );
	$order    = new Order( 'PAYPAL-1', new Payments( array( $capture ) ) );
	$refunds  = $extensions['wcgateway.processor.refunds']( null, $container );
	$refund_id = $refunds->refund( $order, $wc_order, 25000.0, 'Partial refund' );
	vst_assert_same( 'REFUND-1', $refund_id, 'Converted refund delegates to PPCP PaymentsEndpoint' );
	vst_assert_same( 'USD', $container->payments->last_refund->amount->currency_code(), 'Converted refund sends USD to PayPal' );
	vst_assert_same( 1.0, $container->payments->last_refund->amount->value(), 'Converted refund uses snapshot rate and cumulative cents' );

	$mismatched_capture = new Capture( 'CAPTURE-2', new Amount( new Money( 4.01, 'USD' ) ) );
	$mismatched_order   = new Order( 'PAYPAL-1', new Payments( array( $mismatched_capture ) ) );
	try {
		$refunds->refund( $mismatched_order, $wc_order, 25000.0 );
		vst_assert_true( false, 'Refund capture amount must match the locked snapshot' );
	} catch ( \WooCommerce\PayPalCommerce\ApiClient\Exception\RuntimeException $error ) {
		vst_assert_true( true, 'Refund fails closed when capture amount differs from the locked snapshot' );
	}

	$normal_order = new WC_Order();
	vst_assert_same( 'parent-refund', $refunds->refund( $order, $normal_order, 1.0 ), 'Normal PayPal orders retain upstream refund behavior' );

	vst_finish_contract_suite( 'PayPal PPCP adapter' );
}
