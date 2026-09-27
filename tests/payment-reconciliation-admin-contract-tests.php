<?php
/** VST-52 admin display contracts over the VST-50 domain fixture. */
require __DIR__ . '/domain-contract-tests.php';

function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_html__( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function add_query_arg( $key, $value, $url ) { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function wp_date( $format, $timestamp = null ) { return gmdate( $format, null === $timestamp ? time() : $timestamp ); }
function get_option( $key ) { return 'date_format' === $key ? 'Y-m-d' : 'H:i'; }
function get_userdata( $id ) { return (object) [ 'display_name' => 'Operator ' . $id ]; }
function wp_unslash( $value ) { return $value; }
function is_admin() { return true; }
function get_current_screen() { return (object) [ 'id' => 'shop_order' ]; }
function wc_get_page_screen_id() { return 'woocommerce_page_wc-orders'; }
function add_meta_box( $id, $title, $callback, $screen, $context, $priority ) {
	global $test_metaboxes;
	$test_metaboxes[] = [ $id, $screen, $context, $priority ];
}

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
$test_orders[90] = $order;
$test_metaboxes = [];
$_GET['post'] = '90';
$admin->add_order_metabox();
vst_assert_same( [
	[ 'yoohw-vietnam-store-tools-payment-reconciliation', 'shop_order', 'side', 'default' ],
	[ 'yoohw-vietnam-store-tools-payment-reconciliation', 'woocommerce_page_wc-orders', 'side', 'default' ],
], $test_metaboxes, 'Relevant BACS registers in the side stack on legacy and HPOS screens without VietQR' );
unset( $_GET['post'] );

ob_start();
$admin->render_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'Record observation' ), 'Plain BACS can enter manual evidence without VietQR settings' );
vst_assert_true( false !== strpos( $html, 'Unreconciled' ), 'No-history BACS displays unreconciled' );
vst_assert_true( false !== strpos( $html, 'type="button" class="vck-payment-reconciliation__toggle" aria-expanded="false" aria-controls="vck-payment-reconciliation-panel-90"' ), 'Manual toggle begins collapsed and controls an order-specific panel' );
vst_assert_true( false !== strpos( $html, 'id="vck-payment-reconciliation-panel-90" class="vck-payment-reconciliation__panel" hidden' ), 'Manual controls begin in a natively hidden panel' );
vst_assert_true( strpos( $html, 'Unreconciled' ) < strpos( $html, 'id="vck-payment-reconciliation-panel-90"' ) && strpos( $html, 'Record observation' ) > strpos( $html, 'id="vck-payment-reconciliation-panel-90"' ), 'Current status stays visible while manual workflow is collapsed' );
vst_assert_true( false !== strpos( $html, 'input:not([type="hidden"]):not([disabled])' ), 'Expansion focuses a visible enabled field' );
vst_assert_true( false === strpos( $html, '<form ' ), 'Metabox does not nest a form inside the order editor' );
vst_assert_same( 0, $order->saves, 'Admin read does not write old orders' );
$test_actor_allowed = false;
ob_start();
$admin->render_metabox( $order );
$read_only_html = ob_get_clean();
vst_assert_true( false !== strpos( $read_only_html, 'Unreconciled' ) && false === strpos( $read_only_html, 'vck-payment-reconciliation__toggle' ), 'User without edit capability sees status without an empty toggle' );
$test_actor_allowed = true;

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
vst_assert_true( false === strpos( $html, 'vck-payment-reconciliation__toggle' ), 'History-only order has no empty edit toggle' );
$order->payment_method = 'bacs';
$domain::reverse_entry( $order, $match['id'] );
vst_assert_same( 'recorded', $domain::get_order_data( $order )['state'], 'Manual reversal returns to recorded' );
$second = $domain::record_manual_observation( $order, [ 'amount' => '100000', 'currency' => 'VND', 'reference' => 'SECOND' ] );
ob_start();
$admin->render_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'BANK-90' ), 'Projection still displays the first active observation' );
vst_assert_true( false !== strpos( $html, 'name="vck_payment_supersedes" form="vck-payment-reconciliation-form" value="' . $observation['id'] . '"' ), 'Correction targets the projected observation when multiple observations are active' );
vst_assert_true( false === strpos( $html, 'name="vck_payment_supersedes" form="vck-payment-reconciliation-form" value="' . $second['id'] . '"' ), 'Correction does not silently target a different observation' );
vst_assert_true( false !== strpos( $html, 'Select an observation' ) && false !== strpos( $html, 'vck_payment_observation=' . $second['id'] ), 'Operator can explicitly select another active observation' );
$third = $domain::record_manual_observation( $order, [ 'amount' => '100000', 'currency' => 'VND', 'reference' => 'CORRECTED' ], [ 'supersedes' => $observation['id'] ] );
vst_assert_same( $second['id'], $domain::get_order_data( $order )['entry_id'], 'Projection may move to another active observation after correction' );
$_GET['vck_payment_observation'] = $third['id'];
ob_start();
$admin->render_metabox( $order );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'name="vck_payment_selected_observation" form="vck-payment-reconciliation-form" value="' . $third['id'] . '"' ), 'Corrected observation remains selected through redirect' );
vst_assert_true( false !== strpos( $html, 'name="vck_payment_expected_observation" form="vck-payment-reconciliation-form" value="' . $third['id'] . '"' ), 'Corrected observation can be manually matched despite another active observation' );
unset( $_GET['vck_payment_observation'] );

$external = new VST_Admin_Order( 91 );
$test_filters['yoohw_vietnam_store_tools_payment_evidence_sources'] = [ 'verified_bank' => static function ( $order, $proof ) { return $proof; } ];
$domain::record_verified_evidence( $external, 'verified_bank', [ 'amount' => '100000', 'currency' => 'VND', 'observed_at' => '2026-09-26T00:00:00Z', 'transaction_id' => 'BANK-91' ] );
ob_start();
$admin->render_metabox( $external );
$html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'Externally verified evidence is read only here.' ), 'External verified evidence displays read only' );
vst_assert_true( false === strpos( $html, 'vck-payment-reconciliation__toggle' ), 'Externally verified order has no empty edit toggle' );
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
