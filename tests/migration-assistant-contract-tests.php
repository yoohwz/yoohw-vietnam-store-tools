<?php
/** Exercise real scanner/AJAX control flow with a read-only failing-record fixture. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require __DIR__ . '/support/assertions.php';
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { return $value; }
function __( $value ) { return $value; }
function esc_html__( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wc_clean( $value ) { return trim( (string) $value ); }
function wc_strtoupper( $value ) { return strtoupper( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z_]/', '', $value ); }
function get_locale() { return 'en_US'; }
function current_user_can( $cap ) { return 'manage_woocommerce' === $cap && $GLOBALS['allowed']; }
function check_ajax_referer() { return $GLOBALS['nonce']; }
function wc_get_order( $id ) { $GLOBALS['order_loads']++; return false; }
function get_userdata( $id ) { $GLOBALS['user_loads']++; return false; }
class VST_JSON_Response extends Error {
	public $payload;
	public function __construct( $success, $data, $status ) { $this->payload = [ 'success' => $success, 'data' => $data, 'status' => $status ]; }
}
function wp_send_json_success( $data ) { throw new VST_JSON_Response( true, $data, 200 ); }
function wp_send_json_error( $data, $status = 200 ) { throw new VST_JSON_Response( false, $data, $status ); }
class Yoohw_Vietnam_Store_Tools_Request_Security {
	public static function get_post_text( $key, $default = '' ) { return $GLOBALS['mode']; }
}
class VST_Scan_DB {
	public $postmeta = 'postmeta';
	public $posts = 'posts';
	public $users = 'users';
	public $usermeta = 'usermeta';
	public $reads = 0;
	public $rows = [];
	public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
	public function get_col( $query ) { $this->reads++; return []; }
	public function get_results( $query, $format ) {
		$this->reads++;
		return 'billing' === $query[1][0] ? $this->rows : [];
	}
}
$GLOBALS['wpdb'] = new VST_Scan_DB();
foreach ( [ 'vietnam-address-data', 'devvn-migration-tools' ] as $file ) { require dirname( __DIR__ ) . '/includes/class-vietnam-commerce-kit-' . $file . '.php'; }
$engine = new Yoohw_Vietnam_Store_Tools_DevVN_Migration_Tools();
$method = new ReflectionMethod( $engine, 'get_address_migration' );
$method->setAccessible( true );
$map = new ReflectionMethod( $engine, 'get_ward_to_province_map' );
$map->setAccessible( true );
foreach ( $map->invoke( $engine ) as $ward => $province ) {
	$safe = [ 'country' => 'VN', 'state' => '', 'city' => (string) $ward, 'address_2' => '', 'address_type' => 'billing' ];
	if ( $method->invoke( $engine, $safe ) ) { break; }
}
for ( $i = 1; $i <= 201; $i++ ) { $GLOBALS['wpdb']->rows[] = $safe + [ 'order_id' => $i, 'user_id' => $i ]; }
for ( $i = 202; $i <= 220; $i++ ) { $GLOBALS['wpdb']->rows[] = array_merge( $safe, [ 'order_id' => $i, 'user_id' => $i, 'city' => '99999' ] ); }
function request( $mode, $allowed = true, $nonce = true ) {
	global $engine;
	$GLOBALS['mode'] = $mode;
	$GLOBALS['allowed'] = $allowed;
	$GLOBALS['nonce'] = $nonce;
	$GLOBALS['order_loads'] = 0;
	$GLOBALS['user_loads'] = 0;
	$GLOBALS['wpdb']->reads = 0;
	try { $engine->ajax_migration_step(); } catch ( VST_JSON_Response $response ) { return $response->payload; }
	throw new RuntimeException( 'Missing JSON response' );
}
foreach ( [ 'scan', 'start', 'step' ] as $mode ) {
	$response = request( $mode, false );
	vst_assert_same( false, $response['success'], 'Capability denial: ' . $mode );
	vst_assert_same( 0, $GLOBALS['wpdb']->reads, 'Capability denial does not access corpus' );
	$response = request( $mode, true, false );
	vst_assert_same( 403, $response['status'], 'Invalid nonce: ' . $mode );
	vst_assert_same( 0, $GLOBALS['wpdb']->reads, 'Invalid nonce does not access corpus' );
}
$response = request( 'invalid' );
vst_assert_same( 400, $response['status'], 'Unknown mode fails closed' );
vst_assert_same( 0, $GLOBALS['wpdb']->reads, 'Unknown mode does not migrate' );
$scan = request( 'scan' );
vst_assert_same( 201, $scan['data']['addressesSafe'], 'Same exact-safe scanner' );
vst_assert_same( 19, $scan['data']['addressesReview'], 'Unknown rows remain manual review' );
vst_assert_same( 0, $GLOBALS['order_loads'], 'Scan never loads an order for writing' );
vst_assert_same( 0, $GLOBALS['user_loads'], 'Scan never loads a user for writing' );
vst_assert_true( false === strpos( $scan['data']['report'], '#210' ), 'Examples stop after eight per address domain' );
vst_assert_same( $scan['data'], request( 'start' )['data'], 'Status Tools start and assistant scan share results' );
$step = request( 'step' )['data'];
vst_assert_same( 200, $GLOBALS['order_loads'], 'Order write batch capped at 200' );
vst_assert_same( 200, $GLOBALS['user_loads'], 'Customer write batch capped at 200' );
vst_assert_same( 5, count( $step['step']['addressErrors'] ), 'Order errors bounded' );
vst_assert_same( 5, count( $step['step']['customerAddressErrors'] ), 'Customer errors bounded' );
vst_assert_same( true, $step['stopped'], 'Zero progress stops safely' );
vst_assert_same( true, $step['done'], 'No further batch scheduled after stalled step' );
vst_assert_same( 402, $step['remaining'], 'Stalled rows remain visible for operator' );
// Write functions are deliberately undefined: read-only scan or rejected requests must never call them.
vst_finish_contract_suite( 'migration-assistant' );
