<?php
/**
 * Provider-neutral structured logging wrapper for internal plugin use.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal foundational logger. This is not a stable extension API in 1.1.5.
 */
final class Yoohw_Vietnam_Store_Tools_Logger {

	const SOURCE = 'yoohw-vietnam-store-tools';

	/**
	 * Writes a non-sensitive event message with redacted structured context.
	 *
	 * Free-form messages are not inspected for secrets. Sensitive values belong
	 * in the structured context so they can be redacted by normalized key.
	 *
	 * @param string $level   WooCommerce log level.
	 * @param string $message Non-sensitive event description.
	 * @param array  $context Structured event context.
	 * @return bool Whether the event was handed to a WooCommerce logger.
	 */
	public static function log( $level, $message, $context = [] ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return false;
		}

		$logger = wc_get_logger();

		if ( ! is_object( $logger ) || ! is_callable( [ $logger, 'log' ] ) ) {
			return false;
		}

		$context           = is_array( $context ) ? $context : [];
		$context           = Yoohw_Vietnam_Store_Tools_Security::redact_sensitive_data( $context );
		$context['source'] = self::SOURCE;

		try {
			$logger->log( sanitize_key( $level ), (string) $message, $context );
		} catch ( Throwable $error ) {
			unset( $error );
			return false;
		}

		return true;
	}
}
