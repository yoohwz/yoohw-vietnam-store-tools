<?php
/**
 * Provider-neutral security primitives for internal plugin use.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal foundational helpers for opaque identifiers and structured redaction.
 *
 * This class is not a stable extension API in 1.1.5.
 */
final class Yoohw_Vietnam_Store_Tools_Security {

	const REDACTED_VALUE = '[REDACTED]';

	/**
	 * Creates a deterministic, purpose-separated opaque identifier.
	 *
	 * The raw value is never persisted by this helper. Callers must provide a
	 * non-empty purpose so unrelated identifiers cannot share a hash namespace.
	 *
	 * @param mixed  $value   Identifier to hash.
	 * @param string $purpose Explicit domain/purpose for the identifier.
	 * @return string
	 */
	public static function hash_identifier( $value, $purpose ) {
		$purpose = trim( (string) $purpose );

		if ( '' === $purpose || ! is_scalar( $value ) ) {
			return '';
		}

		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';

		if ( '' === $key ) {
			return '';
		}

		return hash_hmac( 'sha256', $purpose . "\0" . (string) $value, $key );
	}

	/**
	 * Recursively redacts values stored under normalized sensitive keys.
	 *
	 * This protects structured data. It does not attempt to discover arbitrary
	 * secrets embedded inside free-form string values.
	 *
	 * @param mixed $value Structured value to redact.
	 * @return mixed
	 */
	public static function redact_sensitive_data( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$redacted = [];

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
				$redacted[ $key ] = self::REDACTED_VALUE;
				continue;
			}

			$redacted[ $key ] = self::redact_sensitive_data( $item );
		}

		return $redacted;
	}

	private static function is_sensitive_key( $key ) {
		$key = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', (string) $key );
		$key = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '_', $key ) );
		$key = trim( $key, '_' );

		if ( in_array( $key, [ 'authorization', 'cookie', 'key', 'nonce', 'signature' ], true ) ) {
			return true;
		}

		return 1 === preg_match( '/(?:^|_)(?:api_key|access_key|consumer_key|credential|password|passwd|passphrase|private_key|secret|token)(?:_|$)/', $key );
	}
}
