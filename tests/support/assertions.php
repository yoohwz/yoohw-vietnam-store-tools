<?php
/**
 * Minimal assertions shared by standalone contract suites.
 *
 * @package VietnamCommerceKit
 */

$vst_contract_failures  = [];
$vst_contract_assertions = 0;

function vst_assert_same( $expected, $actual, $label ) {
	global $vst_contract_assertions, $vst_contract_failures;

	++$vst_contract_assertions;

	if ( $expected !== $actual ) {
		$vst_contract_failures[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	}
}

function vst_assert_true( $actual, $label ) {
	vst_assert_same( true, (bool) $actual, $label );
}

function vst_finish_contract_suite( $label ) {
	global $vst_contract_assertions, $vst_contract_failures;

	if ( ! empty( $vst_contract_failures ) ) {
		fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $vst_contract_failures ) . "\n" );
		exit( 1 );
	}

	echo 'PASS: ' . $vst_contract_assertions . ' ' . $label . " contract checks.\n";
}
