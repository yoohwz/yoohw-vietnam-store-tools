<?php
/** VST-52 admin display contracts over the VST-50 domain fixture. */
require __DIR__ . '/domain-contract-tests.php';

function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_html__( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function add_query_arg( $key, $value, $url = null ) { return is_array( $key ) ? $value . '?' . http_build_query( $key ) : $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function wp_safe_redirect( $url ) { throw new RuntimeException( $url ); }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function wp_verify_nonce( $nonce ) { return 'valid' === $nonce; }
function wp_date( $format, $timestamp = null ) { return gmdate( $format, null === $timestamp ? time() : $timestamp ); }
$test_options['date_format'] = 'Y-m-d';
$test_options['time_format'] = 'H:i';
function get_userdata( $id ) { return (object) [ 'display_name' => 'Operator ' . $id ]; }
function wp_unslash( $value ) { return $value; }
function is_admin() { return true; }
function get_current_screen() { global $test_screen_id; return (object) [ 'id' => $test_screen_id ]; }
function wc_get_page_screen_id() { return 'woocommerce_page_wc-orders'; }
function add_meta_box( $id, $title, $callback, $screen, $context, $priority ) {
	global $test_metaboxes;
	$test_metaboxes[] = [ $id, $screen, $context, $priority ];
}
function add_filter( $hook, $callback ) {
	global $test_admin_filters;
	$test_admin_filters[ $hook ] = $callback;
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
$test_admin_filters = [];
$test_screen_id = 'shop_order';
$_GET['post'] = '90';
$admin->add_order_metabox();
vst_assert_same( [
	[ 'yoohw-vietnam-store-tools-payment-reconciliation', 'shop_order', 'side', 'default' ],
	[ 'yoohw-vietnam-store-tools-payment-reconciliation', 'woocommerce_page_wc-orders', 'side', 'default' ],
], $test_metaboxes, 'Relevant BACS registers in the side stack on legacy and HPOS screens without VietQR' );
vst_assert_true( isset( $test_admin_filters['get_user_option_meta-box-order_shop_order'] ), 'Relevant order adjusts only the rendered metabox order for its current screen' );
$saved_order = [
	'side' => 'yoohw-vietnam-store-tools-bacs-vietqr,other-side-box',
	'normal' => 'order_data,yoohw-vietnam-store-tools-payment-reconciliation,order_notes',
];
$rendered_order = $admin->side_stack_order( $saved_order );
vst_assert_same( 'yoohw-vietnam-store-tools-bacs-vietqr,yoohw-vietnam-store-tools-payment-reconciliation,other-side-box', $rendered_order['side'], 'Saved normal placement is rendered immediately after VietQR in the side stack' );
vst_assert_same( 'order_data,order_notes', $rendered_order['normal'], 'Other normal metaboxes keep their order' );
vst_assert_same( 'order_data,yoohw-vietnam-store-tools-payment-reconciliation,order_notes', $saved_order['normal'], 'Saved user preference is not mutated' );
vst_assert_same( 'yoohw-vietnam-store-tools-bacs-vietqr,yoohw-vietnam-store-tools-payment-reconciliation,other-side-box', $admin->side_stack_order( $rendered_order )['side'], 'Repeated ordering does not duplicate the metabox' );
vst_assert_same( [ 'side' => 'other-side-box,yoohw-vietnam-store-tools-payment-reconciliation' ], $admin->side_stack_order( [ 'side' => 'other-side-box,yoohw-vietnam-store-tools-payment-reconciliation' ] ), 'Existing side customization remains when VietQR is absent' );
vst_assert_same( false, $admin->side_stack_order( false ), 'Default order remains under WordPress registration order' );
unset( $_GET['post'] );
$test_screen_id = 'woocommerce_page_wc-orders';
$_GET['id'] = '90';
$test_admin_filters = [];
$admin->add_order_metabox();
vst_assert_true( isset( $test_admin_filters['get_user_option_meta-box-order_woocommerce_page_wc-orders'] ), 'HPOS order screen also adjusts saved placement at render time' );
unset( $_GET['id'] );
$test_screen_id = 'shop_order';

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
vst_assert_true( false !== strpos( $read_only_html, 'Unreconciled' ) && false === strpos( $read_only_html, 'class="vck-payment-reconciliation__toggle"' ), 'User without edit capability sees status without an empty toggle' );
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
vst_assert_true( false === strpos( $html, 'class="vck-payment-reconciliation__toggle"' ), 'History-only order has no empty edit toggle' );
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
vst_assert_true( false === strpos( $html, 'class="vck-payment-reconciliation__toggle"' ), 'Externally verified order has no empty edit toggle' );
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

// Feature OFF hides empty orders but retains history without any controls or footer form.
$history_before = serialize( $domain::get_history( $order ) );
$meta_before = serialize( $order->meta );
$saves_before = $order->saves;
$test_options[ $payment_option ] = 'no';
$empty = new VST_Admin_Order( 93 );
$test_orders[93] = $empty;
vst_assert_same( false, $panel::is_relevant( $empty ), 'OFF empty BACS is irrelevant' );
$test_metaboxes = [];
$_GET['post'] = '93';
$admin->add_order_metabox();
vst_assert_same( [], $test_metaboxes, 'OFF empty BACS registers no box' );
ob_start(); $admin->render_metabox( $empty ); $html = ob_get_clean();
vst_assert_same( '', $html, 'OFF empty BACS renders nothing' );
$_GET['post'] = '90';
ob_start(); $admin->render_metabox( $order ); $html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'Existing history is read-only' ), 'OFF retained history explains read-only state' );
vst_assert_true( false !== strpos( $html, 'CORRECTED' ), 'OFF retains audit references' );
vst_assert_same( false, false !== strpos( $html, 'name="vck_payment_' ), 'OFF has no editable controls' );
ob_start(); $admin->render_action_form(); $html = ob_get_clean();
vst_assert_same( '', $html, 'OFF has no detached mutation form' );
foreach ( [ $empty, $order ] as $target ) {
 foreach ( [ 'observe', 'match', 'reverse' ] as $operation ) {
  $_POST = [ 'vck_payment_order_id' => $target->get_id(), 'vck_payment_nonce' => 'valid', 'vck_payment_operation' => $operation ];
  try { $admin->handle_action(); vst_assert_true( false, 'Disabled POST must redirect' ); }
  catch ( RuntimeException $e ) { vst_assert_true( false !== strpos( $e->getMessage(), 'yoohw_vietnam_store_tools_payment_feature_disabled' ), 'Valid disabled POST returns bounded disabled notice' ); }
 }
}
$_POST['vck_payment_nonce'] = 'invalid';
try { $admin->handle_action(); vst_assert_true( false, 'Invalid nonce must fail' ); }
catch ( RuntimeException $e ) { vst_assert_same( 'Invalid payment reconciliation request.', $e->getMessage(), 'OFF still checks nonce' ); }
$test_actor_allowed = false;
$_POST['vck_payment_nonce'] = 'valid';
try { $admin->handle_action(); vst_assert_true( false, 'Forbidden user must fail' ); }
catch ( RuntimeException $e ) { vst_assert_same( 'You cannot edit this order.', $e->getMessage(), 'OFF still checks capability' ); }
$test_actor_allowed = true;
vst_assert_same( $history_before, serialize( $domain::get_history( $order ) ), 'OFF admin reads/POST preserve history bytes' );
vst_assert_same( $meta_before, serialize( $order->meta ), 'OFF admin preserves all order metadata' );
vst_assert_same( $saves_before, $order->saves, 'OFF admin never saves' );
vst_assert_same( [], $domain::get_history( $empty ), 'Forged OFF POST does not create history' );
$test_options[ $payment_option ] = 'yes';
ob_start(); $admin->render_metabox( $order ); $html = ob_get_clean();
vst_assert_true( false !== strpos( $html, 'name="vck_payment_operation"' ), 'Re-enable restores existing-history controls' );
unset( $_GET['post'] ); $_POST = [];

vst_finish_contract_suite( 'VST-52 payment admin' );
