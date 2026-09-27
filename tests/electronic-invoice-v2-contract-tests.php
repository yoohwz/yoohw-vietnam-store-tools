<?php
/** Standalone VST-58 document lineage and legacy compatibility contracts. */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/support/assertions.php';
$orders = [];
$uuid_counter = 0;
$hooks = [];
$attachments = [];
function __( $text ) { return $text; }
function add_action() {}
function add_filter() {}
function do_action( $name, ...$args ) { global $hooks; $hooks[] = [ $name, $args ]; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function sanitize_file_name( $value ) { return basename( (string) $value ); }
function wc_clean( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function get_current_user_id() { return 4; }
function get_userdata( $id ) { return (object) [ 'display_name' => 'Operator ' . $id ]; }
function wp_generate_uuid4() { global $uuid_counter; return sprintf( '00000000-0000-4000-8000-%012d', ++$uuid_counter ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function esc_url_raw( $url, $protocols ) { $scheme = parse_url( $url, PHP_URL_SCHEME ); return '' === $url || in_array( $scheme, $protocols, true ) ? $url : ''; }
function wp_http_validate_url( $url ) { return (bool) filter_var( $url, FILTER_VALIDATE_URL ); }
function wp_get_attachment_url( $id ) { global $attachments; return $attachments[$id]['url'] ?? ''; }
function get_attached_file( $id ) { global $attachments; return $attachments[$id]['path'] ?? ''; }
function get_the_title( $id ) { global $attachments; return $attachments[$id]['title'] ?? ''; }
function get_post_type( $id ) { global $attachments; return $attachments[$id]['type'] ?? ''; }
function get_post_meta( $id ) { global $attachments; return $attachments[$id]['order_id'] ?? ''; }
function get_post_mime_type( $id ) { global $attachments; return $attachments[$id]['mime'] ?? ''; }
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class WC_Order {
	private $id;
	public $meta = [];
	public $notes = [];
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { global $orders; $orders[ $this->id ] = clone $this; }
}
function wc_get_order( $id ) { global $orders; $id = $id instanceof WC_Order ? $id->get_id() : (int) $id; return isset( $orders[ $id ] ) ? clone $orders[ $id ] : false; }
class Yoohw_Vietnam_Store_Tools_Admin_Menu {
	const OPTION_ELECTRONIC_INVOICE = 'electronic_invoice';
	public static function is_feature_enabled() { return true; }
}
class Yoohw_Vietnam_Store_Tools_Tax_Invoice {
	const META_REQUESTED = '_yoohw_vietnam_store_tools_tax_invoice_requested';
}
class VST_Test_WPDB {
	public $options = 'wp_options';
	public $rows = [];
	public function prepare( $sql, ...$values ) {
		foreach ( $values as $value ) { $sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", $value ) . "'", $sql, 1 ); }
		return $sql;
	}
	public function query( $sql ) {
		if ( preg_match( "/INSERT IGNORE.*VALUES \\('([^']+)', '([^']+)', 'off'\\)/", $sql, $m ) ) {
			if ( isset( $this->rows[ $m[1] ] ) ) { return 0; }
			$this->rows[ $m[1] ] = $m[2]; return 1;
		}
		if ( preg_match( "/UPDATE .*SET option_value = '([^']+)' WHERE option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
			if ( ( $this->rows[ $m[2] ] ?? null ) !== $m[3] ) { return 0; }
			$this->rows[ $m[2] ] = $m[1]; return 1;
		}
		if ( preg_match( "/DELETE .*WHERE option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
			if ( ( $this->rows[ $m[1] ] ?? null ) !== $m[2] ) { return 0; }
			unset( $this->rows[ $m[1] ] ); return 1;
		}
		throw new Exception( $sql );
	}
	public function get_var( $sql ) {
		if ( ! preg_match( "/WHERE option_name = '([^']+)'/", $sql, $m ) ) { throw new Exception( $sql ); }
		return $this->rows[ $m[1] ] ?? null;
	}
}
$wpdb = new VST_Test_WPDB();
require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-electronic-invoice.php';
$api = 'Yoohw_Vietnam_Store_Tools_Electronic_Invoice';
$order = new WC_Order( 58 );
$order->meta[ $api::META_STATUS ] = 'adjusted';
$order->meta[ $api::META_NUMBER ] = 'OLD-1';
$order->meta[ Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED ] = 'yes';
$orders[58] = clone $order;
$before = $orders[58]->meta;
$read = $api::get_order_data( 58 );
vst_assert_same( 0, $read['workflow_revision'], 'Legacy revision defaults to zero' );
vst_assert_same( '', $read['current_document_id'], 'Legacy current document defaults to empty' );
vst_assert_same( [], $api::get_order_documents( 58 ), 'Legacy read does not invent document' );
vst_assert_same( $before, $orders[58]->meta, 'Legacy read makes no write' );
$legacy = $api::update_order_data( 58, [ 'status' => 'replaced' ], [ 'source' => 'old_connector' ] );
vst_assert_same( true, $legacy, 'Legacy status-only replacement remains accepted' );
vst_assert_same( [], $api::get_order_documents( 58 ), 'Legacy replacement does not fabricate lineage' );
$invalid = $api::update_order_data( 58, [ 'status' => 'ready' ], [ 'v2_strict' => true, 'expected_revision' => 0 ] );
vst_assert_true( is_wp_error( $invalid ), 'Strict ready requires invoice request fields' );
foreach ( [ 'company_name' => 'Acme', 'tax_code' => '0123456789', 'company_address' => 'Ha Noi', 'email' => 'invoice@example.test' ] as $key => $value ) {
	$orders[58]->meta[ '_yoohw_vietnam_store_tools_tax_invoice_' . $key ] = $value;
}
$captured = $api::record_order_document( 58, [ 'kind' => 'legacy' ], [ 'expected_revision' => 0, 'source' => 'admin' ] );
vst_assert_same( true, $captured, 'Legacy document captured explicitly' );
$data = $api::get_order_data( 58 );
vst_assert_same( 1, $data['workflow_revision'], 'Capture advances revision' );
vst_assert_same( 1, count( $api::get_order_documents( 58 ) ), 'Exactly one legacy snapshot' );
vst_assert_true( is_wp_error( $api::record_order_document( 58, [ 'kind' => 'legacy' ], [ 'expected_revision' => 1 ] ) ), 'Legacy snapshot cannot be repeated' );
$prior = $data['current_document_id'];
$doc = [ 'kind' => 'replacement', 'prior_document_id' => $prior, 'provider' => 'Neutral provider', 'number' => 'NEW-2', 'symbol' => 'SER-2', 'issued_at' => '2026-09-27T10:00:00Z' ];
$result = $api::record_order_document( 58, $doc, [ 'expected_revision' => 1, 'expected_current_document_id' => $prior, 'source' => 'admin' ] );
vst_assert_same( true, $result, 'Replacement document recorded' );
$documents = $api::get_order_documents( 58 );
vst_assert_same( $prior, $documents[1]['prior_document_id'], 'Replacement has exact prior link' );
vst_assert_same( 'OLD-1', $documents[0]['number'], 'Legacy snapshot retains old number' );
$new_data = $api::get_order_data( 58 );
vst_assert_same( 'replaced', $new_data['status'], 'Current projection reflects replacement' );
vst_assert_same( 2, $new_data['workflow_revision'], 'Document advances revision' );
vst_assert_true( is_wp_error( $api::record_order_document( 58, $doc, [ 'expected_revision' => 1, 'expected_current_document_id' => $prior ] ) ), 'Stale document write fails' );
$legacy_after = $api::update_order_data( $order, [ 'status' => 'adjusted', 'number' => 'LEGACY-EDIT' ], [ 'source' => 'old_connector' ] );
vst_assert_same( true, $legacy_after, 'Old connector adjustment from stale object remains accepted after v2 opt-in' );
vst_assert_same( 3, $api::get_order_data( 58 )['workflow_revision'], 'Legacy update advances v2 revision' );
vst_assert_same( 'NEW-2', $api::get_order_documents( 58 )[1]['number'], 'Later projection edit cannot rewrite document snapshot' );
vst_assert_true( is_wp_error( $api::record_order_document( 58, [ 'kind' => 'adjustment', 'prior_document_id' => $new_data['current_document_id'] ], [ 'expected_revision' => 2, 'expected_current_document_id' => $new_data['current_document_id'] ] ) ), 'Legacy update invalidates stale v2 tab' );
vst_assert_true( is_wp_error( $api::update_order_data( 58, [ 'status' => 'ready' ], [ 'v2_strict' => true, 'expected_revision' => 3, 'allowed_current_statuses' => [ 'requested', 'verified', 'ready' ] ] ) ), 'Bulk ready guard rejects issued or adjusted current state' );
$ready_order = new WC_Order( 59 );
$ready_order->meta[ $api::META_STATUS ] = 'ready';
$ready_order->meta[ Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED ] = 'yes';
$orders[59] = clone $ready_order;
vst_assert_true( is_wp_error( $api::update_order_data( 59, [ 'status' => 'ready' ], [ 'v2_strict' => true, 'expected_revision' => 0, 'allowed_current_statuses' => [ 'requested', 'verified' ] ] ) ), 'Bulk ready skips an already ready order' );
$fresh = $api::get_order_data( 58 );
$adjustment = [ 'kind' => 'adjustment', 'prior_document_id' => $fresh['current_document_id'], 'provider' => 'Neutral provider', 'number' => 'ADJ-3', 'symbol' => 'SER-3', 'issued_at' => '2026-09-27T11:00:00Z' ];
$adjusted = $api::record_order_document( 58, $adjustment, [ 'expected_revision' => 3, 'expected_current_document_id' => $fresh['current_document_id'], 'source' => 'integration' ] );
vst_assert_same( true, $adjusted, 'Adjustment document recorded after legacy edit' );
vst_assert_same( 'adjustment', $api::get_order_documents( 58 )[2]['kind'], 'Adjustment kind is explicit' );
vst_assert_same( $fresh['current_document_id'], $api::get_order_documents( 58 )[2]['prior_document_id'], 'Adjustment links exact predecessor' );
vst_assert_true( is_wp_error( $api::update_order_data( 58, [ 'issued_at' => '2026-02-31T10:00:00Z' ], [ 'v2_strict' => true, 'expected_revision' => 4 ] ) ), 'Invalid calendar date rejected' );
$after = $api::get_order_data( 58 );
vst_assert_true( is_wp_error( $api::record_order_document( 58, [ 'kind' => 'replacement', 'prior_document_id' => $after['current_document_id'], 'provider' => "Bad\nprovider", 'number' => '5', 'symbol' => 'S', 'issued_at' => '2026-09-27T10:00:00Z' ], [ 'expected_revision' => 4, 'expected_current_document_id' => $after['current_document_id'] ] ) ), 'Document identity control character rejected before sanitization' );
vst_assert_true( is_wp_error( $api::update_order_data( 58, [ 'provider' => "Bad\tprovider" ], [ 'v2_strict' => true, 'expected_revision' => 4 ] ) ), 'Strict update identity control character rejected before sanitization' );
vst_assert_true( is_wp_error( $api::record_order_document( 58, [ 'kind' => 'replacement', 'prior_document_id' => 'other-order', 'provider' => 'N', 'number' => '5', 'symbol' => 'S', 'issued_at' => '2026-09-27T10:00:00Z' ], [ 'expected_revision' => 4, 'expected_current_document_id' => $after['current_document_id'] ] ) ), 'Foreign predecessor rejected' );
$lock_name = '_yoohw_vst_einvoice_lock_58';
$wpdb->rows[ $lock_name ] = 'active|' . ( time() + 20 );
vst_assert_true( is_wp_error( $api::record_order_document( 58, $adjustment, [ 'expected_revision' => 4, 'expected_current_document_id' => $after['current_document_id'] ] ) ), 'Active lock blocks second writer' );
$wpdb->rows[ $lock_name ] = 'expired|' . ( time() - 1 );
$retry = $api::update_order_data( 58, [ 'handoff_reference' => 'R-1' ], [ 'source' => 'old_connector' ] );
vst_assert_same( true, $retry, 'Expired lock is taken over via CAS' );
vst_assert_same( 5, $api::get_order_data( 58 )['workflow_revision'], 'Handoff update advances revision' );
$pdf_path = tempnam( sys_get_temp_dir(), 'vst58' ) . '.pdf';
file_put_contents( $pdf_path, '%PDF-1.4 test' );
$attachments[81] = [ 'type' => 'attachment', 'path' => $pdf_path, 'mime' => 'application/pdf', 'order_id' => 999, 'url' => 'https://example.test/test.pdf', 'title' => 'test.pdf' ];
vst_assert_true( is_wp_error( $api::update_order_data( 58, [ 'pdf_attachment_id' => 81 ], [ 'v2_strict' => true, 'expected_revision' => 5 ] ) ), 'Strict v2 rejects foreign attachment' );
$attachments[81]['order_id'] = 58;
$attachments[81]['mime'] = 'image/jpeg';
vst_assert_true( is_wp_error( $api::update_order_data( 58, [ 'pdf_attachment_id' => 81 ], [ 'v2_strict' => true, 'expected_revision' => 5 ] ) ), 'Strict v2 rejects wrong MIME' );
$attachments[81]['mime'] = 'application/pdf';
vst_assert_same( true, $api::update_order_data( 58, [ 'pdf_attachment_id' => 81 ], [ 'v2_strict' => true, 'expected_revision' => 5 ] ), 'Strict v2 accepts same-order readable PDF' );
vst_assert_same( 6, $api::get_order_data( 58 )['workflow_revision'], 'Attachment write advances revision' );
$attachments[82] = [ 'type' => 'attachment', 'path' => '/missing/invoice.pdf', 'mime' => 'application/pdf', 'order_id' => 58, 'url' => '', 'title' => 'missing.pdf' ];
vst_assert_true( is_wp_error( $api::update_order_data( 58, [ 'pdf_attachment_id' => 82 ], [ 'v2_strict' => true, 'expected_revision' => 6 ] ) ), 'Strict v2 rejects missing file' );
$attachments[82]['order_id'] = 999;
vst_assert_same( true, $api::update_order_data( 58, [ 'pdf_attachment_id' => 82 ], [ 'source' => 'old_connector' ] ), 'Legacy attachment semantics remain permissive' );
vst_assert_same( 7, $api::get_order_data( 58 )['workflow_revision'], 'Legacy attachment update advances revision' );
unlink( $pdf_path );
$before_cap = $api::get_order_documents( 58 );
$orders[58]->meta[ $api::META_DOCUMENTS ] = array_fill( 0, 50, $before_cap[0] );
$cap_result = $api::record_order_document( 58, [ 'kind' => 'adjustment', 'prior_document_id' => $after['current_document_id'], 'provider' => 'N', 'number' => '6', 'symbol' => 'S', 'issued_at' => '2026-09-27T10:00:00Z' ], [ 'expected_revision' => 7, 'expected_current_document_id' => $after['current_document_id'] ] );
vst_assert_true( is_wp_error( $cap_result ), 'Document cap fails closed' );
vst_assert_same( 50, count( $api::get_order_documents( 58 ) ), 'Cap never evicts old documents' );
vst_finish_contract_suite( 'VST-58 electronic invoice' );
