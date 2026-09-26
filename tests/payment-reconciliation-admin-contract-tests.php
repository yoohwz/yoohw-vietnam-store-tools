<?php
/** VST-52 admin display contracts over the VST-50 domain fixture. */
require __DIR__ . '/domain-contract-tests.php';

function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_html__( $value ) { return esc_html( $value ); }
function wp_date( $format, $timestamp = null ) { return gmdate( $format, null === $timestamp ? time() : $timestamp ); }
function get_option( $key ) { return 'date_format' === $key ? 'Y-m-d' : 'H:i'; }
function get_userdata( $id ) { return (object) [ 'display_name' => 'Operator ' . $id ]; }
function wp_unslash( $value ) { return $value; }

class VST_Admin_Order extends WC_Order {
	public $payment_method = 'bacs';
	public function get_payment_method() { return $this->payment_method; }
	public function get_edit_order_url() { return 'https://example.test/order/' . $this->get_id(); }
}

require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-payment-reconciliation-admin.php';
$admin = ( new ReflectionClass( 'Yoohw_Vietnam_Store_Tools_Payment_Reconciliation_Admin' ) )->newInstanceWithoutConstructor();
$domain = 'Yoohw_Vietnam_Store_Tools_Payment_Reconciliation';
$panel = 'Yoohw_Vietnam_Store_Tools_Payment_Reconciliation_Admin';
$order = new VST_Admin_Order( 90 );

ob_start();
$admin->render_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'Record observation' ), 'Plain BACS can enter manual evidence without VietQR settings' );
vst_assert_true( false !== strpos( $html, 'Unreconciled' ), 'No-history BACS displays unreconciled' );
vst_assert_same( 0, $order->saves, 'Admin read does not write old orders' );

$observation = $domain::record_manual_observation( $order, [ 'amount' => '100000', 'currency' => 'VND', 'reference' => 'BANK-90' ] );
ob_start();
$admin->render_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'Recorded' ) && false !== strpos( $html, 'Save correction' ), 'Manual observation is inspectable and correctable' );
vst_assert_true( false !== strpos( $html, 'does not confirm the bank transaction automatically' ), 'Manual match confirmation states the trust boundary' );
vst_assert_true( false !== strpos( $html, 'vck_payment_supersedes' ), 'Correction submits superseded observation identity' );
vst_assert_true( false !== strpos( $html, 'name="vck_payment_expected_observation"' ) && false !== strpos( $html, $observation['id'] ), 'Match binds to the observation shown to the operator' );

$match = $domain::match_manual_observation( $order, $observation['id'] );
ob_start();
$admin->render_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'Reconciled manually' ) && false !== strpos( $html, 'Reverse manual reconciliation' ), 'Manual match offers bounded reversal' );
vst_assert_true( false !== strpos( $html, 'Operator 7' ), 'History displays server actor' );
vst_assert_true( false !== strpos( $html, 'name="vck_payment_expected_match"' ) && false !== strpos( $html, $match['id'] ), 'Reversal binds to the match shown to the operator' );

$order->payment_method = 'cod';
ob_start();
$admin->render_metabox( $order );
$html = ob_get_clean();
vst_assert_true( $panel::is_relevant( $order ) && false !== strpos( $html, 'Reconciliation history' ), 'History remains visible after payment method changes' );
vst_assert_true( false === strpos( $html, 'name="vck_payment_operation"' ), 'Changed payment method cannot use manual controls' );
$order->payment_method = 'bacs';
$domain::reverse_entry( $order, $match['id'] );
vst_assert_same( 'recorded', $domain::get_order_data( $order )['state'], 'Manual reversal returns to recorded' );

$external = new VST_Admin_Order( 91 );
$test_filters['yoohw_vietnam_store_tools_payment_evidence_sources'] = [ 'verified_bank' => static function ( $order, $proof ) { return $proof; } ];
$domain::record_verified_evidence( $external, 'verified_bank', [ 'amount' => '100000', 'currency' => 'VND', 'observed_at' => '2026-09-26T00:00:00Z', 'transaction_id' => 'BANK-91' ] );
ob_start();
$admin->render_metabox( $external );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'Externally verified evidence is read only here.' ), 'External verified evidence displays read only' );
vst_assert_true( false === strpos( $html, 'name="vck_payment_operation"' ), 'External evidence has no manual mutation controls' );
vst_assert_true( false !== strpos( $html, 'verified_bank / BANK-91' ), 'External source and transaction remain visible in audit trail' );

$other = new VST_Admin_Order( 92 );
$other->payment_method = 'cod';
vst_assert_true( ! $panel::is_relevant( $other ), 'Unrelated order has no reconciliation panel' );

$utc = new ReflectionMethod( $panel, 'utc_time' );
vst_assert_same( '', $utc->invoke( $admin, '2026-02-31T12:00' ), 'Invalid observed calendar time is rejected before write' );
vst_assert_same( '2026-09-26T12:00:00+00:00', $utc->invoke( $admin, '2026-09-26T12:00' ), 'Observed store time is persisted as UTC' );

$_GET['vck_payment_notice'] = 'yoohw_vietnam_store_tools_payment_unmatched_amount';
ob_start();
$admin->render_notice();
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'must exactly match' ), 'Amount mismatch has actionable validation feedback' );
unset( $_GET['vck_payment_notice'] );

vst_finish_contract_suite( 'VST-52 payment admin' );
