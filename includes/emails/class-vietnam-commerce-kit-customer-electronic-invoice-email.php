<?php
/**
 * Customer electronic invoice email.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

if ( ! class_exists( 'Yoohw_Vietnam_Store_Tools_Customer_Electronic_Invoice_Email', false ) ) :

	class Yoohw_Vietnam_Store_Tools_Customer_Electronic_Invoice_Email extends WC_Email {

		public $invoice_data = [];

		private $invoice_attachments = [];

		public function __construct() {
			$this->id             = 'yoohw_vietnam_store_tools_customer_electronic_invoice';
			$this->customer_email = true;
			$this->manual         = true;
			$this->title          = __( 'Vietnam electronic invoice', 'yoohw-vietnam-store-tools' );
			$this->description    = __( 'Manually send electronic invoice details and available PDF/XML files to the customer.', 'yoohw-vietnam-store-tools' );
			$this->email_group    = 'order-updates';
			$this->template_html  = 'emails/customer-electronic-invoice.php';
			$this->template_plain = 'emails/plain/customer-electronic-invoice.php';
			$this->template_base  = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'templates/';
			$this->placeholders   = [
				'{order_date}'     => '',
				'{order_number}'   => '',
				'{invoice_number}' => '',
				'{invoice_symbol}' => '',
			];

			parent::__construct();
		}

		public function get_default_subject() {
			return __( 'Electronic invoice for order #{order_number} on {site_title}', 'yoohw-vietnam-store-tools' );
		}

		public function get_default_heading() {
			return __( 'Your electronic invoice', 'yoohw-vietnam-store-tools' );
		}

		public function get_default_additional_content() {
			return __( 'If you need help with this invoice, please contact us at {store_email}.', 'yoohw-vietnam-store-tools' );
		}

		public function trigger( $order_id, $invoice_data = [] ) {
			$this->setup_locale();
			$this->object              = null;
			$this->recipient           = '';
			$this->invoice_data        = [];
			$this->invoice_attachments = [];

			$this->reset_email_placeholders();

			$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

			if ( $order instanceof WC_Order ) {
				$this->object       = $order;
				$this->recipient    = $order->get_billing_email();
				$this->invoice_data = is_array( $invoice_data ) ? $invoice_data : [];

				$this->placeholders['{order_date}']     = wc_format_datetime( $order->get_date_created() );
				$this->placeholders['{order_number}']   = $order->get_order_number();
				$this->placeholders['{invoice_number}'] = sanitize_text_field( (string) ( $this->invoice_data['number'] ?? '' ) );
				$this->placeholders['{invoice_symbol}'] = sanitize_text_field( (string) ( $this->invoice_data['symbol'] ?? '' ) );
				$this->invoice_attachments              = $this->get_invoice_attachment_paths();
			}

			$sent      = false;
			$recipient = $this->get_recipient();

			if ( $this->is_enabled() && $recipient ) {
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

		public function get_attachments() {
			return array_values( array_unique( array_merge( parent::get_attachments(), $this->invoice_attachments ) ) );
		}

		public function get_content_html() {
			return wc_get_template_html(
				$this->template_html,
				[
					'order'              => $this->object,
					'invoice_data'       => $this->invoice_data,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
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
					'invoice_data'       => $this->invoice_data,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => true,
					'email'              => $this,
				],
				'',
				$this->template_base
			);
		}

		private function get_invoice_attachment_paths() {
			$paths = [];

			foreach ( [ 'pdf_attachment_id', 'xml_attachment_id' ] as $key ) {
				$attachment_id = absint( $this->invoice_data[ $key ] ?? 0 );
				$path          = $attachment_id ? get_attached_file( $attachment_id ) : '';

				if ( $path && is_readable( $path ) && is_file( $path ) ) {
					$paths[] = $path;
				}
			}

			return $paths;
		}

		private function reset_email_placeholders() {
			$this->placeholders['{site_title}']   = $this->get_blogname();
			$this->placeholders['{site_address}'] = wp_parse_url( home_url(), PHP_URL_HOST );
			$this->placeholders['{site_url}']     = wp_parse_url( home_url(), PHP_URL_HOST );
			$this->placeholders['{store_email}']  = $this->get_from_address();

			foreach ( [ '{order_date}', '{order_number}', '{invoice_number}', '{invoice_symbol}' ] as $placeholder ) {
				$this->placeholders[ $placeholder ] = '';
			}
		}
	}

endif;
