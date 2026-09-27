<?php
/**
 * Manual payment reconciliation on WooCommerce order screens.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Payment_Reconciliation_Admin {
	const ACTION = 'yoohw_vietnam_store_tools_reconcile_payment';
	const FORM_ID = 'vck-payment-reconciliation-form';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_order_metabox' ] );
		add_action( 'admin_footer', [ $this, 'render_action_form' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_action' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	public static function is_relevant( $order ) {
		return $order instanceof WC_Order && ( 'bacs' === $order->get_payment_method() || Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::get_history( $order ) );
	}

	public static function status_label( $data ) {
		if ( 'reconciled' === $data['state'] ) {
			return 'external_verified' === $data['trust']
				? __( 'Externally verified', 'yoohw-vietnam-store-tools' )
				: __( 'Reconciled manually', 'yoohw-vietnam-store-tools' );
		}
		return 'recorded' === $data['state']
			? __( 'Recorded', 'yoohw-vietnam-store-tools' )
			: __( 'Unreconciled', 'yoohw-vietnam-store-tools' );
	}

	public function add_order_metabox() {
		$order = $this->current_order();
		if ( ! self::is_relevant( $order ) ) {
			return;
		}
		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box( 'yoohw-vietnam-store-tools-payment-reconciliation', __( 'Payment reconciliation', 'yoohw-vietnam-store-tools' ), [ $this, 'render_metabox' ], $screen, 'normal', 'default' );
		}
	}

	public function render_metabox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! self::is_relevant( $order ) ) {
			return;
		}
		$domain = Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::class;
		$data = $domain::get_order_data( $order );
		$history = $domain::get_history( $order );
		$active = $this->active_entries( $history );
		$entry = isset( $active[ $data['entry_id'] ] ) ? $active[ $data['entry_id'] ] : null;
		$manual = 'bacs' === $order->get_payment_method() && 'external_verified' !== $data['trust'] && current_user_can( 'edit_shop_order', $order->get_id() );
		$match = 'reconciled' === $data['state'] && 'manual' === $data['trust'] && isset( $active[ $data['entry_id'] ] ) ? $active[ $data['entry_id'] ] : null;
		$observations = $this->active_observations( $active );
		$selected_id = isset( $_GET['vck_payment_observation'] ) ? sanitize_text_field( wp_unslash( $_GET['vck_payment_observation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only observation selection.
		$observation = $selected_id && isset( $observations[ $selected_id ] ) ? $observations[ $selected_id ] : $this->projected_observation( $data, $active, $match );
		if ( ! $match ) {
			$match = $this->latest_active_match( $active, $observation );
		} elseif ( $observation && $match['evidence_id'] !== $observation['id'] ) {
			$match = $this->latest_active_match( $active, $observation );
		}
		echo '<div class="vck-payment-reconciliation">';
		echo '<p><strong>' . esc_html( self::status_label( $data ) ) . '</strong> · ' . esc_html( $this->trust_label( $data['trust'] ) ) . '</p>';
		if ( $entry ) {
			$this->detail( __( 'Source ID', 'yoohw-vietnam-store-tools' ), $data['source_id'] );
			$this->detail( __( 'Transfer reference', 'yoohw-vietnam-store-tools' ), 'verified' === $entry['kind'] ? $entry['transaction_id'] : $entry['reference'] );
			$this->detail( __( 'Amount and currency', 'yoohw-vietnam-store-tools' ), $entry['amount'] . ' ' . $entry['currency'] );
			$this->detail( __( 'Observed time', 'yoohw-vietnam-store-tools' ), $this->local_time( $entry['observed_at'] ) );
			$this->detail( __( 'Recorded time', 'yoohw-vietnam-store-tools' ), $this->local_time( $entry['recorded_at'] ) );
			$this->detail( __( 'Recorded by', 'yoohw-vietnam-store-tools' ), $this->actor_name( $entry ) );
		}
		if ( $manual ) {
			if ( count( $observations ) > 1 ) {
				echo '<h4>' . esc_html__( 'Select an observation', 'yoohw-vietnam-store-tools' ) . '</h4><ul>';
				foreach ( $observations as $candidate ) {
					$url = add_query_arg( 'vck_payment_observation', $candidate['id'], $order->get_edit_order_url() ) . '#yoohw-vietnam-store-tools-payment-reconciliation';
					$label = '' !== $candidate['reference'] ? $candidate['reference'] : $candidate['id'];
					echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label . ' · ' . $candidate['amount'] . ' ' . $candidate['currency'] ) . '</a>';
					if ( $observation && $candidate['id'] === $observation['id'] ) {
						echo ' <strong>' . esc_html__( 'Selected', 'yoohw-vietnam-store-tools' ) . '</strong>';
					}
					echo '</li>';
				}
				echo '</ul>';
			}
			$this->render_manual_controls( $order, $observation, $match );
		} elseif ( 'external_verified' === $data['trust'] ) {
			echo '<p>' . esc_html__( 'Externally verified evidence is read only here.', 'yoohw-vietnam-store-tools' ) . '</p>';
		}
		if ( $history ) {
			echo '<details><summary>' . esc_html__( 'Reconciliation history', 'yoohw-vietnam-store-tools' ) . '</summary><ol>';
			foreach ( array_reverse( $history ) as $item ) {
				$kind = $this->kind_label( $item['kind'] );
				$detail = $item['amount'] . ' ' . $item['currency'];
				if ( '' !== $item['reference'] ) {
					$detail .= ' · ' . $item['reference'];
				}
				if ( 'verified' === $item['kind'] ) {
					$detail .= ' · ' . $item['source_id'] . ' / ' . $item['transaction_id'];
				}
				echo '<li><strong>' . esc_html( $kind ) . '</strong> — ' . esc_html( $detail ) . '<br>' . esc_html( $this->local_time( $item['recorded_at'] ) );
				if ( ! empty( $item['actor_id'] ) ) {
					echo ' · ' . esc_html( $this->actor_name( $item ) );
				}
				echo '<br>' . esc_html__( 'Observed time', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $this->local_time( $item['observed_at'] ) );
				if ( ! empty( $item['supersedes'] ) ) {
					echo ' · ' . esc_html__( 'Supersedes:', 'yoohw-vietnam-store-tools' ) . ' ' . esc_html( $item['supersedes'] );
				}
				if ( ! empty( $item['note'] ) ) {
					echo '<br>' . esc_html( $item['note'] );
				}
				echo '</li>';
			}
			echo '</ol></details>';
		}
		echo '</div>';
	}

	private function render_manual_controls( $order, $observation, $match ) {
		$form = self::FORM_ID;
		echo '<input type="hidden" name="vck_payment_selected_observation" form="' . esc_attr( $form ) . '" value="' . esc_attr( $observation ? $observation['id'] : '' ) . '">';
		if ( $observation ) {
			echo '<h4>' . esc_html__( 'Selected observation', 'yoohw-vietnam-store-tools' ) . '</h4>';
			$this->detail( __( 'Record ID', 'yoohw-vietnam-store-tools' ), $observation['id'] );
			$this->detail( __( 'Transfer reference', 'yoohw-vietnam-store-tools' ), $observation['reference'] );
			$this->detail( __( 'Amount and currency', 'yoohw-vietnam-store-tools' ), $observation['amount'] . ' ' . $observation['currency'] );
		}
		echo '<h4>' . esc_html( $observation ? __( 'Correct observation', 'yoohw-vietnam-store-tools' ) : __( 'Record transfer observation', 'yoohw-vietnam-store-tools' ) ) . '</h4>';
		if ( $observation ) {
			echo '<p>' . esc_html__( 'A correction appends a new record and keeps the previous record in history.', 'yoohw-vietnam-store-tools' ) . '</p>';
			echo '<input type="hidden" name="vck_payment_supersedes" form="' . esc_attr( $form ) . '" value="' . esc_attr( $observation['id'] ) . '">';
		}
		$this->input( 'vck_payment_amount', __( 'Observed amount', 'yoohw-vietnam-store-tools' ), $observation ? $observation['amount'] : $order->get_total(), 'text' );
		$this->detail( __( 'Order currency', 'yoohw-vietnam-store-tools' ), $order->get_currency() );
		$this->input( 'vck_payment_reference', __( 'Transfer reference (optional)', 'yoohw-vietnam-store-tools' ), $observation ? $observation['reference'] : '', 'text' );
		$this->input( 'vck_payment_observed_at', __( 'Observed time (store time)', 'yoohw-vietnam-store-tools' ), $observation ? $this->input_time( $observation['observed_at'] ) : wp_date( 'Y-m-d\TH:i' ), 'datetime-local' );
		$this->input( 'vck_payment_note', __( 'Note (optional)', 'yoohw-vietnam-store-tools' ), '', 'text' );
		echo '<p><button type="submit" form="' . esc_attr( $form ) . '" name="vck_payment_operation" value="observe" class="button button-primary">' . esc_html( $observation ? __( 'Save correction', 'yoohw-vietnam-store-tools' ) : __( 'Record observation', 'yoohw-vietnam-store-tools' ) ) . '</button></p>';
		if ( $observation && ! $match ) {
			echo '<p>' . esc_html__( 'Confirm that you compared this transfer with the order. This records a manual reconciliation and does not confirm the bank transaction automatically.', 'yoohw-vietnam-store-tools' ) . '</p>';
			echo '<input type="hidden" name="vck_payment_expected_observation" form="' . esc_attr( $form ) . '" value="' . esc_attr( $observation['id'] ) . '">';
			echo '<button type="submit" form="' . esc_attr( $form ) . '" name="vck_payment_operation" value="match" class="button">' . esc_html__( 'Mark manually reconciled', 'yoohw-vietnam-store-tools' ) . '</button>';
		}
		if ( $match ) {
			echo '<p>' . esc_html__( 'Reverse this manual match. The status will be recalculated from the remaining history.', 'yoohw-vietnam-store-tools' ) . '</p>';
			echo '<input type="hidden" name="vck_payment_expected_match" form="' . esc_attr( $form ) . '" value="' . esc_attr( $match['id'] ) . '">';
			echo '<button type="submit" form="' . esc_attr( $form ) . '" name="vck_payment_operation" value="reverse" class="button">' . esc_html__( 'Reverse manual reconciliation', 'yoohw-vietnam-store-tools' ) . '</button>';
		}
	}

	public function render_action_form() {
		$order = $this->current_order();
		if ( ! self::is_relevant( $order ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		echo '<form id="' . esc_attr( self::FORM_ID ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="vck_payment_order_id" value="' . esc_attr( $order->get_id() ) . '">';
		wp_nonce_field( self::ACTION . '_' . $order->get_id(), 'vck_payment_nonce' );
		echo '</form>';
	}

	public function handle_action() {
		$order_id = absint( $this->post( 'vck_payment_order_id' ) );
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( esc_html__( 'You cannot edit this order.', 'yoohw-vietnam-store-tools' ), '', [ 'response' => 403 ] );
		}
		$nonce = $this->post( 'vck_payment_nonce' );
		if ( ! wp_verify_nonce( $nonce, self::ACTION . '_' . $order_id ) ) {
			wp_die( esc_html__( 'Invalid payment reconciliation request.', 'yoohw-vietnam-store-tools' ), '', [ 'response' => 403 ] );
		}
		$domain = Yoohw_Vietnam_Store_Tools_Payment_Reconciliation::class;
		$data = $domain::get_order_data( $order );
		$history = $domain::get_history( $order );
		if ( 'bacs' !== $order->get_payment_method() || 'external_verified' === $data['trust'] ) {
			$this->redirect( $order, 'unavailable' );
		}
		$active = $this->active_entries( $history );
		$match = 'reconciled' === $data['state'] && 'manual' === $data['trust'] && isset( $active[ $data['entry_id'] ] ) ? $active[ $data['entry_id'] ] : null;
		$observations = $this->active_observations( $active );
		$selected_id = $this->post( 'vck_payment_selected_observation' );
		if ( ( '' !== $selected_id && ! isset( $observations[ $selected_id ] ) ) || ( '' === $selected_id && $observations ) ) {
			$this->redirect( $order, 'stale' );
		}
		$observation = '' !== $selected_id ? $observations[ $selected_id ] : null;
		$match = $this->latest_active_match( $active, $observation );
		$operation = sanitize_key( $this->post( 'vck_payment_operation' ) );
		$result = null;
		if ( 'observe' === $operation ) {
			$supersedes = $this->post( 'vck_payment_supersedes' );
			if ( ( $observation && $supersedes !== $observation['id'] ) || ( ! $observation && '' !== $supersedes ) ) {
				$this->redirect( $order, 'stale' );
			}
			$observed_at = $this->utc_time( $this->post( 'vck_payment_observed_at' ) );
			if ( ! $observed_at ) {
				$this->redirect( $order, 'date' );
			}
			$result = $domain::record_manual_observation( $order, [
				'amount' => $this->post( 'vck_payment_amount' ),
				'currency' => $order->get_currency(),
				'reference' => $this->post( 'vck_payment_reference' ),
				'observed_at' => $observed_at,
			], [ 'supersedes' => $supersedes, 'note' => $this->post( 'vck_payment_note' ) ] );
		} elseif ( 'match' === $operation && $observation && ! $match && $observation['id'] === $this->post( 'vck_payment_expected_observation' ) ) {
			$result = $domain::match_manual_observation( $order, $observation['id'] );
		} elseif ( 'reverse' === $operation && $match && $match['id'] === $this->post( 'vck_payment_expected_match' ) ) {
			$result = $domain::reverse_entry( $order, $match['id'] );
		} else {
			$this->redirect( $order, 'stale' );
		}
		$this->redirect( $order, is_wp_error( $result ) ? $result->get_error_code() : 'saved', is_wp_error( $result ) ? $selected_id : ( 'observe' === $operation ? $result['id'] : $selected_id ) );
	}

	public function render_notice() {
		$code = sanitize_key( isset( $_GET['vck_payment_notice'] ) ? wp_unslash( $_GET['vck_payment_notice'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect feedback.
		if ( ! $code ) {
			return;
		}
		$messages = [
			'saved' => __( 'Payment reconciliation record saved.', 'yoohw-vietnam-store-tools' ),
			'date' => __( 'Enter a valid observed date and time.', 'yoohw-vietnam-store-tools' ),
			'stale' => __( 'The reconciliation history changed. Review the current record and try again.', 'yoohw-vietnam-store-tools' ),
			'unavailable' => __( 'Manual reconciliation is unavailable for this payment method or externally verified order.', 'yoohw-vietnam-store-tools' ),
			'yoohw_vietnam_store_tools_payment_invalid_evidence' => __( 'Enter a valid positive amount with the correct currency precision.', 'yoohw-vietnam-store-tools' ),
			'yoohw_vietnam_store_tools_payment_unmatched_amount' => __( 'The observed amount and currency must exactly match the order before manual reconciliation.', 'yoohw-vietnam-store-tools' ),
			'yoohw_vietnam_store_tools_payment_invalid_superseded_entry' => __( 'The observation has changed. Review the current history and try again.', 'yoohw-vietnam-store-tools' ),
		];
		$message = isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Payment reconciliation was not saved. Review the current order and try again.', 'yoohw-vietnam-store-tools' );
		echo '<div class="notice ' . esc_attr( 'saved' === $code ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function current_order() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		if ( ! $screen || ! in_array( $screen->id, $screens, true ) ) {
			return false;
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Order lookup only.
		return $id ? wc_get_order( $id ) : false;
	}

	private function active_entries( $history ) {
		$inactive = [];
		foreach ( $history as $entry ) {
			if ( ! empty( $entry['supersedes'] ) ) {
				$inactive[ $entry['supersedes'] ] = true;
			}
		}
		$active = [];
		foreach ( $history as $entry ) {
			if ( isset( $entry['id'], $entry['kind'] ) && 'reversal' !== $entry['kind'] && ! isset( $inactive[ $entry['id'] ] ) ) {
				$active[ $entry['id'] ] = $entry;
			}
		}
		return $active;
	}

	private function latest_active_observation( $active ) {
		foreach ( array_reverse( $active ) as $entry ) {
			if ( 'observation' === $entry['kind'] ) {
				return $entry;
			}
		}
		return null;
	}

	private function active_observations( $active ) {
		return array_filter( $active, static function ( $entry ) {
			return 'observation' === $entry['kind'];
		} );
	}

	private function projected_observation( $data, $active, $match ) {
		if ( $match && isset( $active[ $match['evidence_id'] ] ) ) {
			return $active[ $match['evidence_id'] ];
		}
		if ( 'recorded' === $data['state'] && isset( $active[ $data['entry_id'] ] ) && 'observation' === $active[ $data['entry_id'] ]['kind'] ) {
			return $active[ $data['entry_id'] ];
		}
		return $this->latest_active_observation( $active );
	}

	private function latest_active_match( $active, $observation ) {
		if ( ! $observation ) {
			return null;
		}
		foreach ( array_reverse( $active ) as $entry ) {
			if ( 'match' === $entry['kind'] && $observation['id'] === $entry['evidence_id'] ) {
				return $entry;
			}
		}
		return null;
	}

	private function post( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_action before mutation.
	}

	private function utc_time( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		return $date && ( ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) )
			? $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' ) : '';
	}

	private function input_time( $value ) {
		return ( new DateTimeImmutable( $value ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
	}

	private function local_time( $value ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $value ) );
	}

	private function actor_name( $entry ) {
		if ( empty( $entry['actor_id'] ) ) {
			return '';
		}
		$user = get_userdata( $entry['actor_id'] );
		return $user ? $user->display_name : '#' . $entry['actor_id'];
	}

	private function trust_label( $trust ) {
		if ( 'manual' === $trust ) {
			return __( 'Manual', 'yoohw-vietnam-store-tools' );
		}
		return 'external_verified' === $trust ? __( 'Externally verified', 'yoohw-vietnam-store-tools' ) : __( 'No evidence', 'yoohw-vietnam-store-tools' );
	}

	private function kind_label( $kind ) {
		$labels = [
			'observation' => __( 'Observation', 'yoohw-vietnam-store-tools' ),
			'match' => __( 'Manual match', 'yoohw-vietnam-store-tools' ),
			'verified' => __( 'External verification', 'yoohw-vietnam-store-tools' ),
			'reversal' => __( 'Reversal', 'yoohw-vietnam-store-tools' ),
		];
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;
	}

	private function detail( $label, $value ) {
		echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( '' !== (string) $value ? $value : '—' ) . '</p>';
	}

	private function input( $name, $label, $value, $type ) {
		echo '<p><label for="' . esc_attr( $name ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" form="' . esc_attr( self::FORM_ID ) . '" value="' . esc_attr( $value ) . '"></p>';
	}

	private function redirect( $order, $code, $selected_id = '' ) {
		$args = [ 'vck_payment_notice' => sanitize_key( $code ) ];
		if ( '' !== $selected_id ) {
			$args['vck_payment_observation'] = sanitize_text_field( $selected_id );
		}
		wp_safe_redirect( add_query_arg( $args, $order->get_edit_order_url() ) . '#yoohw-vietnam-store-tools-payment-reconciliation' );
		exit;
	}
}
