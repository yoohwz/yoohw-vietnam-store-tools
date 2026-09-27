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
	const ACTION_SEND_EMAIL = 'yoohw_vietnam_store_tools_send_einvoice_email';
	const ACTION_RECORD_DOCUMENT = 'yoohw_vietnam_store_tools_record_einvoice_document';
	const MAX_DOCUMENTS = 50;
	const LEASE_SECONDS = 120;

	const META_STATUS            = '_yoohw_vietnam_store_tools_einvoice_status';
	const META_NUMBER            = '_yoohw_vietnam_store_tools_einvoice_number';
	const META_SYMBOL            = '_yoohw_vietnam_store_tools_einvoice_symbol';
	const META_ISSUED_AT         = '_yoohw_vietnam_store_tools_einvoice_issued_at';
	const META_LOOKUP_URL        = '_yoohw_vietnam_store_tools_einvoice_lookup_url';
	const META_PDF_ATTACHMENT_ID = '_yoohw_vietnam_store_tools_einvoice_pdf_attachment_id';
	const META_XML_ATTACHMENT_ID = '_yoohw_vietnam_store_tools_einvoice_xml_attachment_id';
	const META_PROVIDER          = '_yoohw_vietnam_store_tools_einvoice_provider';
	const META_HISTORY           = '_yoohw_vietnam_store_tools_einvoice_history';
	const META_EMAIL_STATUS      = '_yoohw_vietnam_store_tools_einvoice_email_status';
	const META_EMAIL_SENT_AT     = '_yoohw_vietnam_store_tools_einvoice_email_sent_at';
	const META_DOCUMENTS         = '_yoohw_vietnam_store_tools_einvoice_documents';
	const META_CURRENT_DOCUMENT_ID = '_yoohw_vietnam_store_tools_einvoice_current_document_id';
	const META_REVISION          = '_yoohw_vietnam_store_tools_einvoice_revision';
	const META_PROVIDER_DOCUMENT_ID = '_yoohw_vietnam_store_tools_einvoice_provider_document_id';
	const META_HANDOFF_REFERENCE = '_yoohw_vietnam_store_tools_einvoice_handoff_reference';
	const META_PROVIDER_STATUS_TEXT = '_yoohw_vietnam_store_tools_einvoice_provider_status_text';
	const META_HANDED_OFF_AT     = '_yoohw_vietnam_store_tools_einvoice_handed_off_at';
	const META_CONFIRMED_AT      = '_yoohw_vietnam_store_tools_einvoice_confirmed_at';

	private $handling_invoice_upload = '';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_admin_order_metabox' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );
		add_filter( 'woocommerce_email_classes', [ $this, 'register_email_classes' ] );

		if ( self::is_workflow_enabled() ) {
			add_action( 'woocommerce_checkout_create_order', [ $this, 'initialize_requested_workflow' ], 50 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'initialize_requested_workflow' ], 50 );
			add_action( 'admin_post_' . self::ACTION_SAVE, [ $this, 'handle_save_action' ] );
			add_action( 'admin_post_' . self::ACTION_SEND_EMAIL, [ $this, 'handle_send_email_action' ] );
			add_action( 'admin_post_' . self::ACTION_RECORD_DOCUMENT, [ $this, 'handle_record_document_action' ] );
			add_filter( 'upload_mimes', [ $this, 'allow_invoice_upload_mimes' ], 20, 2 );
			add_filter( 'wp_check_filetype_and_ext', [ $this, 'normalize_invoice_xml_filetype' ], 20, 5 );
		}
	}

	public static function is_workflow_enabled() {
		return Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ELECTRONIC_INVOICE );
	}

	public function register_email_classes( $emails ) {
		$email_class_file = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'includes/emails/class-vietnam-commerce-kit-customer-electronic-invoice-email.php';

		if ( file_exists( $email_class_file ) ) {
			include_once $email_class_file;
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Customer_Electronic_Invoice_Email' ) ) {
			$emails['Yoohw_Vietnam_Store_Tools_Customer_Electronic_Invoice_Email'] = new Yoohw_Vietnam_Store_Tools_Customer_Electronic_Invoice_Email();
		}

		return $emails;
	}

	public function allow_invoice_upload_mimes( $mimes, $user = null ) {
		unset( $user );

		if ( ! self::is_workflow_enabled() || ! in_array( $this->handling_invoice_upload, [ 'pdf', 'xml' ], true ) ) {
			return $mimes;
		}

		if ( 'pdf' === $this->handling_invoice_upload ) {
			$mimes['pdf'] = 'application/pdf';
		} else {
			$mimes['xml'] = 'application/xml';
		}

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
			'provider_document_id' => (string) $order->get_meta( self::META_PROVIDER_DOCUMENT_ID, true ),
			'handoff_reference' => (string) $order->get_meta( self::META_HANDOFF_REFERENCE, true ),
			'provider_status_text' => (string) $order->get_meta( self::META_PROVIDER_STATUS_TEXT, true ),
			'handed_off_at'     => (string) $order->get_meta( self::META_HANDED_OFF_AT, true ),
			'confirmed_at'      => (string) $order->get_meta( self::META_CONFIRMED_AT, true ),
			'workflow_revision' => absint( $order->get_meta( self::META_REVISION, true ) ),
			'current_document_id' => (string) $order->get_meta( self::META_CURRENT_DOCUMENT_ID, true ),
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
		$resolved = self::get_order( $order );
		if ( ! $resolved ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_order', __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) );
		}
		$context = is_array( $context ) ? $context : [];
		$strict = ! empty( $context['v2_strict'] );
		$conditional = array_key_exists( 'expected_revision', $context ) && array_key_exists( 'expected_current_document_id', $context );
		if ( ! $resolved->get_id() && ! $strict && ! $conditional ) {
			return self::update_order_data_unlocked( $resolved, $data, $context );
		}
		$token = self::acquire_lock( $resolved->get_id() );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		try {
			$fresh = wc_get_order( $resolved->get_id() );
			if ( ! $fresh instanceof WC_Order ) {
				return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_order', __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) );
			}
			if ( $strict || $conditional ) {
				$expected = isset( $context['expected_revision'] ) ? $context['expected_revision'] : null;
				if ( null === $expected || ! ctype_digit( (string) $expected ) || (int) $expected !== absint( $fresh->get_meta( self::META_REVISION, true ) ) ) {
					return self::stale_error();
				}
				if ( $conditional && ( (string) $context['expected_current_document_id'] !== (string) $fresh->get_meta( self::META_CURRENT_DOCUMENT_ID, true ) || ( isset( $context['expected_projection'] ) && self::get_order_data( $fresh ) !== $context['expected_projection'] ) ) ) {
					return self::stale_error();
				}
			}
			if ( $strict ) {
				if ( isset( $context['allowed_current_statuses'] ) && ( ! is_array( $context['allowed_current_statuses'] ) || ! in_array( self::get_order_data( $fresh )['status'], $context['allowed_current_statuses'], true ) ) ) {
					return self::stale_error();
				}
				if ( isset( $data['status'] ) && in_array( sanitize_key( $data['status'] ), [ 'adjusted', 'replaced' ], true ) && sanitize_key( $data['status'] ) !== self::get_order_data( $fresh )['status'] ) {
					return self::v2_error( 'lineage', __( 'Create a linked adjustment or replacement document for this status.', 'yoohw-vietnam-store-tools' ) );
				}
				$valid = self::validate_v2_projection( $fresh, $data );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
			if ( ! self::owns_lock( $fresh->get_id(), $token ) ) {
				return self::lock_error();
			}
			$has_v2 = '' !== (string) $fresh->get_meta( self::META_REVISION, true ) || '' !== (string) $fresh->get_meta( self::META_CURRENT_DOCUMENT_ID, true ) || (bool) $fresh->get_meta( self::META_DOCUMENTS, true );
			$context['advance_revision'] = $strict || $conditional || $has_v2;
			$context['lock_token'] = $token;
			$write_order = $fresh;
			if ( $order instanceof WC_Order ) {
				self::refresh_caller_meta_for_write( $resolved );
				$write_order = $resolved;
			}
			return self::update_order_data_unlocked( $write_order, $data, $context );
		} finally {
			self::release_lock( $resolved->get_id(), $token );
		}
	}

	private static function refresh_caller_meta_for_write( $order ) {
		if ( ! method_exists( $order, 'get_meta_data' ) || ! method_exists( $order, 'read_meta_data' ) ) {
			return;
		}
		// Keep the caller's pending non-workflow metadata while reloading authoritative invoice state.
		$pending = [];
		foreach ( $order->get_meta_data() as $meta ) {
			if ( ! is_object( $meta ) || ! isset( $meta->key ) || ! method_exists( $meta, 'get_changes' ) ) {
				continue;
			}
			$changes = $meta->get_changes();
			if ( ! empty( $meta->id ) && ! array_key_exists( 'value', $changes ) ) {
				continue;
			}
			$key = (string) $meta->key;
			if ( 0 === strpos( $key, '_yoohw_vietnam_store_tools_einvoice_' ) ) {
				continue;
			}
			$pending[] = [ 'key' => $key, 'value' => $meta->value, 'id' => absint( $meta->id ) ];
		}
		$order->read_meta_data( true );
		foreach ( $pending as $meta ) {
			if ( null === $meta['value'] ) {
				$order->delete_meta_data( $meta['key'] );
			} else {
				$order->update_meta_data( $meta['key'], $meta['value'], $meta['id'] );
			}
		}
	}

	private static function update_order_data_unlocked( $order, $data, $context = [] ) {
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

		if ( ! empty( $context['advance_revision'] ) ) {
			$order->update_meta_data( self::META_REVISION, absint( $order->get_meta( self::META_REVISION, true ) ) + 1 );
		}

		$history_entry = self::create_history_entry( $changes, $context, $note );
		self::append_history_entry( $order, $history_entry );

		if ( isset( $context['lock_token'] ) && ! self::owns_lock( $order->get_id(), $context['lock_token'] ) ) {
			return self::lock_error();
		}

		$order->save();
		if ( ! empty( $context['add_order_note'] ) ) {
			$order->add_order_note( self::get_order_note_message( $current, $next, $note ) );
		}

		do_action( 'yoohw_vietnam_store_tools_einvoice_workflow_updated', $order, self::get_order_data( $order ), $changes, $history_entry );

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
				'sendAction'   => self::ACTION_SEND_EMAIL,
				'documentAction' => self::ACTION_RECORD_DOCUMENT,
				'saving'       => __( 'Saving...', 'yoohw-vietnam-store-tools' ),
				'sending'      => __( 'Sending...', 'yoohw-vietnam-store-tools' ),
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
		$documents = self::get_order_documents( $order );
		$can_original = '' === $data['current_document_id'] || ( 1 === count( $documents ) && 'legacy' === $documents[0]['kind'] );
		?>
		<div id="<?php echo esc_attr( $panel_id ); ?>" class="vck-einvoice" data-vck-einvoice-panel>
			<div class="vck-einvoice__header">
				<div>
					<span class="vck-einvoice__eyebrow"><?php esc_html_e( 'Current status', 'yoohw-vietnam-store-tools' ); ?></span>
					<span class="vck-einvoice__status vck-einvoice__status--<?php echo esc_attr( $status['tone'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
				</div>
				<p><?php esc_html_e( 'This workflow records invoice progress and files only. It does not issue invoices or call a provider API.', 'yoohw-vietnam-store-tools' ); ?></p>
				<?php if ( self::has_unlinked_lineage( $order, $data ) ) : ?><p><?php esc_html_e( 'Legacy status without a linked document.', 'yoohw-vietnam-store-tools' ); ?></p><?php endif; ?>
				<?php if ( self::projection_differs_from_document( $order, $data ) ) : ?><p><?php esc_html_e( 'Current invoice fields differ from the recorded document snapshot.', 'yoohw-vietnam-store-tools' ); ?></p><?php endif; ?>
			</div>

			<?php if ( ! self::is_workflow_enabled() ) : ?>
				<div class="notice notice-info inline vck-einvoice__readonly-notice">
					<p><?php esc_html_e( 'Electronic invoice workflow management is disabled. Existing invoice data is shown in read-only mode.', 'yoohw-vietnam-store-tools' ); ?></p>
				</div>
				<?php $this->render_read_only_summary( $data ); ?>
				<?php $this->render_documents( $order ); ?>
				<?php $this->render_history( $order ); ?>
			</div>
				<?php
				return;
			endif;
			?>

			<input type="hidden" name="vck_einvoice_expected_revision" value="<?php echo esc_attr( $data['workflow_revision'] ); ?>">
			<input type="hidden" name="vck_einvoice_expected_document_id" value="<?php echo esc_attr( $data['current_document_id'] ); ?>">
			<div class="vck-einvoice__grid">
				<?php $this->render_select_field( 'vck_einvoice_status', __( 'Workflow status', 'yoohw-vietnam-store-tools' ), $statuses, $data['status'] ); ?>
				<?php $this->render_text_field( 'vck_einvoice_provider', __( 'Invoice provider', 'yoohw-vietnam-store-tools' ), $data['provider'], __( 'For example: MISA, VNPT, Viettel, or another provider', 'yoohw-vietnam-store-tools' ) ); ?>
				<?php $this->render_text_field( 'vck_einvoice_number', __( 'Invoice number', 'yoohw-vietnam-store-tools' ), $data['number'] ); ?>
				<?php $this->render_text_field( 'vck_einvoice_symbol', __( 'Invoice symbol', 'yoohw-vietnam-store-tools' ), $data['symbol'] ); ?>
				<?php $this->render_datetime_field( 'vck_einvoice_issued_at', __( 'Issue date', 'yoohw-vietnam-store-tools' ), $data['issued_at'] ); ?>
				<?php $this->render_url_field( 'vck_einvoice_lookup_url', __( 'Lookup URL', 'yoohw-vietnam-store-tools' ), $data['lookup_url'] ); ?>
				<?php if ( $data['lookup_url'] ) : ?><p><a href="<?php echo esc_url( $data['lookup_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open invoice lookup page', 'yoohw-vietnam-store-tools' ); ?></a></p><?php endif; ?>
				<?php $this->render_text_field( 'vck_einvoice_provider_document_id', __( 'Provider document ID', 'yoohw-vietnam-store-tools' ), $data['provider_document_id'] ); ?>
				<?php $this->render_text_field( 'vck_einvoice_handoff_reference', __( 'Handoff reference', 'yoohw-vietnam-store-tools' ), $data['handoff_reference'] ); ?>
				<?php $this->render_text_field( 'vck_einvoice_provider_status_text', __( 'Provider status text', 'yoohw-vietnam-store-tools' ), $data['provider_status_text'] ); ?>
				<?php $this->render_datetime_field( 'vck_einvoice_handed_off_at', __( 'Handed off at', 'yoohw-vietnam-store-tools' ), $data['handed_off_at'] ); ?>
				<?php $this->render_datetime_field( 'vck_einvoice_confirmed_at', __( 'Confirmed at', 'yoohw-vietnam-store-tools' ), $data['confirmed_at'] ); ?>
			</div>

			<div class="vck-einvoice__files">
				<?php $this->render_file_field( $order, 'pdf', __( 'PDF invoice', 'yoohw-vietnam-store-tools' ), $data['pdf_attachment_id'], 'application/pdf,.pdf' ); ?>
				<?php $this->render_file_field( $order, 'xml', __( 'XML invoice data', 'yoohw-vietnam-store-tools' ), $data['xml_attachment_id'], 'application/xml,text/xml,.xml' ); ?>
			</div>

			<label class="vck-einvoice__field vck-einvoice__field--wide" for="vck_einvoice_note_<?php echo esc_attr( $order->get_id() ); ?>">
				<span><?php esc_html_e( 'Internal update note', 'yoohw-vietnam-store-tools' ); ?></span>
				<textarea id="vck_einvoice_note_<?php echo esc_attr( $order->get_id() ); ?>" name="vck_einvoice_note" rows="2" placeholder="<?php esc_attr_e( 'Optional context for the change history', 'yoohw-vietnam-store-tools' ); ?>"></textarea>
			</label>

			<p><?php esc_html_e( 'When recording a new document, enter its own provider identifiers and files. Current files are not copied automatically.', 'yoohw-vietnam-store-tools' ); ?></p>
			<label class="vck-einvoice__field" for="vck_einvoice_document_kind"><span><?php esc_html_e( 'New document type', 'yoohw-vietnam-store-tools' ); ?></span>
				<select id="vck_einvoice_document_kind" name="vck_einvoice_document_kind">
					<?php if ( $can_original ) : ?><option value="original"><?php esc_html_e( 'Original invoice', 'yoohw-vietnam-store-tools' ); ?></option><?php endif; ?>
					<?php if ( '' !== $data['current_document_id'] ) : ?>
						<option value="adjustment"><?php esc_html_e( 'Adjustment document', 'yoohw-vietnam-store-tools' ); ?></option>
						<option value="replacement"><?php esc_html_e( 'Replacement document', 'yoohw-vietnam-store-tools' ); ?></option>
					<?php endif; ?>
					<?php if ( '' === $data['current_document_id'] && self::has_legacy_document_data( $data ) ) : ?><option value="legacy"><?php esc_html_e( 'Capture existing legacy document', 'yoohw-vietnam-store-tools' ); ?></option><?php endif; ?>
				</select>
			</label>
			<div class="vck-einvoice__actions">
				<button type="button" class="button button-primary" data-vck-einvoice-save data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::ACTION_SAVE . '_' . $order->get_id() ) ); ?>"><?php esc_html_e( 'Save invoice workflow', 'yoohw-vietnam-store-tools' ); ?></button>
				<button type="button" class="button" data-vck-einvoice-document data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::ACTION_RECORD_DOCUMENT . '_' . $order->get_id() ) ); ?>"><?php esc_html_e( 'Record new document', 'yoohw-vietnam-store-tools' ); ?></button>
				<button type="button" class="button" data-vck-einvoice-send data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::ACTION_SEND_EMAIL . '_' . $order->get_id() ) ); ?>"<?php disabled( '' === trim( (string) $order->get_billing_email() ) ); ?>><?php esc_html_e( 'Send invoice email to customer', 'yoohw-vietnam-store-tools' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=yoohw_vietnam_store_tools_customer_electronic_invoice' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Email settings', 'yoohw-vietnam-store-tools' ); ?></a>
			</div>

			<?php $this->render_documents( $order ); ?>
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
			'provider'   => self::get_post_identity_field( 'vck_einvoice_provider' ),
			'number'     => self::get_post_identity_field( 'vck_einvoice_number' ),
			'symbol'     => self::get_post_identity_field( 'vck_einvoice_symbol' ),
			'issued_at'  => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_issued_at' ),
			'lookup_url' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_lookup_url' ),
			'provider_document_id' => self::get_post_identity_field( 'vck_einvoice_provider_document_id' ),
			'handoff_reference' => self::get_post_identity_field( 'vck_einvoice_handoff_reference' ),
			'provider_status_text' => self::get_post_identity_field( 'vck_einvoice_provider_status_text' ),
			'handed_off_at' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_handed_off_at' ),
			'confirmed_at' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_confirmed_at' ),
		];
		$current_data = self::get_order_data( $order );
		foreach ( [ 'issued_at', 'handed_off_at', 'confirmed_at' ] as $date_key ) {
			if ( '' === $data[ $date_key ] && '' !== $current_data[ $date_key ] && '' === self::format_datetime_input_value( $current_data[ $date_key ] ) ) {
				unset( $data[ $date_key ] );
			}
		}
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
				'v2_strict' => true,
				'expected_revision' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_expected_revision' ),
				'note'     => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_textarea( 'vck_einvoice_note' ),
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->delete_created_attachments( $created_attachments );
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => $result->get_error_message() ] );
		}

		$this->redirect_to_order( $order, [ 'vck_einvoice_notice' => 'saved' ] );
	}

	public function handle_record_document_action() {
		$order_id = absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'order_id' ) );
		if ( ! $order_id || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this order.', 'yoohw-vietnam-store-tools' ) );
		}
		if ( ! check_admin_referer( self::ACTION_RECORD_DOCUMENT . '_' . $order_id, 'yoohw_vietnam_store_tools_einvoice_nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'yoohw-vietnam-store-tools' ) );
		}
		$order = self::get_order( $order_id );
		if ( ! $order || ! self::is_workflow_enabled() || ! self::order_has_invoice_request( $order ) ) {
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => __( 'This order does not contain a VAT invoice request.', 'yoohw-vietnam-store-tools' ) ] );
		}
		$kind = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_document_kind' ) );
		$data = [
			'kind' => $kind,
			'prior_document_id' => 'original' === $kind ? '' : Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_expected_document_id' ),
			'provider' => self::get_post_identity_field( 'vck_einvoice_provider' ),
			'number' => self::get_post_identity_field( 'vck_einvoice_number' ),
			'symbol' => self::get_post_identity_field( 'vck_einvoice_symbol' ),
			'issued_at' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_issued_at' ),
			'lookup_url' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_lookup_url' ),
			'provider_document_id' => self::get_post_identity_field( 'vck_einvoice_provider_document_id' ),
			'handoff_reference' => self::get_post_identity_field( 'vck_einvoice_handoff_reference' ),
			'provider_status_text' => self::get_post_identity_field( 'vck_einvoice_provider_status_text' ),
			'handed_off_at' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_handed_off_at' ),
			'confirmed_at' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_confirmed_at' ),
		];
		$created_attachments = [];
		if ( 'legacy' !== $kind ) {
			foreach ( [ 'pdf', 'xml' ] as $file_type ) {
				$key = $file_type . '_attachment_id';
				$data[ $key ] = 0;
				$id = $this->handle_attachment_upload( 'vck_einvoice_' . $file_type, $file_type, $order );
				if ( is_wp_error( $id ) ) {
					$this->delete_created_attachments( $created_attachments );
					$this->redirect_to_order( $order, [ 'vck_einvoice_error' => $id->get_error_message() ] );
				}
				if ( $id ) {
					$data[ $key ] = $id;
					$created_attachments[] = $id;
				}
			}
		}
		$result = self::record_order_document( $order, $data, [
			'actor_id' => get_current_user_id(),
			'source' => 'admin',
			'note' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_textarea( 'vck_einvoice_note' ),
			'expected_revision' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_expected_revision' ),
			'expected_current_document_id' => Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'vck_einvoice_expected_document_id' ),
		] );
		if ( is_wp_error( $result ) ) {
			$this->delete_created_attachments( $created_attachments );
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => $result->get_error_message() ] );
		}
		$this->redirect_to_order( $order, [ 'vck_einvoice_notice' => 'saved' ] );
	}

	private function render_documents( $order ) {
		$documents = self::get_order_documents( $order );
		$current = self::get_order_data( $order );
		$kind_labels = [ 'original' => __( 'Original invoice', 'yoohw-vietnam-store-tools' ), 'adjustment' => __( 'Adjustment document', 'yoohw-vietnam-store-tools' ), 'replacement' => __( 'Replacement document', 'yoohw-vietnam-store-tools' ), 'legacy' => __( 'Capture existing legacy document', 'yoohw-vietnam-store-tools' ) ];
		?>
		<details class="vck-einvoice-history"<?php echo $documents ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Invoice documents', 'yoohw-vietnam-store-tools' ); ?> (<?php echo esc_html( count( $documents ) ); ?>)</summary>
			<?php if ( ! $documents ) : ?><p><?php esc_html_e( 'No linked invoice documents recorded.', 'yoohw-vietnam-store-tools' ); ?></p><?php endif; ?>
			<ol>
			<?php foreach ( array_reverse( $documents ) as $document ) : ?>
				<li>
					<strong><?php echo esc_html( isset( $kind_labels[ $document['kind'] ] ) ? $kind_labels[ $document['kind'] ] : $document['kind'] ); ?></strong>
					<?php if ( $current['current_document_id'] === $document['id'] ) : ?><?php esc_html_e( 'Current document', 'yoohw-vietnam-store-tools' ); ?><?php endif; ?>
					<code><?php echo esc_html( $document['id'] ); ?></code>
					<?php if ( ! empty( $document['prior_document_id'] ) ) : ?><p><?php esc_html_e( 'Prior document', 'yoohw-vietnam-store-tools' ); ?>: <code><?php echo esc_html( $document['prior_document_id'] ); ?></code></p><?php endif; ?>
					<p><?php echo esc_html( trim( (string) $document['provider'] . ' · ' . (string) $document['number'] . ' · ' . (string) $document['symbol'], ' ·' ) ); ?></p>
					<?php foreach ( [ 'pdf', 'xml' ] as $type ) : ?>
						<?php $id = absint( $document[ $type . '_attachment_id' ] ); $url = $id ? wp_get_attachment_url( $id ) : ''; $path = $id ? get_attached_file( $id ) : ''; ?>
						<?php if ( $id ) : ?><p><?php echo esc_html( strtoupper( $type ) ); ?>: <?php if ( $url && $path && is_readable( $path ) ) : ?><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $document[ $type . '_filename' ] ); ?></a><?php else : ?><?php echo esc_html( $document[ $type . '_filename' ] ); ?> (<?php esc_html_e( 'File unavailable', 'yoohw-vietnam-store-tools' ); ?>)<?php endif; ?></p><?php endif; ?>
					<?php endforeach; ?>
				</li>
			<?php endforeach; ?>
			</ol>
		</details>
		<?php
	}

	public function handle_send_email_action() {
		$order_id = absint( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'order_id' ) );

		if ( ! $order_id || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this order.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( ! check_admin_referer( self::ACTION_SEND_EMAIL . '_' . $order_id, 'yoohw_vietnam_store_tools_einvoice_nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'yoohw-vietnam-store-tools' ) );
		}

		$order = self::get_order( $order_id );

		if ( ! self::is_workflow_enabled() ) {
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => __( 'Electronic invoice workflow management is disabled.', 'yoohw-vietnam-store-tools' ) ] );
		}

		if ( ! self::order_has_invoice_request( $order ) ) {
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => __( 'This order does not contain a VAT invoice request.', 'yoohw-vietnam-store-tools' ) ] );
		}

		if ( '' === trim( (string) $order->get_billing_email() ) ) {
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => __( 'The customer billing email is missing.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$current_data = self::get_order_data( $order );
		if ( ! self::send_customer_invoice_email( $order ) ) {
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => __( 'The electronic invoice email could not be sent.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$result = self::update_order_data(
			$order,
			'sent' === $current_data['status'] ? [] : [ 'status' => 'sent' ],
			[
				'source' => 'customer_email',
				'note' => 'sent' === $current_data['status'] ? '' : __( 'Electronic invoice emailed to the customer.', 'yoohw-vietnam-store-tools' ),
				'expected_revision' => $current_data['workflow_revision'],
				'expected_current_document_id' => $current_data['current_document_id'],
				'expected_projection' => $current_data,
			]
		);
		if ( is_wp_error( $result ) ) {
			$this->redirect_to_order( $order, [ 'vck_einvoice_error' => __( 'The email was sent, but the invoice changed before its status could be updated. Reload the order before sending again.', 'yoohw-vietnam-store-tools' ) ] );
		}

		$this->redirect_to_order( $order, [ 'vck_einvoice_notice' => 'email_sent' ] );
	}

	public static function send_customer_invoice_email( $order ) {
		$order = self::get_order( $order );

		if ( ! $order || '' === trim( (string) $order->get_billing_email() ) || ! function_exists( 'WC' ) || ! WC() ) {
			return false;
		}

		$mailer = WC()->mailer();

		if ( ! $mailer || ! method_exists( $mailer, 'get_emails' ) ) {
			self::record_email_result( $order, false );
			return false;
		}

		$emails    = $mailer->get_emails();
		$email_key = 'Yoohw_Vietnam_Store_Tools_Customer_Electronic_Invoice_Email';

		if ( empty( $emails[ $email_key ] ) || ! is_callable( [ $emails[ $email_key ], 'trigger' ] ) ) {
			self::record_email_result( $order, false );
			return false;
		}

		$sent = (bool) $emails[ $email_key ]->trigger( $order->get_id(), self::get_order_data( $order ) );

		self::record_email_result( $order, $sent );

		return $sent;
	}

	private static function record_email_result( $order, $sent ) {
		$order->update_meta_data( self::META_EMAIL_STATUS, $sent ? 'sent' : 'failed' );

		if ( $sent ) {
			$order->update_meta_data( self::META_EMAIL_SENT_AT, gmdate( 'c' ) );
		} else {
			$order->delete_meta_data( self::META_EMAIL_SENT_AT );
		}

		$order->save();
	}

	public function render_admin_notices() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$notice = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'vck_einvoice_notice' ) );

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Electronic invoice workflow saved.', 'yoohw-vietnam-store-tools' ) . '</p></div>';
		} elseif ( 'email_sent' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Electronic invoice email sent to the customer.', 'yoohw-vietnam-store-tools' ) . '</p></div>';
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
		$path     = $attachment_id ? get_attached_file( $attachment_id ) : '';
		$url      = $path && is_readable( $path ) ? wp_get_attachment_url( $attachment_id ) : '';
		$filename = $attachment_id ? self::get_attachment_display_value( $attachment_id ) : '';
		?>
		<div class="vck-einvoice-file">
			<span class="vck-einvoice-file__label"><?php echo esc_html( $label ); ?></span>
			<?php if ( $url ) : ?>
				<div class="vck-einvoice-file__current">
					<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $filename ); ?></a>
				</div>
			<?php else : ?>
				<span class="vck-einvoice-file__empty"><?php echo $attachment_id ? esc_html( $filename ) . ' (' . esc_html__( 'File unavailable', 'yoohw-vietnam-store-tools' ) . ')' : esc_html__( 'No file attached', 'yoohw-vietnam-store-tools' ); ?></span>
			<?php endif; ?>
			<?php if ( $attachment_id ) : ?><label class="vck-einvoice-file__remove"><input type="checkbox" name="vck_einvoice_remove_<?php echo esc_attr( $type ); ?>" value="yes"> <?php esc_html_e( 'Unlink current file', 'yoohw-vietnam-store-tools' ); ?></label><?php endif; ?>
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
			__( 'Provider document ID', 'yoohw-vietnam-store-tools' ) => $data['provider_document_id'],
			__( 'Handoff reference', 'yoohw-vietnam-store-tools' ) => $data['handoff_reference'],
			__( 'Provider status text', 'yoohw-vietnam-store-tools' ) => $data['provider_status_text'],
			__( 'Handed off at', 'yoohw-vietnam-store-tools' ) => $data['handed_off_at'],
			__( 'Confirmed at', 'yoohw-vietnam-store-tools' ) => $data['confirmed_at'],
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
				<?php $path = $attachment_id ? get_attached_file( $attachment_id ) : ''; $url = $path && is_readable( $path ) ? wp_get_attachment_url( $attachment_id ) : ''; ?>
				<div><dt><?php echo esc_html( $label ); ?></dt><dd>
					<?php if ( $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::get_attachment_display_value( $attachment_id ) ); ?></a>
					<?php else : ?>
						<?php echo $attachment_id ? esc_html( self::get_attachment_display_value( $attachment_id ) ) . ' (' . esc_html__( 'File unavailable', 'yoohw-vietnam-store-tools' ) . ')' : '<span aria-hidden="true">—</span>'; ?>
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- The action nonce is verified before this method is called; PHP supplies the upload error field.
		if ( empty( $_FILES[ $field_name ] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field_name ]['error'] ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- The action nonce is verified before this method is called; the filename is sanitized immediately.
		$filename = sanitize_file_name( wp_unslash( $_FILES[ $field_name ]['name'] ) );

		if ( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) !== $expected_extension ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_file', __( 'Only PDF and XML invoice files are accepted in their matching fields.', 'yoohw-vietnam-store-tools' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP supplies tmp_name as a server-side temporary path; it must remain an exact filesystem path for XML validation.
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

	private static function get_post_identity_field( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Admin action handlers verify their form nonce before calling this helper.
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified by the handler; v2 validation must inspect raw text before sanitization.
		return wp_unslash( $_POST[ $key ] );
	}

	private static function sanitize_data_value( $key, $value ) {
		if ( 'status' === $key ) {
			$value = sanitize_key( $value );

			return isset( self::get_statuses()[ $value ] )
				? $value
				: new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_status', __( 'Select a valid electronic invoice workflow status.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( in_array( $key, [ 'number', 'symbol', 'provider', 'provider_document_id', 'handoff_reference', 'provider_status_text' ], true ) ) {
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

		if ( in_array( $key, [ 'issued_at', 'handed_off_at', 'confirmed_at' ], true ) ) {
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
			'provider_document_id' => self::META_PROVIDER_DOCUMENT_ID,
			'handoff_reference' => self::META_HANDOFF_REFERENCE,
			'provider_status_text' => self::META_PROVIDER_STATUS_TEXT,
			'handed_off_at'     => self::META_HANDED_OFF_AT,
			'confirmed_at'      => self::META_CONFIRMED_AT,
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

	/**
	 * Read immutable invoice document snapshots, oldest first.
	 *
	 * @param WC_Order|int $order Order.
	 * @return array
	 */
	public static function get_order_documents( $order ) {
		$order = self::get_order( $order );
		if ( ! $order ) {
			return [];
		}
		$documents = $order->get_meta( self::META_DOCUMENTS, true );
		return is_array( $documents ) ? array_values( array_filter( $documents, 'is_array' ) ) : [];
	}

	/**
	 * Append a provider-neutral document and project it into the legacy fields.
	 *
	 * The caller must provide context.expected_revision and, for lineage,
	 * context.expected_current_document_id.
	 *
	 * @param WC_Order|int $order Order.
	 * @param array        $document Document fields and kind.
	 * @param array        $context Source, actor, note and expected values.
	 * @return true|WP_Error
	 */
	public static function record_order_document( $order, $document, $context = [] ) {
		$order = self::get_order( $order );
		if ( ! $order ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_invalid_order', __( 'Could not load order.', 'yoohw-vietnam-store-tools' ) );
		}
		$context = is_array( $context ) ? $context : [];
		$document = is_array( $document ) ? $document : [];
		$token = self::acquire_lock( $order->get_id() );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$context['lock_token'] = $token;
		try {
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh instanceof WC_Order || ! self::is_workflow_enabled() || ! self::order_has_invoice_request( $fresh ) ) {
				return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_not_requested', __( 'This order does not contain a VAT invoice request.', 'yoohw-vietnam-store-tools' ) );
			}
			$revision = absint( $fresh->get_meta( self::META_REVISION, true ) );
			$expected = isset( $context['expected_revision'] ) ? $context['expected_revision'] : null;
			if ( null === $expected || ! ctype_digit( (string) $expected ) || (int) $expected !== $revision ) {
				return self::stale_error();
			}
			$documents = self::get_order_documents( $fresh );
			$current_id = (string) $fresh->get_meta( self::META_CURRENT_DOCUMENT_ID, true );
			$kind = isset( $document['kind'] ) ? sanitize_key( $document['kind'] ) : '';
			if ( ! in_array( $kind, [ 'original', 'adjustment', 'replacement', 'legacy' ], true ) ) {
				return self::v2_error( 'kind', __( 'Choose an invoice document type.', 'yoohw-vietnam-store-tools' ) );
			}
			if ( 'legacy' === $kind ) {
				$current = self::get_order_data( $fresh );
				if ( $documents || '' !== $current_id || ! self::has_legacy_document_data( $current ) ) {
					return self::v2_error( 'legacy', __( 'There is no uncaptured legacy invoice document for this order.', 'yoohw-vietnam-store-tools' ) );
				}
				$legacy = self::document_snapshot( $fresh, $current, 'legacy', '', [ 'source' => 'legacy_unknown', 'actor_id' => 0 ] );
				if ( ! self::owns_lock( $fresh->get_id(), $token ) ) {
					return self::lock_error();
				}
				$fresh->update_meta_data( self::META_DOCUMENTS, [ $legacy ] );
				$fresh->update_meta_data( self::META_CURRENT_DOCUMENT_ID, $legacy['id'] );
				$context['advance_revision'] = true;
				$context['note'] = __( 'Existing invoice document captured without verified provenance.', 'yoohw-vietnam-store-tools' );
				$result = self::update_order_data_unlocked( $fresh, [], $context );
				if ( ! is_wp_error( $result ) ) {
					do_action( 'yoohw_vietnam_store_tools_einvoice_document_recorded', $fresh, $legacy );
				}
				return $result;
			}
			$prior_id = isset( $document['prior_document_id'] ) ? sanitize_text_field( $document['prior_document_id'] ) : '';
			if ( in_array( $kind, [ 'adjustment', 'replacement' ], true ) ) {
				$expected_id = isset( $context['expected_current_document_id'] ) ? sanitize_text_field( $context['expected_current_document_id'] ) : '';
				if ( $expected_id !== $current_id || '' === $prior_id || $prior_id !== $current_id ) {
					return self::stale_error();
				}
				$found = false;
				foreach ( $documents as $existing ) {
					if ( isset( $existing['id'] ) && $prior_id === $existing['id'] ) {
						$found = true;
						break;
					}
				}
				if ( ! $found && '' !== $current_id ) {
					return self::v2_error( 'prior', __( 'The prior invoice document could not be found.', 'yoohw-vietnam-store-tools' ) );
				}
			} elseif ( '' !== $prior_id || ( '' !== $current_id && ! ( 1 === count( $documents ) && isset( $documents[0]['kind'] ) && 'legacy' === $documents[0]['kind'] ) ) ) {
				return self::v2_error( 'original', __( 'An original document cannot replace the current invoice document.', 'yoohw-vietnam-store-tools' ) );
			}
			$current = self::get_order_data( $fresh );
			if ( ! $documents && self::has_legacy_document_data( $current ) ) {
				$legacy = self::document_snapshot( $fresh, $current, 'legacy', '', [ 'source' => 'legacy_unknown', 'actor_id' => 0 ] );
				$documents[] = $legacy;
				if ( in_array( $kind, [ 'adjustment', 'replacement' ], true ) && '' === $current_id ) {
					// The admin must first reload to bind the newly captured legacy document.
					return self::v2_error( 'prior', __( 'Record the existing invoice as a document before adding a correction or replacement.', 'yoohw-vietnam-store-tools' ) );
				}
			}
			if ( count( $documents ) >= self::MAX_DOCUMENTS ) {
				return self::v2_error( 'limit', __( 'The invoice document limit for this order has been reached. Existing documents remain available.', 'yoohw-vietnam-store-tools' ) );
			}
			foreach ( [ 'issued_at', 'handed_off_at', 'confirmed_at' ] as $date_key ) {
				if ( isset( $document[ $date_key ] ) && '' !== trim( (string) $document[ $date_key ] ) && ! self::is_strict_datetime( $document[ $date_key ] ) ) {
					return self::v2_error( 'date', __( 'Enter a valid invoice date and time.', 'yoohw-vietnam-store-tools' ) );
				}
			}
			$projection = [];
			$valid_raw = self::validate_v2_identity_fields( $document );
			if ( is_wp_error( $valid_raw ) ) {
				return $valid_raw;
			}
			foreach ( self::get_data_field_map() as $key => $meta_key ) {
				unset( $meta_key );
				if ( array_key_exists( $key, $document ) ) {
					$value = self::sanitize_data_value( $key, $document[ $key ] );
					if ( is_wp_error( $value ) ) {
						return $value;
					}
					$projection[ $key ] = $value;
				} elseif ( 'status' !== $key ) {
					$projection[ $key ] = in_array( $key, [ 'pdf_attachment_id', 'xml_attachment_id' ], true ) ? 0 : '';
				}
			}
			$projection['status'] = 'adjustment' === $kind ? 'adjusted' : ( 'replacement' === $kind ? 'replaced' : 'issued' );
			$valid = self::validate_v2_projection( $fresh, $projection, true );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$doc = self::document_snapshot( $fresh, $projection, $kind, $prior_id, $context );
			$documents[] = $doc;
			if ( ! self::owns_lock( $fresh->get_id(), $token ) ) {
				return self::lock_error();
			}
			$fresh->update_meta_data( self::META_DOCUMENTS, $documents );
			$fresh->update_meta_data( self::META_CURRENT_DOCUMENT_ID, $doc['id'] );
			$context['advance_revision'] = true;
			$context['note'] = isset( $context['note'] ) && '' !== trim( (string) $context['note'] ) ? $context['note'] : __( 'Invoice document recorded.', 'yoohw-vietnam-store-tools' );
			$result = self::update_order_data_unlocked( $fresh, $projection, $context );
			if ( ! is_wp_error( $result ) ) {
				do_action( 'yoohw_vietnam_store_tools_einvoice_document_recorded', $fresh, $doc );
			}
			return $result;
		} finally {
			self::release_lock( $order->get_id(), $token );
		}
	}

	private static function projection_differs_from_document( $order, $data ) {
		if ( '' === $data['current_document_id'] ) {
			return false;
		}
		foreach ( self::get_order_documents( $order ) as $document ) {
			if ( ! isset( $document['id'] ) || $document['id'] !== $data['current_document_id'] ) {
				continue;
			}
			foreach ( [ 'status', 'number', 'symbol', 'issued_at', 'lookup_url', 'pdf_attachment_id', 'xml_attachment_id', 'provider' ] as $key ) {
				if ( (string) $data[ $key ] !== (string) ( isset( $document[ $key ] ) ? $document[ $key ] : '' ) ) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	private static function has_unlinked_lineage( $order, $data ) {
		if ( ! in_array( $data['status'], [ 'adjusted', 'replaced' ], true ) ) {
			return false;
		}
		foreach ( self::get_order_documents( $order ) as $document ) {
			if ( isset( $document['id'], $document['kind'] ) && $document['id'] === $data['current_document_id'] && ( 'adjusted' === $data['status'] ? 'adjustment' : 'replacement' ) === $document['kind'] && ! self::projection_differs_from_document( $order, $data ) ) {
				return false;
			}
		}
		return true;
	}

	private static function has_legacy_document_data( $data ) {
		foreach ( [ 'provider', 'number', 'symbol', 'issued_at', 'lookup_url', 'pdf_attachment_id', 'xml_attachment_id' ] as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				return true;
			}
		}
		return in_array( $data['status'], [ 'issued', 'sent', 'adjusted', 'replaced' ], true );
	}

	private static function document_snapshot( $order, $data, $kind, $prior_id, $context ) {
		$snapshot = [
			'id' => wp_generate_uuid4(),
			'kind' => $kind,
			'prior_document_id' => $prior_id,
			'created_at' => gmdate( 'c' ),
			'actor_id' => isset( $context['actor_id'] ) ? absint( $context['actor_id'] ) : 0,
			'source' => isset( $context['source'] ) ? sanitize_key( $context['source'] ) : 'integration',
		];
		foreach ( self::get_data_field_map() as $key => $meta_key ) {
			unset( $meta_key );
			$snapshot[ $key ] = isset( $data[ $key ] ) ? $data[ $key ] : '';
		}
		foreach ( [ 'pdf', 'xml' ] as $type ) {
			$id = absint( $snapshot[ $type . '_attachment_id' ] );
			$snapshot[ $type . '_filename' ] = $id ? sanitize_file_name( self::get_attachment_display_value( $id ) ) : '';
			$snapshot[ $type . '_url' ] = $id ? esc_url_raw( (string) wp_get_attachment_url( $id ), [ 'http', 'https' ] ) : '';
		}
		return $snapshot;
	}

	private static function validate_v2_identity_fields( $data ) {
		foreach ( [ 'provider' => 120, 'number' => 80, 'symbol' => 80, 'provider_document_id' => 160, 'handoff_reference' => 160, 'provider_status_text' => 160 ] as $key => $limit ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value = $data[ $key ];
			if ( ! is_scalar( $value ) || strlen( (string) $value ) > $limit || preg_match( '/[\x00-\x1F\x7F]/', (string) $value ) ) {
				return self::v2_error( 'field', __( 'An invoice field is too long or contains control characters.', 'yoohw-vietnam-store-tools' ) );
			}
		}
		return true;
	}

	private static function validate_v2_projection( $order, $data, $require_document = false ) {
		$valid_raw = self::validate_v2_identity_fields( $data );
		if ( is_wp_error( $valid_raw ) ) {
			return $valid_raw;
		}
		$current = self::get_order_data( $order );
		$next = array_merge( $current, is_array( $data ) ? $data : [] );
		$status = isset( $next['status'] ) ? sanitize_key( $next['status'] ) : '';
		if ( ! isset( self::get_statuses()[ $status ] ) ) {
			return self::v2_error( 'status', __( 'Select a valid electronic invoice workflow status.', 'yoohw-vietnam-store-tools' ) );
		}
		if ( ( $require_document || $status !== $current['status'] ) && in_array( $status, [ 'verified', 'ready', 'issued', 'sent', 'adjusted', 'replaced' ], true ) && ! self::has_complete_invoice_request( $order ) ) {
			return self::v2_error( 'request', __( 'Complete the company name, tax code, address and invoice email before advancing the invoice workflow.', 'yoohw-vietnam-store-tools' ) );
		}
		$valid_identity = self::validate_v2_identity_fields( $next );
		if ( is_wp_error( $valid_identity ) ) {
			return $valid_identity;
		}
		$advancing = $require_document || $status !== $current['status'];
		if ( '' !== (string) $next['lookup_url'] && ( $advancing || ( isset( $data['lookup_url'] ) && (string) $data['lookup_url'] !== (string) $current['lookup_url'] ) ) ) {
			$url = trim( esc_url_raw( (string) $next['lookup_url'], [ 'http', 'https' ] ) );
			if ( $url !== (string) $next['lookup_url'] || ! wp_http_validate_url( $url ) ) {
				return self::v2_error( 'url', __( 'Enter a valid HTTP or HTTPS invoice lookup URL.', 'yoohw-vietnam-store-tools' ) );
			}
		}
		foreach ( [ 'issued_at', 'handed_off_at', 'confirmed_at' ] as $date_key ) {
			if ( '' !== (string) $next[ $date_key ] && ( $advancing || ( isset( $data[ $date_key ] ) && (string) $data[ $date_key ] !== (string) $current[ $date_key ] ) ) && ! self::is_strict_datetime( $next[ $date_key ] ) ) {
				return self::v2_error( 'date', __( 'Enter a valid invoice date and time.', 'yoohw-vietnam-store-tools' ) );
			}
		}
		if ( ( $require_document || $status !== $current['status'] ) && in_array( $status, [ 'issued', 'sent', 'adjusted', 'replaced' ], true ) ) {
			foreach ( [ 'provider', 'number', 'symbol', 'issued_at' ] as $key ) {
				if ( '' === trim( (string) $next[ $key ] ) ) {
					return self::v2_error( 'document', __( 'Provider, invoice number, symbol and issue time are required for a new invoice document.', 'yoohw-vietnam-store-tools' ) );
				}
			}
		}
		foreach ( [ 'pdf', 'xml' ] as $type ) {
			$key = $type . '_attachment_id';
			if ( ! empty( $next[ $key ] ) && array_key_exists( $key, $data ) && ( $require_document || (string) $next[ $key ] !== (string) $current[ $key ] ) ) {
				$valid = self::validate_v2_attachment( $order, $next[ $key ], $type );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
		}
		return true;
	}

	private static function is_strict_datetime( $raw ) {
		$valid = preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?(?:Z|([+-])(\d{2}):(\d{2}))?$/', (string) $raw, $parts );
		return $valid && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) && (int) $parts[4] <= 23 && (int) $parts[5] <= 59 && ( ! isset( $parts[6] ) || (int) $parts[6] <= 59 ) && ( ! isset( $parts[8] ) || (int) $parts[8] <= 23 ) && ( ! isset( $parts[9] ) || (int) $parts[9] <= 59 ) && ! is_wp_error( self::sanitize_datetime_value( $raw ) );
	}

	private static function has_complete_invoice_request( $order ) {
		$prefix = '_yoohw_vietnam_store_tools_tax_invoice_';
		$values = [];
		foreach ( [ 'company_name', 'tax_code', 'company_address', 'email' ] as $key ) {
			$value = $order->get_meta( $prefix . $key, true );
			$values[ $key ] = '' !== (string) $value ? trim( (string) $value ) : trim( (string) $order->get_meta( '_vck_tax_invoice_' . $key, true ) );
		}
		return '' !== $values['company_name'] && '' !== $values['company_address'] && (bool) preg_match( '/^[0-9]{10}(?:-[0-9]{3})?$/', $values['tax_code'] ) && (bool) is_email( $values['email'] );
	}

	private static function validate_v2_attachment( $order, $id, $type ) {
		$id = absint( $id );
		$path = $id ? get_attached_file( $id ) : '';
		$mime = $id ? get_post_mime_type( $id ) : '';
		$expected = 'pdf' === $type ? [ 'application/pdf' ] : [ 'application/xml', 'text/xml' ];
		if ( 'attachment' !== get_post_type( $id ) || (int) get_post_meta( $id, '_yoohw_vietnam_store_tools_einvoice_order_id', true ) !== $order->get_id() || ! in_array( $mime, $expected, true ) || ! $path || ! is_file( $path ) || ! is_readable( $path ) || strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== $type ) {
			return self::v2_error( 'attachment', __( 'The invoice file must belong to this order and be a readable PDF or XML attachment.', 'yoohw-vietnam-store-tools' ) );
		}
		return true;
	}

	private static function lock_name( $order_id ) {
		return '_yoohw_vst_einvoice_lock_' . absint( $order_id );
	}

	private static function acquire_lock( $order_id ) {
		global $wpdb;
		try {
			$token = bin2hex( random_bytes( 24 ) );
		} catch ( Exception $exception ) {
			return self::lock_error();
		}
		$name = self::lock_name( $order_id );
		$value = $token . '|' . ( time() + self::LEASE_SECONDS );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $value, 'off' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 1 === (int) $inserted ) {
			return $value;
		}
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$parts = explode( '|', (string) $old );
		if ( 2 === count( $parts ) && ctype_digit( $parts[1] ) && (int) $parts[1] < time() ) {
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( 1 === (int) $taken ) {
				return $value;
			}
		}
		return self::v2_error( 'locked', __( 'Another operator is editing this invoice. Reload and retry.', 'yoohw-vietnam-store-tools' ) );
	}

	private static function owns_lock( $order_id, $token ) {
		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::lock_name( $order_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$parts = explode( '|', (string) $stored );
		return hash_equals( (string) $token, (string) $stored ) && 2 === count( $parts ) && (int) $parts[1] >= time();
	}

	private static function release_lock( $order_id, $token ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::lock_name( $order_id ), $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private static function stale_error() {
		return self::v2_error( 'stale', __( 'This invoice changed since the page loaded. Reload and retry.', 'yoohw-vietnam-store-tools' ) );
	}

	private static function lock_error() {
		return self::v2_error( 'lock', __( 'The invoice edit lock expired. Reload and retry.', 'yoohw-vietnam-store-tools' ) );
	}

	private static function v2_error( $code, $message ) {
		return new WP_Error( 'yoohw_vietnam_store_tools_einvoice_' . $code, $message );
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
