<?php
/**
 * WooCommerce order list tools for Vietnamese stores.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Order_Management {

	const META_FULFILLMENT_STATUS = '_yoohw_vietnam_store_tools_fulfillment_status';

	const ACTION_RESEND_TRACKING_EMAIL = 'vck_resend_tracking_email';
	const ACTION_UPDATE_CARRIER        = 'vck_update_carrier';
	const ACTION_EXPORT_SHIPPING       = 'vck_export_shipping_csv';
	const ACTION_EXPORT_INVOICE        = 'vck_export_invoice_csv';
	const ACTION_MARK_PREPARED         = 'vck_mark_prepared';
	const ACTION_MARK_AWAITING         = 'vck_mark_awaiting_handover';
	const ACTION_MARK_HANDED_OVER      = 'vck_mark_handed_over';

	private $filter_keys = [
		'vck_invoice',
		'vck_einvoice_status',
		'vck_carrier',
		'vck_tracking',
		'vck_tracking_email',
		'vck_fulfillment',
		'vck_phone',
		'vck_province',
		'vck_ward',
	];

	public function __construct() {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_order_columns' ], 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_hpos_order_column' ], 20, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_order_columns' ], 20 );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_legacy_order_column' ], 20, 2 );

		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_hpos_filters' ], 30, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ $this, 'filter_hpos_orders' ], 30 );
		add_action( 'restrict_manage_posts', [ $this, 'render_legacy_filters' ], 30, 2 );
		add_action( 'pre_get_posts', [ $this, 'filter_legacy_orders' ], 30 );

		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'add_bulk_actions' ], 20 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $this, 'handle_bulk_actions' ], 20, 3 );
		add_filter( 'bulk_actions-edit-shop_order', [ $this, 'add_bulk_actions' ], 20 );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ $this, 'handle_bulk_actions' ], 20, 3 );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'render_bulk_action_notice' ] );
	}

	public function add_order_columns( $columns ) {
		$columns = is_array( $columns ) ? $columns : [];
		unset( $columns['vck_vat'], $columns['vck_info'] );

		$new_columns = [];
		$inserted    = false;

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$new_columns['vck_info'] = __( 'Information', 'yoohw-vietnam-store-tools' );
				$inserted                = true;
			}
		}

		if ( ! $inserted ) {
			$new_columns['vck_info'] = __( 'Information', 'yoohw-vietnam-store-tools' );
		}

		return $new_columns;
	}

	public function render_hpos_order_column( $column, $order ) {
		$this->render_order_column( $column, $order );
	}

	public function render_legacy_order_column( $column, $post_id = 0 ) {
		$this->render_order_column( $column, wc_get_order( $post_id ) );
	}

	public function render_hpos_filters( $order_type = 'shop_order', $which = 'top' ) {
		if ( 'shop_order' !== $order_type || 'top' !== $which ) {
			return;
		}

		$this->render_filter_panel();
	}

	public function render_legacy_filters( $post_type = '', $which = 'top' ) {
		if ( 'shop_order' !== $post_type || 'top' !== $which ) {
			return;
		}

		$this->render_filter_panel();
	}

	public function filter_hpos_orders( $args ) {
		$filters       = $this->get_request_filters();
		$meta_clauses  = $this->get_meta_query_clauses( $filters );
		$field_clauses = $this->get_hpos_address_field_clauses( $filters );

		if ( ! empty( $meta_clauses ) ) {
			$args['meta_query'] = $this->merge_query_clauses( isset( $args['meta_query'] ) ? $args['meta_query'] : [], $meta_clauses ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( ! empty( $field_clauses ) ) {
			$args['field_query'] = $this->merge_query_clauses( isset( $args['field_query'] ) ? $args['field_query'] : [], $field_clauses );
		}

		return $args;
	}

	public function filter_legacy_orders( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		$filters = $this->get_request_filters();
		$clauses = array_merge( $this->get_meta_query_clauses( $filters ), $this->get_legacy_address_meta_clauses( $filters ) );

		if ( empty( $clauses ) ) {
			return;
		}

		$query->set( 'meta_query', $this->merge_query_clauses( $query->get( 'meta_query' ), $clauses ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	public function add_bulk_actions( $actions ) {
		$actions = is_array( $actions ) ? $actions : [];

		foreach ( $this->get_bulk_action_labels() as $action => $label ) {
			$actions[ $action ] = $label;
		}

		return $actions;
	}

	public function handle_bulk_actions( $redirect_to, $action, $order_ids ) {
		$labels = $this->get_bulk_action_labels();

		if ( ! isset( $labels[ $action ] ) ) {
			return $redirect_to;
		}

		$orders = $this->get_editable_orders( $order_ids );

		if ( self::ACTION_EXPORT_SHIPPING === $action ) {
			$this->stream_shipping_csv( $orders );
		}

		if ( self::ACTION_EXPORT_INVOICE === $action ) {
			$this->stream_invoice_csv( array_filter( $orders, [ $this, 'order_has_invoice_request' ] ) );
		}

		$carrier_options = $this->get_carrier_options();
		$carrier_id      = self::ACTION_UPDATE_CARRIER === $action ? $this->get_requested_bulk_carrier() : '';

		if ( self::ACTION_UPDATE_CARRIER === $action && ( '' === $carrier_id || ! isset( $carrier_options[ $carrier_id ] ) ) ) {
			return add_query_arg( 'vck_order_bulk_error', 'missing_carrier', $redirect_to );
		}

		$updated = 0;
		$failed  = max( 0, count( array_unique( array_map( 'absint', (array) $order_ids ) ) ) - count( $orders ) );

		foreach ( $orders as $order ) {
			$result = false;

			if ( self::ACTION_RESEND_TRACKING_EMAIL === $action ) {
				$result = Yoohw_Vietnam_Store_Tools_Shipping::send_customer_tracking_email( $order );

				if ( $result ) {
					$order->add_order_note( __( 'Tracking email resent from the orders list.', 'yoohw-vietnam-store-tools' ) );
				}
			} elseif ( self::ACTION_UPDATE_CARRIER === $action ) {
				$result = $this->update_order_carrier( $order, $carrier_id, $carrier_options[ $carrier_id ] );
			} else {
				$status = $this->get_status_for_bulk_action( $action );
				$result = '' !== $status ? $this->update_order_fulfillment_status( $order, $status ) : false;
			}

			if ( $result ) {
				++$updated;
			} else {
				++$failed;
			}
		}

		return add_query_arg(
			[
				'vck_order_bulk_action'  => sanitize_key( $action ),
				'vck_order_bulk_updated' => $updated,
				'vck_order_bulk_failed'  => $failed,
			],
			$redirect_to
		);
	}

	public function enqueue_assets() {
		if ( ! $this->is_order_list_screen() ) {
			return;
		}

		$style_handle = 'yoohw-vietnam-store-tools-order-management';

		wp_enqueue_style(
			$style_handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/admin/order-management.css',
			[],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION
		);

		wp_enqueue_script(
			$style_handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/order-management.js',
			[ 'jquery' ],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION,
			true
		);

		$carrier_options = [];

		foreach ( $this->get_carrier_options() as $value => $label ) {
			$carrier_options[] = [
				'value' => $value,
				'label' => $label,
			];
		}

		wp_localize_script(
			$style_handle,
			'yoohwVietnamStoreToolsOrderManagement',
			[
				'carrierAction' => self::ACTION_UPDATE_CARRIER,
				'carriers'      => $carrier_options,
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'wardsNonce'    => wp_create_nonce( 'yoohw_vietnam_store_tools_wards' ),
				'i18n'          => [
					'selectCarrier'       => __( 'Select a carrier', 'yoohw-vietnam-store-tools' ),
					'selectWard'          => __( 'All wards / communes', 'yoohw-vietnam-store-tools' ),
					'selectProvinceFirst' => __( 'Select a city / province first', 'yoohw-vietnam-store-tools' ),
					'loadingWards'        => __( 'Loading ward / commune list...', 'yoohw-vietnam-store-tools' ),
					'loadWardsError'      => __( 'Could not load the ward / commune list. Please try again.', 'yoohw-vietnam-store-tools' ),
				],
			]
		);
	}

	public function render_bulk_action_notice() {
		if ( ! $this->is_order_list_screen() ) {
			return;
		}

		// Read-only admin notice parameters added by the bulk action handler.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['vck_order_bulk_error'] ) ? sanitize_key( wp_unslash( $_GET['vck_order_bulk_error'] ) ) : '';

		if ( 'missing_carrier' === $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Select a carrier before applying the bulk action.', 'yoohw-vietnam-store-tools' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['vck_order_bulk_action'] ) ? sanitize_key( wp_unslash( $_GET['vck_order_bulk_action'] ) ) : '';
		$labels = $this->get_bulk_action_labels();

		if ( ! isset( $labels[ $action ] ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['vck_order_bulk_updated'] ) ? absint( $_GET['vck_order_bulk_updated'] ) : 0;
		$failed  = isset( $_GET['vck_order_bulk_failed'] ) ? absint( $_GET['vck_order_bulk_failed'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: 1: bulk action label, 2: updated order count, 3: skipped order count. */
			__( '%1$s: %2$d orders updated, %3$d skipped.', 'yoohw-vietnam-store-tools' ),
			$labels[ $action ],
			$updated,
			$failed
		);

		echo '<div class="notice ' . esc_attr( $failed ? 'notice-warning' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_order_column( $column, $order ) {
		if ( 'vck_info' !== $column || ! $order instanceof WC_Order ) {
			return;
		}

		$invoice_enabled       = Yoohw_Vietnam_Store_Tools_Tax_Invoice::accepts_new_requests();
		$shipping              = Yoohw_Vietnam_Store_Tools_Shipping::get_order_shipping_data( $order );
		$carrier               = trim( (string) $shipping['provider_name'] );
		$tracking_code         = trim( (string) $shipping['tracking_code'] );
		$display_tracking_code = Yoohw_Vietnam_Store_Tools_Shipping::get_display_tracking_code( $shipping['provider'], $tracking_code );
		$tracking_url          = trim( (string) $shipping['tracking_url'] );

		if ( '' === $carrier && '' !== trim( (string) $shipping['provider'] ) ) {
			$provider_id = sanitize_key( $shipping['provider'] );
			$carriers    = $this->get_carrier_options();
			$carrier     = isset( $carriers[ $provider_id ] ) ? $carriers[ $provider_id ] : $shipping['provider'];
		}

		$has_shipping = '' !== $carrier || '' !== $tracking_code;

		if ( ! $invoice_enabled && ! $has_shipping ) {
			echo '<span class="vck-info-column__empty" aria-hidden="true">—</span>';
			return;
		}

		echo '<div class="vck-info-column">';

		if ( $invoice_enabled ) {
			echo '<div class="vck-info-column__row">';
			echo '<span class="vck-info-column__label">VAT</span>';
			echo '<span class="vck-info-column__content">';

			if ( $this->order_has_invoice_request( $order ) ) {
				$data        = Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_order_data( $order );
				$statuses    = Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_statuses();
				$status      = isset( $statuses[ $data['status'] ] ) ? $statuses[ $data['status'] ] : $statuses['requested'];
				$invoice_url = $order->get_edit_order_url() . '#yoohw-vietnam-store-tools-electronic-invoice';

				echo '<a class="vck-info-column__status vck-info-column__status--' . esc_attr( $status['tone'] ) . '" href="' . esc_url( $invoice_url ) . '" title="' . esc_attr__( 'Electronic invoice workflow', 'yoohw-vietnam-store-tools' ) . '">' . esc_html( $status['label'] ) . '</a>';

				if ( '' !== trim( $data['number'] ) ) {
					$reference = trim( $data['number'] );

					if ( '' !== trim( $data['symbol'] ) ) {
						$reference .= ' · ' . trim( $data['symbol'] );
					}

					echo '<span class="vck-info-column__reference" title="' . esc_attr( $reference ) . '">' . esc_html( $reference ) . '</span>';
				}
			} else {
				echo '<span class="vck-info-column__empty" aria-hidden="true">—</span>';
			}

			echo '</span>';
			echo '</div>';
		}

		if ( $has_shipping ) {
			echo '<div class="vck-info-column__row vck-info-column__row--shipping" title="' . esc_attr__( 'Tracking code', 'yoohw-vietnam-store-tools' ) . '">';
			echo '<span class="screen-reader-text">' . esc_html__( 'Tracking code', 'yoohw-vietnam-store-tools' ) . '</span>';
			echo '<span class="vck-info-column__content">';

			if ( '' !== $carrier ) {
				echo '<span class="vck-info-column__carrier" title="' . esc_attr( $carrier ) . '">' . esc_html( $carrier ) . '</span>';
			}

			if ( '' !== $tracking_code ) {
				if ( '' !== $tracking_url ) {
					echo '<a class="vck-info-column__tracking" href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $display_tracking_code ) . '">' . esc_html( $display_tracking_code ) . '</a>';
				} else {
					echo '<span class="vck-info-column__tracking" title="' . esc_attr( $display_tracking_code ) . '">' . esc_html( $display_tracking_code ) . '</span>';
				}
			}

			echo '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	private function is_invoice_feature_enabled() {
		// Historical invoice data must remain searchable and exportable even when
		// the store is no longer accepting new requests.
		return true;
	}

	private function render_filter_panel() {
		$filters         = $this->get_request_filters();
		$active_count    = count( array_filter( $filters, 'strlen' ) );
		$invoice_enabled = $this->is_invoice_feature_enabled();
		$carriers        = [
			''         => __( 'All carriers', 'yoohw-vietnam-store-tools' ),
			'__none__' => __( 'No carrier', 'yoohw-vietnam-store-tools' ),
		] + $this->get_carrier_options();
		$einvoice_statuses = $invoice_enabled ? [ '' => __( 'All electronic invoice statuses', 'yoohw-vietnam-store-tools' ) ] + wp_list_pluck( Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_statuses(), 'label' ) : [];
		$provinces       = [ '' => __( 'All cities / provinces', 'yoohw-vietnam-store-tools' ) ] + Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_provinces();
		$wards           = [ '' => '' === $filters['vck_province'] ? __( 'Select a city / province first', 'yoohw-vietnam-store-tools' ) : __( 'All wards / communes', 'yoohw-vietnam-store-tools' ) ];

		if ( '' !== $filters['vck_province'] ) {
			$wards += Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_wards_for_province( $filters['vck_province'] );
		}
		?>
		<details class="vck-order-filters"<?php echo $active_count ? ' open' : ''; ?>>
			<summary class="button">
				<span class="dashicons dashicons-filter" aria-hidden="true"></span>
				<?php esc_html_e( 'Vietnam order filters', 'yoohw-vietnam-store-tools' ); ?>
				<?php if ( $active_count ) : ?>
					<span class="vck-order-filters__count"><?php echo esc_html( number_format_i18n( $active_count ) ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="vck-order-filters__panel">
				<div class="vck-order-filters__grid">
					<?php
					if ( $invoice_enabled ) {
						$this->render_filter_select( 'vck_invoice', __( 'VAT invoice', 'yoohw-vietnam-store-tools' ), [ '' => __( 'All invoice requests', 'yoohw-vietnam-store-tools' ), 'yes' => __( 'Requested', 'yoohw-vietnam-store-tools' ), 'no' => __( 'No request', 'yoohw-vietnam-store-tools' ) ], $filters['vck_invoice'] );
						$this->render_filter_select( 'vck_einvoice_status', __( 'Electronic invoice status', 'yoohw-vietnam-store-tools' ), $einvoice_statuses, $filters['vck_einvoice_status'] );
					}
					$this->render_filter_select( 'vck_carrier', __( 'Carrier', 'yoohw-vietnam-store-tools' ), $carriers, $filters['vck_carrier'] );
					$this->render_filter_select( 'vck_tracking', __( 'Tracking code', 'yoohw-vietnam-store-tools' ), [ '' => __( 'All tracking statuses', 'yoohw-vietnam-store-tools' ), 'yes' => __( 'Has tracking code', 'yoohw-vietnam-store-tools' ), 'no' => __( 'Missing tracking code', 'yoohw-vietnam-store-tools' ) ], $filters['vck_tracking'] );
					$this->render_filter_select( 'vck_tracking_email', __( 'Tracking email', 'yoohw-vietnam-store-tools' ), [ '' => __( 'All email statuses', 'yoohw-vietnam-store-tools' ), 'sent' => __( 'Sent', 'yoohw-vietnam-store-tools' ), 'failed' => __( 'Failed', 'yoohw-vietnam-store-tools' ), 'not_sent' => __( 'Not sent', 'yoohw-vietnam-store-tools' ) ], $filters['vck_tracking_email'] );
					$this->render_filter_select( 'vck_fulfillment', __( 'Handover status', 'yoohw-vietnam-store-tools' ), [ '' => __( 'All handover statuses', 'yoohw-vietnam-store-tools' ), 'none' => __( 'Not marked', 'yoohw-vietnam-store-tools' ) ] + wp_list_pluck( $this->get_fulfillment_statuses(), 'label' ), $filters['vck_fulfillment'] );
					$this->render_filter_select( 'vck_phone', __( 'Normalized phone', 'yoohw-vietnam-store-tools' ), [ '' => __( 'All phone statuses', 'yoohw-vietnam-store-tools' ), 'yes' => __( 'Normalized', 'yoohw-vietnam-store-tools' ), 'no' => __( 'Not normalized', 'yoohw-vietnam-store-tools' ) ], $filters['vck_phone'] );
					$this->render_filter_select( 'vck_province', __( 'City / Province', 'yoohw-vietnam-store-tools' ), $provinces, $filters['vck_province'], 'vck-order-filter-province' );
					$this->render_filter_select( 'vck_ward', __( 'Ward / Commune', 'yoohw-vietnam-store-tools' ), $wards, $filters['vck_ward'], 'vck-order-filter-ward', '' === $filters['vck_province'] );
					?>
				</div>
				<div class="vck-order-filters__footer">
					<button type="submit" class="button button-primary" name="filter_action" value="Filter"><?php esc_html_e( 'Filter orders', 'yoohw-vietnam-store-tools' ); ?></button>
					<a href="<?php echo esc_url( remove_query_arg( $this->filter_keys ) ); ?>"><?php esc_html_e( 'Clear filters', 'yoohw-vietnam-store-tools' ); ?></a>
				</div>
			</div>
		</details>
		<?php
	}

	private function render_filter_select( $name, $label, $options, $selected_value, $id = '', $disabled = false ) {
		$id = '' !== $id ? $id : $name;
		?>
		<label class="vck-order-filters__field" for="<?php echo esc_attr( $id ); ?>">
			<span><?php echo esc_html( $label ); ?></span>
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php disabled( $disabled ); ?>>
				<?php foreach ( $options as $value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"<?php selected( (string) $selected_value, (string) $value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	private function get_request_filters() {
		$filters = array_fill_keys( $this->filter_keys, '' );

		foreach ( $filters as $key => $value ) {
			unset( $value );
			// Read-only list filters do not require a nonce.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filters[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}

		$fulfillment_statuses           = $this->get_fulfillment_statuses();
		$einvoice_statuses              = Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_statuses();
		$filters['vck_invoice']         = in_array( $filters['vck_invoice'], [ 'yes', 'no' ], true ) ? $filters['vck_invoice'] : '';
		$filters['vck_einvoice_status'] = isset( $einvoice_statuses[ $filters['vck_einvoice_status'] ] ) ? $filters['vck_einvoice_status'] : '';
		$filters['vck_tracking']        = in_array( $filters['vck_tracking'], [ 'yes', 'no' ], true ) ? $filters['vck_tracking'] : '';
		$filters['vck_tracking_email'] = in_array( $filters['vck_tracking_email'], [ 'sent', 'failed', 'not_sent' ], true ) ? $filters['vck_tracking_email'] : '';
		$filters['vck_fulfillment']    = isset( $fulfillment_statuses[ $filters['vck_fulfillment'] ] ) || 'none' === $filters['vck_fulfillment'] ? $filters['vck_fulfillment'] : '';
		$filters['vck_phone']          = in_array( $filters['vck_phone'], [ 'yes', 'no' ], true ) ? $filters['vck_phone'] : '';

		if ( ! $this->is_invoice_feature_enabled() ) {
			$filters['vck_invoice']         = '';
			$filters['vck_einvoice_status'] = '';
		}

		$carrier_options = $this->get_carrier_options();
		$filters['vck_carrier'] = isset( $carrier_options[ $filters['vck_carrier'] ] ) || '__none__' === $filters['vck_carrier'] ? $filters['vck_carrier'] : '';

		$filters['vck_province'] = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $filters['vck_province'] );
		$filters['vck_province'] = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $filters['vck_province'] ) ? $filters['vck_province'] : '';
		$filters['vck_ward']     = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $filters['vck_ward'] );
		$filters['vck_ward']     = '' !== $filters['vck_province'] && Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $filters['vck_ward'], $filters['vck_province'] ) ? $filters['vck_ward'] : '';

		return $filters;
	}

	private function get_meta_query_clauses( $filters ) {
		$clauses = [];

		if ( 'yes' === $filters['vck_invoice'] ) {
			$clauses[] = [
				'relation' => 'OR',
				[ 'key' => Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'value' => 'yes' ],
				[ 'key' => '_vck_tax_invoice_requested', 'value' => 'yes' ],
			];
		} elseif ( 'no' === $filters['vck_invoice'] ) {
			$clauses[] = [
				'relation' => 'AND',
				$this->get_meta_not_value_clause( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, 'yes' ),
				$this->get_meta_not_value_clause( '_vck_tax_invoice_requested', 'yes' ),
			];
		}

		if ( '' !== $filters['vck_einvoice_status'] ) {
			$clauses[] = [ 'key' => Yoohw_Vietnam_Store_Tools_Electronic_Invoice::META_STATUS, 'value' => $filters['vck_einvoice_status'] ];
		}

		if ( '__none__' === $filters['vck_carrier'] ) {
			$clauses[] = $this->get_empty_meta_clause( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER );
		} elseif ( '' !== $filters['vck_carrier'] ) {
			$clauses[] = [ 'key' => Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, 'value' => $filters['vck_carrier'] ];
		}

		if ( 'yes' === $filters['vck_tracking'] ) {
			$clauses[] = [ 'key' => Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE, 'value' => '', 'compare' => '!=' ];
		} elseif ( 'no' === $filters['vck_tracking'] ) {
			$clauses[] = $this->get_empty_meta_clause( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_CODE );
		}

		if ( in_array( $filters['vck_tracking_email'], [ 'sent', 'failed' ], true ) ) {
			$clauses[] = [ 'key' => Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_EMAIL_STATUS, 'value' => $filters['vck_tracking_email'] ];
		} elseif ( 'not_sent' === $filters['vck_tracking_email'] ) {
			$clauses[] = $this->get_empty_meta_clause( Yoohw_Vietnam_Store_Tools_Shipping::META_TRACKING_EMAIL_STATUS );
		}

		if ( 'none' === $filters['vck_fulfillment'] ) {
			$clauses[] = $this->get_empty_meta_clause( self::META_FULFILLMENT_STATUS );
		} elseif ( '' !== $filters['vck_fulfillment'] ) {
			$clauses[] = [ 'key' => self::META_FULFILLMENT_STATUS, 'value' => $filters['vck_fulfillment'] ];
		}

		$billing_phone_meta  = '_billing_phone_e164';
		$shipping_phone_meta = '_shipping_phone_e164';

		if ( 'yes' === $filters['vck_phone'] ) {
			$clauses[] = [
				'relation' => 'OR',
				[ 'key' => $billing_phone_meta, 'value' => '', 'compare' => '!=' ],
				[ 'key' => $shipping_phone_meta, 'value' => '', 'compare' => '!=' ],
			];
		} elseif ( 'no' === $filters['vck_phone'] ) {
			$clauses[] = [
				'relation' => 'AND',
				$this->get_empty_meta_clause( $billing_phone_meta ),
				$this->get_empty_meta_clause( $shipping_phone_meta ),
			];
		}

		return $clauses;
	}

	private function get_hpos_address_field_clauses( $filters ) {
		$clauses = [];

		if ( '' !== $filters['vck_province'] && '' !== $filters['vck_ward'] ) {
			$clauses[] = [
				'relation' => 'OR',
				[
					'relation' => 'AND',
					[ 'field' => 'billing_state', 'value' => $filters['vck_province'] ],
					[ 'field' => 'billing_city', 'value' => $filters['vck_ward'] ],
				],
				[
					'relation' => 'AND',
					[ 'field' => 'shipping_state', 'value' => $filters['vck_province'] ],
					[ 'field' => 'shipping_city', 'value' => $filters['vck_ward'] ],
				],
			];
		} elseif ( '' !== $filters['vck_province'] ) {
			$clauses[] = [
				'relation' => 'OR',
				[ 'field' => 'billing_state', 'value' => $filters['vck_province'] ],
				[ 'field' => 'shipping_state', 'value' => $filters['vck_province'] ],
			];
		}

		return $clauses;
	}

	private function get_legacy_address_meta_clauses( $filters ) {
		$clauses = [];

		if ( '' !== $filters['vck_province'] && '' !== $filters['vck_ward'] ) {
			$clauses[] = [
				'relation' => 'OR',
				[
					'relation' => 'AND',
					[ 'key' => '_billing_state', 'value' => $filters['vck_province'] ],
					[ 'key' => '_billing_city', 'value' => $filters['vck_ward'] ],
				],
				[
					'relation' => 'AND',
					[ 'key' => '_shipping_state', 'value' => $filters['vck_province'] ],
					[ 'key' => '_shipping_city', 'value' => $filters['vck_ward'] ],
				],
			];
		} elseif ( '' !== $filters['vck_province'] ) {
			$clauses[] = [
				'relation' => 'OR',
				[ 'key' => '_billing_state', 'value' => $filters['vck_province'] ],
				[ 'key' => '_shipping_state', 'value' => $filters['vck_province'] ],
			];
		}

		return $clauses;
	}

	private function get_empty_meta_clause( $meta_key ) {
		return [
			'relation' => 'OR',
			[ 'key' => $meta_key, 'compare' => 'NOT EXISTS' ],
			[ 'key' => $meta_key, 'value' => '' ],
		];
	}

	private function get_meta_not_value_clause( $meta_key, $value ) {
		return [
			'relation' => 'OR',
			[ 'key' => $meta_key, 'compare' => 'NOT EXISTS' ],
			[ 'key' => $meta_key, 'value' => $value, 'compare' => '!=' ],
		];
	}

	private function merge_query_clauses( $existing, $clauses ) {
		$merged = [ 'relation' => 'AND' ];

		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$merged[] = $existing;
		}

		foreach ( $clauses as $clause ) {
			$merged[] = $clause;
		}

		return $merged;
	}

	private function get_bulk_action_labels() {
		$labels = [
			self::ACTION_RESEND_TRACKING_EMAIL => __( 'Resend tracking email', 'yoohw-vietnam-store-tools' ),
			self::ACTION_UPDATE_CARRIER        => __( 'Update carrier', 'yoohw-vietnam-store-tools' ),
			self::ACTION_EXPORT_SHIPPING       => __( 'Export shipping CSV', 'yoohw-vietnam-store-tools' ),
		];

		if ( $this->is_invoice_feature_enabled() ) {
			$labels[ self::ACTION_EXPORT_INVOICE ] = __( 'Export VAT invoice CSV', 'yoohw-vietnam-store-tools' );
		}

		$labels[ self::ACTION_MARK_PREPARED ]    = __( 'Mark as prepared', 'yoohw-vietnam-store-tools' );
		$labels[ self::ACTION_MARK_AWAITING ]    = __( 'Mark as awaiting handover', 'yoohw-vietnam-store-tools' );
		$labels[ self::ACTION_MARK_HANDED_OVER ] = __( 'Mark as handed over', 'yoohw-vietnam-store-tools' );

		return $labels;
	}

	private function get_status_for_bulk_action( $action ) {
		$map = [
			self::ACTION_MARK_PREPARED    => 'prepared',
			self::ACTION_MARK_AWAITING    => 'awaiting_handover',
			self::ACTION_MARK_HANDED_OVER => 'handed_over',
		];

		return isset( $map[ $action ] ) ? $map[ $action ] : '';
	}

	private function get_fulfillment_statuses() {
		return [
			'prepared'          => [ 'label' => __( 'Prepared', 'yoohw-vietnam-store-tools' ), 'tone' => 'info' ],
			'awaiting_handover' => [ 'label' => __( 'Awaiting handover', 'yoohw-vietnam-store-tools' ), 'tone' => 'warning' ],
			'handed_over'       => [ 'label' => __( 'Handed over', 'yoohw-vietnam-store-tools' ), 'tone' => 'success' ],
		];
	}

	private function get_carrier_options() {
		$providers = Yoohw_Vietnam_Store_Tools_Shipping::get_manual_shipping_providers();

		foreach ( Yoohw_Vietnam_Store_Tools_Shipping::get_providers() as $provider_id => $provider ) {
			$providers[ $provider_id ] = [
				'id'   => $provider_id,
				'name' => isset( $provider['name'] ) ? $provider['name'] : $provider_id,
			];
		}

		$options = [];

		foreach ( $providers as $provider_id => $provider ) {
			$options[ sanitize_key( $provider_id ) ] = isset( $provider['name'] ) ? sanitize_text_field( $provider['name'] ) : sanitize_text_field( $provider_id );
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		return $options;
	}

	private function get_requested_bulk_carrier() {
		foreach ( [ 'vck_bulk_carrier_top', 'vck_bulk_carrier_bottom' ] as $key ) {
			// The order list table verifies its bulk action nonce before this handler runs.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = isset( $_REQUEST[ $key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) : '';

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function get_editable_orders( $order_ids ) {
		$orders = [];

		foreach ( array_unique( array_map( 'absint', (array) $order_ids ) ) as $order_id ) {
			if ( ! $order_id || ! current_user_can( 'edit_shop_order', $order_id ) ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				$orders[] = $order;
			}
		}

		return $orders;
	}

	private function update_order_carrier( $order, $carrier_id, $carrier_name ) {
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER, sanitize_key( $carrier_id ) );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_PROVIDER_NAME, sanitize_text_field( $carrier_name ) );
		$order->update_meta_data( Yoohw_Vietnam_Store_Tools_Shipping::META_LAST_SYNCED, gmdate( 'c' ) );
		$order->add_order_note(
			sprintf(
				/* translators: %s: carrier name. */
				__( 'Carrier updated in bulk to %s.', 'yoohw-vietnam-store-tools' ),
				$carrier_name
			)
		);
		$order->save();

		return true;
	}

	private function update_order_fulfillment_status( $order, $status ) {
		$statuses = $this->get_fulfillment_statuses();

		if ( ! isset( $statuses[ $status ] ) ) {
			return false;
		}

		$order->update_meta_data( self::META_FULFILLMENT_STATUS, $status );
		$order->add_order_note(
			sprintf(
				/* translators: %s: handover status label. */
				__( 'Handover status updated in bulk to %s.', 'yoohw-vietnam-store-tools' ),
				$statuses[ $status ]['label']
			)
		);
		$order->save();

		return true;
	}

	private function stream_shipping_csv( $orders ) {
		$headers = [
			__( 'Order number', 'yoohw-vietnam-store-tools' ),
			__( 'Order date', 'yoohw-vietnam-store-tools' ),
			__( 'Customer', 'yoohw-vietnam-store-tools' ),
			__( 'Normalized phone', 'yoohw-vietnam-store-tools' ),
			__( 'Shipping address', 'yoohw-vietnam-store-tools' ),
			__( 'City / Province', 'yoohw-vietnam-store-tools' ),
			__( 'Ward / Commune', 'yoohw-vietnam-store-tools' ),
			__( 'Carrier', 'yoohw-vietnam-store-tools' ),
			__( 'Tracking code', 'yoohw-vietnam-store-tools' ),
			__( 'Shipping status', 'yoohw-vietnam-store-tools' ),
			__( 'Handover status', 'yoohw-vietnam-store-tools' ),
			__( 'Order total', 'yoohw-vietnam-store-tools' ),
		];
		$rows = [];

		foreach ( $orders as $order ) {
			$shipping = Yoohw_Vietnam_Store_Tools_Shipping::get_order_shipping_data( $order );
			$location = $this->get_order_location_data( $order );
			$phone    = $this->get_order_phone_data( $order );
			$rows[]   = [
				$order->get_order_number(),
				$this->get_order_date_for_csv( $order ),
				$this->get_order_customer_name( $order ),
				$phone['display'],
				$this->get_order_address_for_csv( $order, 'shipping' ),
				$location['province'],
				$location['ward'],
				'' !== $shipping['provider_name'] ? $shipping['provider_name'] : $shipping['provider'],
				$shipping['tracking_code'],
				$shipping['status'],
				$this->get_order_fulfillment_label( $order ),
				$order->get_total(),
			];
		}

		$this->stream_csv( 'vietnam-shipping-orders-' . gmdate( 'Y-m-d' ) . '.csv', $headers, $rows );
	}

	private function stream_invoice_csv( $orders ) {
		$headers = [
			__( 'Order number', 'yoohw-vietnam-store-tools' ),
			__( 'Order date', 'yoohw-vietnam-store-tools' ),
			__( 'Customer', 'yoohw-vietnam-store-tools' ),
			__( 'Company legal name', 'yoohw-vietnam-store-tools' ),
			__( 'Tax code', 'yoohw-vietnam-store-tools' ),
			__( 'Invoice recipient email', 'yoohw-vietnam-store-tools' ),
			__( 'Company address', 'yoohw-vietnam-store-tools' ),
			__( 'Electronic invoice status', 'yoohw-vietnam-store-tools' ),
			__( 'Invoice provider', 'yoohw-vietnam-store-tools' ),
			__( 'Invoice number', 'yoohw-vietnam-store-tools' ),
			__( 'Invoice symbol', 'yoohw-vietnam-store-tools' ),
			__( 'Issue date', 'yoohw-vietnam-store-tools' ),
			__( 'Lookup URL', 'yoohw-vietnam-store-tools' ),
			__( 'PDF invoice', 'yoohw-vietnam-store-tools' ),
			__( 'XML invoice data', 'yoohw-vietnam-store-tools' ),
			__( 'Billing phone', 'yoohw-vietnam-store-tools' ),
			__( 'Order total', 'yoohw-vietnam-store-tools' ),
			__( 'Order status', 'yoohw-vietnam-store-tools' ),
		];
		$rows = [];

		foreach ( $orders as $order ) {
			$einvoice          = Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_order_data( $order );
			$einvoice_statuses = Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_statuses();
			$rows[] = [
				$order->get_order_number(),
				$this->get_order_date_for_csv( $order ),
				$this->get_order_customer_name( $order ),
				$this->get_invoice_meta( $order, Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_COMPANY_NAME ),
				$this->get_invoice_meta( $order, Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_TAX_CODE ),
				$this->get_invoice_meta( $order, Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_EMAIL ),
				$this->get_invoice_meta( $order, Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_COMPANY_ADDRESS ),
				isset( $einvoice_statuses[ $einvoice['status'] ] ) ? $einvoice_statuses[ $einvoice['status'] ]['label'] : '',
				$einvoice['provider'],
				$einvoice['number'],
				$einvoice['symbol'],
				$einvoice['issued_at'],
				$einvoice['lookup_url'],
				$einvoice['pdf_attachment_id'] ? wp_get_attachment_url( $einvoice['pdf_attachment_id'] ) : '',
				$einvoice['xml_attachment_id'] ? wp_get_attachment_url( $einvoice['xml_attachment_id'] ) : '',
				$order->get_billing_phone(),
				$order->get_total(),
				wc_get_order_status_name( $order->get_status() ),
			];
		}

		$this->stream_csv( 'vietnam-vat-invoice-orders-' . gmdate( 'Y-m-d' ) . '.csv', $headers, $rows );
	}

	private function stream_csv( $filename, $headers, $rows ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			wp_die( esc_html__( 'Could not create the CSV export.', 'yoohw-vietnam-store-tools' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output is an HTTP response stream; WP_Filesystem is not an equivalent transport.
		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, $headers, ',', '"', '' );

		foreach ( $rows as $row ) {
			fputcsv( $output, array_map( [ $this, 'sanitize_csv_cell' ], $row ), ',', '"', '' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the php://output response stream is intentional.
		fclose( $output );
		exit;
	}

	private function sanitize_csv_cell( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );

		if ( preg_match( '/^[=+\-@]/', $value ) ) {
			$value = "'" . $value;
		}

		return $value;
	}

	private function order_has_invoice_request( $order ) {
		return 'yes' === strtolower( trim( (string) $this->get_invoice_meta( $order, Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED ) ) );
	}

	private function get_invoice_meta( $order, $meta_key ) {
		$value = $order->get_meta( $meta_key, true );

		if ( '' !== (string) $value ) {
			return $value;
		}

		$prefix = '_yoohw_vietnam_store_tools_tax_invoice_';

		return 0 === strpos( $meta_key, $prefix ) ? $order->get_meta( '_vck_tax_invoice_' . substr( $meta_key, strlen( $prefix ) ), true ) : '';
	}

	private function get_order_fulfillment_status( $order ) {
		$status   = sanitize_key( $order->get_meta( self::META_FULFILLMENT_STATUS, true ) );
		$statuses = $this->get_fulfillment_statuses();

		return isset( $statuses[ $status ] ) ? $status : '';
	}

	private function get_order_fulfillment_label( $order ) {
		$status   = $this->get_order_fulfillment_status( $order );
		$statuses = $this->get_fulfillment_statuses();

		return isset( $statuses[ $status ] ) ? $statuses[ $status ]['label'] : '';
	}

	private function get_order_phone_data( $order ) {
		$shipping_raw = is_callable( [ $order, 'get_shipping_phone' ] ) ? trim( (string) $order->get_shipping_phone() ) : '';
		$use_shipping = '' !== $shipping_raw;
		$raw          = $use_shipping ? $shipping_raw : trim( (string) $order->get_billing_phone() );
		$meta_key     = $use_shipping ? '_shipping_phone_e164' : '_billing_phone_e164';
		$e164         = trim( (string) $order->get_meta( $meta_key, true ) );

		if ( '' === $e164 && '' !== $raw ) {
			$normalized = Yoohw_Vietnam_Store_Tools_Phone_Normalization::normalize_phone_number( $raw );
			$e164       = ! empty( $normalized['valid'] ) ? $normalized['e164'] : '';
		}

		return [
			'display' => '' !== $e164 ? $e164 : $raw,
			'tel'     => '' !== $e164 ? $e164 : Yoohw_Vietnam_Store_Tools_Phone_Normalization::sanitize_phone_storage_value( $raw ),
			'raw'     => $raw,
		];
	}

	private function get_order_location_data( $order ) {
		$shipping_state = trim( (string) $order->get_shipping_state() );
		$shipping_city  = trim( (string) $order->get_shipping_city() );
		$use_shipping   = '' !== $shipping_state || '' !== $shipping_city;
		$province_code  = $use_shipping ? $shipping_state : trim( (string) $order->get_billing_state() );
		$ward_code      = $use_shipping ? $shipping_city : trim( (string) $order->get_billing_city() );
		$province_code  = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $province_code );
		$ward_code      = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $ward_code );

		return [
			'province' => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_province_name( $province_code ),
			'ward'     => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_ward_name( $ward_code, $province_code ),
		];
	}

	private function get_order_customer_name( $order ) {
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	private function get_order_date_for_csv( $order ) {
		$date = $order->get_date_created();

		return $date ? $date->date_i18n( 'Y-m-d H:i:s' ) : '';
	}

	private function get_order_address_for_csv( $order, $type ) {
		$address = 'shipping' === $type ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address();

		$address = preg_replace( '/<br\s*\/?>/i', ', ', $address );

		return preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $address ), ENT_QUOTES, 'UTF-8' ) );
	}

	private function is_order_list_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders', 'admin_page_wc-orders' ], true );
	}
}
