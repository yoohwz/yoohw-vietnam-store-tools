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

namespace WooCommerce\PayPalCommerce\Assets { class AssetGetter {} }
namespace WooCommerce\PayPalCommerce\Button\Helper { class Context {} }
namespace WooCommerce\PayPalCommerce\Session { class SessionHandler {} }
namespace WooCommerce\PayPalCommerce\Session\Cancellation { class CancelView {} }
namespace WooCommerce\PayPalCommerce\WcSubscriptions\Helper {
	class SubscriptionHelper {}
	class FreeTrialSubscriptionHelper {}
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
	class Environment {}
	class SettingsStatus {}
	class CardPaymentsConfiguration {}
}

namespace WooCommerce\PayPalCommerce\WcGateway\Processor {
	use WooCommerce\PayPalCommerce\ApiClient\Endpoint\OrderEndpoint;
	use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentsEndpoint;
	use WooCommerce\PayPalCommerce\ApiClient\Entity\Order;
	use WooCommerce\PayPalCommerce\Vendor\Psr\Log\LoggerInterface;
	use WooCommerce\PayPalCommerce\WcGateway\Helper\RefundFeesUpdater;

	class RefundProcessor {
		public function __construct( OrderEndpoint $orders, PaymentsEndpoint $payments, RefundFeesUpdater $fees, string $prefix, LoggerInterface $logger, $future_optional_dependency = null ) {
			unset( $orders, $payments, $fees, $prefix, $logger, $future_optional_dependency );
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
	use WooCommerce\PayPalCommerce\Assets\AssetGetter;
	use WooCommerce\PayPalCommerce\Button\Helper\Context;
	use WooCommerce\PayPalCommerce\SdkV6\Helper\ApplePayConfig;
	use WooCommerce\PayPalCommerce\SdkV6\Helper\ButtonStyleMapper;
	use WooCommerce\PayPalCommerce\SdkV6\Helper\CardFieldStyles;
	use WooCommerce\PayPalCommerce\SdkV6\Helper\FastlaneConfig;
	use WooCommerce\PayPalCommerce\SdkV6\Helper\GooglePayConfig;
	use WooCommerce\PayPalCommerce\SdkV6\Helper\MessagesEligibility;
	use WooCommerce\PayPalCommerce\SdkV6\Helper\MessageStyleMapper;
	use WooCommerce\PayPalCommerce\Session\Cancellation\CancelView;
	use WooCommerce\PayPalCommerce\Session\SessionHandler;
	use WooCommerce\PayPalCommerce\WcGateway\Helper\CardPaymentsConfiguration;
	use WooCommerce\PayPalCommerce\WcGateway\Helper\Environment;
	use WooCommerce\PayPalCommerce\WcGateway\Helper\SettingsStatus;
	use WooCommerce\PayPalCommerce\WcSubscriptions\Helper\FreeTrialSubscriptionHelper;
	use WooCommerce\PayPalCommerce\WcSubscriptions\Helper\SubscriptionHelper;

	trait FakeSdkV6Methods {
		public function script_data(): array { return array( 'currency' => 'VND', 'amount' => '100000' ); }
		public function should_load_on_current_page(): bool { return true; }
		public function determine_render_places(): array {
			return array(
				'product'   => true,
				'cart'      => true,
				'checkout'  => true,
				'pay-now'   => true,
				'mini-cart' => true,
			);
		}
		public function render_card_button_wrapper(): void { echo 'parent-card-wrapper'; }
	}

	if ( 'incompatible-constructor' === getenv( 'VST_PPCP_TEST_SCENARIO' ) ) {
		class SdkV6Manager {
			use FakeSdkV6Methods;
			public function __construct( string $asset_getter, AssetGetter $version, Environment $environment, ButtonStyleMapper $style_mapper, SettingsStatus $settings_status, Context $context, SessionHandler $session_handler, CancelView $cancel_view, bool $final_review_enabled, bool $vaulting_enabled, CardPaymentsConfiguration $card_payments_configuration, bool $card_vaulting_enabled, SubscriptionHelper $subscription_helper, FreeTrialSubscriptionHelper $free_trial_helper, callable $get_subscriptions_mode, string $three_d_secure_contingency, array $credit_card_icons, MessageStyleMapper $message_style_mapper, MessagesEligibility $messages_eligibility, string $merchant_country, GooglePayConfig $google_pay_config, ApplePayConfig $apple_pay_config, FastlaneConfig $fastlane_config, CardFieldStyles $card_field_styles, $future_optional_dependency = null ) {
				unset( $asset_getter, $version, $environment, $style_mapper, $settings_status, $context, $session_handler, $cancel_view, $final_review_enabled, $vaulting_enabled, $card_payments_configuration, $card_vaulting_enabled, $subscription_helper, $free_trial_helper, $get_subscriptions_mode, $three_d_secure_contingency, $credit_card_icons, $message_style_mapper, $messages_eligibility, $merchant_country, $google_pay_config, $apple_pay_config, $fastlane_config, $card_field_styles, $future_optional_dependency );
			}
		}
	} else {
		class SdkV6Manager {
			use FakeSdkV6Methods;
			public function __construct( AssetGetter $asset_getter, string $version, Environment $environment, ButtonStyleMapper $style_mapper, SettingsStatus $settings_status, Context $context, SessionHandler $session_handler, CancelView $cancel_view, bool $final_review_enabled, bool $vaulting_enabled, CardPaymentsConfiguration $card_payments_configuration, bool $card_vaulting_enabled, SubscriptionHelper $subscription_helper, FreeTrialSubscriptionHelper $free_trial_helper, callable $get_subscriptions_mode, string $three_d_secure_contingency, array $credit_card_icons, MessageStyleMapper $message_style_mapper, MessagesEligibility $messages_eligibility, string $merchant_country, GooglePayConfig $google_pay_config, ApplePayConfig $apple_pay_config, FastlaneConfig $fastlane_config, CardFieldStyles $card_field_styles, $future_optional_dependency = null ) {
				unset( $asset_getter, $version, $environment, $style_mapper, $settings_status, $context, $session_handler, $cancel_view, $final_review_enabled, $vaulting_enabled, $card_payments_configuration, $card_vaulting_enabled, $subscription_helper, $free_trial_helper, $get_subscriptions_mode, $three_d_secure_contingency, $credit_card_icons, $message_style_mapper, $messages_eligibility, $merchant_country, $google_pay_config, $apple_pay_config, $fastlane_config, $card_field_styles, $future_optional_dependency );
			}
		}
	}
}

namespace WooCommerce\PayPalCommerce\SdkV6\Helper {
	class ButtonStyleMapper {}
	class MessageStyleMapper {}
	class MessagesEligibility {}
	class GooglePayConfig {}
	class ApplePayConfig {}
	class FastlaneConfig {}
	class CardFieldStyles {}
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
	use WooCommerce\PayPalCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
	use WooCommerce\PayPalCommerce\Vendor\Psr\Log\NullLogger;
	use WooCommerce\PayPalCommerce\WcGateway\Helper\RefundFeesUpdater;
	use WooCommerce\PayPalCommerce\WcGateway\Processor\RefundProcessor;
	use WooCommerce\PayPalCommerce\SdkV6\Assets\SdkV6Manager;

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	function __( $value ) { return $value; }
	function get_woocommerce_currency() { return 'VND'; }
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
			if ( 'sdk-v6.asset-getter' === $id ) { return new \WooCommerce\PayPalCommerce\Assets\AssetGetter(); }
			if ( 'ppcp.asset-version' === $id ) { return '4.1.3'; }
			if ( 'settings.environment' === $id ) { return new \WooCommerce\PayPalCommerce\WcGateway\Helper\Environment(); }
			if ( 'sdk-v6.button-style-mapper' === $id ) { return new \WooCommerce\PayPalCommerce\SdkV6\Helper\ButtonStyleMapper(); }
			if ( 'wcgateway.settings.status' === $id ) { return new \WooCommerce\PayPalCommerce\WcGateway\Helper\SettingsStatus(); }
			if ( 'button.helper.context' === $id ) { return new \WooCommerce\PayPalCommerce\Button\Helper\Context(); }
			if ( 'session.handler' === $id ) { return new \WooCommerce\PayPalCommerce\Session\SessionHandler(); }
			if ( 'session.cancellation.view' === $id ) { return new \WooCommerce\PayPalCommerce\Session\Cancellation\CancelView(); }
			if ( 'wcgateway.configuration.card-configuration' === $id ) { return new \WooCommerce\PayPalCommerce\WcGateway\Helper\CardPaymentsConfiguration(); }
			if ( 'wc-subscriptions.helper' === $id ) { return new \WooCommerce\PayPalCommerce\WcSubscriptions\Helper\SubscriptionHelper(); }
			if ( 'wc-subscriptions.free-trial-subscription-helper' === $id ) { return new \WooCommerce\PayPalCommerce\WcSubscriptions\Helper\FreeTrialSubscriptionHelper(); }
			if ( 'button.subscriptions-mode' === $id ) { return static function () { return 'vaulting_api'; }; }
			if ( 'wcgateway.credit-card-icons' === $id ) { return array(); }
			if ( 'sdk-v6.message-style-mapper' === $id ) { return new \WooCommerce\PayPalCommerce\SdkV6\Helper\MessageStyleMapper(); }
			if ( 'sdk-v6.messages-eligibility' === $id ) { return new \WooCommerce\PayPalCommerce\SdkV6\Helper\MessagesEligibility(); }
			if ( 'sdk-v6.google-pay-config' === $id ) { return new \WooCommerce\PayPalCommerce\SdkV6\Helper\GooglePayConfig(); }
			if ( 'sdk-v6.apple-pay-config' === $id ) { return new \WooCommerce\PayPalCommerce\SdkV6\Helper\ApplePayConfig(); }
			if ( 'sdk-v6.fastlane-config' === $id ) { return new \WooCommerce\PayPalCommerce\SdkV6\Helper\FastlaneConfig(); }
			if ( 'sdk-v6.card-field-styles' === $id ) { return new \WooCommerce\PayPalCommerce\SdkV6\Helper\CardFieldStyles(); }
			return null;
		}
		public function has( string $id ): bool { unset( $id ); return false; }
	}

	final class Fake_PPCP_Service_Module implements ServiceModule {
		private $services;
		public function __construct( array $services ) { $this->services = $services; }
		public function services(): array { return $this->services; }
	}

	function vst_ppcp_service_definitions(): array {
		return array(
			'wcgateway.processor.refunds' => static function ( ContainerInterface $container ): RefundProcessor {
				return new RefundProcessor( $container->get( 'api.endpoint.order' ), $container->get( 'api.endpoint.payments' ), $container->get( 'wcgateway.helper.refund-fees-updater' ), $container->get( 'api.prefix' ), $container->get( 'woocommerce.logger.woocommerce' ) );
			},
			'sdk-v6.manager' => static function ( ContainerInterface $container ): SdkV6Manager {
				unset( $container );
				throw new \RuntimeException( 'Factory is inspected but not resolved by the compatibility gate.' );
			},
		);
	}

	require __DIR__ . '/support/assertions.php';
	require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-paypal-conversion.php';

	$service_definitions = vst_ppcp_service_definitions();
	if ( 'incompatible-constructor' === getenv( 'VST_PPCP_TEST_SCENARIO' ) ) {
		$runtime      = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
		$core_modules = array( new Fake_PPCP_Service_Module( $service_definitions ) );
		vst_assert_same( $core_modules, $runtime->register_ppcp_module( $core_modules ), 'Same constructor arity with incompatible type order leaves PPCP modules unchanged' );
		vst_assert_same( false, $runtime->force_place_order_button( false ), 'Incompatible constructor types fail closed without adapter readiness' );
		vst_finish_contract_suite( 'PayPal PPCP incompatible-constructor probe' );
		exit;
	}

	$missing_sdk_runtime = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	$missing_sdk_modules = array( new Fake_PPCP_Service_Module( array( 'wcgateway.processor.refunds' => $service_definitions['wcgateway.processor.refunds'] ) ) );
	vst_assert_same( $missing_sdk_modules, $missing_sdk_runtime->register_ppcp_module( $missing_sdk_modules ), 'Missing sdk-v6.manager leaves PPCP modules unchanged and boots without the adapter' );
	vst_assert_same( false, $missing_sdk_runtime->force_place_order_button( false ), 'Missing sdk-v6.manager keeps conversion unavailable' );

	$missing_refund_runtime = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	$missing_refund_modules = array( new Fake_PPCP_Service_Module( array( 'sdk-v6.manager' => $service_definitions['sdk-v6.manager'] ) ) );
	vst_assert_same( $missing_refund_modules, $missing_refund_runtime->register_ppcp_module( $missing_refund_modules ), 'Missing wcgateway.processor.refunds leaves PPCP modules unchanged and boots without the adapter' );
	vst_assert_same( false, $missing_refund_runtime->force_place_order_button( false ), 'Missing wcgateway.processor.refunds keeps conversion unavailable' );

	$runtime      = new Yoohw_Vietnam_Store_Tools_PayPal_Conversion();
	$core_modules = array( new Fake_PPCP_Service_Module( $service_definitions ) );
	$registered   = $runtime->register_ppcp_module( $core_modules );
	vst_assert_same( 2, count( $registered ), 'Verified PPCP 4.1.3 services and signatures register exactly one lazy adapter module' );
	$module     = $registered[1];
	$extensions = $module->extensions();
	vst_assert_true( isset( $extensions['wcgateway.processor.refunds'] ), 'Registers the verified PPCP refund service extension' );
	vst_assert_true( isset( $extensions['sdk-v6.manager'] ), 'Registers the verified PPCP SDK v6 manager extension' );

	$container = new Fake_PPCP_Container();
	$manager   = $extensions['sdk-v6.manager']( null, $container );
	$data      = $manager->script_data();
	vst_assert_same( 'USD', $data['currency'], 'SDK v6 manager publishes USD without changing the store currency' );
	vst_assert_same( '4.00', $data['amount'], 'SDK v6 manager publishes the server-owned quote amount' );
	vst_assert_same( false, $manager->should_load_on_current_page(), 'Active conversion disables the SDK v6 payment surface' );
	vst_assert_same(
		array(
			'product'   => false,
			'cart'      => false,
			'checkout'  => false,
			'pay-now'   => false,
			'mini-cart' => false,
		),
		$manager->determine_render_places(),
		'Active conversion disables every SDK v6 express render location'
	);
	ob_start();
	$manager->render_card_button_wrapper();
	$card_wrapper = ob_get_clean();
	vst_assert_same( '', $card_wrapper, 'Active conversion suppresses the SDK v6 card-button wrapper' );
	vst_assert_same( true, $runtime->force_place_order_button( false ), 'Classic checkout retains the regular PPCP Place-order method' );
	vst_assert_same( true, $runtime->force_blocks_place_order_method( false ), 'Blocks checkout retains the regular PPCP Place-order method' );

	$GLOBALS['vst_paypal_conversion_enabled'] = false;
	vst_assert_same( true, $manager->should_load_on_current_page(), 'Disabled conversion preserves the upstream SDK v6 page decision' );
	vst_assert_same( true, $manager->determine_render_places()['checkout'], 'Disabled conversion preserves upstream SDK v6 render locations' );
	ob_start();
	$manager->render_card_button_wrapper();
	$card_wrapper = ob_get_clean();
	vst_assert_same( 'parent-card-wrapper', $card_wrapper, 'Disabled conversion preserves the upstream SDK v6 card-button wrapper' );
	$GLOBALS['vst_paypal_conversion_enabled'] = true;
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

	$probe_command = 'VST_PPCP_TEST_SCENARIO=incompatible-constructor ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1';
	exec( $probe_command, $probe_output, $probe_status );
	vst_assert_same( 0, $probe_status, 'Incompatible same-arity constructor probe exits without a fatal error: ' . implode( "\n", $probe_output ) );

	vst_finish_contract_suite( 'PayPal PPCP adapter' );
}
