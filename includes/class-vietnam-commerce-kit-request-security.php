<?php
/**
 * Request security helpers.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Request_Security {

	public static function verify_checkout_request() {
		return self::verify_post_nonce(
			[
				'woocommerce-process-checkout-nonce',
				'_wpnonce',
				'security',
			],
			'woocommerce-process_checkout'
		);
	}

	public static function verify_account_address_request() {
		return self::verify_post_nonce(
			[
				'woocommerce-edit-address-nonce',
				'_wpnonce',
			],
			'woocommerce-edit_address'
		);
	}

	public static function verify_checkout_or_account_address_request() {
		return self::verify_checkout_request() || self::verify_account_address_request();
	}

	public static function verify_user_profile_request( $user_id ) {
		$user_id = absint( $user_id );

		return $user_id
			&& current_user_can( 'edit_user', $user_id )
			&& self::verify_post_nonce( [ '_wpnonce' ], 'update-user_' . $user_id );
	}

	public static function verify_admin_order_request( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$order_id = $order->get_id();

		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			return false;
		}

		return self::verify_post_nonce( [ 'woocommerce_meta_nonce' ], 'woocommerce_save_data' )
			|| self::verify_post_nonce( [ '_wpnonce' ], 'update-post_' . $order_id )
			|| self::verify_post_nonce( [ '_wpnonce' ], 'edit-post_' . $order_id );
	}

	public static function get_post_text( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce for their form context before processing.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce for their form context before processing.
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	public static function get_post_textarea( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce for their form context before processing.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce for their form context before processing.
		return sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
	}

	public static function has_post_key( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only checks field presence; callers verify before processing.
		return isset( $_POST[ $key ] );
	}

	public static function get_query_text( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request routing; no state is changed from this value.
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request routing; no state is changed from this value.
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	public static function get_admin_order_from_query( $keys = [ 'id', 'post', 'order_id' ] ) {
		if ( ! function_exists( 'wc_get_order' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return false;
		}

		foreach ( $keys as $key ) {
			$key = (string) $key;

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen routing; access is checked before returning the order.
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen routing; access is checked before returning the order.
			$order_id = absint( wp_unslash( $_GET[ $key ] ) );

			if ( ! $order_id ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order && current_user_can( 'edit_shop_order', $order->get_id() ) ) {
				return $order;
			}
		}

		return false;
	}

	private static function verify_post_nonce( $keys, $action ) {
		foreach ( $keys as $key ) {
			$nonce = self::get_post_text( $key );

			if ( '' !== $nonce && wp_verify_nonce( $nonce, $action ) ) {
				return true;
			}
		}

		return false;
	}
}
