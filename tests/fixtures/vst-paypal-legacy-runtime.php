<?php
/** Transport-only fixture for an explicitly isolated disposable local WordPress. */
if ( ! defined( 'VST_PPCP_LEGACY_LOCAL_TEST' ) || ! VST_PPCP_LEGACY_LOCAL_TEST
	|| 'local' !== wp_get_environment_type() || '127.0.0.1' !== parse_url( WP_HOME, PHP_URL_HOST )
	|| 0 !== strpos( DB_NAME, 'vst104_' ) ) {
	throw new RuntimeException( 'Disposable VST-104 local environment required.' );
}

add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
foreach ( array( 'wp_ajax_yoohw_paypal_usd_quote', 'wp_ajax_nopriv_yoohw_paypal_usd_quote' ) as $quote_action ) {
	add_action( $quote_action, static function () {
		if ( get_option( 'vst104_fail_quote' ) ) { wp_send_json_error( array( 'message' => 'Simulated quote failure.' ), 503 ); }
	}, 0 );
}

// Intercept browser navigation only after real WC/PPCP checkout has returned a redirect.
function vst104_local_redirect( $url, $order_id ) {
	if ( is_string( $url ) && false !== strpos( $url, 'sandbox.paypal.com/checkoutnow' ) ) {
		update_option( 'vst104_browser_redirect_' . (int) $order_id, $url, false );
		return add_query_arg( 'vst104-checkout-result', (int) $order_id, WP_HOME );
	}
	return $url;
}
add_filter( 'woocommerce_payment_successful_result', static function ( $result, $id ) {
	$result['redirect'] = vst104_local_redirect( $result['redirect'] ?? '', $id );
	return $result;
}, PHP_INT_MAX, 2 );
add_filter( 'rest_post_dispatch', static function ( $response, $server, $request ) {
	if ( 0 === strpos( $request->get_route(), '/wc/store/' ) && $response instanceof WP_REST_Response ) {
		$data = $response->get_data();
		$trace = get_option( 'vst104_rest', array() );
		$trace[] = array( 'route' => $request->get_route(), 'method' => $request->get_method(), 'payment_methods' => $data['payment_methods'] ?? null, 'posted_method' => $request->get_param( 'payment_method' ) );
		update_option( 'vst104_rest', $trace, false );
	}
	if ( '/wc/store/v1/checkout' === $request->get_route() && $response instanceof WP_REST_Response ) {
		$data = $response->get_data();
		if ( isset( $data['payment_result']['redirect_url'], $data['order_id'] ) ) {
			$data['payment_result']['redirect_url'] = vst104_local_redirect( $data['payment_result']['redirect_url'], $data['order_id'] );
			$response->set_data( $data );
		}
	}
	return $response;
}, PHP_INT_MAX, 3 );
add_action( 'template_redirect', static function () {
	if ( isset( $_GET['vst104-checkout-result'] ) ) {
		$id = absint( $_GET['vst104-checkout-result'] );
		$order = wc_get_order( $id );
		if ( $order && get_option( 'vst104_browser_redirect_' . $id ) ) {
			echo '<h1>SIMULATED PayPal redirect intercepted locally</h1><p>No external browser navigation or real payment.</p><p>' . esc_html( 'Order ' . $id . ': ' . $order->get_currency() . ' ' . $order->get_total() ) . '</p>';
			exit;
		}
	}
} );
add_filter( 'script_loader_src', static function ( $src, $handle ) {
	if ( 'yoohw-vietnam-store-tools-paypal-vnd-usd' === $handle ) {
		return add_query_arg( 'vst104', filemtime( dirname( __DIR__, 2 ) . '/assets/js/frontend/paypal-vnd-usd.js' ), $src );
	}
	return $src;
}, 10, 2 );

// Fault injection is confined to the disposable fixture, before PPCP bootstraps.
add_action( 'plugins_loaded', static function () {
	$scenario = get_option( 'vst104_bootstrap_scenario', '' );
	if ( 'force-v6' === $scenario ) {
		add_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.sdk_v6_enabled', '__return_true', PHP_INT_MAX );
	} elseif ( 'missing-flag' === $scenario ) {
		remove_all_filters( 'woocommerce.feature-flags.woocommerce_paypal_payments.sdk_v6_enabled' );
	}
}, -1 );

function vst104_response( $data, $code = 200 ) {
	return array( 'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'SIMULATED' ), 'cookies' => array(), 'filename' => null );
}

add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) {
	$host = parse_url( $url, PHP_URL_HOST );
	if ( ! in_array( $host, array( 'api-m.sandbox.paypal.com', 'api.sandbox.paypal.com' ), true ) ) {
		return new WP_Error( 'vst104_external_http_blocked', 'All external traffic is blocked in this disposable fixture.' );
	}
	$path   = parse_url( $url, PHP_URL_PATH );
	$method = strtoupper( $args['method'] ?? 'GET' );
	$body   = json_decode( $args['body'] ?? '', true );
	$log    = get_option( 'vst104_http', array() );
	$log[]  = array( 'method' => $method, 'path' => $path, 'body' => is_array( $body ) ? $body : null );
	update_option( 'vst104_http', $log, false );
	if ( '/v1/oauth2/token' === $path ) {
		return vst104_response( array( 'access_token' => 'SIMULATED-NOT-A-REAL-TOKEN', 'token_type' => 'Bearer', 'expires_in' => 32000, 'scope' => 'https://uri.paypal.com/services/payments/payment' ) );
	}
	if ( false !== strpos( $path, '/merchant-integrations/' ) ) {
		return vst104_response( array( 'merchant_id' => 'SIMULATED-MERCHANT', 'payments_receivable' => true, 'primary_email_confirmed' => true, 'products' => array() ) );
	}
	if ( '/v2/checkout/orders' === $path && 'POST' === $method ) {
		$id = 'VST104-' . ( 1 + (int) get_option( 'vst104_sequence', 0 ) );
		update_option( 'vst104_sequence', (int) get_option( 'vst104_sequence', 0 ) + 1 );
		$order = array_merge( $body, array( 'id' => $id, 'status' => 'PAYER_ACTION_REQUIRED', 'create_time' => gmdate( 'c' ), 'links' => array( array( 'rel' => 'payer-action', 'href' => WP_HOME . '/?simulated-paypal-approval=' . $id ) ) ) );
		update_option( 'vst104_order_' . $id, $order, false );
		return vst104_response( $order, 201 );
	}
	if ( preg_match( '#^/v2/checkout/orders/(VST104-[0-9]+)(/capture)?$#', $path, $match ) ) {
		$order = get_option( 'vst104_order_' . $match[1] );
		if ( ! is_array( $order ) ) {
			return vst104_response( array( 'name' => 'RESOURCE_NOT_FOUND' ), 404 );
		}
		if ( 'PATCH' === $method ) {
			return vst104_response( null, 204 );
		}
		if ( ! empty( $match[2] ) ) {
			if ( get_option( 'vst104_fail_capture' ) ) {
				return vst104_response( array( 'name' => 'UNPROCESSABLE_ENTITY', 'details' => array( array( 'issue' => 'INSTRUMENT_DECLINED', 'description' => 'Simulated failure' ) ) ), 422 );
			}
			$order['status'] = 'COMPLETED';
			$order['purchase_units'][0]['payments'] = array( 'captures' => array( array( 'id' => 'CAPTURE-' . $match[1], 'status' => 'COMPLETED', 'final_capture' => true, 'amount' => $order['purchase_units'][0]['amount'] ) ) );
			update_option( 'vst104_order_' . $match[1], $order, false );
		}
		return vst104_response( $order, empty( $match[2] ) ? 200 : 201 );
	}
	if ( preg_match( '#^/v2/payments/captures/CAPTURE-VST104-[0-9]+/refund$#', $path ) && 'POST' === $method ) {
		if ( get_option( 'vst104_fail_refund' ) ) {
			return new WP_Error( 'vst104_simulated_refund_failure', 'Simulated refund transport failure.' );
		}
		return vst104_response( array( 'id' => 'REFUND-' . count( $log ), 'status' => 'COMPLETED', 'amount' => $body['amount'] ), 201 );
	}
	return new WP_Error( 'vst104_unexpected_paypal_http', 'Unimplemented simulated PayPal endpoint: ' . $method . ' ' . $path );
}, PHP_INT_MAX, 3 );
