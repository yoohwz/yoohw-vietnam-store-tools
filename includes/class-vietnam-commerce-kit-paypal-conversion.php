<?php
/**
 * VND to USD boundary for WooCommerce PayPal Payments.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_PayPal_Conversion {

	const GATEWAY_ID             = 'ppcp-gateway';
	const SETTINGS_OPTION        = 'yoohw_vietnam_store_tools_paypal_conversion_settings';
	const PPCP_OPTION            = 'woocommerce-ppcp-data-settings';
	const PPCP_LEGACY_OPTION     = 'woocommerce-ppcp-settings';
	const SUPPORTED_PPCP_VERSION = '4.1.3';
	const SETTING_ENABLED        = 'yoohw_vietnam_store_tools_paypal_vnd_usd_enabled';
	const SETTING_RATE           = 'yoohw_vietnam_store_tools_paypal_vnd_usd_rate';
	const ATTEMPT_KEY            = 'yoohw_vietnam_store_tools_paypal_usd_attempt';
	const SNAPSHOT_META          = '_yoohw_vietnam_store_tools_paypal_usd_snapshot';
	const REFUNDS_META           = '_yoohw_vietnam_store_tools_paypal_usd_refunds';
	const CANCEL_NONCE_PARAM     = 'yoohw-paypal-usd-cancel';
	const CANCEL_TOKEN_PARAM     = 'yoohw-paypal-usd-attempt';
	const SCHEMA_VERSION         = 1;
	const ATTEMPT_LIFETIME       = HOUR_IN_SECONDS;

	private $adapter_incompatible        = false;
	private $adapter_ready               = false;
	private $store_api_checkout_request = false;
	private $create_guard_token          = '';

	public function __construct() {
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_available_gateways' ), 40 );
		add_filter( 'woocommerce_paypal_payments_use_place_order_button', array( $this, 'force_place_order_button' ), 40 );
		add_filter( 'woocommerce_paypal_payments_blocks_add_place_order_method', array( $this, 'force_blocks_place_order_method' ), 40 );
		add_filter( 'woocommerce_paypal_payments_buttons_disabled', array( $this, 'disable_express_buttons' ), 40, 2 );
		add_filter( 'woocommerce_paypal_payments_product_buttons_disabled', array( $this, 'disable_product_buttons' ), 40 );
		add_filter( 'ppcp_create_order_request_body_data', array( $this, 'convert_create_order_payload' ), 30, 3 );
		add_filter( 'ppcp_patch_order_request_body_data', array( $this, 'convert_idempotent_patch' ), 30 );
		add_action( 'woocommerce_paypal_payments_paypal_order_created', array( $this, 'mark_paypal_order_created' ), 20 );
		add_action( 'woocommerce_paypal_payments_woocommerce_order_created', array( $this, 'link_paypal_order' ), 20, 2 );
		add_action( 'woocommerce_paypal_payments_after_order_processor', array( $this, 'complete_payment_attempt' ), 20, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_flow' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'prepare_checkout_attempt' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ), 35 );
		add_action( 'wp_ajax_yoohw_paypal_usd_quote', array( $this, 'ajax_quote' ) );
		add_action( 'wp_ajax_nopriv_yoohw_paypal_usd_quote', array( $this, 'ajax_quote' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'woocommerce_paypal_payments_localized_script_data', array( $this, 'filter_v5_script_data' ), 30 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'mark_store_api_checkout_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'clear_store_api_checkout_request' ), PHP_INT_MAX, 3 );
		add_action( 'woocommerce_init', array( $this, 'handle_cancelled_attempt' ), 20 );
		add_action( 'shutdown', array( $this, 'recover_failed_create_attempt' ), 0 );

		// PPCP invokes this only after its own autoloader has become available.
		add_filter( 'woocommerce_paypal_payments_modules', array( $this, 'register_ppcp_module' ), 30 );
	}

	public static function is_enabled() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		return 'yes' === ( $settings[ self::SETTING_ENABLED ] ?? 'no' );
	}

	public static function get_rate() {
		$rate = self::get_configured_rate();
		return self::is_valid_rate( $rate ) ? $rate : '';
	}

	public static function get_configured_rate() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		return isset( $settings[ self::SETTING_RATE ] ) ? trim( (string) $settings[ self::SETTING_RATE ] ) : '';
	}

	public static function is_valid_rate( $rate ) {
		$rate = trim( (string) $rate );
		if ( ! preg_match( '/^[1-9][0-9]{0,5}(?:\.[0-9]{1,4})?$/', $rate ) ) {
			return false;
		}
		list( $numerator, $scale ) = self::decimal_fraction( $rate );
		return $numerator >= 100 * $scale && $numerator <= 999999 * $scale;
	}

	public static function get_runtime_attempt() {
		if ( ! self::is_active_configuration()
			|| ! function_exists( 'is_checkout' )
			|| ! is_checkout()
			|| ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
			|| ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() )
			|| ! function_exists( 'WC' )
			|| ! WC()->session ) {
			return null;
		}
		$attempt = WC()->session->get( self::ATTEMPT_KEY );
		return self::is_valid_attempt( $attempt ) && (int) $attempt['expires_at'] >= time() && ! empty( $attempt['preview_usd_total'] ) ? $attempt : null;
	}

	public static function sanitize_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$sanitized = array(
			self::SETTING_ENABLED => 'yes' === ( $settings[ self::SETTING_ENABLED ] ?? 'no' ) ? 'yes' : 'no',
			self::SETTING_RATE    => trim( (string) ( $settings[ self::SETTING_RATE ] ?? '' ) ),
		);
		if ( 'yes' === $sanitized[ self::SETTING_ENABLED ] && ! self::is_valid_rate( $sanitized[ self::SETTING_RATE ] ) ) {
			$sanitized[ self::SETTING_ENABLED ] = 'no';
		}
		return $sanitized;
	}

	public function filter_available_gateways( $gateways ) {
		if ( ! self::is_active_configuration() || ! $this->adapter_ready || ! isset( $gateways[ self::GATEWAY_ID ] ) ) {
			if ( 'VND' === get_woocommerce_currency() && self::is_enabled() && isset( $gateways[ self::GATEWAY_ID ] ) ) {
				unset( $gateways[ self::GATEWAY_ID ] );
			}
			return $gateways;
		}
		if ( ! $this->is_supported_checkout_context() || $this->cart_contains_subscription() ) {
			unset( $gateways[ self::GATEWAY_ID ] );
			return $gateways;
		}
		if ( is_wp_error( $this->get_or_refresh_attempt() ) ) {
			unset( $gateways[ self::GATEWAY_ID ] );
			return $gateways;
		}
		foreach ( array_keys( $gateways ) as $gateway_id ) {
			if ( self::GATEWAY_ID !== $gateway_id && 0 === strpos( (string) $gateway_id, 'ppcp-' ) ) {
				unset( $gateways[ $gateway_id ] );
			}
		}
		if ( isset( $gateways[ self::GATEWAY_ID ]->supports ) && is_array( $gateways[ self::GATEWAY_ID ]->supports ) ) {
			$gateways[ self::GATEWAY_ID ]->supports = array_values( array_diff( $gateways[ self::GATEWAY_ID ]->supports, array( 'tokenization', 'subscriptions' ) ) );
		}
		return $gateways;
	}

	public function mark_store_api_checkout_request( $response, $handler, $request ) {
		unset( $handler );
		$route = is_object( $request ) && is_callable( array( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
		$this->store_api_checkout_request = 1 === preg_match( '#^/wc/store/v[0-9]+/checkout/?$#', $route );
		return $response;
	}

	public function clear_store_api_checkout_request( $response, $handler, $request ) {
		unset( $handler, $request );
		$this->store_api_checkout_request = false;
		return $response;
	}

	public function force_place_order_button( $use_place_order ) {
		return self::is_active_configuration() && $this->adapter_ready ? true : $use_place_order;
	}

	public function force_blocks_place_order_method( $add_method ) {
		return self::is_active_configuration() && $this->adapter_ready ? true : $add_method;
	}

	public function disable_express_buttons( $disabled, $context = '' ) {
		unset( $context );
		return self::is_active_configuration() && $this->adapter_ready ? true : $disabled;
	}

	public function disable_product_buttons( $disabled ) {
		return self::is_active_configuration() && $this->adapter_ready ? true : $disabled;
	}

	public function convert_create_order_payload( $data, $payment_method, $request_data ) {
		if ( ! $this->should_convert_request( $data, $payment_method, $request_data ) ) {
			return $data;
		}
		$attempt = $this->get_or_refresh_attempt();
		if ( is_wp_error( $attempt ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( $attempt->get_error_message() );
		}
		if ( 'quote' !== $attempt['state'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal order creation cannot be repeated for the active VND to USD payment attempt. Please start a new checkout attempt.', 'yoohw-vietnam-store-tools' ) );
		}
		try {
			$converted = self::convert_purchase_units( $data, $attempt['rate'] );
			$converted = $this->add_attempt_cancel_url( $converted, $attempt );
		} catch ( Throwable $error ) {
			Yoohw_Vietnam_Store_Tools_Logger::log( 'warning', 'PayPal USD conversion refused an unsafe request.', array( 'reason' => $error->getMessage() ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal USD conversion could not safely prepare this order. Please use another payment method or contact the store.', 'yoohw-vietnam-store-tools' ) );
		}
		$usd_total = $this->get_payload_total( $converted );
		if ( $usd_total !== $attempt['preview_usd_total'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'The PayPal USD amount changed during checkout. Please refresh the checkout and try again.', 'yoohw-vietnam-store-tools' ) );
		}
		$attempt['state']      = 'creating';
		$attempt['payload']    = self::financial_payload( $converted );
		$attempt['usd_total']  = $usd_total;
		$attempt['created_at'] = gmdate( 'c' );
		$this->set_attempt( $attempt );
		$this->create_guard_token = (string) $attempt['token'];
		return $converted;
	}

	public function convert_idempotent_patch( $patches ) {
		$attempt = $this->get_attempt();
		if ( ! self::is_valid_attempt( $attempt ) ) {
			if ( self::is_active_configuration() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
				throw new RuntimeException( __( 'PayPal USD conversion could not verify the active payment attempt before updating the order.', 'yoohw-vietnam-store-tools' ) );
			}
			return $patches;
		}
		if ( 'payment_created' !== $attempt['state'] || (int) $attempt['expires_at'] < time() || empty( $attempt['payload']['purchase_units'][0] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal order updates cannot be safely associated with this VND to USD payment attempt. Please start a new checkout attempt.', 'yoohw-vietnam-store-tools' ) );
		}
		if ( ! is_array( $patches ) || 1 !== count( $patches ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal returned an unsupported order update for this converted payment.', 'yoohw-vietnam-store-tools' ) );
		}

		$patch = reset( $patches );
		$path = (string) ( $patch['path'] ?? '' );
		if ( ! is_array( $patch )
			|| 'replace' !== ( $patch['op'] ?? '' )
			|| ! preg_match( "#^/purchase_units/@reference_id=='([^']+)'$#", $path, $path_matches )
			|| ! isset( $patch['value'] )
			|| ! is_array( $patch['value'] )
			|| (string) ( $patch['value']['reference_id'] ?? '' ) !== (string) $path_matches[1]
			|| (string) ( $attempt['payload']['purchase_units'][0]['reference_id'] ?? '' ) !== (string) $path_matches[1] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal returned an unsupported order update for this converted payment.', 'yoohw-vietnam-store-tools' ) );
		}

		try {
			$converted                    = self::convert_purchase_units( array( 'purchase_units' => array( $patch['value'] ) ), $attempt['rate'] );
			$converted_unit               = $converted['purchase_units'][0];
			$projection                   = self::financial_payload( $converted );
			$frozen_financial_projection = $attempt['payload']['purchase_units'][0];
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal returned an unsafe monetary order update. Please start a new checkout attempt.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( ! isset( $projection['purchase_units'][0] ) || $projection['purchase_units'][0] !== $frozen_financial_projection ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'The PayPal order amount changed after USD conversion was locked. Please start a new checkout attempt.', 'yoohw-vietnam-store-tools' ) );
		}

		$patch['value'] = $converted_unit;
		return array( $patch );
	}

	public function mark_paypal_order_created( $paypal_order ) {
		$attempt = $this->get_attempt();
		if ( ! self::is_valid_attempt( $attempt ) || 'creating' !== $attempt['state'] || ! is_object( $paypal_order ) || ! is_callable( array( $paypal_order, 'id' ) ) ) {
			return;
		}

		$paypal_order_id = (string) $paypal_order->id();
		if ( '' === $paypal_order_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal returned an order that does not match the locked USD payment.', 'yoohw-vietnam-store-tools' ) );
		}
		if ( ! $this->paypal_response_matches_attempt( $paypal_order, $attempt ) ) {
			$attempt['state']           = 'payment_rejected';
			$attempt['paypal_order_id'] = $paypal_order_id;
			$attempt['linked_at']       = gmdate( 'c' );
			$this->set_attempt( $attempt );
			$this->create_guard_token = '';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal returned an order that does not match the locked USD payment.', 'yoohw-vietnam-store-tools' ) );
		}

		$attempt['state']           = 'payment_created';
		$attempt['paypal_order_id'] = $paypal_order_id;
		$attempt['linked_at']       = gmdate( 'c' );
		$this->set_attempt( $attempt );
		$this->create_guard_token = '';
	}

	public function recover_failed_create_attempt() {
		if ( '' === $this->create_guard_token ) {
			return;
		}
		$attempt = $this->get_attempt();
		if ( self::is_valid_attempt( $attempt )
			&& 'creating' === $attempt['state']
			&& hash_equals( $this->create_guard_token, (string) $attempt['token'] )
			&& empty( $attempt['paypal_order_id'] ) ) {
			$attempt['state'] = 'quote';
			unset( $attempt['payload'], $attempt['usd_total'], $attempt['created_at'] );
			$this->set_attempt( $attempt );
		}
		$this->create_guard_token = '';
	}

	public function handle_cancelled_attempt() {
		$attempt = $this->get_attempt();
		if ( ! self::is_valid_attempt( $attempt )
			|| ! in_array( $attempt['state'], array( 'payment_created', 'payment_rejected' ), true )
			|| empty( $attempt['paypal_order_id'] ) ) {
			return;
		}

		if ( isset( $_GET[ self::CANCEL_NONCE_PARAM ], $_GET[ self::CANCEL_TOKEN_PARAM ] )
			&& is_scalar( $_GET[ self::CANCEL_NONCE_PARAM ] )
			&& is_scalar( $_GET[ self::CANCEL_TOKEN_PARAM ] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET[ self::CANCEL_NONCE_PARAM ] ) );
			$token = sanitize_text_field( wp_unslash( $_GET[ self::CANCEL_TOKEN_PARAM ] ) );
			if ( hash_equals( (string) $attempt['token'], $token ) && wp_verify_nonce( $nonce, $this->cancel_nonce_action( $token ) ) ) {
				$this->set_attempt( null );
			}
			return;
		}

		if ( isset( $_GET['ppcp-cancel'] ) && is_scalar( $_GET['ppcp-cancel'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['ppcp-cancel'] ) );
			if ( wp_verify_nonce( $nonce, 'ppcp-cancel' ) ) {
				$this->set_attempt( null );
			}
		}
	}

	private function add_attempt_cancel_url( $data, $attempt ) {
		$cancel_url = $data['payment_source']['paypal']['experience_context']['cancel_url'] ?? '';
		if ( ! is_string( $cancel_url ) || '' === $cancel_url ) {
			throw new InvalidArgumentException( 'Missing PayPal standard checkout cancel URL.' );
		}
		$token = (string) $attempt['token'];
		$data['payment_source']['paypal']['experience_context']['cancel_url'] = add_query_arg(
			array(
				self::CANCEL_NONCE_PARAM => wp_create_nonce( $this->cancel_nonce_action( $token ) ),
				self::CANCEL_TOKEN_PARAM => $token,
			),
			$cancel_url
		);
		return $data;
	}

	private function cancel_nonce_action( $token ) {
		return 'yoohw_paypal_usd_cancel_' . (string) $token;
	}

	public static function should_suppress_ppcp_express() {
		return self::is_active_configuration();
	}

	public function link_paypal_order( $order, $paypal_order ) {
		if ( ! $order instanceof WC_Order || ! is_object( $paypal_order ) || ! is_callable( array( $paypal_order, 'id' ) ) ) {
			return;
		}
		$attempt = $this->get_attempt();
		if ( ! self::is_valid_attempt( $attempt ) || 'payment_created' !== $attempt['state'] || self::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		if ( (string) $paypal_order->id() !== (string) ( $attempt['paypal_order_id'] ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'The WooCommerce order could not be linked to the locked PayPal USD payment.', 'yoohw-vietnam-store-tools' ) );
		}
		$this->freeze_snapshot( $order, $attempt, true );
	}

	public function complete_payment_attempt( $order, $paypal_order ) {
		if ( ! $order instanceof WC_Order || self::GATEWAY_ID !== $order->get_payment_method() || ! is_object( $paypal_order ) || ! is_callable( array( $paypal_order, 'id' ) ) ) {
			return;
		}
		$attempt  = $this->get_attempt();
		$snapshot = $order->get_meta( self::SNAPSHOT_META, true );
		if ( ! self::is_valid_attempt( $attempt )
			|| 'payment_created' !== $attempt['state']
			|| ! self::validate_snapshot( $snapshot )
			|| (string) $paypal_order->id() !== (string) ( $attempt['paypal_order_id'] ?? '' )
			|| (string) $paypal_order->id() !== (string) $snapshot['paypal_order_id'] ) {
			return;
		}
		$this->set_attempt( null );
	}

	public function validate_checkout_flow( $data, $errors ) {
		if ( ! self::is_active_configuration() || ! is_array( $data ) || self::GATEWAY_ID !== ( $data['payment_method'] ?? '' ) || ! is_object( $errors ) || ! is_callable( array( $errors, 'add' ) ) ) {
			return;
		}

		$token = (string) ( $data['wc-ppcp-gateway-payment-token'] ?? '' );
		if ( '' !== $token && 'new' !== $token ) {
			$errors->add( 'paypal_usd_vault_unsupported', __( 'Saved PayPal payment methods are not supported by VND to USD conversion.', 'yoohw-vietnam-store-tools' ) );
		}
		if ( ! empty( $data['paypal_order_id'] ) ) {
			$errors->add( 'paypal_usd_continuation_unsupported', __( 'Continuing an existing PayPal order is not supported by VND to USD conversion.', 'yoohw-vietnam-store-tools' ) );
		}
	}

	public function prepare_checkout_attempt() {
		if ( $this->adapter_ready && $this->is_supported_checkout_context() && self::is_active_configuration() ) {
			$this->get_or_refresh_attempt();
		}
	}

	public function enqueue_checkout_assets() {
		if ( ! $this->adapter_ready || ! $this->is_supported_checkout_context() || ! self::is_active_configuration() ) {
			return;
		}
		$attempt = $this->get_or_refresh_attempt();
		if ( is_wp_error( $attempt ) ) {
			return;
		}
		$path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/js/frontend/paypal-vnd-usd.js';
		if ( ! file_exists( $path ) ) {
			return;
		}
		wp_enqueue_script( 'yoohw-vietnam-store-tools-paypal-vnd-usd', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/frontend/paypal-vnd-usd.js', array( 'jquery', 'wp-data' ), filemtime( $path ), true );
		wp_localize_script(
			'yoohw-vietnam-store-tools-paypal-vnd-usd',
			'yoohwVietnamStoreToolsPayPalUsd',
			array(
				'gatewayId' => self::GATEWAY_ID,
				'rate'      => $attempt['rate'],
				'amount'    => $attempt['preview_usd_total'],
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'yoohw_paypal_usd_quote' ),
				// translators: 1: converted USD amount, 2: manual VND per USD exchange rate.
				'i18n'      => array( 'label' => __( 'PayPal will charge %1$s USD (1 USD = %2$s VND).', 'yoohw-vietnam-store-tools' ) ),
			)
		);
	}

	public function ajax_quote() {
		check_ajax_referer( 'yoohw_paypal_usd_quote', 'nonce' );
		if ( ! $this->adapter_ready || ! self::is_active_configuration() ) {
			wp_send_json_error( array( 'message' => __( 'PayPal USD conversion is not available for this checkout.', 'yoohw-vietnam-store-tools' ) ), 400 );
		}
		$attempt = $this->get_or_refresh_attempt();
		if ( is_wp_error( $attempt ) ) {
			wp_send_json_error( array( 'message' => $attempt->get_error_message() ), 409 );
		}
		wp_send_json_success( array( 'rate' => $attempt['rate'], 'amount' => $attempt['preview_usd_total'] ) );
	}

	public function filter_v5_script_data( $data ) {
		if ( ! $this->adapter_ready || ! is_array( $data ) || ! $this->is_supported_checkout_context() || ! self::is_active_configuration() ) {
			return $data;
		}
		$attempt = $this->get_or_refresh_attempt();
		if ( is_wp_error( $attempt ) ) {
			return $data;
		}
		$data['currency'] = 'USD';
		$data['amount']   = $attempt['preview_usd_total'];
		if ( isset( $data['url_params'] ) && is_array( $data['url_params'] ) ) {
			$data['url_params']['currency'] = 'USD';
		}
		if ( isset( $data['url'] ) ) {
			$data['url'] = add_query_arg( 'currency', 'USD', $data['url'] );
		}
		return $data;
	}

	public function register_ppcp_module( $modules ) {
		if ( self::SUPPORTED_PPCP_VERSION !== $this->get_ppcp_version() ) {
			$this->adapter_incompatible = true;
			return $modules;
		}
		$required = array(
			'WooCommerce\\PayPalCommerce\\Vendor\\Inpsyde\\Modularity\\Module\\ExtendingModule',
			'WooCommerce\\PayPalCommerce\\Vendor\\Psr\\Container\\ContainerInterface',
			'WooCommerce\\PayPalCommerce\\WcGateway\\Processor\\RefundProcessor',
			'WooCommerce\\PayPalCommerce\\WcGateway\\Helper\\RefundFeesUpdater',
			'WooCommerce\\PayPalCommerce\\ApiClient\\Endpoint\\OrderEndpoint',
			'WooCommerce\\PayPalCommerce\\ApiClient\\Endpoint\\PaymentsEndpoint',
			'WooCommerce\\PayPalCommerce\\ApiClient\\Entity\\Amount',
			'WooCommerce\\PayPalCommerce\\ApiClient\\Entity\\Money',
			'WooCommerce\\PayPalCommerce\\ApiClient\\Entity\\Order',
			'WooCommerce\\PayPalCommerce\\ApiClient\\Entity\\RefundCapture',
			'WooCommerce\\PayPalCommerce\\ApiClient\\Exception\\RuntimeException',
			'WooCommerce\\PayPalCommerce\\SdkV6\\Assets\\SdkV6Manager',
			'WooCommerce\\PayPalCommerce\\SdkV6\\Blocks\\V6PaymentMethod',
			'WooCommerce\\PayPalCommerce\\Vendor\\Psr\\Log\\LoggerInterface',
		);
		foreach ( $required as $class ) {
			if ( ! class_exists( $class ) && ! interface_exists( $class ) ) {
				$this->adapter_incompatible = true;
				return $modules;
			}
		}
		if ( ! $this->has_supported_ppcp_signatures() ) {
			$this->adapter_incompatible = true;
			return $modules;
		}
		$adapter = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'includes/class-vietnam-commerce-kit-paypal-ppcp-adapter.php';
		if ( ! is_readable( $adapter ) ) {
			$this->adapter_incompatible = true;
			return $modules;
		}
		require_once $adapter;
		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_PayPal_PPCP_Module' ) ) {
			$modules[] = new Yoohw_Vietnam_Store_Tools_PayPal_PPCP_Module();
			$this->adapter_incompatible = false;
			$this->adapter_ready        = true;
		}
		return $modules;
	}

	private function get_ppcp_version() {
		if ( class_exists( 'WooCommerce\\PayPalCommerce\\PPCP' ) && function_exists( 'get_file_data' ) ) {
			try {
				$reflection  = new ReflectionClass( 'WooCommerce\\PayPalCommerce\\PPCP' );
				$plugin_file = dirname( $reflection->getFileName(), 2 ) . '/woocommerce-paypal-payments.php';
				if ( is_readable( $plugin_file ) ) {
					$headers = get_file_data( $plugin_file, array( 'version' => 'Version' ) );
					if ( is_string( $headers['version'] ?? null ) && '' !== $headers['version'] ) {
						return $headers['version'];
					}
				}
			} catch ( Throwable $error ) {
				// Fall through to PPCP's persisted installed-version option.
			}
		}
		$version = get_option( 'woocommerce-ppcp-version', '' );
		return is_string( $version ) ? $version : '';
	}

	private function has_supported_ppcp_signatures() {
		try {
			$sdk_class                   = new ReflectionClass( 'WooCommerce\\PayPalCommerce\\SdkV6\\Assets\\SdkV6Manager' );
			$refund_class                = new ReflectionClass( 'WooCommerce\\PayPalCommerce\\WcGateway\\Processor\\RefundProcessor' );
			$sdk_method                  = $sdk_class->getMethod( 'script_data' );
			$sdk_page_method             = $sdk_class->getMethod( 'should_load_on_current_page' );
			$sdk_render_places_method    = $sdk_class->getMethod( 'determine_render_places' );
			$sdk_card_wrapper_method     = $sdk_class->getMethod( 'render_card_button_wrapper' );
			$refund_method               = $refund_class->getMethod( 'refund' );
			$sdk_constructor             = $sdk_class->getConstructor();
			$refund_constructor          = $refund_class->getConstructor();

			return ! $sdk_class->isFinal()
				&& ! $refund_class->isFinal()
				&& $sdk_method->isPublic()
				&& ! $sdk_method->isFinal()
				&& 0 === $sdk_method->getNumberOfParameters()
				&& $sdk_page_method->isPublic()
				&& ! $sdk_page_method->isFinal()
				&& 0 === $sdk_page_method->getNumberOfParameters()
				&& $sdk_render_places_method->isPublic()
				&& ! $sdk_render_places_method->isFinal()
				&& 0 === $sdk_render_places_method->getNumberOfParameters()
				&& $sdk_card_wrapper_method->isPublic()
				&& ! $sdk_card_wrapper_method->isFinal()
				&& 0 === $sdk_card_wrapper_method->getNumberOfParameters()
				&& $refund_method->isPublic()
				&& ! $refund_method->isFinal()
				&& 4 === $refund_method->getNumberOfParameters()
				&& $sdk_constructor
				&& 24 === $sdk_constructor->getNumberOfParameters()
				&& $refund_constructor
				&& 5 === $refund_constructor->getNumberOfParameters();
		} catch ( Throwable $error ) {
			return false;
		}
	}

	public function admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( self::is_enabled() && '' === self::get_rate() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'PayPal USD conversion is inactive because its manual VND per USD rate is invalid.', 'yoohw-vietnam-store-tools' ) . '</p></div>';
		}
		if ( self::is_enabled() && ( $this->adapter_incompatible || ! $this->adapter_ready ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'PayPal USD conversion is inactive because the installed WooCommerce PayPal Payments version is incompatible.', 'yoohw-vietnam-store-tools' ) . '</p></div>';
		}
		if ( self::is_enabled() && ! self::is_capture_mode() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'PayPal USD conversion requires CAPTURE intent. PayPal is unavailable until WooCommerce PayPal Payments uses CAPTURE.', 'yoohw-vietnam-store-tools' ) . '</p></div>';
		}
	}

	private function should_convert_request( $data, $payment_method, $request_data ) {
		if ( self::GATEWAY_ID !== $payment_method || ! self::is_active_configuration() ) {
			return false;
		}
		if ( ! $this->adapter_ready ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal USD conversion is unavailable because the installed integration is incompatible.', 'yoohw-vietnam-store-tools' ) );
		}
		$context        = is_array( $request_data ) ? (string) ( $request_data['context'] ?? '' ) : '';
		$funding_source = is_array( $request_data ) ? (string) ( $request_data['funding_source'] ?? '' ) : '';
		$payment_token  = is_array( $request_data ) ? (string) ( $request_data['wc-ppcp-gateway-payment-token'] ?? '' ) : '';
		if ( '' !== $context || 'paypal' !== $funding_source || ( '' !== $payment_token && 'new' !== $payment_token ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'This PayPal payment flow is not supported by VND to USD conversion.', 'yoohw-vietnam-store-tools' ) );
		}
		if ( ! is_array( $data ) || 'CAPTURE' !== strtoupper( (string) ( $data['intent'] ?? '' ) ) || self::contains_key_recursive( $data, 'vault' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'PayPal USD conversion supports one-time CAPTURE payments only.', 'yoohw-vietnam-store-tools' ) );
		}
		return true;
	}

	private function is_supported_checkout_context() {
		$is_checkout_page = function_exists( 'is_checkout' ) && is_checkout();
		if ( ( ! $is_checkout_page && ! $this->store_api_checkout_request ) || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) || ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) ) {
			return false;
		}
		return ! $this->cart_contains_subscription();
	}

	private function cart_contains_subscription() {
		return function_exists( 'wcs_cart_contains_subscription' ) && wcs_cart_contains_subscription();
	}

	private static function is_active_configuration() {
		return 'VND' === get_woocommerce_currency() && self::is_enabled() && '' !== self::get_rate() && self::is_capture_mode();
	}

	private static function is_capture_mode() {
		$settings = get_option( self::PPCP_OPTION, false );
		if ( ! is_array( $settings ) ) {
			$settings = get_option( self::PPCP_LEGACY_OPTION, array() );
		}
		$settings       = is_array( $settings ) ? $settings : array();
		$authorize_only = $settings['authorize_only'] ?? false;
		return ! in_array( $authorize_only, array( true, 1, '1', 'yes', 'on' ), true );
	}

	private function get_or_refresh_attempt() {
		$rate = self::get_rate();
		if ( '' === $rate || ! function_exists( 'WC' ) || ! WC()->session ) {
			return new WP_Error( 'paypal_usd_unavailable', __( 'PayPal USD conversion is unavailable because its exchange rate or checkout session is invalid.', 'yoohw-vietnam-store-tools' ) );
		}
		$source = $this->get_source_state();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$attempt = $this->get_attempt();
		if ( self::is_valid_attempt( $attempt ) && (int) $attempt['expires_at'] < time() ) {
			$attempt = null;
		}
		if ( self::is_valid_attempt( $attempt ) && in_array( $attempt['state'], array( 'creating', 'payment_created', 'payment_rejected' ), true ) ) {
			if ( ! hash_equals( (string) $attempt['fingerprint'], $source['fingerprint'] ) ) {
				return new WP_Error( 'paypal_usd_attempt_locked', __( 'The cart changed after PayPal conversion was locked. Please start a new checkout attempt.', 'yoohw-vietnam-store-tools' ) );
			}
			return $attempt;
		}
		if ( ! self::is_valid_attempt( $attempt ) || (int) $attempt['expires_at'] < time() ) {
			$attempt = array(
				'schema'     => self::SCHEMA_VERSION,
				'token'      => wp_generate_uuid4(),
				'rate'       => $rate,
				'source'     => 'manual',
				'locked_at'  => gmdate( 'c' ),
				'state'      => 'quote',
				'expires_at' => time() + self::ATTEMPT_LIFETIME,
			);
		}
		try {
			$preview_usd_total = self::convert_decimal_to_usd( $source['vnd_total'], $attempt['rate'] );
		} catch ( Throwable $error ) {
			return new WP_Error( 'paypal_usd_source_invalid', __( 'PayPal USD conversion could not verify the current VND total.', 'yoohw-vietnam-store-tools' ) );
		}
		$attempt['cart_hash']         = $source['cart_hash'];
		$attempt['fingerprint']       = $source['fingerprint'];
		$attempt['vnd_total']         = $source['vnd_total'];
		$attempt['preview_usd_total'] = $preview_usd_total;
		$this->set_attempt( $attempt );
		return $attempt;
	}

	private function get_source_state() {
		$total     = '';
		$cart_hash = '';
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$cart_hash = (string) WC()->cart->get_cart_hash();
			if ( '' === $total ) {
				$total = wc_format_decimal( WC()->cart->get_total( 'raw' ), 0 );
			}
		}
		if ( '' === $total || ! self::is_nonnegative_decimal( $total ) ) {
			return new WP_Error( 'paypal_usd_source_invalid', __( 'PayPal USD conversion could not verify the current VND total.', 'yoohw-vietnam-store-tools' ) );
		}
		return array(
			'vnd_total'   => (string) $total,
			'cart_hash'   => $cart_hash,
			'fingerprint' => hash( 'sha256', $cart_hash . '|' . (string) $total ),
		);
	}

	private function get_attempt() {
		return ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::ATTEMPT_KEY ) : null;
	}

	private function set_attempt( $attempt ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::ATTEMPT_KEY, $attempt );
		}
	}

	private static function is_valid_attempt( $attempt ) {
		return is_array( $attempt )
			&& self::SCHEMA_VERSION === (int) ( $attempt['schema'] ?? 0 )
			&& ! empty( $attempt['token'] )
			&& self::is_valid_rate( $attempt['rate'] ?? '' )
			&& ! empty( $attempt['fingerprint'] )
			&& isset( $attempt['expires_at'], $attempt['state'] );
	}

	private function freeze_snapshot( $order, $attempt, $save ) {
		$snapshot = array(
			'schema'          => self::SCHEMA_VERSION,
			'gateway'         => self::GATEWAY_ID,
			'rate'            => $attempt['rate'],
			'source'          => 'manual',
			'locked_at'       => $attempt['locked_at'],
			'created_at'      => $attempt['created_at'] ?? gmdate( 'c' ),
			'vnd_total'       => $attempt['vnd_total'],
			'usd_total'       => $attempt['usd_total'],
			'payload'         => $attempt['payload'],
			'paypal_order_id' => $attempt['paypal_order_id'] ?? '',
		);
		if ( ! self::validate_snapshot( $snapshot ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( __( 'The WooCommerce order could not be linked to the locked PayPal USD payment.', 'yoohw-vietnam-store-tools' ) );
		}
		$existing = $order->get_meta( self::SNAPSHOT_META, true );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			if ( $existing !== $snapshot ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
				throw new RuntimeException( __( 'The WooCommerce order could not be linked to the locked PayPal USD payment.', 'yoohw-vietnam-store-tools' ) );
			}
			return;
		}
		$order->update_meta_data( self::SNAPSHOT_META, $snapshot );
		if ( $save ) {
			$order->save_meta_data();
		}
	}

	private function get_payload_total( $payload ) {
		return isset( $payload['purchase_units'][0]['amount']['value'] ) ? (string) $payload['purchase_units'][0]['amount']['value'] : '';
	}

	private function paypal_response_matches_attempt( $paypal_order, $attempt ) {
		if ( ! is_callable( array( $paypal_order, 'purchase_units' ) ) ) {
			return false;
		}
		$units = $paypal_order->purchase_units();
		if ( ! is_array( $units ) || 1 !== count( $units ) || ! is_object( $units[0] ) || ! is_callable( array( $units[0], 'amount' ) ) ) {
			return false;
		}
		$amount             = $units[0]->amount();
		$frozen_reference   = (string) ( $attempt['payload']['purchase_units'][0]['reference_id'] ?? '' );
		$response_reference = is_callable( array( $units[0], 'reference_id' ) ) ? (string) $units[0]->reference_id() : '';
		return is_object( $amount )
			&& is_callable( array( $amount, 'currency_code' ) )
			&& is_callable( array( $amount, 'value_str' ) )
			&& '' !== $frozen_reference
			&& $frozen_reference === $response_reference
			&& 'USD' === $amount->currency_code()
			&& (string) ( $attempt['usd_total'] ?? '' ) === (string) $amount->value_str();
	}

	private static function financial_payload( $payload ) {
		$financial = array(
			'intent'         => (string) ( $payload['intent'] ?? '' ),
			'purchase_units' => array(),
		);
		foreach ( (array) ( $payload['purchase_units'] ?? array() ) as $unit ) {
			$financial_unit = array(
				'reference_id' => (string) ( $unit['reference_id'] ?? '' ),
				'amount'       => $unit['amount'] ?? array(),
			);
			foreach ( (array) ( $unit['items'] ?? array() ) as $item ) {
				$financial_item = array(
					'quantity'    => (string) ( $item['quantity'] ?? '' ),
					'unit_amount' => $item['unit_amount'] ?? array(),
				);
				if ( isset( $item['tax'] ) ) {
					$financial_item['tax'] = $item['tax'];
				}
				$financial_unit['items'][] = $financial_item;
			}
			if ( isset( $unit['shipping']['options'] ) ) {
				foreach ( (array) $unit['shipping']['options'] as $option ) {
					$financial_unit['shipping']['options'][] = array(
						'id'       => (string) ( $option['id'] ?? '' ),
						'selected' => (bool) ( $option['selected'] ?? false ),
						'amount'   => $option['amount'] ?? array(),
					);
				}
			}
			$financial['purchase_units'][] = $financial_unit;
		}
		return $financial;
	}

	public static function validate_snapshot( $snapshot ) {
		if ( ! is_array( $snapshot )
			|| self::SCHEMA_VERSION !== (int) ( $snapshot['schema'] ?? 0 )
			|| self::GATEWAY_ID !== ( $snapshot['gateway'] ?? '' )
			|| 'manual' !== ( $snapshot['source'] ?? '' )
			|| empty( $snapshot['locked_at'] )
			|| empty( $snapshot['created_at'] )
			|| ! self::is_valid_rate( $snapshot['rate'] ?? '' ) ) {
			return false;
		}
		if ( ! self::is_nonnegative_decimal( $snapshot['vnd_total'] ?? '' ) || ! self::is_usd_money( $snapshot['usd_total'] ?? '' ) || empty( $snapshot['paypal_order_id'] ) ) {
			return false;
		}
		$units = $snapshot['payload']['purchase_units'] ?? array();
		return 'CAPTURE' === ( $snapshot['payload']['intent'] ?? '' )
			&& 1 === count( $units )
			&& ! empty( $units[0]['reference_id'] )
			&& 'USD' === ( $units[0]['amount']['currency_code'] ?? '' )
			&& (string) $snapshot['usd_total'] === (string) ( $units[0]['amount']['value'] ?? '' );
	}

	public static function calculate_refund_usd_cents( $snapshot, $refunds, $amount, $captured_cents ) {
		if ( ! self::validate_snapshot( $snapshot ) || ! is_array( $refunds ) || $captured_cents < 1 ) {
			throw new InvalidArgumentException( 'Invalid PayPal USD refund state.' );
		}
		$total_vnd    = self::vnd_to_minor( $snapshot['vnd_total'] );
		$snapshot_usd = self::usd_to_cents( $snapshot['usd_total'] );
		$refund_vnd   = self::vnd_to_minor( $amount );
		$previous_vnd = 0;
		$previous_usd = 0;
		foreach ( $refunds as $entry ) {
			$previous_vnd += self::vnd_to_minor( $entry['vnd_amount'] ?? '' );
			$previous_usd += (int) ( $entry['usd_cents'] ?? 0 );
		}
		$cumulative_vnd = $previous_vnd + $refund_vnd;
		if ( $refund_vnd < 1 || $cumulative_vnd > $total_vnd || $previous_usd > $captured_cents ) {
			throw new InvalidArgumentException( 'Converted refund exceeds the original payment.' );
		}
		$target_usd = $cumulative_vnd === $total_vnd ? $snapshot_usd : self::convert_fraction_to_usd_cents( (string) $cumulative_vnd, $snapshot['rate'] );
		$usd_cents  = $target_usd - $previous_usd;
		if ( $usd_cents < 1 || $target_usd > $snapshot_usd || $target_usd > $captured_cents ) {
			throw new InvalidArgumentException( 'Converted refund exceeds the captured USD amount.' );
		}
		return $usd_cents;
	}

	public static function convert_decimal_to_usd( $vnd, $rate ) {
		return self::cents_to_money( self::convert_fraction_to_usd_cents( $vnd, $rate ) );
	}

	public static function convert_purchase_units( $data, $rate ) {
		if ( ! self::is_valid_rate( $rate ) || ! isset( $data['purchase_units'] ) || ! is_array( $data['purchase_units'] ) || 1 !== count( $data['purchase_units'] ) ) {
			throw new InvalidArgumentException( 'Expected exactly one PayPal purchase unit and a valid rate.' );
		}
		foreach ( $data['purchase_units'] as $index => $unit ) {
			if ( ! is_array( $unit ) || empty( $unit['amount'] ) || ! is_array( $unit['amount'] ) ) {
				throw new InvalidArgumentException( 'Malformed PayPal purchase unit.' );
			}
			self::assert_known_money_nodes( $unit );
			$data['purchase_units'][ $index ] = self::convert_purchase_unit( $unit, $rate );
		}
		return $data;
	}

	private static function convert_purchase_unit( $unit, $rate ) {
		if ( 'VND' !== ( $unit['amount']['currency_code'] ?? '' ) || ! isset( $unit['amount']['value'] ) ) {
			throw new InvalidArgumentException( 'Unsupported PayPal purchase-unit currency.' );
		}
		$source_total = self::vnd_to_minor( $unit['amount']['value'] );
		$breakdown    = isset( $unit['amount']['breakdown'] ) && is_array( $unit['amount']['breakdown'] ) ? $unit['amount']['breakdown'] : array();
		$allowed      = array( 'item_total', 'shipping', 'handling', 'tax_total', 'insurance', 'shipping_discount', 'discount' );
		if ( ! $breakdown || array_diff( array_keys( $breakdown ), $allowed ) ) {
			throw new InvalidArgumentException( 'Unsupported PayPal amount breakdown.' );
		}
		if ( $source_total !== self::source_breakdown_total( $breakdown ) ) {
			throw new InvalidArgumentException( 'PayPal VND amount and breakdown do not reconcile.' );
		}

		$items          = isset( $unit['items'] ) && is_array( $unit['items'] ) ? $unit['items'] : array();
		$item_total_vnd = 0;
		$tax_total_vnd  = 0;
		$has_item_tax   = false;
		$candidates     = array();
		$item_total_usd = 0;
		$tax_total_usd  = 0;
		foreach ( $items as $key => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['unit_amount'] ) || ! is_array( $item['unit_amount'] ) || ! ctype_digit( (string) ( $item['quantity'] ?? '' ) ) ) {
				throw new InvalidArgumentException( 'Unsupported PayPal item shape.' );
			}
			$quantity = (int) $item['quantity'];
			if ( $quantity < 1 ) {
				throw new InvalidArgumentException( 'Invalid PayPal item quantity.' );
			}
			$item_value          = $item['unit_amount']['value'];
			$item_vnd            = self::money_node_vnd( $item['unit_amount'] );
			$item['unit_amount'] = self::convert_money_node( $item['unit_amount'], $rate );
			$item_cents          = self::usd_to_cents( $item['unit_amount']['value'] );
			$item_total_vnd     += $item_vnd * $quantity;
			$item_total_usd     += $item_cents * $quantity;
			$candidates[]        = self::make_candidate( $item_value, $rate, array( 'items', $key, 'unit_amount', 'value' ), $quantity, $item_cents );
			if ( isset( $item['tax'] ) ) {
				if ( ! is_array( $item['tax'] ) ) {
					throw new InvalidArgumentException( 'Unsupported PayPal item tax.' );
				}
				$tax_value        = $item['tax']['value'];
				$tax_vnd          = self::money_node_vnd( $item['tax'] );
				$item['tax']      = self::convert_money_node( $item['tax'], $rate );
				$tax_cents        = self::usd_to_cents( $item['tax']['value'] );
				$tax_total_vnd   += $tax_vnd * $quantity;
				$tax_total_usd   += $tax_cents * $quantity;
				$has_item_tax     = true;
				$candidates[]      = self::make_candidate( $tax_value, $rate, array( 'items', $key, 'tax', 'value' ), $quantity, $tax_cents );
			}
			$unit['items'][ $key ] = $item;
		}
		if ( $items && ( ! isset( $breakdown['item_total'] ) || self::money_node_vnd( $breakdown['item_total'] ) !== $item_total_vnd ) ) {
			throw new InvalidArgumentException( 'PayPal item total does not match its item leaves.' );
		}
		if ( $has_item_tax && ( ! isset( $breakdown['tax_total'] ) || self::money_node_vnd( $breakdown['tax_total'] ) !== $tax_total_vnd ) ) {
			throw new InvalidArgumentException( 'PayPal tax total does not match its item tax leaves.' );
		}

		$selected_shipping = null;
		if ( isset( $unit['shipping']['options'] ) ) {
			foreach ( (array) $unit['shipping']['options'] as $key => $option ) {
				if ( ! is_array( $option ) || ! isset( $option['amount'] ) || ! is_array( $option['amount'] ) ) {
					throw new InvalidArgumentException( 'Unsupported PayPal shipping option.' );
				}
				$option_vnd = self::money_node_vnd( $option['amount'] );
				$unit['shipping']['options'][ $key ]['amount'] = self::convert_money_node( $option['amount'], $rate );
				if ( true === ( $option['selected'] ?? false ) || 'true' === ( $option['selected'] ?? '' ) ) {
					if ( null !== $selected_shipping ) {
						throw new InvalidArgumentException( 'Multiple selected PayPal shipping options.' );
					}
					$selected_shipping = array(
						'vnd'   => $option_vnd,
						'value' => $option['amount']['value'],
						'cents' => self::usd_to_cents( $unit['shipping']['options'][ $key ]['amount']['value'] ),
						'path'  => array( 'shipping', 'options', $key, 'amount', 'value' ),
					);
				}
			}
			if ( null === $selected_shipping || ! isset( $breakdown['shipping'] ) || self::money_node_vnd( $breakdown['shipping'] ) !== $selected_shipping['vnd'] ) {
				throw new InvalidArgumentException( 'Selected PayPal shipping option does not match shipping breakdown.' );
			}
		}

		$derived_keys = array();
		if ( $items ) {
			$derived_keys[] = 'item_total';
		}
		if ( $has_item_tax ) {
			$derived_keys[] = 'tax_total';
		}
		if ( null !== $selected_shipping ) {
			$derived_keys[] = 'shipping';
			$candidates[]   = self::make_candidate( $selected_shipping['value'], $rate, $selected_shipping['path'], 1, $selected_shipping['cents'] );
		}
		foreach ( $breakdown as $name => $money ) {
			if ( in_array( $name, $derived_keys, true ) ) {
				continue;
			}
			$breakdown[ $name ] = self::convert_money_node( $money, $rate );
			$candidates[]       = self::make_candidate(
				$money['value'],
				$rate,
				array( 'amount', 'breakdown', $name, 'value' ),
				in_array( $name, array( 'shipping_discount', 'discount' ), true ) ? -1 : 1,
				self::usd_to_cents( $breakdown[ $name ]['value'] )
			);
		}
		if ( $items ) {
			$breakdown['item_total'] = array( 'currency_code' => 'USD', 'value' => self::cents_to_money( $item_total_usd ) );
		}
		if ( $has_item_tax ) {
			$breakdown['tax_total'] = array( 'currency_code' => 'USD', 'value' => self::cents_to_money( $tax_total_usd ) );
		}
		if ( null !== $selected_shipping ) {
			$breakdown['shipping'] = array( 'currency_code' => 'USD', 'value' => self::cents_to_money( $selected_shipping['cents'] ) );
		}

		$unit['amount']['breakdown'] = $breakdown;
		$current_total               = self::breakdown_total( $breakdown );
		$target_total                = self::convert_fraction_to_usd_cents( (string) $source_total, $rate );
		$adjustments                 = self::solve_leaf_adjustments( $candidates, $target_total - $current_total );
		if ( null === $adjustments ) {
			throw new InvalidArgumentException( 'PayPal amount cannot be represented consistently in USD cents.' );
		}
		foreach ( $adjustments as $candidate_index => $delta ) {
			if ( 0 !== $delta ) {
				self::adjust_path_cents( $unit, $candidates[ $candidate_index ]['path'], $delta );
			}
		}

		$breakdown = $unit['amount']['breakdown'];
		if ( $items ) {
			$breakdown['item_total'] = array( 'currency_code' => 'USD', 'value' => self::cents_to_money( self::sum_item_leaf_cents( $unit['items'], 'unit_amount' ) ) );
		}
		if ( $has_item_tax ) {
			$breakdown['tax_total'] = array( 'currency_code' => 'USD', 'value' => self::cents_to_money( self::sum_item_leaf_cents( $unit['items'], 'tax' ) ) );
		}
		if ( null !== $selected_shipping ) {
			$breakdown['shipping'] = array( 'currency_code' => 'USD', 'value' => self::path_value( $unit, $selected_shipping['path'] ) );
		}
		$total = self::breakdown_total( $breakdown );
		if ( $target_total !== $total ) {
			throw new InvalidArgumentException( 'PayPal USD hierarchy did not reconcile.' );
		}
		$unit['amount'] = array_merge( $unit['amount'], array( 'currency_code' => 'USD', 'value' => self::cents_to_money( $total ), 'breakdown' => $breakdown ) );
		return $unit;
	}

	private static function assert_known_money_nodes( $node, $path = '' ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( array_key_exists( 'currency_code', $node ) && array_key_exists( 'value', $node ) ) {
			$normalized = preg_replace( '/\.\d+\./', '.*.', $path );
			$allowed    = array( 'amount', 'amount.breakdown.item_total', 'amount.breakdown.shipping', 'amount.breakdown.handling', 'amount.breakdown.tax_total', 'amount.breakdown.insurance', 'amount.breakdown.shipping_discount', 'amount.breakdown.discount', 'items.*.unit_amount', 'items.*.tax', 'shipping.options.*.amount' );
			if ( ! in_array( $normalized, $allowed, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
				throw new InvalidArgumentException( 'Unknown PayPal monetary node: ' . $path );
			}
		}
		foreach ( $node as $key => $value ) {
			self::assert_known_money_nodes( $value, '' === $path ? (string) $key : $path . '.' . $key );
		}
	}

	private static function convert_money_node( $money, $rate ) {
		self::money_node_vnd( $money );
		$money['currency_code'] = 'USD';
		$money['value']         = self::convert_decimal_to_usd( $money['value'], $rate );
		return $money;
	}

	private static function money_node_vnd( $money ) {
		if ( ! is_array( $money ) || 'VND' !== ( $money['currency_code'] ?? '' ) || ! isset( $money['value'] ) ) {
			throw new InvalidArgumentException( 'Unsupported PayPal currency node.' );
		}
		return self::vnd_to_minor( $money['value'] );
	}

	private static function source_breakdown_total( $breakdown ) {
		$total = 0;
		foreach ( $breakdown as $key => $money ) {
			$value = self::money_node_vnd( $money );
			$total += in_array( $key, array( 'shipping_discount', 'discount' ), true ) ? -$value : $value;
		}
		return $total;
	}

	private static function breakdown_total( $breakdown ) {
		if ( ! $breakdown ) {
			throw new InvalidArgumentException( 'Missing PayPal amount breakdown.' );
		}
		$total = 0;
		foreach ( $breakdown as $key => $money ) {
			$value = self::usd_to_cents( $money['value'] ?? '' );
			$total += in_array( $key, array( 'shipping_discount', 'discount' ), true ) ? -$value : $value;
		}
		if ( $total < 0 ) {
			throw new InvalidArgumentException( 'Negative PayPal order total.' );
		}
		return $total;
	}

	private static function make_candidate( $vnd, $rate, $path, $coefficient, $cents ) {
		list( $vnd_number, $vnd_scale )   = self::decimal_fraction( $vnd );
		list( $rate_number, $rate_scale ) = self::decimal_fraction( $rate );
		$exact_numerator = $vnd_number * 100 * $rate_scale;
		$denominator     = $rate_number * $vnd_scale;
		$weight          = abs( (int) $coefficient );

		return array(
			'path'        => $path,
			'coefficient' => (int) $coefficient,
			'cents'       => (int) $cents,
			'costs'       => array(
				-1 => abs( ( ( $cents - 1 ) * $denominator ) - $exact_numerator ) * $weight,
				0  => abs( ( $cents * $denominator ) - $exact_numerator ) * $weight,
				1  => abs( ( ( $cents + 1 ) * $denominator ) - $exact_numerator ) * $weight,
			),
		);
	}

	private static function solve_leaf_adjustments( $candidates, $difference ) {
		if ( 0 === $difference ) {
			return array_fill( 0, count( $candidates ), 0 );
		}
		if ( abs( $difference ) > 100 || count( $candidates ) > 100 ) {
			return null;
		}
		$states = array(
			0 => array(
				'cost'    => 0,
				'choices' => array(),
			),
		);
		foreach ( $candidates as $index => $candidate ) {
			$next = array();
			foreach ( $states as $sum => $state ) {
				foreach ( array( 0, -1, 1 ) as $delta ) {
					if ( -1 === $delta && $candidate['cents'] < 1 ) {
						continue;
					}
					$new_sum = (int) $sum + ( $delta * (int) $candidate['coefficient'] );
					if ( abs( $new_sum ) > abs( $difference ) + 25 ) {
						continue;
					}
					$new_cost = $state['cost'] + $candidate['costs'][ $delta ];
					if ( isset( $next[ $new_sum ] ) && $next[ $new_sum ]['cost'] <= $new_cost ) {
						continue;
					}
					$next[ $new_sum ] = array(
						'cost'    => $new_cost,
						'choices' => $state['choices'],
					);
					$next[ $new_sum ]['choices'][ $index ] = $delta;
				}
			}
			$states = $next;
		}

		return isset( $states[ $difference ] ) ? array_replace( array_fill( 0, count( $candidates ), 0 ), $states[ $difference ]['choices'] ) : null;
	}

	private static function adjust_path_cents( &$node, $path, $delta ) {
		$cursor =& $node;
		foreach ( $path as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) ) {
				throw new InvalidArgumentException( 'Missing PayPal leaf adjustment path.' );
			}
			$cursor =& $cursor[ $segment ];
		}
		$cents = self::usd_to_cents( $cursor ) + $delta;
		if ( $cents < 0 ) {
			throw new InvalidArgumentException( 'Negative PayPal leaf amount.' );
		}
		$cursor = self::cents_to_money( $cents );
	}

	private static function path_value( $node, $path ) {
		foreach ( $path as $segment ) {
			if ( ! isset( $node[ $segment ] ) ) {
				throw new InvalidArgumentException( 'Missing PayPal monetary path.' );
			}
			$node = $node[ $segment ];
		}
		return (string) $node;
	}

	private static function sum_item_leaf_cents( $items, $field ) {
		$total = 0;
		foreach ( $items as $item ) {
			if ( isset( $item[ $field ]['value'] ) ) {
				$total += self::usd_to_cents( $item[ $field ]['value'] ) * (int) $item['quantity'];
			}
		}
		return $total;
	}

	private static function convert_fraction_to_usd_cents( $vnd, $rate ) {
		if ( ! self::is_valid_rate( $rate ) || ! self::is_nonnegative_decimal( $vnd ) ) {
			throw new InvalidArgumentException( 'Invalid VND amount or rate.' );
		}
		list( $vnd_number, $vnd_scale )   = self::decimal_fraction( $vnd );
		list( $rate_number, $rate_scale ) = self::decimal_fraction( $rate );
		$numerator   = $vnd_number * 100 * $rate_scale;
		$denominator = $rate_number * $vnd_scale;
		return intdiv( $numerator + intdiv( $denominator, 2 ), $denominator );
	}

	private static function decimal_fraction( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/', $value, $matches ) ) {
			throw new InvalidArgumentException( 'Invalid decimal value.' );
		}
		$fraction = isset( $matches[2] ) ? $matches[2] : '';
		$scale    = 10 ** strlen( $fraction );
		$number   = (int) $matches[1] * $scale + ( '' === $fraction ? 0 : (int) $fraction );
		return array( $number, $scale );
	}

	private static function is_nonnegative_decimal( $value ) {
		return is_scalar( $value ) && 1 === preg_match( '/^\d+(?:\.\d+)?$/', trim( (string) $value ) );
	}

	private static function vnd_to_minor( $value ) {
		if ( ! self::is_nonnegative_decimal( $value ) ) {
			throw new InvalidArgumentException( 'Invalid VND amount.' );
		}
		list( $number, $scale ) = self::decimal_fraction( $value );
		if ( 0 !== $number % $scale ) {
			throw new InvalidArgumentException( 'VND amount must use zero decimal places.' );
		}
		return intdiv( $number, $scale );
	}

	private static function is_usd_money( $value ) {
		return is_scalar( $value ) && 1 === preg_match( '/^\d+\.\d{2}$/', (string) $value );
	}

	private static function usd_to_cents( $value ) {
		if ( ! self::is_usd_money( $value ) ) {
			throw new InvalidArgumentException( 'Invalid USD amount.' );
		}
		list( $whole, $fraction ) = explode( '.', (string) $value );
		return (int) $whole * 100 + (int) $fraction;
	}

	private static function cents_to_money( $cents ) {
		if ( ! is_int( $cents ) || $cents < 0 ) {
			throw new InvalidArgumentException( 'Invalid USD cents.' );
		}
		return intdiv( $cents, 100 ) . '.' . str_pad( (string) ( $cents % 100 ), 2, '0', STR_PAD_LEFT );
	}

	private static function contains_key_recursive( $node, $needle ) {
		if ( ! is_array( $node ) ) {
			return false;
		}
		if ( array_key_exists( $needle, $node ) ) {
			return true;
		}
		foreach ( $node as $value ) {
			if ( self::contains_key_recursive( $value, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
