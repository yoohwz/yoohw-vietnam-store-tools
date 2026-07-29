<?php
/**
 * API-free shipment tracking tools.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Shipment_Tracking {

	const SETTINGS_SECTION          = 'yoohw_shipment_tracking';
	const OPTION_LOOKUP_ENABLED     = 'yoohw_vietnam_store_tools_tracking_lookup_enabled';
	const OPTION_TEMPLATE_OVERRIDES = 'yoohw_vietnam_store_tools_tracking_url_templates';
	const OPTION_CUSTOM_CARRIERS    = 'yoohw_vietnam_store_tools_tracking_custom_carriers';
	const META_TIMELINE             = '_yoohw_vietnam_store_tools_tracking_timeline';
	const SHORTCODE                 = 'yoohw_order_tracking';
	const BLOCK_NAME                = 'yoohw-vietnam-store-tools/order-tracking';

	public function __construct() {
		add_filter( 'woocommerce_get_sections_shipping', [ $this, 'add_settings_section' ] );
		add_filter( 'woocommerce_get_settings_shipping', [ $this, 'get_settings' ], 20, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION_TEMPLATE_OVERRIDES, [ $this, 'sanitize_multiline_option' ], 20 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION_CUSTOM_CARRIERS, [ $this, 'sanitize_multiline_option' ], 20 );
		add_filter( 'yoohw_vietnam_store_tools_manual_shipping_providers', [ $this, 'add_custom_carriers' ], 20 );
		add_filter( 'yoohw_vietnam_store_tools_order_shipping_data', [ $this, 'prepare_order_shipping_data' ], 20, 2 );
		add_action( 'yoohw_vietnam_store_tools_shipping_manual_shipment_saved', [ $this, 'persist_generated_tracking_url' ], 20, 3 );
		add_action( 'yoohw_vietnam_store_tools_shipping_admin_metabox_after', [ $this, 'render_admin_timeline' ], 20, 2 );
		add_action( 'admin_post_yoohw_vietnam_store_tools_add_tracking_event', [ $this, 'handle_add_timeline_event' ] );
		add_action( 'admin_post_yoohw_vietnam_store_tools_delete_tracking_event', [ $this, 'handle_delete_timeline_event' ] );
		add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'woocommerce_thankyou', [ $this, 'render_order_timeline' ], 26 );
		add_action( 'woocommerce_view_order', [ $this, 'render_order_timeline' ], 26 );
		add_action( 'init', [ $this, 'register_lookup_tools' ] );
	}

	public function add_settings_section( $sections ) {
		$sections = is_array( $sections ) ? $sections : [];
		$sections[ self::SETTINGS_SECTION ] = __( 'Vietnam shipment tracking', 'yoohw-vietnam-store-tools' );

		return $sections;
	}

	public function get_settings( $settings, $current_section ) {
		if ( self::SETTINGS_SECTION !== $current_section ) {
			return $settings;
		}

		return [
			[
				'title' => __( 'Vietnam shipment tracking', 'yoohw-vietnam-store-tools' ),
				'desc'  => __( 'Create tracking links and a customer-facing tracking timeline without connecting to a carrier API.', 'yoohw-vietnam-store-tools' ),
				'type'  => 'title',
				'id'    => 'yoohw_vietnam_store_tools_tracking_options',
			],
			[
				'title'    => __( 'Public order lookup', 'yoohw-vietnam-store-tools' ),
				'desc'     => __( 'Allow the order tracking block and shortcode to look up an order using its order number and the billing email or phone number.', 'yoohw-vietnam-store-tools' ),
				'id'       => self::OPTION_LOOKUP_ENABLED,
				'default'  => 'yes',
				'type'     => 'checkbox',
				'autoload' => false,
			],
			[
				'title'       => __( 'Tracking URL overrides', 'yoohw-vietnam-store-tools' ),
				'desc'        => __( 'Optional. Enter one carrier per line using carrier_id|URL. Use {tracking_code} where the tracking code should appear.', 'yoohw-vietnam-store-tools' ),
				'id'          => self::OPTION_TEMPLATE_OVERRIDES,
				'default'     => '',
				'type'        => 'textarea',
				'css'         => 'width:100%;min-height:130px;font-family:monospace;',
				'placeholder' => "ghtk|https://i.ghtk.vn/{tracking_code}\nghn|https://donhang.ghn.vn/?order_code={tracking_code}",
				'autoload'    => false,
			],
			[
				'title'       => __( 'Custom carriers', 'yoohw-vietnam-store-tools' ),
				'desc'        => __( 'Enter one carrier per line using carrier_id|Carrier name|Tracking URL template. The URL must be HTTP or HTTPS and include {tracking_code}.', 'yoohw-vietnam-store-tools' ),
				'id'          => self::OPTION_CUSTOM_CARRIERS,
				'default'     => '',
				'type'        => 'textarea',
				'css'         => 'width:100%;min-height:130px;font-family:monospace;',
				'placeholder' => 'my_carrier|My Carrier|https://example.com/track/{tracking_code}',
				'autoload'    => false,
			],
			[
				'type' => 'sectionend',
				'id'   => 'yoohw_vietnam_store_tools_tracking_options',
			],
		];
	}

	public function sanitize_multiline_option( $value ) {
		$value = sanitize_textarea_field( (string) $value );
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		$lines = is_array( $lines ) ? array_filter( array_map( 'trim', $lines ), 'strlen' ) : [];

		return implode( "\n", $lines );
	}

	public function add_custom_carriers( $providers ) {
		$providers = is_array( $providers ) ? $providers : [];

		foreach ( self::get_custom_carriers() as $carrier_id => $carrier ) {
			$providers[ $carrier_id ] = [
				'id'   => $carrier_id,
				'name' => $carrier['name'],
			];
		}

		return $providers;
	}

	public function prepare_order_shipping_data( $data, $order ) {
		if ( ! is_array( $data ) || ! $order instanceof WC_Order ) {
			return $data;
		}

		if ( empty( $data['tracking_url'] ) && ! empty( $data['tracking_code'] ) ) {
			$data['tracking_url'] = self::build_tracking_url( $data['provider'], $data['tracking_code'] );
		}

		$statuses = self::get_timeline_statuses();

		if ( ! empty( $data['status_id'] ) && isset( $statuses[ $data['status_id'] ] ) ) {
			$data['status'] = $statuses[ $data['status_id'] ]['label'];
		}

		return $data;
	}

	public function persist_generated_tracking_url( $order, $provider, $request ) {
		unset( $provider, $request );

		if ( ! $order instanceof WC_Order || '' !== trim( (string) $order->get_meta( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_URL, true ) ) ) {
			return;
		}

		$url = self::build_tracking_url(
			$order->get_meta( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, true ),
			$order->get_meta( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE, true )
		);

		if ( '' === $url ) {
			return;
		}

		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_URL, $url );
		$order->save();
	}

	public static function build_tracking_url( $carrier_id, $tracking_code ) {
		$carrier_id    = sanitize_key( (string) $carrier_id );
		$tracking_code = trim( sanitize_text_field( (string) $tracking_code ) );
		$template      = self::get_tracking_url_template( $carrier_id );

		if ( '' === $carrier_id || '' === $tracking_code || '' === $template || ! self::template_has_placeholder( $template ) ) {
			return '';
		}

		$url = str_replace( [ '{tracking_code}', '%1$s', '%s' ], rawurlencode( $tracking_code ), $template );
		$url = esc_url_raw( $url, [ 'http', 'https' ] );

		if ( ! wp_http_validate_url( $url ) ) {
			return '';
		}

		return (string) apply_filters( 'yoohw_vietnam_store_tools_tracking_url', $url, $carrier_id, $tracking_code, $template );
	}

	public static function get_tracking_url_template( $carrier_id ) {
		$carrier_id = sanitize_key( (string) $carrier_id );
		$templates  = self::get_default_tracking_templates();
		$custom     = self::get_custom_carriers();

		if ( isset( $custom[ $carrier_id ] ) ) {
			$templates[ $carrier_id ] = $custom[ $carrier_id ]['template'];
		}

		foreach ( self::parse_template_overrides() as $override_id => $template ) {
			$templates[ $override_id ] = $template;
		}

		return isset( $templates[ $carrier_id ] ) ? $templates[ $carrier_id ] : '';
	}

	public static function get_default_tracking_templates() {
		$templates = [
			'ghtk'        => 'https://i.ghtk.vn/{tracking_code}',
			'viettelpost' => 'https://viettelpost.com.vn/tra-cuu-hanh-trinh-don/?code={tracking_code}',
			'ghn'         => 'https://donhang.ghn.vn/?order_code={tracking_code}',
			'vnpost'      => 'https://vnpost.vn/vi/tra-cuu-hanh-trinh-buu-pham?key={tracking_code}',
			'jtexpress'   => 'https://jtexpress.vn/vi/tracking?type=track&billcode={tracking_code}',
			'ninjavan'    => 'https://www.ninjavan.co/vi-vn/tracking?id={tracking_code}',
		];

		return (array) apply_filters( 'yoohw_vietnam_store_tools_default_tracking_url_templates', $templates );
	}

	public static function get_custom_carriers() {
		$value    = (string) get_option( self::OPTION_CUSTOM_CARRIERS, '' );
		$lines    = preg_split( '/\r\n|\r|\n/', $value );
		$carriers = [];

		foreach ( is_array( $lines ) ? $lines : [] as $line ) {
			$parts    = array_map( 'trim', explode( '|', $line, 3 ) );
			$id       = isset( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
			$name     = isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';
			$template = isset( $parts[2] ) ? self::sanitize_tracking_template( $parts[2] ) : '';

			if ( '' === $id || '' === $name || '' === $template || ! self::template_has_placeholder( $template ) ) {
				continue;
			}

			$carriers[ $id ] = [
				'id'       => $id,
				'name'     => $name,
				'template' => $template,
			];
		}

		return $carriers;
	}

	private static function parse_template_overrides() {
		$value     = (string) get_option( self::OPTION_TEMPLATE_OVERRIDES, '' );
		$lines     = preg_split( '/\r\n|\r|\n/', $value );
		$templates = [];

		foreach ( is_array( $lines ) ? $lines : [] as $line ) {
			$parts    = array_map( 'trim', explode( '|', $line, 2 ) );
			$id       = isset( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
			$template = isset( $parts[1] ) ? self::sanitize_tracking_template( $parts[1] ) : '';

			if ( '' !== $id && '' !== $template && self::template_has_placeholder( $template ) ) {
				$templates[ $id ] = $template;
			}
		}

		return $templates;
	}

	private static function sanitize_tracking_template( $template ) {
		$template = trim( sanitize_text_field( (string) $template ) );

		return preg_match( '#^https?://#i', $template ) ? $template : '';
	}

	private static function template_has_placeholder( $template ) {
		return false !== strpos( $template, '{tracking_code}' ) || false !== strpos( $template, '%1$s' ) || false !== strpos( $template, '%s' );
	}

	public static function get_timeline_statuses() {
		return [
			'created'             => [ 'label' => __( 'Shipment prepared', 'yoohw-vietnam-store-tools' ), 'tone' => 'neutral' ],
			'picked_up'           => [ 'label' => __( 'Picked up by carrier', 'yoohw-vietnam-store-tools' ), 'tone' => 'info' ],
			'in_transit'          => [ 'label' => __( 'In transit', 'yoohw-vietnam-store-tools' ), 'tone' => 'info' ],
			'out_for_delivery'    => [ 'label' => __( 'Out for delivery', 'yoohw-vietnam-store-tools' ), 'tone' => 'warning' ],
			'delivered'           => [ 'label' => __( 'Delivered', 'yoohw-vietnam-store-tools' ), 'tone' => 'success' ],
			'delivery_failed'     => [ 'label' => __( 'Delivery failed', 'yoohw-vietnam-store-tools' ), 'tone' => 'danger' ],
			'returned_to_sender'  => [ 'label' => __( 'Returned to sender', 'yoohw-vietnam-store-tools' ), 'tone' => 'danger' ],
		];
	}

	public static function get_timeline( $order ) {
		$order = wc_get_order( $order );

		if ( ! $order instanceof WC_Order ) {
			return [];
		}

		$stored   = $order->get_meta( self::META_TIMELINE, true );
		$events   = [];
		$statuses = self::get_timeline_statuses();

		foreach ( is_array( $stored ) ? $stored : [] as $event ) {
			$status = isset( $event['status'] ) ? sanitize_key( $event['status'] ) : '';

			if ( ! isset( $statuses[ $status ] ) ) {
				continue;
			}

			$events[] = [
				'id'          => isset( $event['id'] ) ? sanitize_text_field( $event['id'] ) : wp_generate_uuid4(),
				'status'      => $status,
				'location'    => isset( $event['location'] ) ? sanitize_text_field( $event['location'] ) : '',
				'note'        => isset( $event['note'] ) ? sanitize_textarea_field( $event['note'] ) : '',
				'occurred_at' => isset( $event['occurred_at'] ) ? sanitize_text_field( $event['occurred_at'] ) : '',
				'created_at'  => isset( $event['created_at'] ) ? sanitize_text_field( $event['created_at'] ) : '',
				'user_id'     => isset( $event['user_id'] ) ? absint( $event['user_id'] ) : 0,
			];
		}

		usort(
			$events,
			static function ( $left, $right ) {
				return strcmp( $left['occurred_at'], $right['occurred_at'] );
			}
		);

		return $events;
	}

	public static function add_timeline_event( $order, $event_data ) {
		$order    = wc_get_order( $order );
		$statuses = self::get_timeline_statuses();
		$status   = isset( $event_data['status'] ) ? sanitize_key( $event_data['status'] ) : '';

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_invalid_tracking_order', __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( ! isset( $statuses[ $status ] ) ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_invalid_tracking_status', __( 'Select a valid shipment status.', 'yoohw-vietnam-store-tools' ) );
		}

		$occurred_at = self::normalize_event_datetime( isset( $event_data['occurred_at'] ) ? $event_data['occurred_at'] : '' );

		if ( '' === $occurred_at ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_invalid_tracking_date', __( 'Enter a valid shipment event date.', 'yoohw-vietnam-store-tools' ) );
		}

		$events   = self::get_timeline( $order );
		$events[] = [
			'id'          => wp_generate_uuid4(),
			'status'      => $status,
			'location'    => isset( $event_data['location'] ) ? sanitize_text_field( $event_data['location'] ) : '',
			'note'        => isset( $event_data['note'] ) ? sanitize_textarea_field( $event_data['note'] ) : '',
			'occurred_at' => $occurred_at,
			'created_at'  => gmdate( 'c' ),
			'user_id'     => get_current_user_id(),
		];

		usort(
			$events,
			static function ( $left, $right ) {
				return strcmp( $left['occurred_at'], $right['occurred_at'] );
			}
		);

		$order->update_meta_data( self::META_TIMELINE, $events );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS_ID, $status );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS, $statuses[ $status ]['label'] );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_LAST_SYNCED, gmdate( 'c' ) );
		$order->save();

		do_action( 'yoohw_vietnam_store_tools_tracking_timeline_updated', $order, $events, 'add' );

		return true;
	}

	public static function delete_timeline_event( $order, $event_id ) {
		$order    = wc_get_order( $order );
		$event_id = sanitize_text_field( (string) $event_id );

		if ( ! $order instanceof WC_Order || '' === $event_id ) {
			return false;
		}

		$events = array_values(
			array_filter(
				self::get_timeline( $order ),
				static function ( $event ) use ( $event_id ) {
					return $event_id !== $event['id'];
				}
			)
		);

		$order->update_meta_data( self::META_TIMELINE, $events );

		if ( $events ) {
			$latest   = end( $events );
			$statuses = self::get_timeline_statuses();
			$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS_ID, $latest['status'] );
			$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS, $statuses[ $latest['status'] ]['label'] );
		} else {
			$order->delete_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS_ID );
			$order->delete_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS );
		}

		$order->save();
		do_action( 'yoohw_vietnam_store_tools_tracking_timeline_updated', $order, $events, 'delete' );

		return true;
	}

	private static function normalize_event_datetime( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return gmdate( 'c' );
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );

		if ( false === $date ) {
			return '';
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' );
	}

	public function render_admin_timeline( $order, $shipping_data = [] ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! empty( Yoohw_Vietnam_Store_Tools_Shipping::get_providers() ) ) {
			return;
		}

		$shipping_data      = is_array( $shipping_data ) ? $shipping_data : [];
		$tracking_code      = trim( (string) ( $shipping_data['tracking_code'] ?? '' ) );
		$shipment_status_id = sanitize_key( $shipping_data['status_id'] ?? '' );
		$shipment_status    = sanitize_key( $shipping_data['status'] ?? '' );

		if ( '' === $tracking_code || ( 'manual' !== $shipment_status_id && 'manual' !== $shipment_status ) ) {
			return;
		}

		$events   = self::get_timeline( $order );
		$statuses = self::get_timeline_statuses();
		$nonce    = wp_create_nonce( 'yoohw_vietnam_store_tools_tracking_timeline_' . $order->get_id() );
		?>
		<section id="yoohw-vietnam-store-tools-tracking-timeline" class="vck-admin-tracking-timeline">
			<h4><?php esc_html_e( 'Manual tracking timeline', 'yoohw-vietnam-store-tools' ); ?></h4>
			<?php if ( $events ) : ?>
				<ol class="vck-admin-tracking-timeline__events">
					<?php foreach ( array_reverse( $events ) as $event ) : ?>
						<li class="vck-admin-tracking-timeline__event vck-admin-tracking-timeline__event--<?php echo esc_attr( $statuses[ $event['status'] ]['tone'] ); ?>">
							<div class="vck-admin-tracking-timeline__event-heading">
								<strong><?php echo esc_html( $statuses[ $event['status'] ]['label'] ); ?></strong>
								<button type="button" class="button-link-delete" data-vck-delete-tracking-event="<?php echo esc_attr( $event['id'] ); ?>" aria-label="<?php esc_attr_e( 'Delete timeline event', 'yoohw-vietnam-store-tools' ); ?>"><?php esc_html_e( 'Delete', 'yoohw-vietnam-store-tools' ); ?></button>
							</div>
							<time datetime="<?php echo esc_attr( $event['occurred_at'] ); ?>"><?php echo esc_html( self::format_event_datetime( $event['occurred_at'] ) ); ?></time>
							<?php if ( '' !== $event['location'] ) : ?><span><?php echo esc_html( $event['location'] ); ?></span><?php endif; ?>
							<?php if ( '' !== $event['note'] ) : ?><p><?php echo nl2br( esc_html( $event['note'] ) ); ?></p><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No manual tracking events have been added.', 'yoohw-vietnam-store-tools' ); ?></p>
			<?php endif; ?>

			<div class="vck-admin-tracking-timeline__fields" data-vck-tracking-timeline-form>
				<p><label for="vck_tracking_event_status_<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Shipment status', 'yoohw-vietnam-store-tools' ); ?></label>
				<select id="vck_tracking_event_status_<?php echo esc_attr( $order->get_id() ); ?>" class="widefat" data-vck-tracking-field="status">
					<?php foreach ( $statuses as $status_id => $status ) : ?>
						<option value="<?php echo esc_attr( $status_id ); ?>"><?php echo esc_html( $status['label'] ); ?></option>
					<?php endforeach; ?>
				</select></p>
				<p><label for="vck_tracking_event_date_<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Event date and time', 'yoohw-vietnam-store-tools' ); ?></label>
				<input id="vck_tracking_event_date_<?php echo esc_attr( $order->get_id() ); ?>" class="widefat" type="datetime-local" value="<?php echo esc_attr( current_time( 'Y-m-d\TH:i' ) ); ?>" data-vck-tracking-field="occurred_at"></p>
				<p><label for="vck_tracking_event_location_<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Location', 'yoohw-vietnam-store-tools' ); ?></label>
				<input id="vck_tracking_event_location_<?php echo esc_attr( $order->get_id() ); ?>" class="widefat" type="text" maxlength="200" data-vck-tracking-field="location"></p>
				<p><label for="vck_tracking_event_note_<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Customer-facing note', 'yoohw-vietnam-store-tools' ); ?></label>
				<textarea id="vck_tracking_event_note_<?php echo esc_attr( $order->get_id() ); ?>" class="widefat" rows="2" maxlength="1000" data-vck-tracking-field="note"></textarea></p>
				<button type="button" class="button" data-vck-add-tracking-event data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Add timeline event', 'yoohw-vietnam-store-tools' ); ?></button>
			</div>
		</section>
		<?php
	}

	public function handle_add_timeline_event() {
		$order = $this->get_admin_timeline_order();

		$result = self::add_timeline_event(
			$order,
			[
				'status'      => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'status' ),
				'occurred_at' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'occurred_at' ),
				'location'    => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'location' ),
				'note'        => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'note' ),
			]
		);

		$this->redirect_to_timeline( $order, is_wp_error( $result ) ? 'error' : 'added', is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	public function handle_delete_timeline_event() {
		$order    = $this->get_admin_timeline_order();
		$event_id = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'event_id' );
		$deleted  = self::delete_timeline_event( $order, $event_id );

		$this->redirect_to_timeline( $order, $deleted ? 'deleted' : 'error', $deleted ? '' : __( 'The timeline event could not be deleted.', 'yoohw-vietnam-store-tools' ) );
	}

	private function get_admin_timeline_order() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage shipments.', 'yoohw-vietnam-store-tools' ) );
		}

		$order_id = absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'order_id' ) );

		if ( ! $order_id || ! check_admin_referer( 'yoohw_vietnam_store_tools_tracking_timeline_' . $order_id, 'nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'yoohw-vietnam-store-tools' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Could not load order.', 'yoohw-vietnam-store-tools' ) );
		}

		return $order;
	}

	private function redirect_to_timeline( $order, $notice, $error = '' ) {
		$url = add_query_arg( 'vck_tracking_notice', sanitize_key( $notice ), $order->get_edit_order_url() );

		if ( '' !== $error ) {
			$url = add_query_arg( 'vck_tracking_error', $error, $url );
		}

		wp_safe_redirect( $url . '#yoohw-vietnam-store-tools-tracking-timeline' );
		exit;
	}

	public function render_admin_notice() {
		$notice = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'vck_tracking_notice' ) );

		if ( '' === $notice ) {
			return;
		}

		$messages = [
			'added'   => __( 'Tracking timeline event added.', 'yoohw-vietnam-store-tools' ),
			'deleted' => __( 'Tracking timeline event deleted.', 'yoohw-vietnam-store-tools' ),
		];
		$type     = isset( $messages[ $notice ] ) ? 'success' : 'error';
		$message  = isset( $messages[ $notice ] ) ? $messages[ $notice ] : Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'vck_tracking_error' );

		if ( '' !== $message ) {
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	public function enqueue_admin_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}

		$style_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/css/admin/shipment-tracking.css';
		$script_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/js/admin/shipment-tracking.js';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style( 'yoohw-vietnam-store-tools-shipment-tracking-admin', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/admin/shipment-tracking.css', [], filemtime( $style_path ) );
		}

		if ( file_exists( $script_path ) ) {
			wp_enqueue_script( 'yoohw-vietnam-store-tools-shipment-tracking-admin', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/shipment-tracking.js', [], filemtime( $script_path ), true );
			wp_localize_script(
				'yoohw-vietnam-store-tools-shipment-tracking-admin',
				'yoohwVietnamStoreToolsShipmentTracking',
				[
					'adminPostUrl' => admin_url( 'admin-post.php' ),
					'confirmDelete' => __( 'Delete this tracking timeline event?', 'yoohw-vietnam-store-tools' ),
				]
			);
		}
	}

	public function render_order_timeline( $order_id ) {
		if ( ! Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_CUSTOMER_SHIPMENT_DISPLAY ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			echo $this->get_timeline_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by renderer.
		}
	}

	private function get_timeline_html( $order ) {
		$events = self::get_timeline( $order );

		if ( ! $events ) {
			return '';
		}

		$this->enqueue_frontend_style();
		$statuses = self::get_timeline_statuses();
		ob_start();
		?>
		<section class="vck-tracking-timeline" aria-labelledby="vck-tracking-timeline-title-<?php echo esc_attr( $order->get_id() ); ?>">
			<h2 id="vck-tracking-timeline-title-<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Shipment journey', 'yoohw-vietnam-store-tools' ); ?></h2>
			<ol>
				<?php foreach ( array_reverse( $events ) as $event ) : ?>
					<li class="vck-tracking-timeline__event vck-tracking-timeline__event--<?php echo esc_attr( $statuses[ $event['status'] ]['tone'] ); ?>">
						<div class="vck-tracking-timeline__marker" aria-hidden="true"></div>
						<div class="vck-tracking-timeline__content">
							<strong><?php echo esc_html( $statuses[ $event['status'] ]['label'] ); ?></strong>
							<time datetime="<?php echo esc_attr( $event['occurred_at'] ); ?>"><?php echo esc_html( self::format_event_datetime( $event['occurred_at'] ) ); ?></time>
							<?php if ( '' !== $event['location'] ) : ?><span><?php echo esc_html( $event['location'] ); ?></span><?php endif; ?>
							<?php if ( '' !== $event['note'] ) : ?><p><?php echo nl2br( esc_html( $event['note'] ) ); ?></p><?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php

		return ob_get_clean();
	}

	private static function format_event_datetime( $value ) {
		$timestamp = strtotime( (string) $value );

		return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '';
	}

	public function register_lookup_tools() {
		add_shortcode( self::SHORTCODE, [ $this, 'render_lookup_shortcode' ] );

		$block_dir = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'blocks/order-tracking';

		if ( function_exists( 'register_block_type' ) && file_exists( $block_dir . '/block.json' ) ) {
			$block = register_block_type(
				$block_dir,
				[
					'title'           => __( 'Vietnam order tracking', 'yoohw-vietnam-store-tools' ),
					'description'     => __( 'Customers enter an order number and their billing email or phone number to view shipment tracking.', 'yoohw-vietnam-store-tools' ),
					'render_callback' => [ $this, 'render_lookup_block' ],
				]
			);

			if ( $block instanceof WP_Block_Type && ! empty( $block->editor_script_handles ) ) {
				foreach ( $block->editor_script_handles as $script_handle ) {
					wp_set_script_translations( $script_handle, 'yoohw-vietnam-store-tools', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'languages' );
				}
			}
		}
	}

	public function render_lookup_shortcode() {
		return $this->get_lookup_html();
	}

	public function render_lookup_block() {
		return $this->get_lookup_html();
	}

	private function get_lookup_html() {
		$this->enqueue_frontend_style();

		if ( 'yes' !== get_option( self::OPTION_LOOKUP_ENABLED, 'yes' ) ) {
			return '<div class="woocommerce-info">' . esc_html__( 'Public order lookup is currently disabled.', 'yoohw-vietnam-store-tools' ) . '</div>';
		}

		$order        = null;
		$error        = '';
		$order_number = '';
		$contact      = '';

		if ( isset( $_POST['vck_order_lookup_submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
			$order_number = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_order_number' );
			$contact      = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_order_contact' );
			$honeypot     = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_order_website' );
			$nonce        = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_order_lookup_nonce' );

			if ( '' !== $honeypot || ! wp_verify_nonce( $nonce, 'yoohw_vietnam_store_tools_order_lookup' ) ) {
				$error = __( 'The order could not be verified. Please try again.', 'yoohw-vietnam-store-tools' );
			} else {
				$order = $this->find_lookup_order( $order_number, $contact );

				if ( ! $order ) {
					$error = __( 'No matching order was found. Check the order number and email or phone number.', 'yoohw-vietnam-store-tools' );
				}
			}
		}

		ob_start();
		?>
		<div class="vck-order-lookup">
			<form class="vck-order-lookup__form" method="post">
				<h2><?php esc_html_e( 'Track your order', 'yoohw-vietnam-store-tools' ); ?></h2>
				<p><?php esc_html_e( 'Enter the order number and the billing email or phone number used for the order.', 'yoohw-vietnam-store-tools' ); ?></p>
				<?php if ( '' !== $error ) : ?><div class="woocommerce-error" role="alert"><?php echo esc_html( $error ); ?></div><?php endif; ?>
				<div class="vck-order-lookup__fields">
					<p><label for="vck_order_number"><?php esc_html_e( 'Order number', 'yoohw-vietnam-store-tools' ); ?></label><input id="vck_order_number" name="vck_order_number" type="text" value="<?php echo esc_attr( $order_number ); ?>" autocomplete="off" required></p>
					<p><label for="vck_order_contact"><?php esc_html_e( 'Email or phone number', 'yoohw-vietnam-store-tools' ); ?></label><input id="vck_order_contact" name="vck_order_contact" type="text" value="<?php echo esc_attr( $contact ); ?>" autocomplete="email" required></p>
				</div>
				<p class="vck-order-lookup__honeypot" aria-hidden="true"><label for="vck_order_website"><?php esc_html_e( 'Website', 'yoohw-vietnam-store-tools' ); ?></label><input id="vck_order_website" name="vck_order_website" type="text" tabindex="-1" autocomplete="off"></p>
				<?php wp_nonce_field( 'yoohw_vietnam_store_tools_order_lookup', 'vck_order_lookup_nonce' ); ?>
				<button class="button alt" type="submit" name="vck_order_lookup_submit" value="1"><?php esc_html_e( 'Look up order', 'yoohw-vietnam-store-tools' ); ?></button>
			</form>
			<?php if ( $order instanceof WC_Order ) : ?>
				<?php echo $this->get_lookup_result_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by renderer. ?>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}

	private function find_lookup_order( $order_number, $contact ) {
		$order_number = ltrim( trim( sanitize_text_field( (string) $order_number ) ), '#' );
		$contact      = trim( sanitize_text_field( (string) $contact ) );

		if ( '' === $order_number || '' === $contact ) {
			return null;
		}

		$order = ctype_digit( $order_number ) ? wc_get_order( absint( $order_number ) ) : null;
		$order = apply_filters( 'yoohw_vietnam_store_tools_tracking_lookup_order', $order, $order_number );

		if ( ! $order instanceof WC_Order || ! hash_equals( (string) $order->get_order_number(), $order_number ) || ! $this->order_contact_matches( $order, $contact ) ) {
			return null;
		}

		return $order;
	}

	private function order_contact_matches( $order, $contact ) {
		if ( false !== strpos( $contact, '@' ) ) {
			$submitted_email = strtolower( sanitize_email( $contact ) );
			$billing_email   = strtolower( sanitize_email( $order->get_billing_email() ) );

			return '' !== $submitted_email && '' !== $billing_email && hash_equals( $billing_email, $submitted_email );
		}

		$submitted = Yoohw_Vietnam_Store_Tools_Phone_Normalization::normalize_phone_number( $contact );
		$submitted = $submitted['valid'] ? $submitted['e164'] : Yoohw_Vietnam_Store_Tools_Phone_Normalization::sanitize_phone_storage_value( $contact );
		$phones    = [
			$order->get_billing_phone(),
			method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : $order->get_meta( '_shipping_phone', true ),
			$order->get_meta( '_billing_phone_e164', true ),
			$order->get_meta( '_shipping_phone_e164', true ),
		];

		foreach ( array_filter( array_map( 'strval', $phones ) ) as $phone ) {
			$normalized = Yoohw_Vietnam_Store_Tools_Phone_Normalization::normalize_phone_number( $phone );
			$normalized = $normalized['valid'] ? $normalized['e164'] : Yoohw_Vietnam_Store_Tools_Phone_Normalization::sanitize_phone_storage_value( $phone );

			if ( '' !== $submitted && '' !== $normalized && hash_equals( $normalized, $submitted ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_lookup_result_html( $order ) {
		$shipping              = Yoohw_Vietnam_Store_Tools_Shipping::get_order_shipping_data( $order );
		$display_tracking_code = Yoohw_Vietnam_Store_Tools_Shipping::get_display_tracking_code( $shipping['provider'], $shipping['tracking_code'] );
		$rows                  = [
			__( 'Order number', 'yoohw-vietnam-store-tools' )     => '#' . $order->get_order_number(),
			__( 'Order date', 'yoohw-vietnam-store-tools' )       => $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '',
			__( 'Order status', 'yoohw-vietnam-store-tools' )     => wc_get_order_status_name( $order->get_status() ),
			__( 'Shipping provider', 'yoohw-vietnam-store-tools' ) => $shipping['provider_name'],
			__( 'Tracking code', 'yoohw-vietnam-store-tools' )    => $display_tracking_code,
			__( 'Shipping status', 'yoohw-vietnam-store-tools' )  => $shipping['status'],
		];

		ob_start();
		?>
		<section class="vck-order-lookup__result">
			<h2><?php esc_html_e( 'Order tracking information', 'yoohw-vietnam-store-tools' ); ?></h2>
			<dl>
				<?php foreach ( $rows as $label => $value ) : ?>
					<?php if ( '' !== trim( (string) $value ) ) : ?><div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div><?php endif; ?>
				<?php endforeach; ?>
			</dl>
			<?php if ( '' !== $shipping['tracking_url'] && '' !== $shipping['tracking_code'] ) : ?><p><a class="button" href="<?php echo esc_url( $shipping['tracking_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Track on carrier website', 'yoohw-vietnam-store-tools' ); ?></a></p><?php endif; ?>
			<?php echo $this->get_timeline_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by renderer. ?>
		</section>
		<?php

		return ob_get_clean();
	}

	private function enqueue_frontend_style() {
		$style_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/css/shipment-tracking.css';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style( 'yoohw-vietnam-store-tools-shipment-tracking', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/shipment-tracking.css', [], filemtime( $style_path ) );
		}
	}
}
