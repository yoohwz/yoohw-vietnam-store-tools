<?php
/**
 * Provider-neutral electronic invoice workflow for WooCommerce orders.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Electronic_Invoice {

	const ACTION_SAVE = 'yoohw_vietnam_store_tools_save_einvoice_workflow';

	const META_STATUS            = '_yoohw_vietnam_store_tools_einvoice_status';
	const META_NUMBER            = '_yoohw_vietnam_store_tools_einvoice_number';
	const META_SYMBOL            = '_yoohw_vietnam_store_tools_einvoice_symbol';
	const META_ISSUED_AT         = '_yoohw_vietnam_store_tools_einvoice_issued_at';
	const META_LOOKUP_URL        = '_yoohw_vietnam_store_tools_einvoice_lookup_url';
	const META_PDF_ATTACHMENT_ID = '_yoohw_vietnam_store_tools_einvoice_pdf_attachment_id';
	const META_XML_ATTACHMENT_ID = '_yoohw_vietnam_store_tools_einvoice_xml_attachment_id';
	const META_PROVIDER          = '_yoohw_vietnam_store_tools_einvoice_provider';
	const META_HISTORY           = '_yoohw_vietnam_store_tools_einvoice_history';

	private $handling_invoice_upload = '';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_admin_order_metabox' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );

		if ( self::is_workflow_enabled() ) {
			add_action( 'woocommerce_checkout_create_order', [ $this, 'initialize_requested_workflow' ], 50 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'initialize_requested_workflow' ], 50 );
			add_action( 'admin_post_' . self::ACTION_SAVE, [ $this, 'handle_save_action' ] );
			add_filter( 'upload_mimes', [ $this, 'allow_invoice_upload_mimes' ], 20, 2 );
			add_filter( 'wp_check_filetype_and_ext', [ $this, 'normalize_invoice_xml_filetype' ], 20, 5 );
		}
	}

	public static function is_workflow_enabled() {
		return Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE );
	}

	public function allow_invoice_upload_mimes( $mimes, $user = null ) {
		unset( $user );

		if ( ! self::is_workflow_enabled() ) {
			return $mimes;
		}

		if ( '' === $this->handling_invoice_upload && ! current_user_can( 'edit_shop_orders' ) ) {
			return $mimes;
		}

		$mimes['pdf'] = 'application/pdf';
		$mimes['xml'] = 'application/xml';

		return $mimes;
	}

	public function normalize_invoice_xml_filetype( $data, $file, $filename, $mimes, $real_mime ) {
		unset( $mimes );

		if ( ! self::is_workflow_enabled() || 'xml' !== $this->handling_invoice_upload || 'xml' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			return $data;
		}

		if ( in_array( $real_mime, [ 'application/xml', 'text/xml', 'text/plain' ], true ) && self::is_valid_xml_file( $file ) ) {
			$data['ext']             = 'xml';
			$data['type']            = 'application/xml';
			$data['proper_filename'] = false;
		}

		return $data;
	}

	/**
	 * Returns the fixed workflow states used by the plugin and connector add-ons.
	 *
	 * @return array
	 */
	public static function get_statuses() {
		return [
			'requested' => [
				'label' => __( 'Requested', 'yoohw-vietnam-store-tools' ),
				'tone'  => 'pending',
			],
			'verified' => [
				'label' => __( 'Information verified', 'yoohw-vietnam-store-tools' ),
				'tone'  => 'info',
			],
			'ready' => [
				'label' => __( 'Ready to issue', 'yoohw-vietnam-store-tools' ),
				'tone'  => 'info',
			],
			'issued' => [
				'label' => __( 'Issued', 'yoohw-vietnam-store-tools' ),
				'tone'  => 'success',
			],
			'sent' => [
				'label' => __( 'Sent to customer', 'yoohw-vietnam-store-tools' ),
				'tone'  => 'success',
			],
			'adjusted' => [
				'label' => __( 'Adjusted', 'yoohw-vietnam-store-tools' ),
				'tone'  => 'warning',
			],
			'replaced' => [
				'label' => __( 'Replaced', 'yoohw-vietnam-store-tools' ),
				'tone'  => 'warning',
			],
		];
	}

	/**
	 * Returns normalized workflow data for an order.
	 *
	 * Orders created before this workflow was added are treated as requested when
	 * they contain an existing VAT invoice request.
	 *
	 * @param WC_Order|int $order Order object or ID.
	 * @return array
	 */
	public static function get_order_data( $order ) {
		$order = self::get_order( $order );

		if ( ! $order ) {
			return [];
		}

		$status   = sanitize_key( $order->get_meta( self::META_STATUS, true ) );
		$statuses = self::get_statuses();

		if ( ! isset( $statuses[ $status ] ) ) {
			$status = self::order_has_invoice_request( $order ) ? 'requested' : '';
		}

		return [
			'status'            => $status,
			'number'            => (string) $order->get_meta( self::META_NUMBER, true ),
			'symbol'            => (string) $order->get_meta( self::META_SYMBOL, true ),
			'issued_at'         => (string) $order->get_meta( self::META_ISSUED_AT, true ),
			'lookup_url'        => (string) $order->get_meta( self::META_LOOKUP_URL, true ),
			'pdf_attachment_id' => absint( $order->get_meta( self::META_PDF_ATTACHMENT_ID, true ) ),
			'xml_attachment_id' => absint( $order->get_meta( self::META_XML_ATTACHMENT_ID, true ) ),
			'provider'          => (string) $order->get_meta( self::META_PROVIDER, true ),
		];
	}

	/**
	 * Updates provider-neutral invoice data and records a change history entry.
	 *
	 * Connector add-ons can call this method after issuing or synchronizing an
	 * invoice. The method deliberately performs no provider API request itself.
	 *
	 * @param WC_Order|int $order   Order object or ID.
	 * @param array        $data    Workflow fields to update.
	 * @param array        $context Optional actor, source, note and order-note settings.
	 * @return true|WP_Error
	 */
	public static function update_order_data( $order, $data, $context = [] ) {
		$order = self::get_order( $order );

		if ( ! $order ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_order', __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( ! self::is_workflow_enabled() ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_disabled', __( 'Electronic invoice workflow management is disabled.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( ! self::order_has_invoice_request( $order ) ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_not_requested', __( 'This order does not contain a VAT invoice request.', 'yoohw-vietnam-store-tools' ) );
		}

		$data    = is_array( $data ) ? $data : [];
		$context = wp_parse_args(
			is_array( $context ) ? $context : [],
			[
				'actor_id'       => get_current_user_id(),
				'source'         => 'integration',
				'note'           => '',
				'add_order_note' => true,
			]
		);
		$current = self::get_order_data( $order );
		$next    = $current;
		$fields  = self::get_data_field_map();

		foreach ( $fields as $data_key => $meta_key ) {
			unset( $meta_key );

			if ( ! array_key_exists( $data_key, $data ) ) {
				continue;
			}

			$value = self::sanitize_data_value( $data_key, $data[ $data_key ] );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$next[ $data_key ] = $value;
		}

		$changes = [];

		foreach ( $fields as $data_key => $meta_key ) {
			if ( ! array_key_exists( $data_key, $data ) ) {
				continue;
			}

			$current_value = $current[ $data_key ];

			if ( 'status' === $data_key && '' === (string) $order->get_meta( self::META_STATUS, true ) ) {
				$current_value = '';
			}

			if ( (string) $current_value === (string) $next[ $data_key ] ) {
				continue;
			}

			$changes[] = [
				'field' => $data_key,
				'from'  => $current_value,
				'to'    => $next[ $data_key ],
			];
			$order->update_meta_data( $meta_key, $next[ $data_key ] );
		}

		$note = trim( sanitize_textarea_field( $context['note'] ) );

		if ( empty( $changes ) && '' === $note ) {
			return true;
		}

		$history_entry = self::create_history_entry( $changes, $context, $note );
		self::append_history_entry( $order, $history_entry );

		if ( ! empty( $context['add_order_note'] ) ) {
			$order->add_order_note( self::get_order_note_message( $current, $next, $note ) );
		}

		$order->save();

		do_action( 'yoohw_vietnam_store_tools_einvoice_workflow_updated', $order, $next, $changes, $history_entry );

		return true;
	}

	/**
	 * Returns newest-first workflow history entries.
	 *
	 * @param WC_Order|int $order Order object or ID.
	 * @return array
	 */
	public static function get_order_history( $order ) {
		$order = self::get_order( $order );

		if ( ! $order ) {
			return [];
		}

		$history = $order->get_meta( self::META_HISTORY, true );

		return is_array( $history ) ? array_reverse( $history ) : [];
	}

	public function initialize_requested_workflow( $order, $request = null ) {
		unset( $request );

		if ( ! $order instanceof WC_Order || ! self::order_has_invoice_request( $order ) || '' !== (string) $order->get_meta( self::META_STATUS, true ) ) {
			return;
		}

		$order->update_meta_data( self::META_STATUS, 'requested' );
		self::append_history_entry(
			$order,
			self::create_history_entry(
				[
					[
						'field' => 'status',
						'from'  => '',
						'to'    => 'requested',
					],
				],
				[
					'actor_id' => 0,
					'source'   => 'checkout',
				],
				__( 'Created from the customer VAT invoice request.', 'yoohw-vietnam-store-tools' )
			)
		);
	}

	public function add_admin_order_metabox() {
		$order = $this->get_current_admin_order();

		if ( ! self::order_has_invoice_request( $order ) ) {
			return;
		}

		foreach ( $this->get_order_admin_screen_ids() as $screen_id ) {
			add_meta_box(
				'yoohw-vietnam-store-tools-electronic-invoice',
				__( 'Electronic invoice workflow', 'yoohw-vietnam-store-tools' ),
				[ $this, 'render_admin_order_metabox' ],
				$screen_id,
				'normal',
				'default'
			);
		}
	}

	public function enqueue_admin_assets() {
		$order = $this->get_current_admin_order();

		if ( ! self::order_has_invoice_request( $order ) ) {
			return;
		}

		$handle = 'yoohw-vietnam-store-tools-electronic-invoice';

		wp_enqueue_style(
			$handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/admin/electronic-invoice.css',
			[],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION
		);

		if ( ! self::is_workflow_enabled() ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/electronic-invoice.js',
			[],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION,
			true
		);
		wp_localize_script(
			$handle,
			'yoohwVietnamStoreToolsElectronicInvoice',
			[
				'adminPostUrl' => admin_url( 'admin-post.php' ),
				'action'       => self::ACTION_SAVE,
				'saving'       => __( 'Saving...', 'yoohw-vietnam-store-tools' ),
			]
		);
	}

	public function render_admin_order_metabox( $post_or_order_object ) {
		$order = $this->get_admin_order_from_object( $post_or_order_object );

		if ( ! self::order_has_invoice_request( $order ) ) {
			return;
		}

		$data     = self::get_order_data( $order );
		$statuses = self::get_statuses();
		$status   = isset( $statuses[ $data['status'] ] ) ? $statuses[ $data['status'] ] : $statuses['requested'];
		$panel_id = 'vck-electronic-invoice-' . $order->get_id();
		?>
		<div id="<?php echo esc_attr( $panel_id ); ?>" class="vck-einvoice" data-vck-einvoice-panel>
			<div class="vck-einvoice__header">
				<div>
					<span class="vck-einvoice__eyebrow"><?php esc_html_e( 'Current status', 'yoohw-vietnam-store-tools' ); ?></span>
					<span class="vck-einvoice__status vck-einvoice__status--<?php echo esc_attr( $status['tone'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
				</div>
				<p><?php esc_html_e( 'This workflow records invoice progress and files only. It does not issue invoices or call a provider API.', 'yoohw-vietnam-store-tools' ); ?></p>
			</div>

			<?php if ( ! self::is_workflow_enabled() ) : ?>
				<div class="notice notice-info inline vck-einvoice__readonly-notice">
					<p><?php esc_html_e( 'Electronic invoice workflow management is disabled. Existing invoice data is shown in read-only mode.', 'yoohw-vietnam-store-tools' ); ?></p>
				</div>
				<?php $this->render_read_only_summary( $data ); ?>
				<?php $this->render_history( $order ); ?>
			</div>
				<?php
				return;
			endif;
			?>

			<div class="vck-einvoice__grid">
				<?php $this->render_select_field( 'vck_einvoice_status', __( 'Workflow status', 'yoohw-vietnam-store-tools' ), $statuses, $data['status'] ); ?>
				<?php $this->render_text_field( 'vck_einvoice_provider', __( 'Invoice provider', 'yoohw-vietnam-store-tools' ), $data['provider'], __( 'For example: MISA, VNPT, Viettel, or another provider', 'yoohw-vietnam-store-tools' ) ); ?>
				<?php $this->render_text_field( 'vck_einvoice_number', __( 'Invoice number', 'yoohw-vietnam-store-tools' ), $data['number'] ); ?>
				<?php $this->render_text_field( 'vck_einvoice_symbol', __( 'Invoice symbol', 'yoohw-vietnam-store-tools' ), $data['symbol'] ); ?>
				<?php $this->render_datetime_field( 'vck_einvoice_issued_at', __( 'Issue date', 'yoohw-vietnam-store-tools' ), $data['issued_at'] ); ?>
				<?php $this->render_url_field( 'vck_einvoice_lookup_url', __( 'Lookup URL', 'yoohw-vietnam-store-tools' ), $data['lookup_url'] ); ?>
			</div>

			<div class="vck-einvoice__files">
				<?php $this->render_file_field( $order, 'pdf', __( 'PDF invoice', 'yoohw-vietnam-store-tools' ), $data['pdf_attachment_id'], 'application/pdf,.pdf' ); ?>
				<?php $this->render_file_field( $order, 'xml', __( 'XML invoice data', 'yoohw-vietnam-store-tools' ), $data['xml_attachment_id'], 'application/xml,text/xml,.xml' ); ?>
			</div>

			<label class="vck-einvoice__field vck-einvoice__field--wide" for="vck_einvoice_note_<?php echo esc_attr( $order->get_id() ); ?>">
				<span><?php esc_html_e( 'Internal update note', 'yoohw-vietnam-store-tools' ); ?></span>
				<textarea id="vck_einvoice_note_<?php echo esc_attr( $order->get_id() ); ?>" name="vck_einvoice_note" rows="2" placeholder="<?php esc_attr_e( 'Optional context for the change history', 'yoohw-vietnam-store-tools' ); ?>"></textarea>
			</label>

			<div class="vck-einvoice__actions">
				<button type="button" class="button button-primary" data-vck-einvoice-save data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::ACTION_SAVE . '_' . $order->get_id() ) ); ?>"><?php esc_html_e( 'Save invoice workflow', 'yoohw-vietnam-store-tools' ); ?></button>
			</div>

			<?php $this->render_history( $order ); ?>
		</div>
		<?php
	}

	public function handle_save_action() {
		$order_id = absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'order_id' ) );

		if ( ! $order_id || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this order.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( ! check_admin_referer( self::ACTION_SAVE . '_' . $order_id, 'yoohw_vietnam_store_tools_einvoice_nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'yoohw-vietnam-store-tools' ) );
		}

		$order = self::get_order( $order_id );

		if ( ! self::is_workflow_enabled() ) {
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => __( 'Electronic invoice workflow management is disabled.', 'yoohw-vietnam-store-tools' ) ] );
		}

		if ( ! self::order_has_invoice_request( $order ) ) {
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => __( 'This order does not contain a VAT invoice request.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$data = [
			'status'     => sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_status' ) ),
			'provider'   => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_provider' ),
			'number'     => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_number' ),
			'symbol'     => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_symbol' ),
			'issued_at'  => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_issued_at' ),
			'lookup_url' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_lookup_url' ),
		];
		$created_attachments = [];

		foreach ( [ 'pdf', 'xml' ] as $file_type ) {
			$attachment_key = $file_type . '_attachment_id';
			$remove_key     = 'vck_einvoice_remove_' . $file_type;

			if ( 'yes' === Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $remove_key ) ) {
				$data[ $attachment_key ] = 0;
			}

			$attachment_id = $this->handle_attachment_upload( 'vck_einvoice_' . $file_type, $file_type, $order );

			if ( is_wp_error( $attachment_id ) ) {
				$this->delete_created_attachments( $created_attachments );
				$this->redirect_to_order( $order, [ 'vck_einvoice_error' => $attachment_id->get_error_message() ] );
			}

			if ( $attachment_id ) {
				$data[ $attachment_key ] = $attachment_id;
				$created_attachments[]   = $attachment_id;
			}
		}

		$result = self::update_order_data(
			$order,
			$data,
			[
				'actor_id' => get_current_user_id(),
				'source'   => 'admin',
				'note'     => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_textarea( 'vck_einvoice_note' ),
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->delete_created_attachments( $created_attachments );
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => $result->get_error_message() ] );
		}

		$this->redirect_to_order( $order, [ 'vck_einvoice_notice' => 'saved' ] );
	}

	public function render_admin_notices() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		if ( 'saved' === sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'vck_einvoice_notice' ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Electronic invoice workflow saved.', 'yoohw-vietnam-store-tools' ) . '</p></div>';
		}

		$error = Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'vck_einvoice_error' );

		if ( '' !== $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	private function render_select_field( $name, $label, $statuses, $selected_status ) {
		echo '<label class="vck-einvoice__field" for="' . esc_attr( $name ) . '"><span>' . esc_html( $label ) . '</span>';
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" required>';

		foreach ( $statuses as $status_id => $status ) {
			echo '<option value="' . esc_attr( $status_id ) . '"' . selected( $selected_status, $status_id, false ) . '>' . esc_html( $status['label'] ) . '</option>';
		}

		echo '</select></label>';
	}

	private function render_text_field( $name, $label, $value, $placeholder = '' ) {
		echo '<label class="vck-einvoice__field" for="' . esc_attr( $name ) . '"><span>' . esc_html( $label ) . '</span>';
		echo '<input type="text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off"></label>';
	}

	private function render_datetime_field( $name, $label, $value ) {
		echo '<label class="vck-einvoice__field" for="' . esc_attr( $name ) . '"><span>' . esc_html( $label ) . '</span>';
		echo '<input type="datetime-local" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( self::format_datetime_input_value( $value ) ) . '"></label>';
	}

	private function render_url_field( $name, $label, $value ) {
		echo '<label class="vck-einvoice__field" for="' . esc_attr( $name ) . '"><span>' . esc_html( $label ) . '</span>';
		echo '<input type="url" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="https://" inputmode="url"></label>';
	}

	private function render_file_field( $order, $type, $label, $attachment_id, $accept ) {
		$field_id = 'vck_einvoice_' . $type . '_' . $order->get_id();
		$url      = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		$filename = $attachment_id ? self::get_attachment_display_value( $attachment_id ) : '';
		?>
		<div class="vck-einvoice-file">
			<span class="vck-einvoice-file__label"><?php echo esc_html( $label ); ?></span>
			<?php if ( $url ) : ?>
				<div class="vck-einvoice-file__current">
					<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $filename ); ?></a>
				</div>
				<label class="vck-einvoice-file__remove"><input type="checkbox" name="vck_einvoice_remove_<?php echo esc_attr( $type ); ?>" value="yes"> <?php esc_html_e( 'Unlink current file', 'yoohw-vietnam-store-tools' ); ?></label>
			<?php else : ?>
				<span class="vck-einvoice-file__empty"><?php esc_html_e( 'No file attached', 'yoohw-vietnam-store-tools' ); ?></span>
			<?php endif; ?>
			<label class="vck-einvoice-file__upload" for="<?php echo esc_attr( $field_id ); ?>">
				<span><?php echo esc_html( $attachment_id ? __( 'Replace file', 'yoohw-vietnam-store-tools' ) : __( 'Choose file', 'yoohw-vietnam-store-tools' ) ); ?></span>
				<input type="file" id="<?php echo esc_attr( $field_id ); ?>" name="vck_einvoice_<?php echo esc_attr( $type ); ?>" accept="<?php echo esc_attr( $accept ); ?>">
			</label>
		</div>
		<?php
	}

	private function render_read_only_summary( $data ) {
		$fields = [
			__( 'Invoice provider', 'yoohw-vietnam-store-tools' ) => $data['provider'],
			__( 'Invoice number', 'yoohw-vietnam-store-tools' )   => $data['number'],
			__( 'Invoice symbol', 'yoohw-vietnam-store-tools' )   => $data['symbol'],
			__( 'Issue date', 'yoohw-vietnam-store-tools' )       => $data['issued_at'] ? self::format_datetime_display_value( $data['issued_at'] ) : '',
			__( 'Lookup URL', 'yoohw-vietnam-store-tools' )       => $data['lookup_url'],
		];
		?>
		<dl class="vck-einvoice__readonly">
			<?php foreach ( $fields as $label => $value ) : ?>
				<div><dt><?php echo esc_html( $label ); ?></dt><dd>
					<?php if ( __( 'Lookup URL', 'yoohw-vietnam-store-tools' ) === $label && $value ) : ?>
						<a href="<?php echo esc_url( $value ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $value ); ?></a>
					<?php else : ?>
						<?php echo '' !== (string) $value ? esc_html( $value ) : '<span aria-hidden="true">—</span>'; ?>
					<?php endif; ?>
				</dd></div>
			<?php endforeach; ?>
			<?php foreach ( [ 'pdf' => __( 'PDF invoice', 'yoohw-vietnam-store-tools' ), 'xml' => __( 'XML invoice data', 'yoohw-vietnam-store-tools' ) ] as $type => $label ) : ?>
				<?php $attachment_id = $data[ $type . '_attachment_id' ]; ?>
				<?php $url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : ''; ?>
				<div><dt><?php echo esc_html( $label ); ?></dt><dd>
					<?php if ( $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::get_attachment_display_value( $attachment_id ) ); ?></a>
					<?php else : ?>
						<span aria-hidden="true">—</span>
					<?php endif; ?>
				</dd></div>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	private function render_history( $order ) {
		$history = self::get_order_history( $order );
		?>
		<details class="vck-einvoice-history"<?php echo empty( $history ) ? '' : ' open'; ?>>
			<summary><?php esc_html_e( 'Change history', 'yoohw-vietnam-store-tools' ); ?> <span>(<?php echo esc_html( number_format_i18n( count( $history ) ) ); ?>)</span></summary>
			<?php if ( empty( $history ) ) : ?>
				<p class="vck-einvoice-history__empty"><?php esc_html_e( 'No workflow changes have been recorded yet.', 'yoohw-vietnam-store-tools' ); ?></p>
			<?php else : ?>
				<ol>
					<?php foreach ( array_slice( $history, 0, 25 ) as $entry ) : ?>
						<?php $this->render_history_entry( $entry ); ?>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</details>
		<?php
	}

	private function render_history_entry( $entry ) {
		$timestamp = ! empty( $entry['timestamp'] ) ? strtotime( $entry['timestamp'] ) : false;
		$actor     = ! empty( $entry['actor_name'] ) ? $entry['actor_name'] : __( 'System', 'yoohw-vietnam-store-tools' );
		$changes   = ! empty( $entry['changes'] ) && is_array( $entry['changes'] ) ? $entry['changes'] : [];
		?>
		<li>
			<div class="vck-einvoice-history__meta">
				<strong><?php echo esc_html( $actor ); ?></strong>
				<?php if ( $timestamp ) : ?><time datetime="<?php echo esc_attr( gmdate( 'c', $timestamp ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ); ?></time><?php endif; ?>
			</div>
			<?php if ( $changes ) : ?>
				<ul>
					<?php foreach ( $changes as $change ) : ?>
						<li><?php echo wp_kses_post( self::format_history_change( $change ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $entry['note'] ) ) : ?><p><?php echo nl2br( esc_html( $entry['note'] ) ); ?></p><?php endif; ?>
		</li>
		<?php
	}

	private function handle_attachment_upload( $field_name, $expected_extension, $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce is verified before this method is called.
		if ( empty( $_FILES[ $field_name ] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field_name ]['error'] ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce is verified before this method is called.
		$filename = sanitize_file_name( wp_unslash( $_FILES[ $field_name ]['name'] ) );

		if ( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) !== $expected_extension ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_file', __( 'Only PDF and XML invoice files are accepted in their matching fields.', 'yoohw-vietnam-store-tools' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce is verified before this method is called.
		if ( 'xml' === $expected_extension && ! self::is_valid_xml_file( $_FILES[ $field_name ]['tmp_name'] ) ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_xml', __( 'The XML invoice file is not well-formed.', 'yoohw-vietnam-store-tools' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$this->handling_invoice_upload = $expected_extension;

		try {
			$attachment_id = media_handle_upload(
				$field_name,
				0,
				[],
				[
					'test_form' => false,
				]
			);
		} finally {
			$this->handling_invoice_upload = '';
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_yoohw_vietnam_store_tools_einvoice_order_id', $order->get_id() );

		return absint( $attachment_id );
	}

	private static function is_valid_xml_file( $file ) {
		if ( ! is_readable( $file ) || ! class_exists( 'DOMDocument' ) ) {
			return false;
		}

		$previous_errors = libxml_use_internal_errors( true );
		$document        = new DOMDocument();
		$is_valid        = $document->load( $file, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		return (bool) $is_valid;
	}

	private static function sanitize_data_value( $key, $value ) {
		if ( 'status' === $key ) {
			$value = sanitize_key( $value );

			return isset( self::get_statuses()[ $value ] )
				? $value
				: new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_status', __( 'Select a valid electronic invoice workflow status.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( in_array( $key, [ 'number', 'symbol', 'provider' ], true ) ) {
			return trim( sanitize_text_field( wc_clean( $value ) ) );
		}

		if ( 'lookup_url' === $key ) {
			$raw_value = trim( (string) $value );
			$value     = trim( esc_url_raw( $raw_value, [ 'http', 'https' ] ) );

			if ( '' !== $raw_value && ( '' === $value || ! wp_http_validate_url( $value ) ) ) {
				return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_url', __( 'Enter a valid HTTP or HTTPS invoice lookup URL.', 'yoohw-vietnam-store-tools' ) );
			}

			return $value;
		}

		if ( 'issued_at' === $key ) {
			return self::sanitize_datetime_value( $value );
		}

		if ( in_array( $key, [ 'pdf_attachment_id', 'xml_attachment_id' ], true ) ) {
			$attachment_id = absint( $value );

			if ( $attachment_id && 'attachment' !== get_post_type( $attachment_id ) ) {
				return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_attachment', __( 'The selected invoice attachment could not be found.', 'yoohw-vietnam-store-tools' ) );
			}

			return $attachment_id;
		}

		return '';
	}

	private static function sanitize_datetime_value( $value ) {
		$value = trim( sanitize_text_field( $value ) );

		if ( '' === $value ) {
			return '';
		}

		try {
			$date = new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $exception ) {
			unset( $exception );
			return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_date', __( 'Enter a valid invoice issue date.', 'yoohw-vietnam-store-tools' ) );
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' );
	}

	private static function format_datetime_input_value( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return '';
		}

		try {
			$date = new DateTimeImmutable( $value );
		} catch ( Exception $exception ) {
			unset( $exception );
			return '';
		}

		return $date->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
	}

	private static function format_datetime_display_value( $value ) {
		try {
			$date = new DateTimeImmutable( $value );
		} catch ( Exception $exception ) {
			unset( $exception );
			return (string) $value;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp() );
	}

	private static function get_data_field_map() {
		return [
			'status'            => self::META_STATUS,
			'number'            => self::META_NUMBER,
			'symbol'            => self::META_SYMBOL,
			'issued_at'         => self::META_ISSUED_AT,
			'lookup_url'        => self::META_LOOKUP_URL,
			'pdf_attachment_id' => self::META_PDF_ATTACHMENT_ID,
			'xml_attachment_id' => self::META_XML_ATTACHMENT_ID,
			'provider'          => self::META_PROVIDER,
		];
	}

	private static function get_field_labels() {
		return [
			'status'            => __( 'Workflow status', 'yoohw-vietnam-store-tools' ),
			'number'            => __( 'Invoice number', 'yoohw-vietnam-store-tools' ),
			'symbol'            => __( 'Invoice symbol', 'yoohw-vietnam-store-tools' ),
			'issued_at'         => __( 'Issue date', 'yoohw-vietnam-store-tools' ),
			'lookup_url'        => __( 'Lookup URL', 'yoohw-vietnam-store-tools' ),
			'pdf_attachment_id' => __( 'PDF invoice', 'yoohw-vietnam-store-tools' ),
			'xml_attachment_id' => __( 'XML invoice data', 'yoohw-vietnam-store-tools' ),
			'provider'          => __( 'Invoice provider', 'yoohw-vietnam-store-tools' ),
		];
	}

	private static function create_history_entry( $changes, $context, $note ) {
		$actor_id   = ! empty( $context['actor_id'] ) ? absint( $context['actor_id'] ) : 0;
		$actor      = $actor_id ? get_userdata( $actor_id ) : false;
		$actor_name = $actor ? $actor->display_name : '';

		return [
			'id'         => wp_generate_uuid4(),
			'timestamp'  => gmdate( 'c' ),
			'actor_id'   => $actor_id,
			'actor_name' => sanitize_text_field( $actor_name ),
			'source'     => sanitize_key( isset( $context['source'] ) ? $context['source'] : 'integration' ),
			'note'       => $note,
			'changes'    => array_values( is_array( $changes ) ? $changes : [] ),
		];
	}

	private static function append_history_entry( $order, $entry ) {
		$history = $order->get_meta( self::META_HISTORY, true );
		$history = is_array( $history ) ? $history : [];
		$history[] = $entry;

		if ( count( $history ) > 100 ) {
			$history = array_slice( $history, -100 );
		}

		$order->update_meta_data( self::META_HISTORY, $history );
	}

	private static function get_order_note_message( $current, $next, $note ) {
		$statuses = self::get_statuses();

		if ( $current['status'] !== $next['status'] ) {
			$message = sprintf(
				/* translators: 1: previous invoice workflow status, 2: new invoice workflow status. */
				__( 'Electronic invoice workflow status changed from %1$s to %2$s.', 'yoohw-vietnam-store-tools' ),
				isset( $statuses[ $current['status'] ] ) ? $statuses[ $current['status'] ]['label'] : $current['status'],
				isset( $statuses[ $next['status'] ] ) ? $statuses[ $next['status'] ]['label'] : $next['status']
			);
		} else {
			$message = __( 'Electronic invoice workflow details updated.', 'yoohw-vietnam-store-tools' );
		}

		if ( '' !== $note ) {
			$message .= ' ' . $note;
		}

		return $message;
	}

	private static function format_history_change( $change ) {
		$field  = isset( $change['field'] ) ? $change['field'] : '';
		$labels = self::get_field_labels();
		$label  = isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
		$from   = self::format_history_value( $field, isset( $change['from'] ) ? $change['from'] : '' );
		$to     = self::format_history_value( $field, isset( $change['to'] ) ? $change['to'] : '' );

		return sprintf(
			/* translators: 1: changed field label, 2: previous value, 3: new value. */
			__( '%1$s: %2$s → %3$s', 'yoohw-vietnam-store-tools' ),
			'<strong>' . esc_html( $label ) . '</strong>',
			esc_html( '' !== $from ? $from : '—' ),
			esc_html( '' !== $to ? $to : '—' )
		);
	}

	private static function format_history_value( $field, $value ) {
		if ( 'status' === $field ) {
			$statuses = self::get_statuses();
			return isset( $statuses[ $value ] ) ? $statuses[ $value ]['label'] : (string) $value;
		}

		if ( 'issued_at' === $field && '' !== (string) $value ) {
			$timestamp = strtotime( $value );
			return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : (string) $value;
		}

		if ( in_array( $field, [ 'pdf_attachment_id', 'xml_attachment_id' ], true ) ) {
			return $value ? self::get_attachment_display_value( $value ) : '';
		}

		return (string) $value;
	}

	private static function get_attachment_display_value( $attachment_id ) {
		$path = get_attached_file( absint( $attachment_id ) );

		if ( $path ) {
			return wp_basename( $path );
		}

		$title = get_the_title( absint( $attachment_id ) );

		return '' !== $title ? $title : '#' . absint( $attachment_id );
	}

	private function delete_created_attachments( $attachment_ids ) {
		foreach ( $attachment_ids as $attachment_id ) {
			wp_delete_attachment( absint( $attachment_id ), true );
		}
	}

	private function redirect_to_order( $order, $query_args ) {
		$url = $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url( 'edit.php?post_type=shop_order' );
		wp_safe_redirect( add_query_arg( $query_args, $url ) );
		exit;
	}

	private static function order_has_invoice_request( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$requested = $order->get_meta( Yoohw_Vietnam_Store_Tools_Tax_Invoice::META_REQUESTED, true );

		if ( '' === (string) $requested ) {
			$requested = $order->get_meta( '_vck_tax_invoice_requested', true );
		}

		return 'yes' === strtolower( trim( (string) $requested ) );
	}

	private static function get_order( $order ) {
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		return function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order ) ) : false;
	}

	private function get_current_admin_order() {
		return Yoohw_Vietnam_Store_Tools_Request_Security::get_admin_order_from_query();
	}

	private function get_admin_order_from_object( $post_or_order_object ) {
		if ( $post_or_order_object instanceof WC_Order ) {
			return $post_or_order_object;
		}

		if ( $post_or_order_object instanceof WP_Post ) {
			return wc_get_order( $post_or_order_object->ID );
		}

		return is_numeric( $post_or_order_object ) ? wc_get_order( absint( $post_or_order_object ) ) : false;
	}

	private function get_order_admin_screen_ids() {
		$screen_ids = [ 'shop_order' ];

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $screen_ids ) ) );
	}
}
