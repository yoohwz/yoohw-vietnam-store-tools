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

		public $shipment_details = [];

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
				'{shipment_status}'   => '',
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

		public function trigger( $order_id, $shipping_data = [], $provider = [], $context = [] ) {
			$this->setup_locale();
			$context             = is_array( $context ) ? $context : [];
			$this->object        = null;
			$this->recipient     = '';
			$this->provider_name = '';
			$this->tracking_code = '';
			$this->tracking_url  = '';
			$this->shipment_details = [];

			$this->reset_email_placeholders();

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
				$this->placeholders['{shipment_status}']   = isset( $shipping_data['status'] ) ? sanitize_text_field( (string) $shipping_data['status'] ) : '';
				$this->placeholders['{tracking_code}']     = $this->tracking_code;
				$this->placeholders['{tracking_url}']      = $this->tracking_url;

				if ( ! empty( $context['include_shipment_details'] ) ) {
					$this->shipment_details = $this->get_shipment_details( $shipping_data, $order );
				}
			}

			$sent      = false;
			$recipient = $this->get_recipient();

			if ( $recipient ) {
				$subject = ! empty( $context['internal_trigger'] )
					? $this->format_string( __( 'Shipping update: {shipment_status} - Order #{order_number}', 'yoohw-vietnam-store-tools' ) )
					: $this->get_subject();
				$sent = $this->send(
					$recipient,
					$subject,
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
					'shipment_details'   => $this->shipment_details,
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
					'shipment_details'   => $this->shipment_details,
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

		private function reset_email_placeholders() {
			$this->placeholders['{site_title}']   = $this->get_blogname();
			$this->placeholders['{site_address}'] = wp_parse_url( home_url(), PHP_URL_HOST );
			$this->placeholders['{site_url}']     = wp_parse_url( home_url(), PHP_URL_HOST );
			$this->placeholders['{store_email}']  = $this->get_from_address();

			foreach ( [ '{order_date}', '{order_number}', '{shipping_provider}', '{shipment_status}', '{tracking_code}', '{tracking_url}' ] as $placeholder ) {
				$this->placeholders[ $placeholder ] = '';
			}
		}

		private function get_shipment_details( $shipping_data, $order ) {
			$details = [];

			foreach (
				[
					'service_name' => __( 'Shipping service', 'yoohw-vietnam-store-tools' ),
					'status'       => __( 'Shipment status', 'yoohw-vietnam-store-tools' ),
				] as $key => $label
			) {
				$value = isset( $shipping_data[ $key ] ) ? trim( sanitize_text_field( (string) $shipping_data[ $key ] ) ) : '';

				if ( '' !== $value && $value !== $this->provider_name ) {
					$details[] = [
						'label' => $label,
						'value' => esc_html( $value ),
					];
				}
			}

			foreach (
				[
					'cod_amount' => __( 'COD amount', 'yoohw-vietnam-store-tools' ),
				] as $key => $label
			) {
				$value = isset( $shipping_data[ $key ] ) ? trim( (string) $shipping_data[ $key ] ) : '';

				if ( '' === $value ) {
					continue;
				}

				$details[] = [
					'label' => $label,
					'value' => function_exists( 'wc_price' )
						? wc_price( (float) $value, [ 'currency' => $order->get_currency() ] )
						: esc_html( $value ),
				];
			}

			$last_synced = isset( $shipping_data['last_synced'] ) ? trim( (string) $shipping_data['last_synced'] ) : '';

			if ( '' !== $last_synced && function_exists( 'wc_string_to_datetime' ) && function_exists( 'wc_format_datetime' ) ) {
				try {
					$details[] = [
						'label' => __( 'Last shipment update', 'yoohw-vietnam-store-tools' ),
						'value' => esc_html( wc_format_datetime( wc_string_to_datetime( $last_synced ) ) ),
					];
				} catch ( Exception $exception ) {
					unset( $exception );
				}
			}

			return $details;
		}
	}

endif;
