<?php
/**
 * Shipping provider framework for Vietnam Store Toolkit for WooCommerce.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Shipping {

	const META_PROVIDER      = '_vck_shipping_provider';
	const META_PROVIDER_NAME = '_vck_shipping_provider_name';
	const META_SERVICE_CODE  = '_vck_shipping_service_code';
	const META_SERVICE_NAME  = '_vck_shipping_service_name';
	const META_LABEL_ID      = '_vck_shipping_label_id';
	const META_TRACKING_CODE = '_vck_shipping_tracking_code';
	const META_TRACKING_ID   = '_vck_shipping_tracking_id';
	const META_TRACKING_URL  = '_vck_shipping_tracking_url';
	const META_STATUS_ID     = '_vck_shipping_status_id';
	const META_STATUS        = '_vck_shipping_status';
	const META_FEE           = '_vck_shipping_fee';
	const META_INSURANCE_FEE = '_vck_shipping_insurance_fee';
	const META_COD_AMOUNT    = '_vck_shipping_cod_amount';
	const META_LAST_SYNCED   = '_vck_shipping_last_synced_at';
	const META_RAW_RESPONSE  = '_vck_shipping_raw_response';
	const META_TRACKING_EMAIL_STATUS  = '_vck_shipping_tracking_email_status';
	const META_TRACKING_EMAIL_SENT_AT = '_vck_shipping_tracking_email_sent_at';
	const META_TRACKING_EMAIL_CODE     = '_vck_shipping_tracking_email_code';

	private $auto_synced_order_ids = [];

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_admin_order_metabox' ] );
		add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );
		add_action( 'admin_post_yoohw_vietnam_store_tools_create_shipment', [ $this, 'handle_create_shipment_action' ] );
		add_action( 'admin_post_yoohw_vietnam_store_tools_sync_shipment', [ $this, 'handle_sync_shipment_action' ] );
		add_action( 'admin_post_yoohw_vietnam_store_tools_print_shipment', [ $this, 'handle_print_shipment_action' ] );
		add_action( 'admin_post_yoohw_vietnam_store_tools_cancel_shipment', [ $this, 'handle_cancel_shipment_action' ] );
		add_action( 'admin_post_yoohw_vietnam_store_tools_save_manual_shipment', [ $this, 'handle_save_manual_shipment_action' ] );
		add_filter( 'woocommerce_email_classes', [ $this, 'register_email_classes' ] );
		add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_rate_order_itemmeta' ] );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'remove_rate_formatted_meta_data' ], 10, 2 );
		add_action( 'woocommerce_thankyou', [ $this, 'render_frontend_order_tracking' ], 25 );
		add_action( 'woocommerce_view_order', [ $this, 'render_frontend_order_tracking' ], 25 );
	}

	public function render_frontend_order_tracking( $order_id ) {
		if ( ! Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_CUSTOMER_SHIPMENT_DISPLAY ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$data = self::get_order_shipping_data( $order );

		if ( empty( $data['provider_name'] ) && empty( $data['service_name'] ) && empty( $data['tracking_code'] ) && empty( $data['status'] ) ) {
			return;
		}

		$rows = [
			__( 'Provider', 'yoohw-vietnam-store-tools' )      => $data['provider_name'],
			__( 'Service', 'yoohw-vietnam-store-tools' )       => $data['service_name'],
			__( 'Tracking code', 'yoohw-vietnam-store-tools' ) => $this->format_frontend_tracking_code( $data['provider'], $data['tracking_code'], $data['tracking_url'] ),
			__( 'Status', 'yoohw-vietnam-store-tools' )        => $data['status'],
		];

		echo '<section class="woocommerce-order-details vck-order-shipping-tracking">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Shipping information', 'yoohw-vietnam-store-tools' ) . '</h2>';
		echo '<table class="woocommerce-table shop_table shop_table_responsive"><tbody>';

		foreach ( $rows as $label => $value ) {
			if ( '' === trim( wp_strip_all_tags( (string) $value ) ) ) {
				continue;
			}

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</section>';
	}

	private function format_frontend_tracking_code( $provider_id, $tracking_code, $tracking_url ) {
		$tracking_code         = trim( (string) $tracking_code );
		$display_tracking_code = self::get_display_tracking_code( $provider_id, $tracking_code );
		$tracking_url          = trim( (string) $tracking_url );

		if ( '' === $tracking_code ) {
			return '';
		}

		if ( '' === $tracking_url ) {
			return esc_html( $display_tracking_code );
		}

		return '<a href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $display_tracking_code ) . '</a>';
	}

	public function register_email_classes( $emails ) {
		$email_class_file = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'includes/emails/class-vietnam-commerce-kit-customer-shipping-tracking-email.php';

		if ( file_exists( $email_class_file ) ) {
			include_once $email_class_file;
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Customer_Shipping_Tracking_Email' ) ) {
			$emails['Yoohw_Vietnam_Store_Tools_Customer_Shipping_Tracking_Email'] = new Yoohw_Vietnam_Store_Tools_Customer_Shipping_Tracking_Email();
		}

		return $emails;
	}

	public function hide_rate_order_itemmeta( $hidden_meta ) {
		$hidden_meta = is_array( $hidden_meta ) ? $hidden_meta : [];

		return array_values(
			array_unique(
				array_merge(
					$hidden_meta,
					[
						'vck_provider',
						'vck_rate_source',
						'vck_rate_error',
						'vck_rate_error_key',
						'vck_ghtk_fee',
						'vck_ghtk_insurance_fee',
						'vck_ghtk_extra_fees',
						'vck_ghtk_transport',
						'vck_ghtk_requested_transport',
						'vck_ghtk_service_name',
						'vck_ghtk_delivery',
						'vck_viettelpost_service_code',
						'vck_viettelpost_service_name',
						'vck_viettelpost_delivery_time',
						'vck_viettelpost_exchange_weight',
					]
				)
			)
		);
	}

	public function remove_rate_formatted_meta_data( $formatted_meta, $item ) {
		if ( ! $this->is_shipping_order_item( $item ) || ! is_array( $formatted_meta ) ) {
			return $formatted_meta;
		}

		foreach ( $formatted_meta as $meta_id => $meta ) {
			$key = isset( $meta->key ) ? rawurldecode( (string) $meta->key ) : '';

			if ( 0 === strpos( $key, 'vck_' ) || 0 === strpos( $key, '_vck_' ) ) {
				unset( $formatted_meta[ $meta_id ] );
			}
		}

		return $formatted_meta;
	}

	private function is_shipping_order_item( $item ) {
		if ( $item instanceof WC_Order_Item_Shipping ) {
			return true;
		}

		return is_object( $item ) && method_exists( $item, 'get_type' ) && 'shipping' === $item->get_type();
	}

	public static function get_providers() {
		$providers = apply_filters( 'yoohw_vietnam_store_tools_shipping_providers', [] );

		if ( ! is_array( $providers ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $providers as $key => $provider ) {
			if ( ! is_array( $provider ) ) {
				continue;
			}

			if ( empty( $provider['id'] ) && is_string( $key ) ) {
				$provider['id'] = $key;
			}

			$provider = self::normalize_provider( $provider );

			if ( '' === $provider['id'] ) {
				continue;
			}

			$normalized[ $provider['id'] ] = $provider;
		}

		return $normalized;
	}

	public static function get_provider( $provider_id ) {
		$provider_id = sanitize_key( $provider_id );
		$providers   = self::get_providers();

		return isset( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : null;
	}

	public static function get_display_tracking_code( $provider_id, $tracking_code ) {
		$tracking_code = trim( (string) $tracking_code );
		$separator_pos = strrpos( $tracking_code, '.' );

		if ( 'ghtk' !== sanitize_key( $provider_id ) || false === $separator_pos ) {
			return $tracking_code;
		}

		$short_code = trim( (string) substr( $tracking_code, $separator_pos + 1 ) );

		return '' !== $short_code ? $short_code : $tracking_code;
	}

	public static function get_order_shipping_data( $order, $force_refresh = false ) {
		$order = self::get_order( $order );

		if ( ! $order ) {
			return [];
		}

		if ( $force_refresh && is_callable( [ $order, 'read_meta_data' ] ) ) {
			$order->read_meta_data( true );
		}

		$data = [
			'provider'      => (string) $order->get_meta( self::META_PROVIDER, true ),
			'provider_name' => (string) $order->get_meta( self::META_PROVIDER_NAME, true ),
			'service_code'  => (string) $order->get_meta( self::META_SERVICE_CODE, true ),
			'service_name'  => (string) $order->get_meta( self::META_SERVICE_NAME, true ),
			'label_id'      => (string) $order->get_meta( self::META_LABEL_ID, true ),
			'tracking_code' => (string) $order->get_meta( self::META_TRACKING_CODE, true ),
			'tracking_id'   => (string) $order->get_meta( self::META_TRACKING_ID, true ),
			'tracking_url'  => (string) $order->get_meta( self::META_TRACKING_URL, true ),
			'status_id'     => (string) $order->get_meta( self::META_STATUS_ID, true ),
			'status'        => (string) $order->get_meta( self::META_STATUS, true ),
			'fee'           => (string) $order->get_meta( self::META_FEE, true ),
			'insurance_fee' => (string) $order->get_meta( self::META_INSURANCE_FEE, true ),
			'cod_amount'    => (string) $order->get_meta( self::META_COD_AMOUNT, true ),
			'last_synced'   => (string) $order->get_meta( self::META_LAST_SYNCED, true ),
		];

		return (array) apply_filters( 'yoohw_vietnam_store_tools_order_shipping_data', $data, $order );
	}

	public static function update_order_shipping_data( $order, $provider, $data = [] ) {
		$order = self::get_order( $order );

		if ( ! $order ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_shipping_invalid_order', __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) );
		}

		$previous_provider      = sanitize_key( $order->get_meta( self::META_PROVIDER, true ) );
		$previous_tracking_code = trim( (string) $order->get_meta( self::META_TRACKING_CODE, true ) );
		$provider               = is_array( $provider ) ? self::normalize_provider( $provider ) : self::get_provider( $provider );

		if ( ! $provider ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_shipping_invalid_provider', __( 'Shipping provider is not available.', 'yoohw-vietnam-store-tools' ) );
		}

		$data                   = is_array( $data ) ? $data : [];
		$has_tracking_code      = array_key_exists( 'tracking_code', $data ) && null !== $data['tracking_code'];

		if ( $previous_provider !== $provider['id'] && ! $has_tracking_code ) {
			$data['tracking_code'] = '';
			$has_tracking_code     = true;
		}

		$incoming_tracking_code = $has_tracking_code ? trim( (string) $data['tracking_code'] ) : $previous_tracking_code;
		$shipment_changed       = $previous_provider !== $provider['id'] || ( $has_tracking_code && $previous_tracking_code !== $incoming_tracking_code );

		if ( $shipment_changed ) {
			$shipment_reset_defaults = [
				'label_id'     => '',
				'tracking_id'  => '',
				'tracking_url' => '',
				'status_id'    => '',
				'status'       => '',
				'raw_response' => [],
			];

			foreach ( $shipment_reset_defaults as $shipment_data_key => $default_value ) {
				if ( ! array_key_exists( $shipment_data_key, $data ) ) {
					$data[ $shipment_data_key ] = $default_value;
				}
			}
		}

		$data = wp_parse_args(
			$data,
			[
				'provider'      => $provider['id'],
				'provider_name' => $provider['name'],
				'last_synced'   => gmdate( 'c' ),
			]
		);

		$meta_map = [
			'provider'      => self::META_PROVIDER,
			'provider_name' => self::META_PROVIDER_NAME,
			'service_code'  => self::META_SERVICE_CODE,
			'service_name'  => self::META_SERVICE_NAME,
			'label_id'      => self::META_LABEL_ID,
			'tracking_code' => self::META_TRACKING_CODE,
			'tracking_id'   => self::META_TRACKING_ID,
			'tracking_url'  => self::META_TRACKING_URL,
			'status_id'     => self::META_STATUS_ID,
			'status'        => self::META_STATUS,
			'fee'           => self::META_FEE,
			'insurance_fee' => self::META_INSURANCE_FEE,
			'cod_amount'    => self::META_COD_AMOUNT,
			'last_synced'   => self::META_LAST_SYNCED,
			'raw_response'  => self::META_RAW_RESPONSE,
		];

		foreach ( $meta_map as $data_key => $meta_key ) {
			if ( ! array_key_exists( $data_key, $data ) || null === $data[ $data_key ] ) {
				continue;
			}

			$order->update_meta_data( $meta_key, self::sanitize_meta_value( $data_key, $data[ $data_key ] ) );
		}

		if ( $shipment_changed ) {
			$order->delete_meta_data( self::META_TRACKING_EMAIL_STATUS );
			$order->delete_meta_data( self::META_TRACKING_EMAIL_SENT_AT );
			$order->delete_meta_data( self::META_TRACKING_EMAIL_CODE );
		}

		$order->save();

		return true;
	}

	private function update_order_manual_shipping_data( $order, $data ) {
		$order = self::get_order( $order );

		if ( ! $order ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_shipping_invalid_order', __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) );
		}

		$previous_provider      = sanitize_key( $order->get_meta( self::META_PROVIDER, true ) );
		$previous_tracking_code = trim( (string) $order->get_meta( self::META_TRACKING_CODE, true ) );
		$data                   = wp_parse_args(
			is_array( $data ) ? $data : [],
			[
				'provider'      => '',
				'provider_name' => '',
				'service_code'  => '',
				'service_name'  => '',
				'label_id'      => '',
				'tracking_code' => '',
				'tracking_id'   => '',
				'tracking_url'  => '',
				'status_id'     => 'manual',
				'status'        => 'manual',
				'fee'           => '',
				'insurance_fee' => '',
				'cod_amount'    => '',
				'last_synced'   => gmdate( 'c' ),
				'raw_response'  => [],
			]
		);
		$tracking_url           = self::sanitize_manual_tracking_url( $data['tracking_url'] );

		if ( is_wp_error( $tracking_url ) ) {
			return $tracking_url;
		}

		$data['tracking_url'] = $tracking_url;

		if ( is_array( $data['raw_response'] ) && array_key_exists( 'tracking_url', $data['raw_response'] ) ) {
			$data['raw_response']['tracking_url'] = $tracking_url;
		}

		$meta_map = [
			'provider'      => self::META_PROVIDER,
			'provider_name' => self::META_PROVIDER_NAME,
			'service_code'  => self::META_SERVICE_CODE,
			'service_name'  => self::META_SERVICE_NAME,
			'label_id'      => self::META_LABEL_ID,
			'tracking_code' => self::META_TRACKING_CODE,
			'tracking_id'   => self::META_TRACKING_ID,
			'tracking_url'  => self::META_TRACKING_URL,
			'status_id'     => self::META_STATUS_ID,
			'status'        => self::META_STATUS,
			'fee'           => self::META_FEE,
			'insurance_fee' => self::META_INSURANCE_FEE,
			'cod_amount'    => self::META_COD_AMOUNT,
			'last_synced'   => self::META_LAST_SYNCED,
			'raw_response'  => self::META_RAW_RESPONSE,
		];

		foreach ( $meta_map as $data_key => $meta_key ) {
			$order->update_meta_data( $meta_key, self::sanitize_meta_value( $data_key, $data[ $data_key ] ) );
		}

		if ( $previous_provider !== sanitize_key( $data['provider'] ) || $previous_tracking_code !== trim( (string) $data['tracking_code'] ) ) {
			$order->delete_meta_data( self::META_TRACKING_EMAIL_STATUS );
			$order->delete_meta_data( self::META_TRACKING_EMAIL_SENT_AT );
			$order->delete_meta_data( self::META_TRACKING_EMAIL_CODE );
		}

		$order->save();

		return true;
	}

	public function add_admin_order_metabox() {
		$order = $this->get_current_admin_order();

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->maybe_auto_sync_admin_order_shipment( $order );

		foreach ( $this->get_order_admin_screen_ids() as $screen_id ) {
			add_meta_box(
				'yoohw-vietnam-store-tools-shipping',
				__( 'Vietnam shipping', 'yoohw-vietnam-store-tools' ),
				[ $this, 'render_admin_order_metabox' ],
				$screen_id,
				'side',
				'default'
			);
		}
	}

	public function render_admin_order_metabox( $post_or_order_object ) {
		$order = $this->get_admin_order_from_object( $post_or_order_object );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$data      = self::get_order_shipping_data( $order );
		$providers = self::get_providers();
		$provider  = $data['provider'] ? self::get_provider( $data['provider'] ) : null;
		$data      = $this->maybe_auto_sync_admin_order_shipment( $order, $data, $provider );
		$provider  = $data['provider'] ? self::get_provider( $data['provider'] ) : null;

		$this->render_admin_shipping_data( $order, $data, $provider );
		$this->render_admin_shipping_actions( $order, $data, $providers, $provider );
		do_action( 'yoohw_vietnam_store_tools_shipping_admin_metabox_after', $order, $data, $provider );
	}

	private function maybe_auto_sync_admin_order_shipment( $order, $data = null, $provider = null ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return is_array( $data ) ? $data : [];
		}

		$data     = is_array( $data ) ? $data : self::get_order_shipping_data( $order );
		$order_id = $order->get_id();

		if ( ! $order_id || isset( $this->auto_synced_order_ids[ $order_id ] ) ) {
			return $data;
		}

		$this->auto_synced_order_ids[ $order_id ] = true;

		if ( ! $provider && ! empty( $data['provider'] ) ) {
			$provider = self::get_provider( $data['provider'] );
		}

		if ( ! $provider || empty( $data['provider'] ) || ! $this->has_active_shipment( $data ) || ! $this->provider_supports( $provider, 'sync' ) ) {
			return $data;
		}

		$result = $this->call_provider( $provider, 'sync', $order );

		if ( is_wp_error( $result ) ) {
			do_action( 'yoohw_vietnam_store_tools_shipping_auto_sync_failed', $order, $provider, $result );
			return $data;
		}

		$saved = self::update_order_shipping_data( $order, $provider, $result );

		if ( is_wp_error( $saved ) ) {
			do_action( 'yoohw_vietnam_store_tools_shipping_auto_sync_failed', $order, $provider, $saved );
			return $data;
		}

		do_action( 'yoohw_vietnam_store_tools_shipping_shipment_auto_synced', $order, $provider, $result );

		return self::get_order_shipping_data( $order );
	}

	public function render_admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notice = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'yoohw_vietnam_store_tools_shipping_notice' ) );

		if ( '' !== $notice ) {
			$map = [
				'created'                   => [
					'type'    => 'success',
					'message' => __( 'Shipment created.', 'yoohw-vietnam-store-tools' ),
				],
				'synced'                    => [
					'type'    => 'success',
					'message' => __( 'Shipment synced.', 'yoohw-vietnam-store-tools' ),
				],
				'cancelled'                 => [
					'type'    => 'success',
					'message' => __( 'Shipment cancelled.', 'yoohw-vietnam-store-tools' ),
				],
				'manual_saved'              => [
					'type'    => 'success',
					'message' => __( 'Manual shipment details saved.', 'yoohw-vietnam-store-tools' ),
				],
				'manual_saved_email_sent'   => [
					'type'    => 'success',
					'message' => __( 'Manual shipment details saved and tracking email sent to the customer.', 'yoohw-vietnam-store-tools' ),
				],
				'manual_saved_email_failed' => [
					'type'    => 'warning',
					'message' => __( 'Manual shipment details saved, but the tracking email could not be sent.', 'yoohw-vietnam-store-tools' ),
				],
			];

			if ( isset( $map[ $notice ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $map[ $notice ]['type'] ) . ' is-dismissible"><p>' . esc_html( $map[ $notice ]['message'] ) . '</p></div>';
			}
		}

		$message = Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'yoohw_vietnam_store_tools_shipping_error' );

		if ( '' !== $message ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	public function handle_create_shipment_action() {
		$this->handle_shipment_action( 'create' );
	}

	public function handle_sync_shipment_action() {
		$this->handle_shipment_action( 'sync' );
	}

	public function handle_cancel_shipment_action() {
		$this->handle_shipment_action( 'cancel' );
	}

	public function handle_print_shipment_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage shipments.', 'yoohw-vietnam-store-tools' ) );
		}

		$order_id = absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'order_id' ) );

		if ( ! $order_id || ! check_admin_referer( 'yoohw_vietnam_store_tools_shipping_action_' . $order_id, 'yoohw_vietnam_store_tools_shipping_nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'yoohw-vietnam-store-tools' ) );
		}

		$order = self::get_order( $order_id );

		if ( ! $order ) {
			$this->redirect_to_order( null, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$provider_id = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'provider_id' ) );
		$provider_id = '' !== $provider_id ? $provider_id : sanitize_key( $order->get_meta( self::META_PROVIDER, true ) );
		$provider    = self::get_provider( $provider_id );

		if ( ! $provider ) {
			$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'Shipping provider is not available.', 'yoohw-vietnam-store-tools' ) ] );
		}

		if ( ! $this->provider_supports( $provider, 'print' ) ) {
			$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'This provider does not support this action.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$result = call_user_func(
			$provider['print_shipment'],
			$order,
			[
				'action'      => 'print',
				'provider_id' => $provider['id'],
				'request'     => $this->get_action_request(),
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_order(
				$order,
				[
					'yoohw_vietnam_store_tools_shipping_error' => sprintf(
						/* translators: %s: error message. */
						__( 'Shipping action failed: %s', 'yoohw-vietnam-store-tools' ),
						$result->get_error_message()
					),
				]
			);
		}

		if ( ! is_array( $result ) || empty( $result['content'] ) ) {
			$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'Shipping provider returned an invalid response.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$filename     = ! empty( $result['filename'] ) ? sanitize_file_name( $result['filename'] ) : 'shipping-label.pdf';
		$content_type = ! empty( $result['content_type'] ) ? sanitize_mime_type( $result['content_type'] ) : 'application/pdf';

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $result['content'] ) );
		echo $result['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file content.
		exit;
	}

	public function handle_save_manual_shipment_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage shipments.', 'yoohw-vietnam-store-tools' ) );
		}

		$order_id = absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'order_id' ) );

		if ( ! $order_id || ! check_admin_referer( 'yoohw_vietnam_store_tools_shipping_action_' . $order_id, 'yoohw_vietnam_store_tools_shipping_nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'yoohw-vietnam-store-tools' ) );
		}

		$order = self::get_order( $order_id );

		if ( ! $order ) {
			$this->redirect_to_order( null, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$current_data     = self::get_order_shipping_data( $order );
		$manual_providers = $this->get_manual_shipping_providers( $current_data );
		$provider_id      = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'provider_id' ) );
		$request          = $this->get_action_request();
		$tracking_code    = isset( $request['tracking_code'] ) ? trim( (string) $request['tracking_code'] ) : '';
		$send_email       = ! empty( $request['send_tracking_email'] ) && $this->is_truthy_request_value( $request['send_tracking_email'] );

		if ( '' === $provider_id || empty( $manual_providers[ $provider_id ] ) ) {
			$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'Shipping provider is not available.', 'yoohw-vietnam-store-tools' ) ] );
		}

		if ( '' === $tracking_code ) {
			$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'Tracking code is required.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$provider     = $manual_providers[ $provider_id ];
		$tracking_url = isset( $request['tracking_url'] ) ? trim( (string) $request['tracking_url'] ) : '';

		$saved = $this->update_order_manual_shipping_data(
			$order,
			[
				'provider'      => $provider['id'],
				'provider_name' => $provider['name'],
				'tracking_code' => $tracking_code,
				'tracking_url'  => $tracking_url,
				'status_id'     => 'manual',
				'status'        => 'manual',
				'last_synced'   => gmdate( 'c' ),
				'raw_response'  => [
					'source'        => 'manual_admin_entry',
					'tracking_code' => $tracking_code,
					'tracking_url'  => $tracking_url,
				],
			]
		);

		if ( is_wp_error( $saved ) ) {
			$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_error' => $saved->get_error_message() ] );
		}

		do_action( 'yoohw_vietnam_store_tools_shipping_manual_shipment_saved', $order, $provider, $request );

		$notice = 'manual_saved';

		if ( $send_email ) {
			$notice = self::send_customer_tracking_email( $order, $provider )
				? 'manual_saved_email_sent'
				: 'manual_saved_email_failed';
		}

		$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_notice' => $notice ] );
	}

	private function handle_shipment_action( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage shipments.', 'yoohw-vietnam-store-tools' ) );
		}

		$order_id = absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'order_id' ) );

		if ( ! $order_id || ! check_admin_referer( 'yoohw_vietnam_store_tools_shipping_action_' . $order_id, 'yoohw_vietnam_store_tools_shipping_nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'yoohw-vietnam-store-tools' ) );
		}

		$order = self::get_order( $order_id );

		if ( ! $order ) {
			$this->redirect_to_order( null, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$provider_id = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'provider_id' ) );

		if ( '' === $provider_id && 'create' !== $action ) {
			$provider_id = sanitize_key( $order->get_meta( self::META_PROVIDER, true ) );
		}

		$provider = self::get_provider( $provider_id );

		if ( ! $provider ) {
			$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'Shipping provider is not available.', 'yoohw-vietnam-store-tools' ) ] );
		}

		if ( ! $this->provider_supports( $provider, $action ) ) {
			$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_error' => __( 'This provider does not support this action.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$result = $this->call_provider( $provider, $action, $order );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_order(
				$order,
				[
					'yoohw_vietnam_store_tools_shipping_error' => sprintf(
						/* translators: %s: error message. */
						__( 'Shipping action failed: %s', 'yoohw-vietnam-store-tools' ),
						$result->get_error_message()
					),
				]
			);
		}

		$saved = self::update_order_shipping_data( $order, $provider, $result );

		if ( is_wp_error( $saved ) ) {
			$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_error' => $saved->get_error_message() ] );
		}

		do_action( 'yoohw_vietnam_store_tools_shipping_shipment_' . $this->get_action_past_tense( $action ), $order, $provider, $result );

		$this->redirect_to_order( $order, [ 'yoohw_vietnam_store_tools_shipping_notice' => $this->get_action_notice_key( $action ) ] );
	}

	private function render_admin_shipping_data( $order, $data, $provider = null ) {
		$this->render_admin_shipping_styles();

		$provider_name = $data['provider_name'] ? $data['provider_name'] : $data['provider'];
		$service       = $this->format_service_value( $data );
		$tracking_code = trim( (string) $data['tracking_code'] );
		$label_id      = trim( (string) $data['label_id'] );
		$status        = $this->format_status_value( $data );
		$status_tone   = $this->get_status_tone( $data );
		$shipping_fee  = $this->format_money_value( $data['fee'] );
		$cod_amount    = $this->format_money_value( $data['cod_amount'] );
		$last_synced   = $this->format_datetime_value( $data['last_synced'] );
		$is_cancelled  = $this->is_shipment_cancelled( $data );
		$is_active     = $this->has_active_shipment( $data );

		if ( $this->values_overlap( $provider_name, $service ) ) {
			$service = '';
		}

		if ( $this->values_match( $tracking_code, $label_id ) ) {
			$label_id = '';
		}

		$rows = [
			[
				'label' => __( 'Provider', 'yoohw-vietnam-store-tools' ),
				'value' => esc_html( $provider_name ),
			],
			[
				'label' => __( 'Tracking code', 'yoohw-vietnam-store-tools' ),
				'value' => $this->format_tracking_code_value( $data['provider'], $tracking_code, $data['tracking_url'] ),
			],
			[
				'label' => __( 'Status', 'yoohw-vietnam-store-tools' ),
				'value' => esc_html( $status ),
				'class' => 'vck-admin-shipping-card__status',
			],
		];

		if ( ! $is_cancelled ) {
			$rows = array_merge(
				$rows,
				[
					[
						'label' => __( 'Service', 'yoohw-vietnam-store-tools' ),
						'value' => esc_html( $service ),
					],
					[
						'label' => __( 'Label ID', 'yoohw-vietnam-store-tools' ),
						'value' => esc_html( $label_id ),
					],
					[
						'label' => __( 'Shipping fee', 'yoohw-vietnam-store-tools' ),
						'value' => $shipping_fee,
					],
					[
						'label' => __( 'COD amount', 'yoohw-vietnam-store-tools' ),
						'value' => $cod_amount,
					],
					[
						'label' => __( 'Last synced', 'yoohw-vietnam-store-tools' ),
						'value' => $last_synced,
					],
				]
			);
		}

		$has_rows = false;

		foreach ( $rows as $row ) {
			if ( '' !== $row['value'] ) {
				$has_rows = true;
				break;
			}
		}

		if ( ! $has_rows ) {
			echo '<p>' . esc_html__( 'No shipment data has been saved for this order.', 'yoohw-vietnam-store-tools' ) . '</p>';
			return;
		}

		echo '<div class="vck-admin-shipping-summary vck-admin-shipping-summary--' . esc_attr( $status_tone ) . '">';
		echo '<div class="vck-admin-shipping-card">';

		foreach ( $rows as $row ) {
			if ( '' === $row['value'] ) {
				continue;
			}

			$this->render_admin_shipping_card_row( $row['label'], $row['value'], isset( $row['class'] ) ? $row['class'] : '' );
		}

		if ( $order instanceof WC_Order && $provider && $is_active ) {
			$this->render_admin_shipping_card_actions( $order, $provider );
		}

		echo '</div>';
		echo '</div>';
	}

	private function render_admin_shipping_card_row( $label, $value, $class = '' ) {
		$row_class = 'vck-admin-shipping-card__row';

		if ( '' !== $class ) {
			$row_class .= ' ' . sanitize_html_class( $class );
		}

		echo '<div class="' . esc_attr( $row_class ) . '">';
		echo '<span class="vck-admin-shipping-card__label">' . esc_html( $label ) . '</span>';
		echo '<span class="vck-admin-shipping-card__value">' . wp_kses_post( $value ) . '</span>';
		echo '</div>';
	}

	private function render_admin_shipping_card_actions( $order, $provider ) {
		$has_actions = $this->provider_supports( $provider, 'sync' ) || $this->provider_supports( $provider, 'cancel' );

		if ( ! $has_actions ) {
			return;
		}

		echo '<div class="vck-admin-shipping-card__actions">';

		if ( $this->provider_supports( $provider, 'sync' ) ) {
			$this->render_admin_shipping_card_action_button(
				$order,
				$provider,
				'yoohw_vietnam_store_tools_sync_shipment',
				__( 'Sync shipment', 'yoohw-vietnam-store-tools' ),
				'sync',
				'dashicons-update-alt',
				false
			);
		}

		if ( $this->provider_supports( $provider, 'print' ) ) {
			$this->render_admin_shipping_card_action_button(
				$order,
				$provider,
				'yoohw_vietnam_store_tools_print_shipment',
				__( 'Print label', 'yoohw-vietnam-store-tools' ),
				'print',
				'dashicons-printer',
				false
			);
		}

		if ( $this->provider_supports( $provider, 'cancel' ) ) {
			$this->render_admin_shipping_card_action_button(
				$order,
				$provider,
				'yoohw_vietnam_store_tools_cancel_shipment',
				__( 'Cancel shipment', 'yoohw-vietnam-store-tools' ),
				'cancel',
				'dashicons-trash',
				true
			);
		}

		echo '</div>';
	}

	private function render_admin_shipping_card_action_button( $order, $provider, $action, $label, $type, $icon, $show_text ) {
		echo '<button type="button" class="vck-admin-shipping-card__action vck-admin-shipping-card__action--' . esc_attr( $type ) . '" data-vck-shipping-action="' . esc_attr( $action ) . '" data-vck-shipping-order-id="' . esc_attr( $order->get_id() ) . '" data-vck-shipping-provider-id="' . esc_attr( $provider['id'] ) . '" data-vck-shipping-nonce="' . esc_attr( wp_create_nonce( 'yoohw_vietnam_store_tools_shipping_action_' . $order->get_id() ) ) . '" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">';
		echo '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';

		if ( $show_text ) {
			echo '<span>' . esc_html( $label ) . '</span>';
		} else {
			echo '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
		}

		echo '</button>';
	}

	private function render_admin_shipping_styles() {
		static $rendered = false;

		if ( $rendered ) {
			return;
		}

		$rendered = true;
		?>
		<style>
			.vck-admin-shipping-summary {
				margin: 0 0 10px;
			}
			.vck-admin-shipping-card {
				box-sizing: border-box;
				display: flex;
				max-width: 100%;
				min-width: 0;
				flex-direction: column;
				gap: 10px;
				padding: 14px 16px;
				border: 1px solid #dcdcde;
				border-radius: 24px;
				background: #f6f7f7;
				color: #1d2327;
				line-height: 1.25;
			}
			.vck-admin-shipping-card__row {
				min-width: 0;
			}
			.vck-admin-shipping-card__label {
				display: block;
				color: #646970;
				font-size: 11px;
				font-weight: 600;
				letter-spacing: 0;
				text-transform: uppercase;
			}
			.vck-admin-shipping-card__value {
				display: block;
				overflow-wrap: anywhere;
				font-size: 13px;
				font-weight: 600;
				margin-top: 2px;
			}
			.vck-admin-shipping-card__value a {
				color: inherit;
				text-decoration: none;
			}
			.vck-admin-shipping-card__value a:hover {
				text-decoration: underline;
			}
			.vck-admin-shipping-card__copyable {
				display: inline-flex;
				align-items: center;
				max-width: 100%;
				gap: 5px;
				vertical-align: middle;
			}
			.vck-admin-shipping-card__tracking-text {
				min-width: 0;
				overflow-wrap: anywhere;
			}
			.vck-admin-shipping-card__copy {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				flex: 0 0 auto;
				width: 22px;
				height: 22px;
				margin: 0;
				padding: 0;
				border: 0;
				border-radius: 999px;
				background: transparent;
				color: #50575e;
				cursor: pointer;
			}
			.vck-admin-shipping-card__copy:hover,
			.vck-admin-shipping-card__copy:focus {
				background: rgba(255, 255, 255, 0.72);
				color: #1d2327;
				box-shadow: none;
				outline: 1px solid rgba(80, 87, 94, 0.28);
			}
			.vck-admin-shipping-card__copy .dashicons {
				width: 16px;
				height: 16px;
				font-size: 16px;
				line-height: 16px;
			}
			.vck-admin-shipping-card__copy.is-copied {
				background: #edfaef;
				color: #008a20;
			}
			.vck-admin-shipping-card__status .vck-admin-shipping-card__value {
				font-weight: 700;
			}
			.vck-admin-shipping-card__actions {
				display: flex;
				align-items: center;
				gap: 6px;
				margin-top: 2px;
				padding-top: 8px;
				border-top: 1px solid rgba(0, 0, 0, 0.08);
			}
			.vck-admin-shipping-card__action {
				box-sizing: border-box;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				gap: 4px;
				min-height: 28px;
				margin: 0;
				padding: 0 10px;
				border: 1px solid #c3c4c7;
				border-radius: 999px;
				background: rgba(255, 255, 255, 0.72);
				color: #50575e;
				cursor: pointer;
				font-size: 12px;
				font-weight: 600;
				line-height: 1;
			}
			.vck-admin-shipping-card__action:hover,
			.vck-admin-shipping-card__action:focus {
				background: #fff;
				border-color: #8c8f94;
				color: #1d2327;
			}
			.vck-admin-shipping-card__action .dashicons {
				width: 16px;
				height: 16px;
				font-size: 16px;
				line-height: 16px;
			}
			.vck-admin-shipping-card__action--sync {
				width: 30px;
				padding: 0;
			}
			.vck-admin-shipping-card__action--print {
				width: 30px;
				padding: 0;
				color: #0a4b78;
			}
			.vck-admin-shipping-card__action--cancel {
				background: rgba(255, 255, 255, 0.78);
				border-color: #facfd2;
				color: #b32d2e;
			}
			.vck-admin-shipping-card__action--cancel:hover,
			.vck-admin-shipping-card__action--cancel:focus {
				background: #fff7f7;
				border-color: #f29ca3;
				color: #8a2424;
			}
			.vck-admin-shipping-summary--pending .vck-admin-shipping-card {
				background: #f6f7f7;
				border-color: #c3c4c7;
			}
			.vck-admin-shipping-summary--info .vck-admin-shipping-card {
				background: #f8fbff;
				border-color: #c5d9ed;
			}
			.vck-admin-shipping-summary--info .vck-admin-shipping-card__status .vck-admin-shipping-card__value {
				color: #0a4b78;
			}
			.vck-admin-shipping-summary--success .vck-admin-shipping-card {
				background: #f6fff7;
				border-color: #b8e6bf;
			}
			.vck-admin-shipping-summary--success .vck-admin-shipping-card__status .vck-admin-shipping-card__value {
				color: #008a20;
			}
			.vck-admin-shipping-summary--warning .vck-admin-shipping-card {
				background: #fffaf0;
				border-color: #f0c33c;
			}
			.vck-admin-shipping-summary--warning .vck-admin-shipping-card__status .vck-admin-shipping-card__value {
				color: #7a4b00;
			}
			.vck-admin-shipping-summary--danger .vck-admin-shipping-card {
				background: #fff7f7;
				border-color: #facfd2;
			}
			.vck-admin-shipping-summary--danger .vck-admin-shipping-card__status .vck-admin-shipping-card__value {
				color: #b32d2e;
			}
			.vck-admin-shipping-form__toggle {
				box-sizing: border-box;
				display: flex;
				align-items: center;
				justify-content: space-between;
				width: 100%;
				margin: 12px 0 0;
				padding: 10px 2px;
				border: 0;
				border-top: 1px solid #dcdcde;
				border-bottom: 1px solid #dcdcde;
				background: transparent;
				color: var(--wp-admin-theme-color, #2271b1);
				cursor: pointer;
				font-weight: 600;
				text-align: left;
			}
			.vck-admin-shipping-form__toggle:hover,
			.vck-admin-shipping-form__toggle:focus {
				color: var(--wp-admin-theme-color-darker-10, #135e96);
			}
			.vck-admin-shipping-form__toggle:focus-visible {
				border-radius: 2px;
				box-shadow: 0 0 0 1px var(--wp-admin-theme-color, #2271b1);
				outline: 2px solid transparent;
			}
			.vck-admin-shipping-form__toggle .dashicons {
				transition: transform 0.15s ease;
			}
			.vck-admin-shipping-form__toggle[aria-expanded="true"] .dashicons {
				transform: rotate(180deg);
			}
			.vck-admin-shipping-manual-form[hidden] {
				display: none;
			}
		</style>
		<?php
	}

	private function render_admin_shipping_actions( $order, $data, $providers, $provider ) {
		if ( empty( $providers ) ) {
			$this->render_manual_shipment_form( $order, $data );
			$this->render_action_submit_script();
			return;
		}

		$has_active_shipment = $this->has_active_shipment( $data );

		if ( ! $has_active_shipment ) {
			echo '<div class="vck-admin-shipping-actions" style="margin-top:12px;">';
			$this->render_create_shipment_form( $order, $data, $providers );
			echo '</div>';
		} elseif ( ! $provider ) {
			echo '<p class="description">' . esc_html__( 'The saved shipment provider is not available.', 'yoohw-vietnam-store-tools' ) . '</p>';
		}

		$this->render_action_submit_script();
	}

	private function render_manual_shipment_form( $order, $data ) {
		$manual_providers = $this->get_manual_shipping_providers( $data );

		if ( empty( $manual_providers ) ) {
			return;
		}

		$panel_id             = 'vck-shipping-manual-form-' . $order->get_id();
		$selected_provider_id = $this->get_selected_manual_shipping_provider_id( $order, $data, $manual_providers );
		$tracking_code        = trim( (string) $data['tracking_code'] );
		$tracking_url         = trim( (string) $data['tracking_url'] );
		$billing_email        = trim( (string) $order->get_billing_email() );
		$send_email_id        = 'vck_manual_shipping_send_tracking_email_' . $order->get_id();
		$email_settings_url   = admin_url( 'admin.php?page=wc-settings&tab=email&section=yoohw_vietnam_store_tools_customer_shipping_tracking_email' );
		$button_label         = '' === $tracking_code
			? __( 'Save tracking code', 'yoohw-vietnam-store-tools' )
			: __( 'Update tracking code', 'yoohw-vietnam-store-tools' );

		if ( '' !== $tracking_code ) {
			echo '<button type="button" class="vck-admin-shipping-form__toggle" data-vck-shipping-form-toggle aria-expanded="false" aria-controls="' . esc_attr( $panel_id ) . '">';
			echo '<span>' . esc_html__( 'Update tracking code', 'yoohw-vietnam-store-tools' ) . '</span>';
			echo '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
			echo '</button>';
		}

		echo '<div id="' . esc_attr( $panel_id ) . '" data-vck-shipping-create-panel class="vck-admin-shipping-manual-form" style="margin-top:12px;"' . ( '' !== $tracking_code ? ' hidden' : '' ) . '>';
		echo '<p><label for="vck_manual_shipping_provider_' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Provider', 'yoohw-vietnam-store-tools' ) . '</label>';
		echo '<select id="vck_manual_shipping_provider_' . esc_attr( $order->get_id() ) . '" name="provider_id" class="widefat">';

		foreach ( $manual_providers as $provider_id => $manual_provider ) {
			echo '<option value="' . esc_attr( $provider_id ) . '"' . selected( $selected_provider_id, $provider_id, false ) . '>' . esc_html( $manual_provider['name'] ) . '</option>';
		}

		echo '</select></p>';
		echo '<p><label for="vck_manual_shipping_tracking_code_' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Tracking code', 'yoohw-vietnam-store-tools' ) . '</label>';
		echo '<input type="text" id="vck_manual_shipping_tracking_code_' . esc_attr( $order->get_id() ) . '" name="yoohw_vietnam_store_tools_shipping[tracking_code]" class="widefat" value="' . esc_attr( $tracking_code ) . '" autocomplete="off" required></p>';
		echo '<p><label for="vck_manual_shipping_tracking_url_' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Tracking URL', 'yoohw-vietnam-store-tools' ) . '</label>';
		echo '<input type="url" id="vck_manual_shipping_tracking_url_' . esc_attr( $order->get_id() ) . '" name="yoohw_vietnam_store_tools_shipping[tracking_url]" class="widefat" value="' . esc_attr( $tracking_url ) . '" autocomplete="off">';
		echo '<span class="description">' . esc_html__( 'Leave blank to create the link from the carrier tracking URL template.', 'yoohw-vietnam-store-tools' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping&section=yoohw_shipment_tracking' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Tracking settings', 'yoohw-vietnam-store-tools' ) . '</a></span></p>';
		echo '<p class="vck-admin-shipping-manual-form__send-email">';
		echo '<label for="' . esc_attr( $send_email_id ) . '">';
		echo '<input type="checkbox" id="' . esc_attr( $send_email_id ) . '" name="yoohw_vietnam_store_tools_shipping[send_tracking_email]" value="yes"' . disabled( '' === $billing_email, true, false ) . '> ';
		echo esc_html__( 'Email tracking details to the customer', 'yoohw-vietnam-store-tools' );
		echo '</label>';
		echo ' <a href="' . esc_url( $email_settings_url ) . '" target="_blank" rel="noopener noreferrer">(' . esc_html__( 'Email settings', 'yoohw-vietnam-store-tools' ) . ')</a>';

		if ( '' === $billing_email ) {
			echo '<br><span class="description">' . esc_html__( 'The customer billing email is missing.', 'yoohw-vietnam-store-tools' ) . '</span>';
		}

		echo '</p>';
		echo '<button type="button" class="button button-primary" data-vck-shipping-action="yoohw_vietnam_store_tools_save_manual_shipment" data-vck-shipping-order-id="' . esc_attr( $order->get_id() ) . '" data-vck-shipping-nonce="' . esc_attr( wp_create_nonce( 'yoohw_vietnam_store_tools_shipping_action_' . $order->get_id() ) ) . '">' . esc_html( $button_label ) . '</button>';
		echo '</div>';
	}

	public static function get_manual_shipping_providers( $current_data = [] ) {
		$providers = [
			'ghtk'        => [
				'id'   => 'ghtk',
				'name' => 'Giao Hàng Tiết Kiệm',
			],
			'viettelpost' => [
				'id'   => 'viettelpost',
				'name' => 'Viettel Post',
			],
			'ghn'         => [
				'id'   => 'ghn',
				'name' => 'Giao Hàng Nhanh',
			],
			'vnpost'      => [
				'id'   => 'vnpost',
				'name' => 'Vietnam Post',
			],
			'jtexpress'   => [
				'id'   => 'jtexpress',
				'name' => 'J&T Express',
			],
			'ninjavan'    => [
				'id'   => 'ninjavan',
				'name' => 'Ninja Van',
			],
			'bestexpress' => [
				'id'   => 'bestexpress',
				'name' => 'BEST Express',
			],
			'spx'         => [
				'id'   => 'spx',
				'name' => 'SPX Express',
			],
			'ahamove'     => [
				'id'   => 'ahamove',
				'name' => 'Ahamove',
			],
			'grabexpress' => [
				'id'   => 'grabexpress',
				'name' => 'GrabExpress',
			],
			'other'       => [
				'id'   => 'other',
				'name' => __( 'Other carrier', 'yoohw-vietnam-store-tools' ),
			],
		];

		if ( is_array( $current_data ) && ! empty( $current_data['provider'] ) && ! isset( $providers[ $current_data['provider'] ] ) ) {
			$providers[ $current_data['provider'] ] = [
				'id'   => $current_data['provider'],
				'name' => ! empty( $current_data['provider_name'] ) ? $current_data['provider_name'] : $current_data['provider'],
			];
		}

		/**
		 * Filters carrier choices available for manual tracking entry.
		 *
		 * @param array $providers Carrier options keyed by provider ID.
		 */
		$providers = apply_filters( 'yoohw_vietnam_store_tools_manual_shipping_providers', $providers );

		if ( ! is_array( $providers ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $providers as $key => $provider ) {
			if ( ! is_array( $provider ) ) {
				continue;
			}

			if ( empty( $provider['id'] ) && is_string( $key ) ) {
				$provider['id'] = $key;
			}

			$provider = self::normalize_provider( $provider );

			if ( '' === $provider['id'] ) {
				continue;
			}

			$normalized[ $provider['id'] ] = [
				'id'   => $provider['id'],
				'name' => $provider['name'],
			];
		}

		return $normalized;
	}

	private function get_selected_manual_shipping_provider_id( $order, $data, $manual_providers ) {
		if ( ! empty( $data['provider'] ) && isset( $manual_providers[ $data['provider'] ] ) ) {
			return $data['provider'];
		}

		$checkout_provider = $this->get_order_checkout_shipping_provider_id( $order, $manual_providers );

		if ( '' !== $checkout_provider ) {
			return $checkout_provider;
		}

		return (string) array_key_first( $manual_providers );
	}

	private function has_active_shipment( $data ) {
		return $this->has_shipment_data( $data ) && ! $this->is_shipment_cancelled( $data );
	}

	private function has_shipment_data( $data ) {
		foreach ( [ 'tracking_code', 'label_id', 'tracking_id' ] as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_shipment_cancelled( $data ) {
		$status_id = isset( $data['status_id'] ) ? strtolower( trim( (string) $data['status_id'] ) ) : '';
		$status    = isset( $data['status'] ) ? strtolower( trim( (string) $data['status'] ) ) : '';

		return in_array( $status_id, [ 'cancelled', '-1' ], true )
			|| false !== strpos( $status, 'cancel' )
			|| false !== strpos( $status, 'hủy' )
			|| false !== strpos( $status, 'huỷ' );
	}

	private function get_status_tone( $data ) {
		if ( $this->is_shipment_cancelled( $data ) ) {
			return 'danger';
		}

		$status_id = isset( $data['status_id'] ) ? strtolower( trim( (string) $data['status_id'] ) ) : '';
		$status    = isset( $data['status'] ) ? strtolower( trim( (string) $data['status'] ) ) : '';

		if ( in_array( $status_id, [ '5', '6', '11' ], true ) || false !== strpos( $status, 'deliver' ) || false !== strpos( $status, 'reconcile' ) ) {
			return 'success';
		}

		if ( in_array( $status_id, [ '7', '8', '9', '10', '13', '20', '21' ], true )
			|| false !== strpos( $status, 'fail' )
			|| false !== strpos( $status, 'delay' )
			|| false !== strpos( $status, 'return' )
			|| false !== strpos( $status, 'compensat' )
		) {
			return 'warning';
		}

		if ( in_array( $status_id, [ '2', '3', '4', '12' ], true )
			|| false !== strpos( $status, 'received' )
			|| false !== strpos( $status, 'picked' )
			|| false !== strpos( $status, 'picking' )
			|| false !== strpos( $status, 'delivery' )
		) {
			return 'info';
		}

		return '' !== $status_id || '' !== $status ? 'pending' : 'neutral';
	}

	private function render_create_shipment_form( $order, $data, $providers ) {
		$panel_id             = 'vck-shipping-create-form-' . $order->get_id();
		$selected_provider_id = $this->get_selected_create_shipment_provider_id( $order, $data, $providers );

		echo '<button type="button" class="vck-admin-shipping-form__toggle" data-vck-shipping-form-toggle aria-expanded="false" aria-controls="' . esc_attr( $panel_id ) . '">';
		echo '<span>' . esc_html__( 'Create shipment', 'yoohw-vietnam-store-tools' ) . '</span>';
		echo '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
		echo '</button>';
		echo '<div id="' . esc_attr( $panel_id ) . '" data-vck-shipping-create-panel style="margin:12px 0 8px;" hidden>';
		echo '<p><label for="vck_shipping_provider">' . esc_html__( 'Provider', 'yoohw-vietnam-store-tools' ) . '</label>';
		echo '<select id="vck_shipping_provider" name="provider_id" class="widefat">';

		foreach ( $providers as $provider_id => $provider ) {
			echo '<option value="' . esc_attr( $provider_id ) . '"' . selected( $selected_provider_id, $provider_id, false ) . '>' . esc_html( $provider['name'] ) . '</option>';
		}

		echo '</select></p>';
		$this->render_provider_create_fields( $order, $data, $providers, $selected_provider_id );
		echo '<button type="button" class="button button-primary" data-vck-shipping-action="yoohw_vietnam_store_tools_create_shipment" data-vck-shipping-order-id="' . esc_attr( $order->get_id() ) . '" data-vck-shipping-nonce="' . esc_attr( wp_create_nonce( 'yoohw_vietnam_store_tools_shipping_action_' . $order->get_id() ) ) . '">' . esc_html__( 'Create shipment', 'yoohw-vietnam-store-tools' ) . '</button>';
		echo '</div>';

		$this->render_create_form_script( $panel_id );
	}

	private function get_selected_create_shipment_provider_id( $order, $data, $providers ) {
		if ( ! empty( $data['provider'] ) && isset( $providers[ $data['provider'] ] ) ) {
			return $data['provider'];
		}

		$checkout_provider = $this->get_order_checkout_shipping_provider_id( $order, $providers );

		if ( '' !== $checkout_provider ) {
			return $checkout_provider;
		}

		return (string) array_key_first( $providers );
	}

	private function get_order_checkout_shipping_provider_id( $order, $providers ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			if ( ! is_object( $shipping_item ) ) {
				continue;
			}

			$provider = $this->get_order_item_meta_value( $shipping_item, [ 'vck_provider', '_vck_provider' ] );
			$provider = sanitize_key( (string) $provider );

			if ( '' === $provider && method_exists( $shipping_item, 'get_method_id' ) ) {
				$provider = $this->get_provider_id_from_shipping_method_id( $shipping_item->get_method_id() );
			}

			if ( '' !== $provider && isset( $providers[ $provider ] ) ) {
				return $provider;
			}
		}

		return '';
	}

	private function get_order_item_meta_value( $item, $meta_keys ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return '';
		}

		foreach ( $meta_keys as $meta_key ) {
			$value = $item->get_meta( $meta_key, true );

			if ( '' !== trim( (string) $value ) ) {
				return $value;
			}
		}

		return '';
	}

	private function get_provider_id_from_shipping_method_id( $method_id ) {
		$method_id = sanitize_key( (string) $method_id );
		$map       = [
			'vck_ghtk'       => 'ghtk',
			'vck_viettelpost' => 'viettelpost',
		];

		return isset( $map[ $method_id ] ) ? $map[ $method_id ] : '';
	}

	private function render_provider_create_fields( $order, $data, $providers, $selected_provider_id ) {
		$has_fields = false;

		foreach ( $providers as $provider_id => $provider ) {
			if ( empty( $provider['render_create_fields'] ) || ! is_callable( $provider['render_create_fields'] ) ) {
				continue;
			}

			$has_fields = true;
			echo '<div class="vck-admin-shipping-provider-fields" data-vck-shipping-provider-fields="' . esc_attr( $provider_id ) . '"' . ( $provider_id === $selected_provider_id ? '' : ' hidden' ) . ' style="margin:8px 0 12px;">';
			call_user_func(
				$provider['render_create_fields'],
				$order,
				$data,
				[
					'provider_id' => $provider_id,
					'selected'    => $provider_id === $selected_provider_id,
				]
			);
			echo '</div>';
		}

		if ( ! $has_fields ) {
			return;
		}
	}

	private function render_create_form_script( $panel_id ) {
		?>
		<script>
			(function() {
				var panel = document.getElementById(<?php echo wp_json_encode( $panel_id ); ?>);
				if (!panel) {
					return;
				}
				var select = panel.querySelector('select[name="provider_id"]');
				var fieldGroups = panel.querySelectorAll('[data-vck-shipping-provider-fields]');
				if (!select || !fieldGroups.length) {
					return;
				}
				function syncProviderFields() {
					fieldGroups.forEach(function(group) {
						group.hidden = group.getAttribute('data-vck-shipping-provider-fields') !== select.value;
					});
				}
				select.addEventListener('change', syncProviderFields);
				syncProviderFields();
			})();
		</script>
		<?php
	}

	private function render_action_submit_script() {
		?>
		<script>
			(function() {
				if (window.yoohwVietnamStoreToolsShippingActionSubmitReady) {
					return;
				}
				window.yoohwVietnamStoreToolsShippingActionSubmitReady = true;

				function copyText(text) {
					if (navigator.clipboard && navigator.clipboard.writeText) {
						return navigator.clipboard.writeText(text);
					}

					return new Promise(function(resolve, reject) {
						var input = document.createElement('textarea');
						input.value = text;
						input.setAttribute('readonly', 'readonly');
						input.style.position = 'fixed';
						input.style.top = '-9999px';
						input.style.left = '-9999px';
						document.body.appendChild(input);
						input.select();

						try {
							if (document.execCommand('copy')) {
								resolve();
							} else {
								reject(new Error('copy_failed'));
							}
						} catch (error) {
							reject(error);
						}

						document.body.removeChild(input);
					});
				}

				function showCopiedState(button) {
					var originalLabel = button.getAttribute('data-vck-copy-label') || button.getAttribute('aria-label') || '';
					var copiedLabel = button.getAttribute('data-vck-copied-label') || originalLabel;
					var icon = button.querySelector('.dashicons');

					button.classList.add('is-copied');
					button.setAttribute('aria-label', copiedLabel);
					button.setAttribute('title', copiedLabel);

					if (icon) {
						icon.classList.remove('dashicons-admin-page');
						icon.classList.add('dashicons-yes-alt');
					}

					window.clearTimeout(button.yoohwVietnamStoreToolsCopyTimer);
					button.yoohwVietnamStoreToolsCopyTimer = window.setTimeout(function() {
						button.classList.remove('is-copied');
						button.setAttribute('aria-label', originalLabel);
						button.setAttribute('title', originalLabel);

						if (icon) {
							icon.classList.remove('dashicons-yes-alt');
							icon.classList.add('dashicons-admin-page');
						}
					}, 1400);
				}

				document.addEventListener('click', function(event) {
					var copyButton = event.target.closest('[data-vck-shipping-copy]');

					if (copyButton) {
						event.preventDefault();
						copyText(copyButton.getAttribute('data-vck-shipping-copy') || '')
							.then(function() {
								showCopiedState(copyButton);
							})
							.catch(function() {});
						return;
					}

					var formToggle = event.target.closest('[data-vck-shipping-form-toggle]');

					if (formToggle) {
						event.preventDefault();

						var formPanel = document.getElementById(formToggle.getAttribute('aria-controls') || '');
						if (!formPanel) {
							return;
						}

						var shouldExpand = formToggle.getAttribute('aria-expanded') !== 'true';
						formToggle.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
						formPanel.hidden = !shouldExpand;

						if (shouldExpand) {
							var firstField = formPanel.querySelector('select:not([disabled]), input:not([disabled]), textarea:not([disabled])');
							if (firstField) {
								firstField.focus();
							}
						}
						return;
					}

					var button = event.target.closest('[data-vck-shipping-action]');
					if (!button) {
						return;
					}

					var panel = button.closest('[data-vck-shipping-create-panel]');
					var form = document.createElement('form');
					form.method = 'post';
					form.action = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;

					function appendField(name, value) {
						var input = document.createElement('input');
						input.type = 'hidden';
						input.name = name;
						input.value = value == null ? '' : String(value);
						form.appendChild(input);
					}

					appendField('action', button.getAttribute('data-vck-shipping-action') || '');
					appendField('order_id', button.getAttribute('data-vck-shipping-order-id') || '');
					appendField('provider_id', button.getAttribute('data-vck-shipping-provider-id') || '');
					appendField('yoohw_vietnam_store_tools_shipping_nonce', button.getAttribute('data-vck-shipping-nonce') || '');

					if ((button.getAttribute('data-vck-shipping-action') || '') === 'yoohw_vietnam_store_tools_print_shipment') {
						form.target = '_blank';
					}

					if (panel) {
						var providerSelect = panel.querySelector('select[name="provider_id"]');
						if (providerSelect) {
							appendField('provider_id', providerSelect.value || '');
						}

						panel.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(field) {
							if (field.disabled || !field.name || 'provider_id' === field.name) {
								return;
							}

							if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
								return;
							}

							appendField(field.name, field.value || '');
						});
					}

					document.body.appendChild(form);
					form.submit();
				});
			})();
		</script>
		<?php
	}

	private function call_provider( $provider, $action, $order ) {
		$callback_key = $action . '_shipment';
		$context      = [
			'action'      => $action,
			'provider_id' => $provider['id'],
			'request'     => $this->get_action_request(),
		];

		$result = call_user_func( $provider[ $callback_key ], $order, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_shipping_invalid_response', __( 'Shipping provider returned an invalid response.', 'yoohw-vietnam-store-tools' ) );
		}

		return $result;
	}

	private function provider_supports( $provider, $action ) {
		$callback_key = $action . '_shipment';

		return in_array( $action, $provider['supports'], true ) && ! empty( $provider[ $callback_key ] ) && is_callable( $provider[ $callback_key ] );
	}

	private static function normalize_provider( $provider ) {
		$provider = wp_parse_args(
			$provider,
			[
				'id'                   => '',
				'name'                 => '',
				'supports'             => [],
				'render_create_fields' => null,
				'create_shipment'      => null,
				'sync_shipment'        => null,
				'print_shipment'       => null,
				'cancel_shipment'      => null,
			]
		);

		$provider['id']       = sanitize_key( $provider['id'] );
		$provider['name']     = '' !== $provider['name'] ? sanitize_text_field( $provider['name'] ) : $provider['id'];
		$provider['supports'] = is_array( $provider['supports'] ) ? array_map( 'sanitize_key', $provider['supports'] ) : [];
		$provider['supports'] = array_values( array_intersect( $provider['supports'], [ 'create', 'sync', 'print', 'cancel' ] ) );

		return $provider;
	}

	private function get_action_request() {
		$request_key = 'yoohw_vietnam_store_tools_shipping';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Shipment action handlers verify the nonce before this payload is read.
		if ( empty( $_POST[ $request_key ] ) || ! is_array( $_POST[ $request_key ] ) ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Shipment handlers verify the nonce; sanitize_action_request() recursively sanitizes every key and scalar value.
		return $this->sanitize_action_request( wp_unslash( $_POST[ $request_key ] ) );
	}

	private function sanitize_action_request( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = [];

			foreach ( $value as $key => $item ) {
				$sanitized[ sanitize_key( $key ) ] = $this->sanitize_action_request( $item );
			}

			return $sanitized;
		}

		return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
	}

	private function is_truthy_request_value( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'yes', 'true', 'on' ], true );
	}

	public static function send_customer_tracking_email( $order, $provider = null, $context = [] ) {
		$order = self::get_order( $order );

		if ( ! $order ) {
			return false;
		}

		$shipping_data = self::get_order_shipping_data( $order, true );

		if ( ! is_array( $provider ) ) {
			$provider = self::get_provider( $shipping_data['provider'] );
		}

		if ( ! is_array( $provider ) ) {
			$provider = self::normalize_provider(
				[
					'id'   => $shipping_data['provider'],
					'name' => $shipping_data['provider_name'],
				]
			);
		}

		if ( '' === trim( $shipping_data['tracking_code'] ) || '' === trim( (string) $order->get_billing_email() ) || ! function_exists( 'WC' ) || ! WC() ) {
			self::record_tracking_email_result( $order, false, $shipping_data['tracking_code'] );
			return false;
		}

		$mailer = WC()->mailer();

		if ( ! $mailer || ! method_exists( $mailer, 'get_emails' ) ) {
			self::record_tracking_email_result( $order, false, $shipping_data['tracking_code'] );
			return false;
		}

		$emails    = $mailer->get_emails();
		$email_key = 'Yoohw_Vietnam_Store_Tools_Customer_Shipping_Tracking_Email';

		if ( empty( $emails[ $email_key ] ) || ! is_callable( [ $emails[ $email_key ], 'trigger' ] ) ) {
			self::record_tracking_email_result( $order, false, $shipping_data['tracking_code'] );
			return false;
		}

		$sent = (bool) $emails[ $email_key ]->trigger( $order->get_id(), $shipping_data, $provider, is_array( $context ) ? $context : [] );

		self::record_tracking_email_result( $order, $sent, $shipping_data['tracking_code'] );

		return $sent;
	}

	private static function record_tracking_email_result( $order, $sent, $tracking_code = '' ) {
		$order->update_meta_data( self::META_TRACKING_EMAIL_STATUS, $sent ? 'sent' : 'failed' );
		$order->update_meta_data( self::META_TRACKING_EMAIL_CODE, sanitize_text_field( (string) $tracking_code ) );

		if ( $sent ) {
			$order->update_meta_data( self::META_TRACKING_EMAIL_SENT_AT, gmdate( 'c' ) );
		} else {
			$order->delete_meta_data( self::META_TRACKING_EMAIL_SENT_AT );
		}

		$order->save();
	}

	private static function sanitize_meta_value( $key, $value ) {
		if ( 'raw_response' === $key ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				return wp_json_encode( $value );
			}

			return sanitize_textarea_field( (string) $value );
		}

		if ( 'tracking_url' === $key ) {
			return esc_url_raw( $value );
		}

		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	}

	private static function sanitize_manual_tracking_url( $value ) {
		$raw_value = trim( (string) $value );

		if ( '' === $raw_value ) {
			return '';
		}

		$url = trim( esc_url_raw( $raw_value, [ 'http', 'https' ] ) );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_shipping_invalid_tracking_url', __( 'Enter a valid HTTP or HTTPS tracking URL, or leave it blank to use the carrier template.', 'yoohw-vietnam-store-tools' ) );
		}

		return $url;
	}

	private static function get_order( $order ) {
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		return is_numeric( $order ) && function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order ) ) : false;
	}

	private function get_order_admin_screen_ids() {
		$screen_ids = [ 'shop_order' ];

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $screen_ids ) ) );
	}

	private function get_current_admin_order() {
		return Yoohw_Vietnam_Store_Tools_Request_Security::get_admin_order_from_query();
	}

	private function get_admin_order_from_object( $post_or_order_object ) {
		if ( $post_or_order_object instanceof WC_Order ) {
			return $post_or_order_object;
		}

		if ( $post_or_order_object instanceof WP_Post ) {
			return wc_get_order( $post_or_order_object->ID );
		}

		if ( is_numeric( $post_or_order_object ) ) {
			return wc_get_order( absint( $post_or_order_object ) );
		}

		return false;
	}

	private function redirect_to_order( $order, $args ) {
		if ( $order instanceof WC_Order && is_callable( [ $order, 'get_edit_order_url' ] ) ) {
			$url = $order->get_edit_order_url();
		} else {
			$url = admin_url( 'admin.php?page=wc-orders' );
		}

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	private function format_service_value( $data ) {
		if ( '' !== $data['service_name'] && '' !== $data['service_code'] ) {
			return $data['service_name'] . ' (' . $data['service_code'] . ')';
		}

		return '' !== $data['service_name'] ? $data['service_name'] : $data['service_code'];
	}

	private function format_status_value( $data ) {
		$status_id_key = strtolower( trim( (string) $data['status_id'] ) );
		$status_key    = strtolower( trim( (string) $data['status'] ) );

		if ( 'manual' === $status_id_key || in_array( $status_key, [ 'manual', 'manually entered' ], true ) ) {
			return __( 'Manually entered', 'yoohw-vietnam-store-tools' );
		}

		if ( '' !== $data['status'] && '' !== $data['status_id'] ) {
			if ( $this->values_match_case_insensitive( $data['status'], $data['status_id'] ) ) {
				return $data['status'];
			}

			return $data['status'] . ' (' . $data['status_id'] . ')';
		}

		return '' !== $data['status'] ? $data['status'] : $data['status_id'];
	}

	private function format_tracking_code_value( $provider_id, $tracking_code, $tracking_url ) {
		$tracking_code         = trim( (string) $tracking_code );
		$display_tracking_code = self::get_display_tracking_code( $provider_id, $tracking_code );
		$tracking_url          = trim( (string) $tracking_url );

		if ( '' === $tracking_code ) {
			return '';
		}

		$value = '' === $tracking_url
			? '<span class="vck-admin-shipping-card__tracking-text">' . esc_html( $display_tracking_code ) . '</span>'
			: '<a class="vck-admin-shipping-card__tracking-text" href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $display_tracking_code ) . '</a>';

		return '<span class="vck-admin-shipping-card__copyable">' . $value . '<button type="button" class="vck-admin-shipping-card__copy" data-vck-shipping-copy="' . esc_attr( $display_tracking_code ) . '" data-vck-copy-label="' . esc_attr__( 'Copy tracking code', 'yoohw-vietnam-store-tools' ) . '" data-vck-copied-label="' . esc_attr__( 'Copied', 'yoohw-vietnam-store-tools' ) . '" aria-label="' . esc_attr__( 'Copy tracking code', 'yoohw-vietnam-store-tools' ) . '" title="' . esc_attr__( 'Copy tracking code', 'yoohw-vietnam-store-tools' ) . '"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button></span>';
	}

	private function format_money_value( $value ) {
		if ( '' === $value ) {
			return '';
		}

		return function_exists( 'wc_price' ) && is_numeric( $value ) ? wc_price( (float) $value ) : esc_html( $value );
	}

	private function format_datetime_value( $value ) {
		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		if ( ! $timestamp ) {
			return esc_html( $value );
		}

		return esc_html( date_i18n( wc_date_format() . ' ' . wc_time_format(), $timestamp ) );
	}

	private function values_match( $first, $second ) {
		$first  = trim( wp_strip_all_tags( (string) $first ) );
		$second = trim( wp_strip_all_tags( (string) $second ) );

		return '' !== $first && '' !== $second && $first === $second;
	}

	private function values_match_case_insensitive( $first, $second ) {
		$first  = strtolower( trim( wp_strip_all_tags( (string) $first ) ) );
		$second = strtolower( trim( wp_strip_all_tags( (string) $second ) ) );

		return '' !== $first && '' !== $second && $first === $second;
	}

	private function values_overlap( $first, $second ) {
		$first  = strtolower( trim( wp_strip_all_tags( (string) $first ) ) );
		$second = strtolower( trim( wp_strip_all_tags( (string) $second ) ) );

		return '' !== $first
			&& '' !== $second
			&& ( $first === $second || false !== strpos( $first, $second ) || false !== strpos( $second, $first ) );
	}

	private function get_action_past_tense( $action ) {
		$map = [
			'create' => 'created',
			'sync'   => 'synced',
			'cancel' => 'cancelled',
		];

		return isset( $map[ $action ] ) ? $map[ $action ] : $action;
	}

	private function get_action_notice_key( $action ) {
		$map = [
			'create' => 'created',
			'sync'   => 'synced',
			'cancel' => 'cancelled',
		];

		return isset( $map[ $action ] ) ? $map[ $action ] : $action;
	}
}
