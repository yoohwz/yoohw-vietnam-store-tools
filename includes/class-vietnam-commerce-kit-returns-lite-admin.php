<?php
/**
 * Order-admin controls for manual exceptions and Returns Lite.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Returns_Lite_Admin {
	private $footer_forms = [];
	private $active_form = '';

	public function __construct() {
		add_action( 'yoohw_vietnam_store_tools_shipping_admin_metabox_after', [ $this, 'render_exceptions' ], 30 );
		add_action( 'add_meta_boxes', [ $this, 'add_returns_metabox' ] );
		add_action( 'admin_post_yoohw_vietnam_store_tools_record_exception', [ $this, 'handle_exception' ] );
		add_action( 'admin_post_yoohw_vietnam_store_tools_return_action', [ $this, 'handle_return' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'admin_footer', [ $this, 'render_action_forms' ] );
	}

	public function render_exceptions( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		$domain = 'Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions';
		$current = $domain::get_current_shipment( $order );
		$history = $domain::get_exceptions( $order );
		if ( empty( $current['id'] ) && ! $history ) {
			return;
		}
		echo '<hr><h4>' . esc_html__( 'Fulfillment exceptions', 'yoohw-vietnam-store-tools' ) . '</h4>';
		if ( ! empty( $current['id'] ) ) {
			$data = isset( $current['data'] ) ? $current['data'] : [];
			echo '<p><strong>' . esc_html__( 'Current shipment', 'yoohw-vietnam-store-tools' ) . ':</strong> ' . esc_html( $current['id'] ) . '<br>';
			echo esc_html( isset( $data['provider_name'] ) ? $data['provider_name'] : '' ) . ' ' . esc_html( isset( $data['tracking_code'] ) ? $data['tracking_code'] : '' ) . '<br>';
			echo esc_html( $current['closed'] ? __( 'Closed', 'yoohw-vietnam-store-tools' ) : __( 'Open', 'yoohw-vietnam-store-tools' ) ) . '</p>';
		}
		if ( $history ) {
			echo '<ul>';
			foreach ( array_reverse( $history ) as $entry ) {
				echo '<li><strong>' . esc_html( self::exception_label( $entry['type'] ) ) . '</strong> · ' . esc_html( isset( $entry['occurred_at'] ) ? $entry['occurred_at'] : '' );
				echo '<br>' . esc_html__( 'Shipment', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( isset( $entry['shipment_id'] ) ? $entry['shipment_id'] : '' );
				if ( ! empty( $entry['parent_shipment_id'] ) || ! empty( $entry['replacement_shipment_id'] ) ) {
					echo '<br>' . esc_html__( 'Predecessor / replacement', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( isset( $entry['parent_shipment_id'] ) ? $entry['parent_shipment_id'] : '' ) . ' / ' . esc_html( isset( $entry['replacement_shipment_id'] ) ? $entry['replacement_shipment_id'] : '' );
				}
				echo '<br>' . esc_html__( 'Source / actor', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( isset( $entry['source_id'] ) ? $entry['source_id'] : '' ) . ' / ' . esc_html( isset( $entry['actor_id'] ) ? $entry['actor_id'] : 0 );
				if ( ! empty( $entry['note'] ) ) {
					echo '<br>' . esc_html( $entry['note'] );
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $current['id'] ) && ! $current['closed'] ) {
			$this->action_form_open();
			wp_nonce_field( 'yoohw_vst_exception_' . $order->get_id(), 'yoohw_vst_nonce' );
			echo '<input type="hidden" name="action" value="yoohw_vietnam_store_tools_record_exception">';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
			echo '<input type="hidden" name="expected_shipment_id" value="' . esc_attr( $current['id'] ) . '">';
			echo '<p><label>' . esc_html__( 'Record exception', 'yoohw-vietnam-store-tools' ) . ' <select name="exception_type">';
			foreach ( [ 'failed_handoff', 'delivery_failed', 'returned_to_sender' ] as $type ) {
				echo '<option value="' . esc_attr( $type ) . '">' . esc_html( self::exception_label( $type ) ) . '</option>';
			}
			echo '</select></label></p><p><label>' . esc_html__( 'Operator note (optional)', 'yoohw-vietnam-store-tools' ) . '<br><textarea name="note" rows="2" style="width:100%"></textarea></label></p>';
			submit_button( __( 'Record exception', 'yoohw-vietnam-store-tools' ), 'secondary', 'submit', false );
			$this->action_form_close();
		}
	}

	public function add_returns_metabox() {
		$order = Yoohw_Vietnam_Store_Tools_Request_Security::get_admin_order_from_query();
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box( 'yoohw-vst-returns-lite', __( 'Returns Lite', 'yoohw-vietnam-store-tools' ), [ $this, 'render_returns_metabox' ], $screen, 'normal', 'default' );
		}
	}

	public function render_returns_metabox( $object ) {
		$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : false );
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		$returns = Yoohw_Vietnam_Store_Tools_Returns_Lite::get_returns( $order );
		$ledger = Yoohw_Vietnam_Store_Tools_Returns_Lite::get_ledger( $order );
		$items = $order->get_items( 'line_item' );
		$refunds = $order->get_refunds();
		$allocated = [];
		foreach ( $returns as $record ) {
			if ( 'cancelled' === $record['state'] ) {
				continue;
			}
			foreach ( $record['items'] as $item ) {
				$id = (int) $item['order_item_id'];
				$allocated[ $id ] = isset( $allocated[ $id ] ) ? $allocated[ $id ] + (int) $item['quantity'] : (int) $item['quantity'];
			}
		}
		if ( ! $returns ) {
			echo '<p>' . esc_html__( 'No returns recorded for this order.', 'yoohw-vietnam-store-tools' ) . '</p>';
		}
		foreach ( $returns as $record ) {
			$this->render_return( $order, $record, $items, $refunds, $ledger['events'], $allocated );
		}
		echo '<hr><h4>' . esc_html__( 'Create manual return', 'yoohw-vietnam-store-tools' ) . '</h4>';
		echo '<p>' . esc_html__( 'Choose product items and whole-unit quantities. This does not create a refund or restock products.', 'yoohw-vietnam-store-tools' ) . '</p>';
		$this->form_open( $order, 'create', '', 0 );
		echo '<input type="hidden" name="ledger_revision" value="' . esc_attr( $ledger['revision'] ) . '">';
		$this->render_item_fields( $items, [] );
		echo '<p><label>' . esc_html__( 'Reason', 'yoohw-vietnam-store-tools' ) . '<br><input type="text" name="reason" class="widefat" required></label></p>';
		echo '<p><label>' . esc_html__( 'Operator note (optional)', 'yoohw-vietnam-store-tools' ) . '<br><textarea name="note" class="widefat" rows="2"></textarea></label></p>';
		submit_button( __( 'Create return', 'yoohw-vietnam-store-tools' ), 'secondary', 'submit', false );
		$this->action_form_close();
	}

	private function render_return( $order, $record, $order_items, $refunds, $events, $allocated ) {
		$labels = [ 'open' => __( 'Open', 'yoohw-vietnam-store-tools' ), 'received' => __( 'Goods received', 'yoohw-vietnam-store-tools' ), 'closed' => __( 'Closed', 'yoohw-vietnam-store-tools' ), 'cancelled' => __( 'Return voided', 'yoohw-vietnam-store-tools' ) ];
		$state = $record['state'];
		echo '<div class="vst-return-record"><h4>' . esc_html( $labels[ $state ] ) . ' · ' . esc_html( $record['id'] ) . '</h4>';
		echo '<p>' . esc_html( $record['reason'] ) . '</p><ul>';
		foreach ( $record['items'] as $item ) {
			$id = (int) $item['order_item_id'];
			$existing = isset( $order_items[ $id ] ) && $order_items[ $id ] instanceof WC_Order_Item_Product ? $order_items[ $id ] : null;
			echo '<li>' . esc_html( $item['name'] ) . ' · ' . esc_html( $item['sku'] ) . ' · ' . esc_html( $item['quantity'] ) . ' / ' . esc_html( $item['ordered_quantity'] ) . ' (#' . esc_html( $id ) . ')';
			if ( ! $existing || (int) $existing->get_quantity() < ( isset( $allocated[ $id ] ) ? $allocated[ $id ] : (int) $item['quantity'] ) ) {
				echo ' <strong>' . esc_html__( 'Order item changed or missing', 'yoohw-vietnam-store-tools' ) . '</strong>';
			}
			echo '</li>';
		}
		echo '</ul>';
		$outbound = $record['outbound_shipment'];
		if ( ! empty( $outbound['id'] ) ) {
			$current = Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions::get_current_shipment( $order );
			echo '<p>' . esc_html__( 'Original shipment', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $outbound['id'] ) . ' · ' . esc_html( $outbound['provider'] ) . ' · ' . esc_html( $outbound['tracking_code'] );
			if ( $current['id'] !== $outbound['id'] ) {
				echo ' (' . esc_html__( 'historical reference', 'yoohw-vietnam-store-tools' ) . ')';
			}
			echo '</p>';
		}
		if ( ! empty( $record['returned_exception_id'] ) ) {
			echo '<p>' . esc_html__( 'Return-to-sender exception', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $record['returned_exception_id'] );
			$found = false;
			foreach ( Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions::get_exceptions( $order ) as $entry ) {
				$found = $found || $record['returned_exception_id'] === $entry['id'];
			}
			if ( ! $found ) {
				echo ' (' . esc_html__( 'missing reference', 'yoohw-vietnam-store-tools' ) . ')';
			}
			echo '</p>';
		}
		if ( ! empty( $record['reverse_reference'] ) ) {
			echo '<p>' . esc_html__( 'Reverse shipment reference', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $record['reverse_reference'] ) . '</p>';
		}
		foreach ( $record['refund_ids'] as $refund_id ) {
			$matched = null;
			foreach ( $refunds as $refund ) {
				if ( $refund instanceof WC_Order_Refund && (int) $refund_id === $refund->get_id() ) {
					$matched = $refund;
				}
			}
			echo '<p>' . esc_html__( 'Linked refund', 'yoohw-vietnam-store-tools' ) . ' ';
			echo $matched ? '<a href="' . esc_url( $order->get_edit_order_url() . '#woocommerce-order-items' ) . '">#' . esc_html( $refund_id ) . '</a>: ' : '#' . esc_html( $refund_id ) . ': ';
			echo $matched ? esc_html( wp_strip_all_tags( wc_price( abs( $matched->get_amount() ), [ 'currency' => $order->get_currency() ] ) ) . ' · ' . $matched->get_reason() ) : esc_html__( 'missing refund', 'yoohw-vietnam-store-tools' );
			echo '</p>';
			$this->form_open( $order, 'unlink_refund', $record['id'], $record['revision'] );
			echo '<input type="hidden" name="refund_id" value="' . esc_attr( $refund_id ) . '">';
			submit_button( __( 'Unlink refund', 'yoohw-vietnam-store-tools' ), 'secondary', 'submit', false );
			$this->action_form_close();
		}
		if ( $refunds ) {
			$this->form_open( $order, 'link_refund', $record['id'], $record['revision'] );
			echo '<select name="refund_id">';
			foreach ( $refunds as $refund ) {
				if ( $refund instanceof WC_Order_Refund && ! in_array( $refund->get_id(), $record['refund_ids'], true ) ) {
					echo '<option value="' . esc_attr( $refund->get_id() ) . '">#' . esc_html( $refund->get_id() ) . '</option>';
				}
			}
			echo '</select> ';
			submit_button( __( 'Link existing refund', 'yoohw-vietnam-store-tools' ), 'secondary', 'submit', false );
			$this->action_form_close();
		}
		if ( 'open' === $state ) {
			$this->form_open( $order, 'correct_items', $record['id'], $record['revision'] );
			$this->render_item_fields( $order_items, $record['items'] );
			submit_button( __( 'Save return items', 'yoohw-vietnam-store-tools' ), 'secondary', 'submit', false );
			$this->action_form_close();
		}
		$this->form_open( $order, 'correct', $record['id'], $record['revision'] );
		echo '<p><label>' . esc_html__( 'Reason', 'yoohw-vietnam-store-tools' ) . '<br><input type="text" name="reason" class="widefat" value="' . esc_attr( $record['reason'] ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Operator note', 'yoohw-vietnam-store-tools' ) . '<br><textarea name="note" class="widefat" rows="2"></textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Reverse shipment reference', 'yoohw-vietnam-store-tools' ) . '<br><input type="text" name="reverse_reference" class="widefat" value="' . esc_attr( $record['reverse_reference'] ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Return-to-sender exception ID (optional)', 'yoohw-vietnam-store-tools' ) . '<br><input type="text" name="returned_exception_id" class="widefat" value="' . esc_attr( $record['returned_exception_id'] ) . '"></label></p>';
		submit_button( __( 'Save return correction', 'yoohw-vietnam-store-tools' ), 'secondary', 'submit', false );
		$this->action_form_close();
		if ( in_array( $state, [ 'open', 'received' ], true ) ) {
			$next_states = 'open' === $state ? [ 'received', 'closed', 'cancelled' ] : [ 'closed', 'cancelled' ];
			foreach ( $next_states as $next ) {
				$this->form_open( $order, 'transition', $record['id'], $record['revision'] );
				echo '<input type="hidden" name="state" value="' . esc_attr( $next ) . '">';
				echo '<p><label>' . esc_html__( 'Transition note (optional)', 'yoohw-vietnam-store-tools' ) . '<br><input type="text" name="note" class="widefat"></label></p>';
				submit_button( 'cancelled' === $next ? __( 'Void this return', 'yoohw-vietnam-store-tools' ) : $labels[ $next ], 'secondary', 'submit', false );
				$this->action_form_close();
			}
		}
		echo '<details><summary>' . esc_html__( 'Audit history', 'yoohw-vietnam-store-tools' ) . '</summary><ul>';
		foreach ( $events as $event ) {
			if ( $record['id'] === $event['return_id'] ) {
				echo '<li>' . esc_html( $event['occurred_at'] ) . ' · ' . esc_html( $event['kind'] ) . ' · ' . esc_html( $event['state'] ) . ' · ' . esc_html__( 'Actor', 'yoohw-vietnam-store-tools' ) . ' #' . esc_html( $event['actor_id'] );
				if ( ! empty( $event['changes']['note'] ) ) {
					echo ' · ' . esc_html( $event['changes']['note'] );
				}
				echo '<br><code>' . esc_html( wp_json_encode( $event['changes'] ) ) . '</code></li>';
			}
		}
		echo '</ul></details></div><hr>';
	}

	private function render_item_fields( $order_items, $selected ) {
		$quantities = [];
		foreach ( $selected as $item ) {
			$quantities[ $item['order_item_id'] ] = $item['quantity'];
		}
		foreach ( $order_items as $id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			echo '<p><label>' . esc_html( $item->get_name() ) . ' (#' . esc_html( $id ) . ', ' . esc_html__( 'ordered', 'yoohw-vietnam-store-tools' ) . ' ' . esc_html( $item->get_quantity() ) . ') ';
			echo '<input type="number" min="0" step="1" name="items[' . esc_attr( $id ) . ']" value="' . esc_attr( isset( $quantities[ $id ] ) ? $quantities[ $id ] : 0 ) . '" style="width:75px"></label></p>';
		}
	}

	private function form_open( $order, $operation, $return_id, $revision ) {
		$this->action_form_open();
		wp_nonce_field( 'yoohw_vst_return_' . $order->get_id(), 'yoohw_vst_nonce' );
		echo '<input type="hidden" name="action" value="yoohw_vietnam_store_tools_return_action">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
		echo '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		echo '<input type="hidden" name="return_id" value="' . esc_attr( $return_id ) . '">';
		echo '<input type="hidden" name="return_revision" value="' . esc_attr( $revision ) . '">';
	}

	private function action_form_open() {
		$this->active_form = 'yoohw-vst-action-' . count( $this->footer_forms );
		$this->footer_forms[] = $this->active_form;
		ob_start();
	}

	private function action_form_close() {
		$html = ob_get_clean();
		// The order editor already owns an outer form. Associate controls with a footer form.
		$html = preg_replace( '/<(input|select|textarea|button)\b/i', '<$1 form="' . esc_attr( $this->active_form ) . '"', $html );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic field is escaped when composed above.
		echo $html;
		$this->active_form = '';
	}

	public function render_action_forms() {
		foreach ( $this->footer_forms as $form_id ) {
			echo '<form id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"></form>';
		}
	}

	public function handle_exception() {
		$order = $this->authorized_order( 'yoohw_vst_exception_' );
		$type = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'exception_type' ) );
		if ( ! in_array( $type, [ 'failed_handoff', 'delivery_failed', 'returned_to_sender' ], true ) ) {
			$this->redirect( $order, __( 'Invalid manual exception type.', 'yoohw-vietnam-store-tools' ) );
		}
		$result = Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions::record_exception( $order, [ 'type' => $type, 'expected_shipment_id' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'expected_shipment_id' ) ], [ 'note' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_textarea( 'note' ) ] );
		$this->redirect( $order, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	public function handle_return() {
		$order = $this->authorized_order( 'yoohw_vst_return_' );
		$operation = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'operation' ) );
		$data = [
			'reason' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'reason' ),
			'note' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_textarea( 'note' ),
			'reverse_reference' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'reverse_reference' ),
			'returned_exception_id' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'returned_exception_id' ),
			'refund_id' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'refund_id' ),
			'state' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'state' ),
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorized_order() verified this form's order-specific nonce above.
		if ( in_array( $operation, [ 'create', 'correct_items' ], true ) && isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorized_order() verified the nonce; the domain validates item IDs, ownership and quantities.
			$data['items'] = array_filter( wp_unslash( $_POST['items'] ), static function ( $quantity ) { return is_scalar( $quantity ) && '' !== (string) $quantity && '0' !== (string) $quantity; } );
		}
		$domain = 'Yoohw_Vietnam_Store_Tools_Returns_Lite';
		if ( 'create' === $operation ) {
			$result = $domain::create( $order, isset( $data['items'] ) ? $data['items'] : [], $data, absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'ledger_revision' ) ) );
		} else {
			if ( ! in_array( $operation, [ 'correct', 'correct_items', 'transition', 'link_refund', 'unlink_refund' ], true ) ) {
				$this->redirect( $order, __( 'Unknown return action.', 'yoohw-vietnam-store-tools' ) );
			}
			$kind = 'correct_items' === $operation ? 'correct' : $operation;
			if ( 'correct_items' === $operation ) {
				$data = [ 'items' => isset( $data['items'] ) ? $data['items'] : [] ];
			}
			$result = $domain::mutate( $order, Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'return_id' ), absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'return_revision' ) ), $kind, $data );
		}
		$this->redirect( $order, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	private function authorized_order( $nonce_prefix ) {
		$order_id = absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'order_id' ) );
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order_id ) || ! check_admin_referer( $nonce_prefix . $order_id, 'yoohw_vst_nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed or order access denied.', 'yoohw-vietnam-store-tools' ) );
		}
		return $order;
	}

	private function redirect( $order, $error ) {
		$url = $order->get_edit_order_url();
		$url = add_query_arg( $error ? [ 'yoohw_vst_return_error' => $error ] : [ 'yoohw_vst_return_saved' => 1 ], $url );
		wp_safe_redirect( $url );
		exit;
	}

	public function render_notice() {
		$error = Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'yoohw_vst_return_error' );
		$saved = Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'yoohw_vst_return_saved' );
		if ( '' === $error && '' === $saved ) {
			return;
		}
		echo '<div class="notice notice-' . ( $error ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $error ? $error : __( 'Order operation saved.', 'yoohw-vietnam-store-tools' ) ) . '</p></div>';
	}

	private static function exception_label( $type ) {
		$labels = [
			'failed_handoff' => __( 'Failed handoff', 'yoohw-vietnam-store-tools' ),
			'delivery_failed' => __( 'Delivery failed', 'yoohw-vietnam-store-tools' ),
			'returned_to_sender' => __( 'Returned to sender', 'yoohw-vietnam-store-tools' ),
			'cancelled' => __( 'Shipment cancelled', 'yoohw-vietnam-store-tools' ),
			'replaced' => __( 'Shipment replaced', 'yoohw-vietnam-store-tools' ),
		];
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}
}
