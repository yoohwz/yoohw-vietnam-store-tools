<?php
/**
 * Provider-neutral, order-owned payment reconciliation evidence.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Payment_Reconciliation {
	const META_HISTORY = '_yoohw_vietnam_store_tools_payment_reconciliation_history';
	const META_TRANSACTION_PREFIX = '_yoohw_vietnam_store_tools_payment_transaction_';
	const STATE_UNRECONCILED = 'unreconciled';
	const STATE_RECORDED     = 'recorded';
	const STATE_RECONCILED   = 'reconciled';
	const TRUST_MANUAL       = 'manual';
	const TRUST_EXTERNAL     = 'external_verified';

	public static function get_states() {
		return [ self::STATE_UNRECONCILED, self::STATE_RECORDED, self::STATE_RECONCILED ];
	}

	public static function get_history( $order ) {
		$order = self::order( $order );
		$history = $order ? $order->get_meta( self::META_HISTORY, true ) : [];
		return is_array( $history ) ? array_values( $history ) : [];
	}

	public static function get_order_data( $order ) {
		$order = self::order( $order );
		if ( ! $order ) {
			return [];
		}
		$history = self::get_history( $order );
		$inactive = [];
		foreach ( $history as $entry ) {
			if ( ! empty( $entry['supersedes'] ) ) {
				$inactive[ $entry['supersedes'] ] = true;
			}
		}
		$active = [];
		foreach ( $history as $entry ) {
			if ( isset( $entry['id'], $entry['kind'] ) && ! isset( $inactive[ $entry['id'] ] ) && 'reversal' !== $entry['kind'] ) {
				$active[ $entry['id'] ] = $entry;
			}
		}
		$result = [ 'state' => self::STATE_UNRECONCILED, 'trust' => '', 'source_id' => '', 'entry_id' => '' ];
		$verified_result = null;
		foreach ( $active as $entry ) {
			if ( 'observation' === $entry['kind'] && self::STATE_UNRECONCILED === $result['state'] ) {
				$result = [ 'state' => self::STATE_RECORDED, 'trust' => self::TRUST_MANUAL, 'source_id' => 'manual', 'entry_id' => $entry['id'] ];
			}
			if ( 'match' === $entry['kind'] && isset( $active[ $entry['evidence_id'] ] ) && self::exact_order_amount( $order, $entry ) ) {
				$result = [ 'state' => self::STATE_RECONCILED, 'trust' => self::TRUST_MANUAL, 'source_id' => 'manual', 'entry_id' => $entry['id'] ];
			}
			if ( 'verified' === $entry['kind'] && self::exact_order_amount( $order, $entry ) ) {
				$verified_result = [ 'state' => self::STATE_RECONCILED, 'trust' => self::TRUST_EXTERNAL, 'source_id' => $entry['source_id'], 'entry_id' => $entry['id'] ];
			}
		}
		return null !== $verified_result ? $verified_result : $result;
	}

	public static function record_manual_observation( $order, $data, $context = [] ) {
		$order = self::order( $order );
		if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return self::error( 'manual_forbidden' );
		}
		$normalized = self::normalize_evidence( $data );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$normalized['kind'] = 'observation';
		$normalized['trust'] = self::TRUST_MANUAL;
		$normalized['source_id'] = 'manual';
		$normalized['transaction_id'] = '';
		$normalized['actor_id'] = get_current_user_id();
		$normalized['supersedes'] = isset( $context['supersedes'] ) ? sanitize_text_field( $context['supersedes'] ) : '';
		if ( '' !== $normalized['supersedes'] && ! self::active_entry( $order, $normalized['supersedes'], [ 'observation' ] ) ) {
			return self::error( 'invalid_superseded_entry' );
		}
		return self::append( $order, $normalized, $context );
	}

	public static function match_manual_observation( $order, $entry_id, $context = [] ) {
		$order = self::order( $order );
		if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return self::error( 'manual_forbidden' );
		}
		$observation = self::active_entry( $order, $entry_id, [ 'observation' ] );
		if ( ! $observation || ! self::exact_order_amount( $order, $observation ) ) {
			return self::error( 'unmatched_amount' );
		}
		foreach ( self::get_history( $order ) as $entry ) {
			if ( 'match' === $entry['kind'] && $entry_id === $entry['evidence_id'] && self::active_entry( $order, $entry['id'], [ 'match' ] ) ) {
				return $entry;
			}
		}
		$entry = $observation;
		$entry['kind'] = 'match';
		$entry['evidence_id'] = $entry_id;
		$entry['actor_id'] = get_current_user_id();
		$entry['supersedes'] = '';
		return self::append( $order, $entry, $context );
	}

	public static function record_verified_evidence( $order, $source_id, $evidence, $context = [] ) {
		$order = self::order( $order );
		$source_id = sanitize_key( $source_id );
		if ( ! $order || '' === $source_id || 'manual' === $source_id || ! is_array( $evidence ) ) {
			return self::error( 'invalid_evidence' );
		}
		$sources = apply_filters( 'yoohw_vietnam_store_tools_payment_evidence_sources', [] );
		if ( ! is_array( $sources ) || ! isset( $sources[ $source_id ] ) || ! is_callable( $sources[ $source_id ] ) ) {
			return self::error( 'unregistered_source' );
		}
		$proof = call_user_func( $sources[ $source_id ], $order, $evidence );
		if ( is_wp_error( $proof ) ) {
			return $proof;
		}
		if ( ! is_array( $proof ) ) {
			return self::error( 'invalid_evidence' );
		}
		$normalized = self::normalize_evidence( $proof );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$transaction_id = isset( $proof['transaction_id'] ) ? sanitize_text_field( $proof['transaction_id'] ) : '';
		if ( ! isset( $proof['observed_at'] ) ) {
			return self::error( 'invalid_evidence' );
		}
		if ( '' === $transaction_id || ! self::exact_order_amount( $order, $normalized ) ) {
			return self::error( 'unmatched_amount' );
		}
		foreach ( self::get_history( $order ) as $previous ) {
			if ( 'verified' !== $previous['kind'] || $source_id !== $previous['source_id'] || $transaction_id !== $previous['transaction_id'] ) {
				continue;
			}
			if ( $normalized['amount'] === $previous['amount'] && $normalized['currency'] === $previous['currency'] && $normalized['observed_at'] === $previous['observed_at'] && self::active_entry( $order, $previous['id'], [ 'verified' ] ) ) {
				return $previous;
			}
			return self::error( 'conflicting_transaction' );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return self::error( 'unavailable_transaction_lookup' );
		}
		$transaction_key = self::transaction_meta_key( $source_id, $transaction_id );
		$owners = wc_get_orders(
			[
				'type'         => 'shop_order',
				'status'       => 'any',
				'limit'        => 2,
				'return'       => 'ids',
				'meta_key'     => $transaction_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			]
		);
		if ( ! is_array( $owners ) ) {
			return self::error( 'unavailable_transaction_lookup' );
		}
		if ( $owners ) {
			return self::error( 'conflicting_transaction' );
		}
		$normalized['kind'] = 'verified';
		$normalized['trust'] = self::TRUST_EXTERNAL;
		$normalized['source_id'] = $source_id;
		$normalized['transaction_id'] = $transaction_id;
		$normalized['actor_id'] = 0;
		$normalized['supersedes'] = '';
		return self::append( $order, $normalized, $context );
	}

	public static function reverse_entry( $order, $entry_id, $context = [] ) {
		$order = self::order( $order );
		if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return self::error( 'manual_forbidden' );
		}
		$target = self::active_entry( $order, $entry_id, [ 'observation', 'match', 'verified' ] );
		if ( ! $target ) {
			return self::error( 'invalid_superseded_entry' );
		}
		$entry = [ 'kind' => 'reversal', 'trust' => $target['trust'], 'source_id' => $target['source_id'], 'transaction_id' => $target['transaction_id'], 'reference' => $target['reference'], 'amount' => $target['amount'], 'currency' => $target['currency'], 'observed_at' => $target['observed_at'], 'actor_id' => get_current_user_id(), 'supersedes' => $entry_id ];
		return self::append( $order, $entry, $context );
	}

	private static function append( $order, $entry, $context ) {
		$entry['id'] = wp_generate_uuid4();
		$entry['recorded_at'] = gmdate( 'c' );
		$entry['note'] = isset( $context['note'] ) ? sanitize_textarea_field( $context['note'] ) : '';
		$history = self::get_history( $order );
		$history[] = $entry;
		$order->update_meta_data( self::META_HISTORY, $history );
		if ( 'verified' === $entry['kind'] ) {
			$order->update_meta_data( self::transaction_meta_key( $entry['source_id'], $entry['transaction_id'] ), $entry['id'] );
		}
		$order->save();
		do_action( 'yoohw_vietnam_store_tools_payment_reconciliation_updated', $order, self::get_order_data( $order ), $entry );
		return $entry;
	}

	private static function active_entry( $order, $entry_id, $kinds ) {
		$history = self::get_history( $order );
		foreach ( $history as $entry ) {
			if ( ! empty( $entry['supersedes'] ) && $entry_id === $entry['supersedes'] ) {
				return false;
			}
		}
		foreach ( $history as $entry ) {
			if ( isset( $entry['id'], $entry['kind'] ) && $entry_id === $entry['id'] && in_array( $entry['kind'], $kinds, true ) ) {
				return $entry;
			}
		}
		return false;
	}

	private static function normalize_evidence( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['amount'], $data['currency'] ) ) {
			return self::error( 'invalid_evidence' );
		}
		$raw_amount = trim( (string) $data['amount'] );
		$currency = strtoupper( trim( (string) $data['currency'] ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return self::error( 'invalid_evidence' );
		}
		$amount = self::normalize_amount( $raw_amount );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}
		$observed_at = isset( $data['observed_at'] ) ? trim( (string) $data['observed_at'] ) : gmdate( 'c' );
		if ( ! preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\+00:00|Z)$/', $observed_at ) ) {
			return self::error( 'invalid_evidence' );
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', str_replace( 'Z', '+00:00', $observed_at ) );
		$date_errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $date_errors ) && ( $date_errors['warning_count'] || $date_errors['error_count'] ) ) ) {
			return self::error( 'invalid_evidence' );
		}
		return [ 'reference' => isset( $data['reference'] ) ? sanitize_text_field( $data['reference'] ) : '', 'amount' => $amount, 'currency' => $currency, 'observed_at' => gmdate( 'c', $date->getTimestamp() ) ];
	}

	private static function exact_order_amount( $order, $entry ) {
		$amount = self::normalize_amount( $order->get_total() );
		return ! is_wp_error( $amount ) && strtoupper( $order->get_currency() ) === $entry['currency'] && $amount === $entry['amount'];
	}

	private static function normalize_amount( $value ) {
		$raw = trim( (string) $value );
		if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $raw ) ) {
			return self::error( 'invalid_evidence' );
		}
		$parts = explode( '.', $raw, 2 );
		$whole = ltrim( $parts[0], '0' );
		$whole = '' === $whole ? '0' : $whole;
		$fraction = isset( $parts[1] ) ? $parts[1] : '';
		$decimals = (int) wc_get_price_decimals();
		if ( $decimals < 0 || ( strlen( $fraction ) > $decimals && preg_match( '/[1-9]/', substr( $fraction, $decimals ) ) ) ) {
			return self::error( 'invalid_evidence' );
		}
		$expected = $whole . ( $decimals ? '.' . str_pad( substr( $fraction, 0, $decimals ), $decimals, '0' ) : '' );
		$formatted = wc_format_decimal( $raw, $decimals );
		if ( $expected !== $formatted || ! preg_match( '/[1-9]/', $expected ) ) {
			return self::error( 'invalid_evidence' );
		}
		return $expected;
	}

	private static function transaction_meta_key( $source_id, $transaction_id ) {
		return self::META_TRANSACTION_PREFIX . hash( 'sha256', $source_id . "\0" . $transaction_id );
	}

	private static function order( $order ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order );
		return $order instanceof WC_Order ? $order : false;
	}

	private static function error( $code ) {
		return new WP_Error( 'yoohw_vietnam_store_tools_payment_' . $code, __( 'Payment evidence could not be recorded.', 'yoohw-vietnam-store-tools' ) );
	}
}
