<?php
/**
 * Internal, manual Returns Lite event ledger for WooCommerce orders.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Returns_Lite {
	const META_LEDGER = '_yoohw_vietnam_store_tools_returns_lite';
	const SCHEMA = 1;
	const LEASE_SECONDS = 120;

	public static function get_ledger( $order ) {
		$order = self::order( $order );
		$stored = $order ? $order->get_meta( self::META_LEDGER, true ) : null;
		if ( ! is_array( $stored ) || ! isset( $stored['schema'], $stored['revision'], $stored['events'] ) || self::SCHEMA !== (int) $stored['schema'] || ! is_array( $stored['events'] ) ) {
			return [ 'schema' => self::SCHEMA, 'revision' => 0, 'events' => [] ];
		}
		return $stored;
	}

	public static function get_returns( $order ) {
		$records = [];
		foreach ( self::get_ledger( $order )['events'] as $event ) {
			if ( ! is_array( $event ) || empty( $event['return_id'] ) || ! isset( $event['changes'] ) || ! is_array( $event['changes'] ) ) {
				continue;
			}
			$id = $event['return_id'];
			$records[ $id ] = array_merge( isset( $records[ $id ] ) ? $records[ $id ] : [ 'id' => $id ], $event['changes'] );
			$records[ $id ]['revision'] = isset( $event['return_revision'] ) ? (int) $event['return_revision'] : 0;
			$records[ $id ]['updated_at'] = isset( $event['occurred_at'] ) ? $event['occurred_at'] : '';
			$records[ $id ]['updated_by'] = isset( $event['actor_id'] ) ? (int) $event['actor_id'] : 0;
		}
		return array_values( $records );
	}

	public static function create( $order, $items, $data, $expected_ledger_revision ) {
		return self::write( $order, '', 0, 'create', array_merge( (array) $data, [ 'items' => $items ] ), $expected_ledger_revision );
	}

	public static function mutate( $order, $return_id, $expected_revision, $kind, $data = [] ) {
		return self::write( $order, $return_id, $expected_revision, $kind, $data, null );
	}

	private static function write( $order, $return_id, $expected_revision, $kind, $data, $expected_ledger_revision ) {
		$order = self::order( $order );
		if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return self::error( 'forbidden', __( 'You cannot edit returns for this order.', 'yoohw-vietnam-store-tools' ) );
		}
		$token = self::acquire_lock( $order->get_id() );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		try {
			// Never validate against the order object supplied by the caller.
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh instanceof WC_Order ) {
				return self::error( 'missing_order', __( 'Order could not be reloaded.', 'yoohw-vietnam-store-tools' ) );
			}
			if ( method_exists( $fresh, 'read_meta_data' ) ) {
				$fresh->read_meta_data( true );
			}
			$stored = $fresh->get_meta( self::META_LEDGER, true );
			if ( '' !== $stored && ( ! is_array( $stored ) || ! isset( $stored['schema'], $stored['revision'], $stored['events'] ) || self::SCHEMA !== (int) $stored['schema'] || ! is_array( $stored['events'] ) ) ) {
				return self::error( 'schema', __( 'Return history format is unsupported. No changes were saved.', 'yoohw-vietnam-store-tools' ) );
			}
			$ledger = self::get_ledger( $fresh );
			$records = self::get_returns( $fresh );
			$target = null;
			foreach ( $records as $record ) {
				if ( $return_id === $record['id'] ) {
					$target = $record;
					break;
				}
			}
			if ( 'create' === $kind ) {
				if ( null !== $expected_ledger_revision && (int) $expected_ledger_revision !== (int) $ledger['revision'] ) {
					return self::stale();
				}
				$return_id = wp_generate_uuid4();
				$changes = self::create_changes( $fresh, $records, $data );
				$return_revision = 1;
			} else {
				if ( ! $target || (int) $expected_revision !== (int) $target['revision'] || ! wp_is_uuid( $return_id ) ) {
					return self::stale();
				}
				$changes = self::mutation_changes( $fresh, $records, $target, $kind, (array) $data );
				$return_revision = (int) $target['revision'] + 1;
			}
			if ( is_wp_error( $changes ) ) {
				return $changes;
			}
			if ( ! self::owns_lock( $fresh->get_id(), $token ) ) {
				return self::error( 'lock_lost', __( 'Return edit lock expired. Reload and retry.', 'yoohw-vietnam-store-tools' ) );
			}
			$ledger['revision'] = (int) $ledger['revision'] + 1;
			$event = [
				'schema' => self::SCHEMA,
				'id' => wp_generate_uuid4(),
				'return_id' => $return_id,
				'kind' => $kind,
				'state' => isset( $changes['state'] ) ? $changes['state'] : ( $target ? $target['state'] : 'open' ),
				'ledger_revision' => $ledger['revision'],
				'return_revision' => $return_revision,
				'actor_id' => get_current_user_id(),
				'occurred_at' => gmdate( 'c' ),
				'changes' => $changes,
			];
			$ledger['events'][] = $event;
			$fresh->update_meta_data( self::META_LEDGER, $ledger );
			$fresh->save();
			return $event;
		} finally {
			self::release_lock( $order->get_id(), $token );
		}
	}

	private static function create_changes( $order, $records, $data ) {
		$items = self::validate_items( $order, $records, isset( $data['items'] ) ? $data['items'] : [], '' );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$reason = sanitize_text_field( isset( $data['reason'] ) ? $data['reason'] : '' );
		if ( '' === $reason ) {
			return self::error( 'reason', __( 'Enter a return reason.', 'yoohw-vietnam-store-tools' ) );
		}
		$shipment = Yoohw_Vietnam_Store_Tools_Shipment_Identity::get_current_shipment( $order );
		$shipping = isset( $shipment['data'] ) ? $shipment['data'] : [];
		return [
			'order_id' => $order->get_id(),
			'state' => 'open',
			'items' => $items,
			'reason' => $reason,
			'note' => sanitize_textarea_field( isset( $data['note'] ) ? $data['note'] : '' ),
			'created_at' => gmdate( 'c' ),
			'created_by' => get_current_user_id(),
			'refund_ids' => [],
			'outbound_shipment' => [
				'id' => isset( $shipment['id'] ) ? $shipment['id'] : '',
				'provider' => isset( $shipping['provider'] ) ? $shipping['provider'] : '',
				'tracking_code' => isset( $shipping['tracking_code'] ) ? $shipping['tracking_code'] : '',
			],
			'reverse_reference' => '',
		];
	}

	private static function mutation_changes( $order, $records, $target, $kind, $data ) {
		$state = $target['state'];
		if ( 'transition' === $kind ) {
			$next = isset( $data['state'] ) ? sanitize_key( $data['state'] ) : '';
			$allowed = [ 'open' => [ 'received', 'closed', 'cancelled' ], 'received' => [ 'closed', 'cancelled' ] ];
			if ( ! isset( $allowed[ $state ] ) || ! in_array( $next, $allowed[ $state ], true ) ) {
				return self::error( 'transition', __( 'This return state change is not allowed.', 'yoohw-vietnam-store-tools' ) );
			}
			return [ 'state' => $next, 'note' => sanitize_textarea_field( isset( $data['note'] ) ? $data['note'] : '' ) ];
		}
		if ( 'correct' === $kind ) {
			$changes = [];
			if ( array_key_exists( 'items', $data ) ) {
				if ( 'open' !== $state ) {
					return self::error( 'frozen', __( 'Items cannot be changed after this return leaves open state.', 'yoohw-vietnam-store-tools' ) );
				}
				$items = self::validate_items( $order, $records, $data['items'], $target['id'] );
				if ( is_wp_error( $items ) ) {
					return $items;
				}
				$changes['items'] = $items;
			}
			foreach ( [ 'reason' => 'sanitize_text_field', 'note' => 'sanitize_textarea_field', 'reverse_reference' => 'sanitize_text_field' ] as $field => $sanitize ) {
				if ( array_key_exists( $field, $data ) ) {
					$changes[ $field ] = call_user_func( $sanitize, $data[ $field ] );
					if ( 'reason' === $field && '' === $changes[ $field ] ) {
						return self::error( 'reason', __( 'Enter a return reason.', 'yoohw-vietnam-store-tools' ) );
					}
					if ( 'reverse_reference' === $field ) {
						$changes[ $field ] = substr( $changes[ $field ], 0, 200 );
					}
				}
			}
			return $changes ? $changes : self::error( 'empty', __( 'No return changes were submitted.', 'yoohw-vietnam-store-tools' ) );
		}
		if ( 'link_refund' === $kind || 'unlink_refund' === $kind ) {
			$id = isset( $data['refund_id'] ) ? absint( $data['refund_id'] ) : 0;
			if ( ! $id ) {
				return self::error( 'refund', __( 'Choose a WooCommerce refund.', 'yoohw-vietnam-store-tools' ) );
			}
			$ids = isset( $target['refund_ids'] ) ? array_map( 'absint', $target['refund_ids'] ) : [];
			if ( 'link_refund' === $kind ) {
				if ( in_array( $id, $ids, true ) ) {
					return self::error( 'refund', __( 'Refund is already linked to this return.', 'yoohw-vietnam-store-tools' ) );
				}
				$valid = false;
				foreach ( $order->get_refunds() as $refund ) {
					if ( $refund instanceof WC_Order_Refund && $id === $refund->get_id() && $order->get_id() === (int) $refund->get_parent_id() ) {
						$valid = true;
					}
				}
				if ( ! $valid ) {
					return self::error( 'refund', __( 'Refund does not belong to this order.', 'yoohw-vietnam-store-tools' ) );
				}
				$ids[] = $id;
			} elseif ( ! in_array( $id, $ids, true ) ) {
				return self::error( 'refund', __( 'Refund is not linked to this return.', 'yoohw-vietnam-store-tools' ) );
			}
			if ( 'unlink_refund' === $kind ) {
				$ids = array_diff( $ids, [ $id ] );
			}
			return [ 'refund_ids' => array_values( array_unique( $ids ) ) ];
		}
		return self::error( 'kind', __( 'Unknown return operation.', 'yoohw-vietnam-store-tools' ) );
	}

	private static function validate_items( $order, $records, $requested, $excluded_return_id ) {
		if ( ! is_array( $requested ) || ! $requested ) {
			return self::error( 'items', __( 'Choose at least one product item.', 'yoohw-vietnam-store-tools' ) );
		}
		$allocated = [];
		foreach ( $records as $record ) {
			if ( $excluded_return_id === $record['id'] || 'cancelled' === $record['state'] ) {
				continue;
			}
			foreach ( $record['items'] as $item ) {
				$id = (int) $item['order_item_id'];
				$allocated[ $id ] = isset( $allocated[ $id ] ) ? $allocated[ $id ] + (int) $item['quantity'] : (int) $item['quantity'];
			}
		}
		$order_items = $order->get_items( 'line_item' );
		$items = [];
		foreach ( $requested as $id => $quantity ) {
			if ( ! ctype_digit( (string) $id ) || ! ctype_digit( (string) $quantity ) || (int) $quantity < 1 || ! isset( $order_items[ (int) $id ] ) || ! $order_items[ (int) $id ] instanceof WC_Order_Item_Product ) {
				return self::error( 'items', __( 'Return items or quantities are invalid.', 'yoohw-vietnam-store-tools' ) );
			}
			$item = $order_items[ (int) $id ];
			$capacity = (int) $item->get_quantity();
			if ( $capacity < 1 || (int) $quantity + ( isset( $allocated[ (int) $id ] ) ? $allocated[ (int) $id ] : 0 ) > $capacity ) {
				return self::error( 'capacity', __( 'Return quantity exceeds the current order item quantity.', 'yoohw-vietnam-store-tools' ) );
			}
			$product = $item->get_product();
			$items[] = [
				'order_item_id' => (int) $id,
				'product_id' => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'name' => $item->get_name(),
				'sku' => $product ? $product->get_sku() : '',
				'ordered_quantity' => $capacity,
				'quantity' => (int) $quantity,
			];
		}
		return $items;
	}

	public static function lock_name( $order_id ) {
		return '_yoohw_vst_returns_lock_' . absint( $order_id );
	}

	public static function acquire_lock( $order_id ) {
		global $wpdb;
		$name = self::lock_name( $order_id );
		try {
			$token = bin2hex( random_bytes( 24 ) );
		} catch ( Exception $exception ) {
			return self::error( 'lock', __( 'Return edit lock could not be created. Retry later.', 'yoohw-vietnam-store-tools' ) );
		}
		$value = $token . '|' . ( time() + self::LEASE_SECONDS );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $value, 'off' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 1 === (int) $inserted ) {
			return $value;
		}
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$parts = explode( '|', (string) $old );
		if ( 2 === count( $parts ) && ctype_digit( $parts[1] ) && (int) $parts[1] < time() ) {
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( 1 === (int) $taken ) {
				return $value;
			}
		}
		return self::error( 'locked', __( 'Another operator is editing returns for this order. Reload and retry.', 'yoohw-vietnam-store-tools' ) );
	}

	public static function owns_lock( $order_id, $token ) {
		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::lock_name( $order_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$parts = explode( '|', (string) $stored );
		return hash_equals( (string) $token, (string) $stored ) && 2 === count( $parts ) && (int) $parts[1] >= time();
	}

	public static function release_lock( $order_id, $token ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::lock_name( $order_id ), $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private static function order( $order ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order ) : false;
		return $order instanceof WC_Order ? $order : false;
	}

	private static function stale() {
		return self::error( 'stale', __( 'This return changed since the page loaded. Reload and retry.', 'yoohw-vietnam-store-tools' ) );
	}

	private static function error( $code, $message ) {
		return new WP_Error( 'yoohw_vietnam_store_tools_return_' . $code, $message );
	}
}
