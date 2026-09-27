<?php
/**
 * Current shipment identity and timeline bindings on WooCommerce orders.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Shipment_Identity {
	const META_CURRENT_ID = '_yoohw_vietnam_store_tools_current_shipment_id';
	const META_LEGACY_ID = '_yoohw_vietnam_store_tools_legacy_shipment_id';
	const META_CLOSED_ID = '_yoohw_vietnam_store_tools_closed_shipment_id';
	const META_EVENT_BINDINGS = '_yoohw_vietnam_store_tools_tracking_event_shipments';

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
		$closed_id = (string) $order->get_meta( self::META_CLOSED_ID, true );
		// Old cancelled shipments have no lifecycle marker; their shipping status is enough to prevent reopening.
		$closed = '' !== $id && ( $id === $closed_id || ( isset( $data['status_id'] ) && 'cancelled' === $data['status_id'] ) );
		return [ 'id' => $id, 'closed' => $closed, 'data' => $data ];
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

	public static function close_current( $order, $expected_id ) {
		$order = self::order( $order );
		if ( ! $order ) {
			return self::error( 'invalid_order' );
		}
		self::refresh_order( $order );
		$current = self::get_current_shipment( $order );
		$materialized_legacy = 'legacy:' . $order->get_id() === $expected_id
			&& $current['id'] === (string) $order->get_meta( self::META_LEGACY_ID, true );
		if ( '' === (string) $expected_id || ( $expected_id !== $current['id'] && ! $materialized_legacy ) || $current['id'] === (string) $order->get_meta( self::META_CLOSED_ID, true ) ) {
			return self::error( 'stale_shipment' );
		}
		$id = $current['id'];
		if ( 0 === strpos( $id, 'legacy:' ) ) {
			$id = wp_generate_uuid4();
			$order->update_meta_data( self::META_CURRENT_ID, $id );
			$order->update_meta_data( self::META_LEGACY_ID, $id );
		}
		$order->update_meta_data( self::META_CLOSED_ID, $id );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS_ID, 'cancelled' );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_STATUS, __( 'Cancelled', 'yoohw-vietnam-store-tools' ) );
		$order->save();
		return $id;
	}

	public static function replace_shipment( $order, $new_provider, $new_data, $context = [] ) {
		$order = self::order( $order );
		if ( ! $order || ! is_array( $new_data ) ) {
			return self::error( 'invalid_order' );
		}
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return self::error( 'forbidden' );
		}
		self::refresh_order( $order );
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
		$order->delete_meta_data( self::META_CLOSED_ID );
		$order->save();
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
		return new WP_Error( 'yoohw_vietnam_store_tools_shipment_' . $code, __( 'Shipment is no longer current.', 'yoohw-vietnam-store-tools' ) );
	}
}
