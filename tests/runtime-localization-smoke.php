<?php
/**
 * Runtime translation smoke executed through `wp eval-file` in CI.
 *
 * @var array $args Positional arguments supplied by WP-CLI.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$expected = isset( $args[0] ) ? (string) $args[0] : '';

if ( '' === $expected ) {
	WP_CLI::error( 'Expected translation argument is required.' );
}

if ( ! did_action( 'init' ) ) {
	do_action( 'init' );
}

if ( 'vi' !== determine_locale() && ! switch_to_locale( 'vi' ) ) {
	WP_CLI::error( 'Unable to switch the runtime locale to vi.' );
}

$actual = __( 'Add rule', 'yoohw-vietnam-store-tools' );

if ( $actual !== $expected ) {
	WP_CLI::error( sprintf( 'Translation mismatch. Expected "%s", got "%s".', $expected, $actual ) );
}

WP_CLI::success( sprintf( 'Translation resolved as "%s" for locale %s.', $actual, determine_locale() ) );
