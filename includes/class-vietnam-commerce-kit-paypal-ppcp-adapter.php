<?php
/**
 * Lazily loaded PPCP adapter verified against the 4.1.3 integration contract.
 *
 * This file is included only inside PPCP's module filter, after its autoloader
 * has registered the interfaces and classes referenced below.
 *
 * @package VietnamCommerceKit
 */

use WooCommerce\PayPalCommerce\ApiClient\Entity\Amount;
use WooCommerce\PayPalCommerce\ApiClient\Entity\Money;
use WooCommerce\PayPalCommerce\ApiClient\Entity\Order;
use WooCommerce\PayPalCommerce\ApiClient\Entity\RefundCapture;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\OrderEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentsEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Exception\RuntimeException;
use WooCommerce\PayPalCommerce\SdkV6\Assets\SdkV6Manager;
use WooCommerce\PayPalCommerce\Vendor\Inpsyde\Modularity\Module\ExtendingModule;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\Vendor\Psr\Log\LoggerInterface;
use WooCommerce\PayPalCommerce\WcGateway\Helper\RefundFeesUpdater;
use WooCommerce\PayPalCommerce\WcGateway\Processor\RefundProcessor;

final class Yoohw_Vietnam_Store_Tools_PayPal_PPCP_Module implements ExtendingModule {

	public function id(): string {
		return 'yoohw-vietnam-store-tools-paypal-usd';
	}

	public function extensions(): array {
		return array(
			'wcgateway.processor.refunds' => static function ( $service, ContainerInterface $container ) {
				unset( $service );
				return new Yoohw_Vietnam_Store_Tools_PayPal_USD_Refund_Processor(
					$container->get( 'api.endpoint.order' ),
					$container->get( 'api.endpoint.payments' ),
					$container->get( 'wcgateway.helper.refund-fees-updater' ),
					$container->get( 'api.prefix' ),
					$container->get( 'woocommerce.logger.woocommerce' )
				);
			},
			'sdk-v6.manager'                => static function ( $service, ContainerInterface $container ) {
				// The constructor mirrors the capability contract introduced in PPCP 4.1.3.
				unset( $service );
				$settings = $container->get( 'settings.settings-provider' );
				return new Yoohw_Vietnam_Store_Tools_PayPal_USD_SdkV6_Manager(
					$container->get( 'sdk-v6.asset-getter' ),
					$container->get( 'ppcp.asset-version' ),
					$container->get( 'settings.environment' ),
					$container->get( 'sdk-v6.button-style-mapper' ),
					$container->get( 'wcgateway.settings.status' ),
					$container->get( 'button.helper.context' ),
					$container->get( 'session.handler' ),
					$container->get( 'session.cancellation.view' ),
					! $settings->enable_pay_now(),
					$settings->save_paypal_and_venmo(),
					$container->get( 'wcgateway.configuration.card-configuration' ),
					$container->has( 'save-payment-methods.eligible' ) && $container->get( 'save-payment-methods.eligible' ) && $settings->save_card_details(),
					$container->get( 'wc-subscriptions.helper' ),
					$container->get( 'wc-subscriptions.free-trial-subscription-helper' ),
					$container->get( 'button.subscriptions-mode' ),
					$settings->three_d_secure_enum(),
					$container->get( 'wcgateway.credit-card-icons' ),
					$container->get( 'sdk-v6.message-style-mapper' ),
					$container->get( 'sdk-v6.messages-eligibility' ),
					$settings->merchant_country(),
					$container->get( 'sdk-v6.google-pay-config' ),
					$container->get( 'sdk-v6.apple-pay-config' ),
					$container->get( 'sdk-v6.fastlane-config' ),
					$container->get( 'sdk-v6.card-field-styles' )
				);
			},
		);
	}
}

final class Yoohw_Vietnam_Store_Tools_PayPal_USD_SdkV6_Manager extends SdkV6Manager {

	public function __construct( ...$args ) {
		parent::__construct( ...$args );
	}

	public function script_data(): array {
		$data    = parent::script_data();
		$attempt = Yoohw_Vietnam_Store_Tools_PayPal_Conversion::get_runtime_attempt();

		if ( ! is_array( $attempt ) || ! isset( $attempt['preview_usd_total'] ) ) {
			return $data;
		}

		$data['currency'] = 'USD';
		$data['amount']   = (string) $attempt['preview_usd_total'];

		return $data;
	}

	public function should_load_on_current_page(): bool {
		if ( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::should_suppress_ppcp_express() ) {
			return false;
		}
		return parent::should_load_on_current_page();
	}

	public function determine_render_places(): array {
		if ( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::should_suppress_ppcp_express() ) {
			return array(
				'product'   => false,
				'cart'      => false,
				'checkout'  => false,
				'pay-now'   => false,
				'mini-cart' => false,
			);
		}
		return parent::determine_render_places();
	}

	public function render_card_button_wrapper(): void {
		if ( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::should_suppress_ppcp_express() ) {
			return;
		}
		parent::render_card_button_wrapper();
	}
}

final class Yoohw_Vietnam_Store_Tools_PayPal_USD_Refund_Processor extends RefundProcessor {

	private $payments_endpoint;
	private $prefix;

	public function __construct( OrderEndpoint $order_endpoint, PaymentsEndpoint $payments_endpoint, RefundFeesUpdater $refund_fees_updater, string $prefix, LoggerInterface $logger ) {
		parent::__construct( $order_endpoint, $payments_endpoint, $refund_fees_updater, $prefix, $logger );
		$this->payments_endpoint = $payments_endpoint;
		$this->prefix            = $prefix;
	}

	public function refund( Order $order, \WC_Order $wc_order, float $amount, string $reason = '' ): string {
		$snapshot = $wc_order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::SNAPSHOT_META, true );
		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) {
			return parent::refund( $order, $wc_order, $amount, $reason );
		}
		if ( $wc_order->get_payment_method() !== Yoohw_Vietnam_Store_Tools_PayPal_Conversion::GATEWAY_ID
			|| ! Yoohw_Vietnam_Store_Tools_PayPal_Conversion::validate_snapshot( $snapshot )
			|| $order->id() !== $snapshot['paypal_order_id'] ) {
			throw new RuntimeException( 'Invalid PayPal USD conversion snapshot.' );
		}

		$payments = $this->get_payments( $order );
		$captures = $payments->captures();
		if ( 1 !== count( $captures ) ) {
			throw new RuntimeException( 'Converted order must have exactly one capture.' );
		}

		$capture = $captures[0];
		if ( ! is_callable( array( $capture, 'amount' ) ) || 'USD' !== $capture->amount()->currency_code() ) {
			throw new RuntimeException( 'Converted order capture currency is not USD.' );
		}
		$captured = (int) round( $capture->amount()->value() * 100 );
		if ( number_format( $captured / 100, 2, '.', '' ) !== $snapshot['usd_total'] ) {
			throw new RuntimeException( 'Converted order capture amount does not match the locked USD snapshot.' );
		}

		$refunds = $wc_order->get_meta( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META, true );
		$refunds = is_array( $refunds ) ? $refunds : array();
		foreach ( $refunds as $entry ) {
			if ( ! empty( $entry['capture_id'] ) && $entry['capture_id'] !== $capture->id() ) {
				throw new RuntimeException( 'Converted refund capture does not match the original capture.' );
			}
		}

		try {
			if ( abs( $amount - round( $amount ) ) > 0.000001 ) {
				throw new \InvalidArgumentException( 'VND refund amount must use zero decimal places.' );
			}
			$vnd_amount = number_format( $amount, 0, '.', '' );
			$usd_cents  = Yoohw_Vietnam_Store_Tools_PayPal_Conversion::calculate_refund_usd_cents( $snapshot, $refunds, $vnd_amount, $captured );
		} catch ( \InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
			throw new RuntimeException( $error->getMessage() );
		}

		$usd_amount = number_format( $usd_cents / 100, 2, '.', '' );
		$refund     = new RefundCapture(
			$capture,
			$capture->invoice_id() ?: $this->prefix . $wc_order->get_order_number(),
			$reason,
			new Amount( new Money( (float) $usd_amount, 'USD' ) )
		);
		$refund_id  = $this->payments_endpoint->refund( $refund );

		$refunds[] = array(
			'vnd_amount' => $vnd_amount,
			'usd_cents'  => $usd_cents,
			'capture_id' => $capture->id(),
			'refund_id'  => $refund_id,
			'created_at' => gmdate( 'c' ),
		);
		$wc_order->update_meta_data( Yoohw_Vietnam_Store_Tools_PayPal_Conversion::REFUNDS_META, $refunds );
		$wc_order->save_meta_data();

		return $refund_id;
	}
}
