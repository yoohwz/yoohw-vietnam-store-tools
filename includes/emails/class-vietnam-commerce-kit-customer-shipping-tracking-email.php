<?php
/**
 * Customer shipping tracking email.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

if ( ! class_exists( 'Yoohw_Vietnam_Store_Tools_Customer_Shipping_Tracking_Email', false ) ) :

	class Yoohw_Vietnam_Store_Tools_Customer_Shipping_Tracking_Email extends WC_Email {

		public $provider_name = '';

		public $tracking_code = '';

		public $tracking_url = '';

		public function __construct() {
			$this->id             = 'yoohw_vietnam_store_tools_customer_shipping_tracking';
			$this->customer_email = true;
			$this->manual         = true;
			$this->title          = __( 'Vietnam shipping tracking', 'yoohw-vietnam-store-tools' );
			$this->description    = __( 'Manually send customers their Vietnam shipping provider and tracking code from the order shipping metabox.', 'yoohw-vietnam-store-tools' );
			$this->email_group    = 'order-updates';
			$this->template_html  = 'emails/customer-shipping-tracking.php';
			$this->template_plain = 'emails/plain/customer-shipping-tracking.php';
			$this->template_base  = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'templates/';
			$this->placeholders   = [
				'{order_date}'        => '',
				'{order_number}'      => '',
				'{shipping_provider}' => '',
				'{tracking_code}'     => '',
				'{tracking_url}'      => '',
			];

			parent::__construct();
		}

		public function get_default_subject() {
			return __( 'Shipping update for order #{order_number} on {site_title}', 'yoohw-vietnam-store-tools' );
		}

		public function get_default_heading() {
			return __( 'Your order is on its way', 'yoohw-vietnam-store-tools' );
		}

		public function get_default_additional_content() {
			return __( 'If you need any help with your shipment, please contact us at {store_email}.', 'yoohw-vietnam-store-tools' );
		}

		public function trigger( $order_id, $shipping_data = [], $provider = [] ) {
			$this->setup_locale();
			$this->object        = null;
			$this->recipient     = '';
			$this->provider_name = '';
			$this->tracking_code = '';
			$this->tracking_url  = '';

			foreach ( array_keys( $this->placeholders ) as $placeholder ) {
				$this->placeholders[ $placeholder ] = '';
			}

			$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

			if ( $order instanceof WC_Order ) {
				$shipping_data       = is_array( $shipping_data ) ? $shipping_data : [];
				$provider            = is_array( $provider ) ? $provider : [];
				$this->object        = $order;
				$this->recipient     = $order->get_billing_email();
				$this->provider_name = $this->get_provider_name( $shipping_data, $provider );
				$this->tracking_code = Yoohw_Vietnam_Store_Tools_Shipping::get_display_tracking_code(
					isset( $shipping_data['provider'] ) ? $shipping_data['provider'] : '',
					isset( $shipping_data['tracking_code'] ) ? sanitize_text_field( (string) $shipping_data['tracking_code'] ) : ''
				);
				$this->tracking_url  = isset( $shipping_data['tracking_url'] ) ? esc_url_raw( (string) $shipping_data['tracking_url'] ) : '';

				$this->placeholders['{order_date}']        = wc_format_datetime( $order->get_date_created() );
				$this->placeholders['{order_number}']      = $order->get_order_number();
				$this->placeholders['{shipping_provider}'] = $this->provider_name;
				$this->placeholders['{tracking_code}']     = $this->tracking_code;
				$this->placeholders['{tracking_url}']      = $this->tracking_url;
			}

			$sent      = false;
			$recipient = $this->get_recipient();

			if ( $recipient ) {
				$sent = $this->send(
					$recipient,
					$this->get_subject(),
					$this->get_content(),
					$this->get_headers(),
					$this->get_attachments()
				);
			}

			$this->restore_locale();

			return $sent;
		}

		public function get_content_html() {
			return wc_get_template_html(
				$this->template_html,
				[
					'order'              => $this->object,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'provider_name'      => $this->provider_name,
					'tracking_code'      => $this->tracking_code,
					'tracking_url'       => $this->tracking_url,
					'sent_to_admin'      => false,
					'plain_text'         => false,
					'email'              => $this,
				],
				'',
				$this->template_base
			);
		}

		public function get_content_plain() {
			return wc_get_template_html(
				$this->template_plain,
				[
					'order'              => $this->object,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'provider_name'      => $this->provider_name,
					'tracking_code'      => $this->tracking_code,
					'tracking_url'       => $this->tracking_url,
					'sent_to_admin'      => false,
					'plain_text'         => true,
					'email'              => $this,
				],
				'',
				$this->template_base
			);
		}

		private function get_provider_name( $shipping_data, $provider ) {
			if ( ! empty( $provider['name'] ) ) {
				return sanitize_text_field( (string) $provider['name'] );
			}

			if ( ! empty( $shipping_data['provider_name'] ) ) {
				return sanitize_text_field( (string) $shipping_data['provider_name'] );
			}

			if ( ! empty( $shipping_data['provider'] ) ) {
				return sanitize_text_field( (string) $shipping_data['provider'] );
			}

			return '';
		}
	}

endif;
