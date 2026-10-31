<?php
/**
 * PPCP service-extension contracts for PayPal VND/USD conversion.
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
	interface ServiceModule {
		public function services(): array;
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
		public $calls = 0;
		public $fail = false;
		public function refund( $refund ): string {
			++$this->calls;
			if ( $this->fail ) { throw new \WooCommerce\PayPalCommerce\ApiClient\Exception\RuntimeException( 'PayPal refund failed.' ); }
			$this->last_refund = $refund;
			return 'REFUND-' . $this->calls;
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
	use WooCommerce\PayPalCommerce\ApiClient\Entity\Payments;
	use WooCommerce\PayPalCommerce\Vendor\Psr\Log\LoggerInterface;
	use WooCommerce\PayPalCommerce\WcGateway\Helper\RefundFeesUpdater;

	trait FakeRefundProcessorMethods {
		public function __construct( OrderEndpoint $orders, PaymentsEndpoint $payments, RefundFeesUpdater $fees, string $prefix, LoggerInterface $logger, $future_optional_dependency = null ) {
			unset( $orders, $payments, $fees, $prefix, $logger, $future_optional_dependency );
		}
		public function refund( Order $order, \WC_Order $wc_order, float $amount, string $reason = '' ): string {
			unset( $order, $wc_order, $amount, $reason );
			return 'parent-refund';
		}
	}

	if ( 'missing-refund-class' === getenv( 'VST_PPCP_TEST_SCENARIO' ) ) {
		// Intentionally absent: the preflight must stop before loading the adapter.
	} elseif ( 'incompatible-refund-helper' === getenv( 'VST_PPCP_TEST_SCENARIO' ) ) {
		class RefundProcessor {
			use FakeRefundProcessorMethods;
			private function get_payments( Order $order ): Payments {
				return $order->test_payments();
			}
		}
	} elseif ( 'incompatible-refund-signature' === getenv( 'VST_PPCP_TEST_SCENARIO' ) ) {
		class RefundProcessor {
			use FakeRefundProcessorMethods;
			protected function get_payments( string $order ): Payments {
				throw new \RuntimeException( 'Incompatible helper must never be called.' );
			}
		}
	} else {
		class RefundProcessor {
			use FakeRefundProcessorMethods;
			protected function get_payments( Order $order ): Payments {
				return $order->test_payments();
			}
		}
	}
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
	use WooCommerce\PayPalCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
	use WooCommerce\PayPalCommerce\Vendor\Psr\Log\NullLogger;
	use WooCommerce\PayPalCommerce\WcGateway\Helper\RefundFeesUpdater;
	use WooCommerce\PayPalCommerce\WcGateway\Processor\RefundProcessor;

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	function __( $value ) { return $value; }
	$GLOBALS['vst_currency'] = 'VND';
	function get_woocommerce_currency() { return $GLOBALS['vst_currency']; }
	$GLOBALS['vst_is_checkout'] = true;
	$GLOBALS['vst_paypal_conversion_enabled'] = true;
	function is_checkout() { return $GLOBALS['vst_is_checkout']; }
	function is_order_received_page() { return false; }
	function is_checkout_pay_page() { return false; }
	function get_option( $key, $default = false ) {
		if ( 'yoohw_vietnam_store_tools_paypal_conversion_settings' === $key ) {
			return array(
				'yoohw_vietnam_store_tools_paypal_vnd_usd_enabled' => $GLOBALS['vst_paypal_conversion_enabled'] ? 'yes' : 'no',
				'yoohw_vietnam_store_tools_paypal_vnd_usd_rate' => '25000',
			);
		}
		if ( 'woocommerce-ppcp-data-settings' === $key ) {
			return array( 'authorize_only' => false );
		}
		return $default;
	}
	function current_user_can() { return true; }
	function esc_html( $value ) { return $value; }
	function esc_html__( $value ) { return $value; }
	$GLOBALS['vst_registered_filters'] = array();
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['vst_registered_filters'][ $hook ] = array( $callback, $priority, $accepted_args );
	}
	function add_action() {}

	class WC_Order {
		private $meta;
		public $saves = 0;
		public $payment_method = 'ppcp-gateway';
		public function __construct( array $meta = array() ) { $this->meta = $meta; }
		public function get_meta( $key, $single = true ) { unset( $single ); return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function save_meta_data() { ++$this->saves; }
		public function get_payment_method(): string { return $this->payment_method; }
		public function get_order_number(): string { return '1001'; }
	}

	final class Fake_PPCP_Container implements ContainerInterface {
		public $payments;
		private $sdk_v6;
		public function __construct( bool $sdk_v6 = false ) {
			$this->payments = new PaymentsEndpoint();
			$this->sdk_v6 = $sdk_v6;
		}
		public function get( string $id ) {
			if ( 'api.endpoint.order' === $id ) { return new OrderEndpoint(); }
			if ( 'api.endpoint.payments' === $id ) { return $this->payments; }
			if ( 'wcgateway.helper.refund-fees-updater' === $id ) { return new RefundFeesUpdater(); }
			if ( 'api.prefix' === $id ) { return 'TEST-'; }
			if ( 'woocommerce.logger.woocommerce' === $id ) { return new NullLogger(); }
			throw new \RuntimeException( 'Unexpected adapter dependency: ' . $id );
		}
		public function has( string $id ): bool { return 'sdk-v6.manager' === $id && $this->sdk_v6; }
	}

	final class Fake_PPCP_Service_Module implements ServiceModule {
		private $services;
		public function __construct( array $services ) { $this->services = $services; }
		public function services(): array { return $this->services; }
	}

	function vst_ppcp_service_definitions(): array {
		return array(
			'api.endpoint.order' => static function (): OrderEndpoint {
				throw new \RuntimeException( 'Compatibility-only service definition.' );
			},
			'api.endpoint.payments' => static function (): PaymentsEndpoint {
				throw new \RuntimeException( 'Compatibility-only service definition.' );
			},
			'api.prefix' => static function (): string { return 'TEST-'; },
			'wcgateway.processor.refunds' => static function (): RefundProcessor {
				throw new \RuntimeException( 'Compatibility-only service definition.' );
			},
			'wcgateway.helper.refund-fees-updater' => static function (): RefundFeesUpdater {
				throw new \RuntimeException( 'Compatibility-only service definition.' );
			},
			'woocommerce.logger.woocommerce' => static function (): \WooCommerce\PayPalCommerce\Vendor\Psr\Log\LoggerInterface {
				throw new \RuntimeException( 'Compatibility-only service definition.' );
			},
		);
	}

	require __DIR__ . '/support/assertions.php';
	require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-paypal-conversion.php';

	$gateway = (object) array( 'id' => 'ppcp-gateway' );
	$unevaluated = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	vst_assert_same( array( array( $unevaluated, 'select_legacy_checkout_stack' ), PHP_INT_MAX, 1 ), $GLOBALS['vst_registered_filters']['woocommerce.feature-flags.woocommerce_paypal_payments.sdk_v6_enabled'], 'Selects the public legacy-stack flag at the final filter priority' );
	vst_assert_same( array( array( $unevaluated, 'guard_automatic_refunds' ), 40, 3 ), $GLOBALS['vst_registered_filters']['woocommerce_payment_gateway_supports'], 'Automatic refunds use the gateway support guard' );
	ob_start(); $unevaluated->admin_notices(); $notice = ob_get_clean();
	vst_assert_true( false !== strpos( $notice, 'NOT_EVALUATED' ) && false === strpos( $notice, 'incompatible' ), 'Not-evaluated notice is truthful and does not imply incompatibility' );
	vst_assert_same( array(), $unevaluated->filter_available_gateways( array( 'ppcp-gateway' => $gateway ) ), 'Enabled unverified VND adapter suppresses the gateway' );
	vst_assert_same( false, $unevaluated->guard_automatic_refunds( true, 'refunds', $gateway ), 'Unevaluated refund adapter fails closed' );
	vst_assert_same( true, $unevaluated->guard_automatic_refunds( true, 'products', $gateway ), 'Refund guard preserves unrelated gateway features' );
	vst_assert_same( true, $unevaluated->guard_automatic_refunds( true, 'refunds', (object) array( 'id' => 'other-gateway' ) ), 'Refund guard preserves other gateways' );

	$service_definitions = vst_ppcp_service_definitions();
	$probe_scenario = getenv( 'VST_PPCP_TEST_SCENARIO' );
	if ( in_array( $probe_scenario, array( 'missing-refund-class', 'incompatible-refund-helper', 'incompatible-refund-signature' ), true ) ) {
		$runtime = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
		$runtime->select_legacy_checkout_stack( true );
		$core_modules = array( new Fake_PPCP_Service_Module( $service_definitions ) );
		vst_assert_same( $core_modules, $runtime->register_ppcp_module( $core_modules ), 'Incompatible refund contract leaves PPCP modules unchanged' );
		vst_assert_same( false, $runtime->force_place_order_button( false ), 'Incompatible refund contract blocks converted checkout' );
		vst_assert_same( false, $runtime->guard_automatic_refunds( true, 'refunds', $gateway ), 'Incompatible refund contract blocks automatic refunds' );
		$missing_class = 'missing-refund-class' === $probe_scenario;
		vst_assert_same( $missing_class ? 'INCOMPATIBLE_MISSING_CLASS' : 'INCOMPATIBLE_SIGNATURE', $runtime->get_adapter_status()['reason'], 'Refund preflight records its diagnostic reason' );
		vst_assert_same( 'WooCommerce\\PayPalCommerce\\WcGateway\\Processor\\RefundProcessor' . ( $missing_class ? '' : '::get_payments' ), $runtime->get_adapter_status()['probe'], 'Refund preflight names the exact failed dependency' );
		vst_assert_same( false, class_exists( 'Yoohw_Vietnam_Store_Tools_PayPal_PPCP_Module', false ), 'Failed preflight does not load the dependent adapter classes' );
		vst_finish_contract_suite( 'PayPal PPCP ' . $probe_scenario . ' probe' );
		exit;
	}

	vst_assert_same( false, class_exists( 'WooCommerce\\PayPalCommerce\\SdkV6\\Assets\\SdkV6Manager' ), 'Fixture intentionally omits SdkV6Manager to prove adapter independence' );
	vst_assert_same( false, class_exists( 'WooCommerce\\PayPalCommerce\\Settings\\Data\\SettingsProvider' ), 'Fixture intentionally omits SettingsProvider to prove adapter independence' );
	$runtime_reflection = new \ReflectionClass( 'Yoohw_Vietnam_Store_Tools_PayPal_Conversion' );
	$service_contract_method = $runtime_reflection->getMethod( 'required_ppcp_service_types' );
	if ( PHP_VERSION_ID < 80100 ) {
		$service_contract_method->setAccessible( true );
	}
	$service_contract = $service_contract_method->invoke( $runtime_reflection->newInstanceWithoutConstructor() );
	vst_assert_same( array_keys( $service_definitions ), array_keys( $service_contract ), 'Compatibility preflight requires exactly the six refund services' );
	$adapter_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-paypal-ppcp-adapter.php' );
	preg_match_all( "/\\\$container->get\\( '([^']+)' \\)/", $adapter_source, $adapter_service_matches );
	$direct_adapter_dependencies = array_values( array_unique( $adapter_service_matches[1] ) );
	vst_assert_same( array(), array_values( array_diff( $direct_adapter_dependencies, array_keys( $service_contract ) ) ), 'Every direct refund adapter dependency is covered by the compatibility preflight' );

	foreach ( array_keys( $service_definitions ) as $missing_service ) {
		$missing_services = $service_definitions;
		unset( $missing_services[ $missing_service ] );
		$runtime = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
		$runtime->select_legacy_checkout_stack( true );
		$core_modules = array( new Fake_PPCP_Service_Module( $missing_services ) );
		vst_assert_same( $core_modules, $runtime->register_ppcp_module( $core_modules ), 'Missing ' . $missing_service . ' leaves modules unchanged' );
		vst_assert_same( array( 'reason' => 'INCOMPATIBLE_MISSING_SERVICE', 'probe' => $missing_service, 'ready' => false ), $runtime->get_adapter_status(), 'Missing service records the exact dependency' );
		vst_assert_same( false, $runtime->guard_automatic_refunds( true, 'refunds', $gateway ), 'Missing refund dependency blocks automatic refunds' );
	}
	$bad_services = $service_definitions;
	$bad_services['api.endpoint.payments'] = static function () { return new PaymentsEndpoint(); };
	$runtime = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	$runtime->select_legacy_checkout_stack( true );
	$core_modules = array( new Fake_PPCP_Service_Module( $bad_services ) );
	vst_assert_same( $core_modules, $runtime->register_ppcp_module( $core_modules ), 'Untyped refund dependency factory fails closed' );
	vst_assert_same( array( 'reason' => 'INCOMPATIBLE_SERVICE_SIGNATURE', 'probe' => 'api.endpoint.payments', 'ready' => false ), $runtime->get_adapter_status(), 'Invalid service signature names its dependency' );

	$runtime = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	vst_assert_same( false, $runtime->select_legacy_checkout_stack( true ), 'Enabled VND conversion selects the public legacy checkout stack' );
	$core_modules = array( new Fake_PPCP_Service_Module( $service_definitions ) );
	$registered = $runtime->register_ppcp_module( $core_modules );
	vst_assert_same( 2, count( $registered ), 'Six compatible refund services register exactly one lazy adapter without SDK classes' );
	vst_assert_same( array( 'reason' => 'COMPATIBLE', 'probe' => '', 'ready' => true ), $runtime->get_adapter_status(), 'Legacy checkout and refund preflight reach compatible readiness' );
	$extensions = $registered[1]->extensions();
	vst_assert_same( array( 'wcgateway.processor.refunds' ), array_keys( $extensions ), 'Adapter extends only the refund processor' );
	vst_assert_same( true, $runtime->force_place_order_button( false ), 'Classic checkout retains the regular PPCP Place-order method' );
	vst_assert_same( true, $runtime->force_blocks_place_order_method( false ), 'Blocks checkout retains the regular PPCP Place-order method' );
	vst_assert_same( true, $runtime->guard_automatic_refunds( true, 'refunds', $gateway ), 'Verified refund adapter preserves automatic refunds' );
	vst_assert_same( false, $runtime->guard_automatic_refunds( false, 'refunds', $gateway ), 'Verified adapter preserves upstream unsupported refund decisions' );
	$runtime->verify_checkout_stack( new Fake_PPCP_Container() );
	vst_assert_same( true, $runtime->get_adapter_status()['ready'], 'Built container without SDK v6 preserves checkout readiness' );

	$without_flag = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	$without_flag_modules = $without_flag->register_ppcp_module( $core_modules );
	vst_assert_same( 2, count( $without_flag_modules ), 'Missing public flag still registers historical-order refund support' );
	vst_assert_same( array( 'reason' => 'INCOMPATIBLE_CHECKOUT_STACK', 'probe' => 'sdk-v6-enabled', 'ready' => false ), $without_flag->get_adapter_status(), 'Missing public flag selection blocks checkout' );
	vst_assert_same( false, $without_flag->force_place_order_button( false ), 'Missing public flag cannot force converted checkout' );
	vst_assert_same( true, $without_flag->guard_automatic_refunds( true, 'refunds', $gateway ), 'Missing checkout flag does not disable compatible historical refunds' );

	$third_party = new Fake_PPCP_Service_Module( array( 'sdk-v6.manager' => static function () { return new \stdClass(); } ) );
	$third_party_runtime = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	$third_party_runtime->select_legacy_checkout_stack( true );
	$third_party_modules = $third_party_runtime->register_ppcp_module( array( $core_modules[0], $third_party ) );
	vst_assert_same( 3, count( $third_party_modules ), 'Third-party SDK module still receives the refund extension' );
	vst_assert_same( array( 'reason' => 'INCOMPATIBLE_CHECKOUT_STACK', 'probe' => 'sdk-v6-enabled', 'ready' => false ), $third_party_runtime->get_adapter_status(), 'Third-party SDK service blocks converted checkout' );
	vst_assert_same( true, $third_party_runtime->guard_automatic_refunds( true, 'refunds', $gateway ), 'Third-party SDK service preserves verified refund readiness' );
	$runtime->verify_checkout_stack( new Fake_PPCP_Container( true ) );
	vst_assert_same( false, $runtime->get_adapter_status()['ready'], 'SDK service appended after module filtering blocks checkout at built-container verification' );
	vst_assert_same( true, $runtime->guard_automatic_refunds( true, 'refunds', $gateway ), 'Built-container checkout failure preserves verified refund readiness' );

	$GLOBALS['vst_paypal_conversion_enabled'] = false;
	$disabled = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	vst_assert_same( true, $disabled->select_legacy_checkout_stack( true ), 'Disabled conversion preserves the enabled SDK flag' );
	vst_assert_same( false, $disabled->select_legacy_checkout_stack( false ), 'Disabled conversion preserves the disabled SDK flag' );
	$disabled_modules = $disabled->register_ppcp_module( array( $core_modules[0], $third_party ) );
	vst_assert_same( true, $disabled->guard_automatic_refunds( true, 'refunds', $gateway ), 'Toggle OFF keeps historical converted refunds available' );
	$GLOBALS['vst_paypal_conversion_enabled'] = true;
	$GLOBALS['vst_currency'] = 'USD';
	$non_vnd = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	vst_assert_same( true, $non_vnd->select_legacy_checkout_stack( true ), 'Non-VND store preserves the enabled SDK flag' );
	vst_assert_same( false, $non_vnd->select_legacy_checkout_stack( false ), 'Non-VND store preserves the disabled SDK flag' );
	$non_vnd_modules = $non_vnd->register_ppcp_module( array( $core_modules[0], $third_party ) );
	vst_assert_same( true, $non_vnd->guard_automatic_refunds( true, 'refunds', $gateway ), 'Non-VND store keeps historical converted refunds available' );
	$GLOBALS['vst_currency'] = 'VND';

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
	$capture = new Capture( 'CAPTURE-1', new Amount( new Money( 4.0, 'USD' ) ) );
	$order = new Order( 'PAYPAL-1', new Payments( array( $capture ) ) );
	$container = new Fake_PPCP_Container();
	$refunds = $extensions['wcgateway.processor.refunds']( null, $container );
	vst_assert_same( 'REFUND-1', $refunds->refund( $order, $wc_order, 25000.0, 'Partial refund' ), 'Converted refund delegates to PPCP PaymentsEndpoint' );
	vst_assert_same( 'USD', $container->payments->last_refund->amount->currency_code(), 'Converted refund sends USD to PayPal' );
	vst_assert_same( 1.0, $container->payments->last_refund->amount->value(), 'Converted refund uses snapshot rate' );
	$history = $wc_order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META );
	vst_assert_same( '25000', $history[0]['vnd_amount'], 'Refund history records the VND amount' );
	vst_assert_same( 100, $history[0]['usd_cents'], 'Refund history records exact USD cents' );
	vst_assert_same( 'CAPTURE-1', $history[0]['capture_id'], 'Refund history records the original capture' );
	vst_assert_same( 'REFUND-1', $history[0]['refund_id'], 'Refund history records the completed PayPal refund' );

	function vst_assert_refund_rejected( $refunds, $order, $wc_order, $amount, $payments, $label ) {
		$calls = $payments->calls;
		$saves = $wc_order->saves;
		$history = $wc_order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META );
		try {
			$refunds->refund( $order, $wc_order, $amount );
			vst_assert_true( false, $label );
		} catch ( \WooCommerce\PayPalCommerce\ApiClient\Exception\RuntimeException $error ) {
			vst_assert_true( true, $label );
		}
		vst_assert_same( $calls, $payments->calls, $label . ' does not call PayPal' );
		vst_assert_same( $saves, $wc_order->saves, $label . ' does not save metadata' );
		vst_assert_same( $history, $wc_order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META ), $label . ' preserves refund history' );
	}

	foreach ( array(
		'capture amount mismatch' => array( new Capture( 'CAPTURE-1', new Amount( new Money( 4.01, 'USD' ) ) ) ),
		'capture currency mismatch' => array( new Capture( 'CAPTURE-1', new Amount( new Money( 4.0, 'EUR' ) ) ) ),
		'capture history mismatch' => array( new Capture( 'CAPTURE-2', new Amount( new Money( 4.0, 'USD' ) ) ) ),
		'missing capture' => array(),
		'multiple captures' => array( $capture, $capture ),
	) as $label => $captures ) {
		vst_assert_refund_rejected( $refunds, new Order( 'PAYPAL-1', new Payments( $captures ) ), $wc_order, 25000.0, $container->payments, $label );
	}
	vst_assert_refund_rejected( $refunds, new Order( 'PAYPAL-OTHER', new Payments( array( $capture ) ) ), $wc_order, 25000.0, $container->payments, 'PayPal order identity mismatch' );
	vst_assert_refund_rejected( $refunds, $order, $wc_order, 25000.5, $container->payments, 'Fractional VND refund' );
	vst_assert_refund_rejected( $refunds, $order, $wc_order, 100000.0, $container->payments, 'Cumulative refund exceeds original payment' );
	$wrong_gateway = new WC_Order( array( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META => $snapshot ) );
	$wrong_gateway->payment_method = 'other-gateway';
	vst_assert_refund_rejected( $refunds, $order, $wrong_gateway, 25000.0, $container->payments, 'Converted snapshot on another gateway' );
	$invalid_snapshot = $snapshot;
	$invalid_snapshot['rate'] = 'invalid';
	vst_assert_refund_rejected( $refunds, $order, new WC_Order( array( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META => $invalid_snapshot ) ), 25000.0, $container->payments, 'Invalid conversion snapshot' );

	$cumulative_order = new WC_Order( array( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META => $snapshot ) );
	foreach ( array( array( 33333.0, 1.33 ), array( 33333.0, 1.34 ), array( 33334.0, 1.33 ) ) as $partial ) {
		$refunds->refund( $order, $cumulative_order, $partial[0] );
		vst_assert_same( $partial[1], $container->payments->last_refund->amount->value(), 'Cumulative rounding refunds the remaining USD cents exactly' );
	}
	$cumulative_history = $cumulative_order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META );
	vst_assert_same( 400, array_sum( array_column( $cumulative_history, 'usd_cents' ) ), 'Full cumulative refund equals the original USD capture' );
	vst_assert_refund_rejected( $refunds, $order, $cumulative_order, 1.0, $container->payments, 'Refund after full repayment' );

	$failure_order = new WC_Order( array( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META => $snapshot, Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META => $history ) );
	$container->payments->fail = true;
	try {
		$refunds->refund( $order, $failure_order, 25000.0 );
		vst_assert_true( false, 'PayPal failure must propagate' );
	} catch ( \WooCommerce\PayPalCommerce\ApiClient\Exception\RuntimeException $error ) {
		vst_assert_same( 'PayPal refund failed.', $error->getMessage(), 'PayPal refund failure propagates' );
	}
	vst_assert_same( $history, $failure_order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META ), 'Failed PayPal refund preserves existing history' );
	vst_assert_same( 0, $failure_order->saves, 'Failed PayPal refund does not save a successful entry' );
	$container->payments->fail = false;

	foreach ( array(
		'toggle OFF' => array( false, 'VND', $disabled_modules[2] ),
		'non-VND store' => array( true, 'USD', $non_vnd_modules[2] ),
		'missing legacy flag' => array( true, 'VND', $without_flag_modules[1] ),
		'third-party SDK service' => array( true, 'VND', $third_party_modules[2] ),
	) as $label => $scenario ) {
		$GLOBALS['vst_paypal_conversion_enabled'] = $scenario[0];
		$GLOBALS['vst_currency'] = $scenario[1];
		$historical_order = new WC_Order( array( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META => $snapshot, Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META => $history ) );
		$historical_refunds = $scenario[2]->extensions()['wcgateway.processor.refunds']( null, $container );
		$historical_refunds->refund( $order, $historical_order, 75000.0 );
		vst_assert_same( 3.0, $container->payments->last_refund->amount->value(), $label . ' refunds historical orders from the locked snapshot' );
		$historical_history = $historical_order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META );
		vst_assert_same( $history[0], $historical_history[0], $label . ' preserves previous refund history' );
		vst_assert_same( 400, array_sum( array_column( $historical_history, 'usd_cents' ) ), $label . ' keeps cumulative refunds within the capture' );
	}
	$GLOBALS['vst_paypal_conversion_enabled'] = true;
	$GLOBALS['vst_currency'] = 'VND';
	$normal_order = new WC_Order();
	$calls = $container->payments->calls;
	vst_assert_same( 'parent-refund', $refunds->refund( $order, $normal_order, 1.0 ), 'Normal PayPal orders delegate to the parent refund behavior' );
	vst_assert_same( $calls, $container->payments->calls, 'Normal PayPal refunds bypass the converted refund endpoint' );
	vst_assert_same( 0, $normal_order->saves, 'Normal PayPal refunds do not gain conversion metadata' );

	foreach ( array( 'missing-refund-class', 'incompatible-refund-helper', 'incompatible-refund-signature' ) as $scenario ) {
		$probe_command = 'VST_PPCP_TEST_SCENARIO=' . escapeshellarg( $scenario ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1';
		$probe_output = array();
		$probe_status = 0;
		exec( $probe_command, $probe_output, $probe_status );
		vst_assert_same( 0, $probe_status, $scenario . ' probe exits without a fatal error: ' . implode( "\n", $probe_output ) );
	}
	vst_finish_contract_suite( 'PayPal PPCP adapter' );
}
