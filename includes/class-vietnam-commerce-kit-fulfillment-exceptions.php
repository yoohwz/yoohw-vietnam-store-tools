<?php
/**
 * Shipment identity and operational exceptions on WooCommerce orders.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions {
	const META_CURRENT_ID   = '_yoohw_vietnam_store_tools_current_shipment_id';
	const META_LEGACY_ID    = '_yoohw_vietnam_store_tools_legacy_shipment_id';
	const META_HISTORY      = '_yoohw_vietnam_store_tools_shipment_exception_history';
	const META_EVENT_BINDINGS = '_yoohw_vietnam_store_tools_tracking_event_shipments';

	public static function get_exception_types() {
		return [ 'failed_handoff', 'delivery_failed', 'cancelled', 'returned_to_sender', 'replaced' ];
	}

	public static function get_current_shipment( $order ) {
		$order = self::order( $order );
		if ( ! $order ) {
			return [];
		}
		$data = Yoohw_Vietnam_Store_Tools_Shipping::get_order_shipping_data( $order );
		$id = (string) $order->get_meta( self::META_CURRENT_ID, true );
		$has_legacy_shipment = '' !== (string) $order->get_meta( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, true )
			&& ( '' !== (string) $order->get_meta( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE, true )
				|| '' !== (string) $order->get_meta( Yoohw_Vietnam_Store_Tools_Shipping::META_LABEL_ID, true )
				|| '' !== (string) $order->get_meta( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_ID, true ) );
		if ( '' === $id && $has_legacy_shipment ) {
			$id = 'legacy:' . $order->get_id();
		}
		return [ 'id' => $id, 'closed' => self::is_closed( $order, $id ), 'data' => $data ];
	}

	public static function get_exceptions( $order ) {
		$order = self::order( $order );
		$history = $order ? $order->get_meta( self::META_HISTORY, true ) : [];
		return is_array( $history ) ? array_values( $history ) : [];
	}

	public static function ensure_current_id( $order ) {
		$order = self::order( $order );
		$current = self::get_current_shipment( $order );
		if ( ! $order || empty( $current['id'] ) || $current['closed'] ) {
			return self::error( 'no_active_shipment' );
		}
		if ( 0 === strpos( $current['id'], 'legacy:' ) ) {
			$id = wp_generate_uuid4();
			$order->update_meta_data( self::META_CURRENT_ID, $id );
			$order->update_meta_data( self::META_LEGACY_ID, $id );
			$order->save();
			return $id;
		}
		return $current['id'];
	}

	public static function record_exception( $order, $data, $context = [] ) {
		$order = self::order( $order );
		if ( ! $order || ! is_array( $data ) ) {
			return self::error( 'invalid_order' );
		}
		self::refresh_order( $order );
		$type = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '';
		if ( ! in_array( $type, self::get_exception_types(), true ) || 'replaced' === $type ) {
			return self::error( 'invalid_type' );
		}
		$check = self::authorize( $order, $context, $data );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$current = self::get_current_shipment( $order );
		$expected = isset( $data['expected_shipment_id'] ) ? sanitize_text_field( $data['expected_shipment_id'] ) : '';
		if ( '' === $expected || $expected !== $current['id'] || $current['closed'] ) {
			return self::error( 'stale_shipment' );
		}
		$id = self::ensure_current_id( $order );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$entry = self::entry( $order, $type, $id, $current['data'], $context );
		if ( 'cancelled' === $type ) {
			$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS_ID, 'cancelled' );
			$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS, __( 'Cancelled', 'yoohw-vietnam-store-tools' ) );
		}
		self::append( $order, $entry );
		return $entry;
	}

	public static function replace_shipment( $order, $new_provider, $new_data, $context = [] ) {
		$order = self::order( $order );
		if ( ! $order || ! is_array( $new_data ) ) {
			return self::error( 'invalid_order' );
		}
		self::refresh_order( $order );
		$check = self::authorize( $order, $context, [ 'provider' => $new_provider, 'data' => $new_data, 'type' => 'replaced' ] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$provider_id = is_array( $new_provider ) && isset( $new_provider['id'] ) ? $new_provider['id'] : $new_provider;
		$registered_provider = Yoohw_Vietnam_Store_Tools_Shipping::get_provider( $provider_id );
		if ( ! $registered_provider ) {
			return self::error( 'invalid_provider' );
		}
		$current = self::get_current_shipment( $order );
		$expected = isset( $context['expected_shipment_id'] ) ? sanitize_text_field( $context['expected_shipment_id'] ) : '';
		if ( '' === $expected || $expected !== $current['id'] ) {
			return self::error( 'stale_shipment' );
		}
		$previous_id = 0 === strpos( $current['id'], 'legacy:' ) ? wp_generate_uuid4() : $current['id'];
		$new_id = wp_generate_uuid4();
		unset( $new_data['provider'], $new_data['provider_name'] );
		$new_data = array_merge( [ 'tracking_code' => '', 'label_id' => '', 'tracking_id' => '', 'status_id' => '', 'status' => '', 'tracking_url' => '', 'raw_response' => [] ], $new_data );
		$result = Yoohw_Vietnam_Store_Tools_Shipping::update_order_shipping_data( $order, $registered_provider, $new_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 0 === strpos( $current['id'], 'legacy:' ) ) {
			$order->update_meta_data( self::META_LEGACY_ID, $previous_id );
		}
		$order->update_meta_data( self::META_CURRENT_ID, $new_id );
		$entry = self::entry( $order, 'replaced', $previous_id, $current['data'], $context );
		$entry['replacement_shipment_id'] = $new_id;
		$entry['parent_shipment_id'] = $previous_id;
		self::append( $order, $entry );
		return [ 'id' => $new_id, 'parent_id' => $previous_id ];
	}

	public static function assert_current( $order, $expected_id ) {
		$order = self::order( $order );
		if ( ! $order ) {
			return self::error( 'invalid_order' );
		}
		self::refresh_order( $order );
		$current = self::get_current_shipment( $order );
		return ! empty( $current ) && '' !== (string) $expected_id && $expected_id === $current['id'] && ! $current['closed'] ? true : self::error( 'stale_shipment' );
	}

	public static function bind_timeline_event( $order, $event_id, $shipment_id ) {
		$order = self::order( $order );
		if ( ! $order ) {
			return;
		}
		$bindings = $order->get_meta( self::META_EVENT_BINDINGS, true );
		$bindings = is_array( $bindings ) ? $bindings : [];
		$bindings[ $event_id ] = $shipment_id;
		$order->update_meta_data( self::META_EVENT_BINDINGS, $bindings );
		$order->save();
	}

	public static function timeline_event_is_current( $order, $event_id ) {
		$order = self::order( $order );
		$current = self::get_current_shipment( $order );
		if ( ! $order ) {
			return false;
		}
		if ( empty( $current['id'] ) ) {
			return true;
		}
		if ( $current['closed'] ) {
			return false;
		}
		$bindings = $order->get_meta( self::META_EVENT_BINDINGS, true );
		$bindings = is_array( $bindings ) ? $bindings : [];
		if ( isset( $bindings[ $event_id ] ) ) {
			return $current['id'] === $bindings[ $event_id ];
		}
		$legacy_id = (string) $order->get_meta( self::META_LEGACY_ID, true );
		return 0 === strpos( $current['id'], 'legacy:' ) || ( '' !== $legacy_id && $current['id'] === $legacy_id );
	}

	private static function authorize( $order, $context, $payload ) {
		$source = isset( $context['source_id'] ) ? sanitize_key( $context['source_id'] ) : 'manual';
		if ( 'manual' === $source ) {
			return current_user_can( 'edit_shop_order', $order->get_id() ) ? true : self::error( 'forbidden' );
		}
		$sources = apply_filters( 'yoohw_vietnam_store_tools_shipment_exception_sources', [] );
		if ( ! is_array( $sources ) || ! isset( $sources[ $source ] ) || ! is_callable( $sources[ $source ] ) ) {
			return self::error( 'unregistered_source' );
		}
		return call_user_func( $sources[ $source ], $order, $payload, $context ) === true ? true : self::error( 'invalid_source_evidence' );
	}

	private static function entry( $order, $type, $shipment_id, $data, $context ) {
		$source = isset( $context['source_id'] ) ? sanitize_key( $context['source_id'] ) : 'manual';
		return [
			'id' => wp_generate_uuid4(),
			'shipment_id' => $shipment_id,
			'type' => $type,
			'provider' => $data['provider'],
			'tracking_code' => $data['tracking_code'],
			'occurred_at' => gmdate( 'c' ),
			'actor_id' => 'manual' === $source ? get_current_user_id() : 0,
			'source_id' => $source,
			'note' => isset( $context['note'] ) ? sanitize_textarea_field( $context['note'] ) : '',
			'parent_shipment_id' => '',
			'replacement_shipment_id' => '',
		];
	}

	private static function append( $order, $entry ) {
		$history = self::get_exceptions( $order );
		$history[] = $entry;
		$order->update_meta_data( self::META_HISTORY, $history );
		$order->save();
		do_action( 'yoohw_vietnam_store_tools_shipment_exception_recorded', $order, $entry );
	}

	private static function is_closed( $order, $id ) {
		if ( '' === $id ) {
			return false;
		}
		foreach ( self::get_exceptions( $order ) as $entry ) {
			if ( $id === $entry['shipment_id'] && in_array( $entry['type'], [ 'cancelled', 'replaced' ], true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function order( $order ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order );
		return $order instanceof WC_Order ? $order : false;
	}

	/** Discard cached order meta before checking a persisted shipment identity. */
	public static function refresh_order( $order ) {
		if ( $order instanceof WC_Order && method_exists( $order, 'read_meta_data' ) ) {
			$order->read_meta_data( true );
		}
	}

	private static function error( $code ) {
		return new WP_Error( 'yoohw_vietnam_store_tools_shipment_' . $code, __( 'Shipment exception could not be recorded.', 'yoohw-vietnam-store-tools' ) );
	}
}
