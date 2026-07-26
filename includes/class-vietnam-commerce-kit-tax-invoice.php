<?php
/**
 * WooCommerce Vietnam tax invoice request fields.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Tax_Invoice {

	const OPTION_ID        = 'yoohw_vietnam_store_tools_allow_tax_invoice_request';
	const LEGACY_OPTION_ID = 'vietnam_' . 'commerce_kit_allow_tax_invoice_request';

	const FIELD_REQUESTED       = 'yoohw_vietnam_store_tools_tax_invoice_requested';
	const FIELD_COMPANY_NAME    = 'yoohw_vietnam_store_tools_tax_invoice_company_name';
	const FIELD_TAX_CODE        = 'yoohw_vietnam_store_tools_tax_invoice_tax_code';
	const FIELD_COMPANY_ADDRESS = 'yoohw_vietnam_store_tools_tax_invoice_company_address';
	const FIELD_EMAIL           = 'yoohw_vietnam_store_tools_tax_invoice_email';

	const META_REQUESTED       = '_yoohw_vietnam_store_tools_tax_invoice_requested';
	const META_COMPANY_NAME    = '_yoohw_vietnam_store_tools_tax_invoice_company_name';
	const META_TAX_CODE        = '_yoohw_vietnam_store_tools_tax_invoice_tax_code';
	const META_COMPANY_ADDRESS = '_yoohw_vietnam_store_tools_tax_invoice_company_address';
	const META_EMAIL           = '_yoohw_vietnam_store_tools_tax_invoice_email';

	const BLOCK_FIELD_REQUESTED       = 'yoohw-vietnam-store-tools/tax-invoice-requested';
	const BLOCK_FIELD_COMPANY_NAME    = 'yoohw-vietnam-store-tools/tax-invoice-company-name';
	const BLOCK_FIELD_TAX_CODE        = 'yoohw-vietnam-store-tools/tax-invoice-tax-code';
	const BLOCK_FIELD_COMPANY_ADDRESS = 'yoohw-vietnam-store-tools/tax-invoice-company-address';
	const BLOCK_FIELD_EMAIL           = 'yoohw-vietnam-store-tools/tax-invoice-email';

	private $rendered_checkout_fields = false;

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_migrate_tax_invoice_option' ] );
		add_action( 'woocommerce_init', [ $this, 'register_block_checkout_fields' ] );
		add_filter( 'woocommerce_tax_settings', [ $this, 'add_invoice_setting' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'woocommerce_after_checkout_registration_form', [ $this, 'render_checkout_fields' ], 20 );
		add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_checkout_fields_fallback' ], 50 );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout_fields' ], 20, 2 );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save_order_fields' ], 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'save_block_order_fields' ], 30, 2 );
		add_action( 'add_meta_boxes', [ $this, 'add_admin_order_metabox' ] );
		add_action( 'woocommerce_email_order_meta', [ $this, 'render_new_order_email_invoice_fields' ], 20, 4 );
	}

	/**
	 * Whether new VAT invoice requests are accepted at checkout.
	 *
	 * @return bool
	 */
	public static function accepts_new_requests() {
		return 'yes' === get_option( self::OPTION_ID, 'no' );
	}

	/**
	 * Backward-compatible alias for integrations checking this feature.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return self::accepts_new_requests();
	}

	public function register_block_checkout_fields() {
		if ( ! $this->should_enable_checkout_fields() || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		$requested_condition = $this->get_block_requested_condition();
		$hidden_condition    = [ 'not' => $requested_condition ];

		woocommerce_register_additional_checkout_field(
			[
				'id'                         => self::BLOCK_FIELD_REQUESTED,
				'label'                      => __( 'Request a VAT invoice', 'yoohw-vietnam-store-tools' ),
				'optionalLabel'              => __( 'Request a VAT invoice', 'yoohw-vietnam-store-tools' ),
				'location'                   => 'order',
				'type'                       => 'checkbox',
				'show_in_order_confirmation' => false,
			]
		);

		woocommerce_register_additional_checkout_field(
			[
				'id'                         => self::BLOCK_FIELD_COMPANY_NAME,
				'label'                      => __( 'Company legal name', 'yoohw-vietnam-store-tools' ),
				'optionalLabel'              => __( 'Company legal name', 'yoohw-vietnam-store-tools' ),
				'location'                   => 'order',
				'type'                       => 'text',
				'required'                   => $requested_condition,
				'hidden'                     => $hidden_condition,
				'attributes'                 => [
					'autocomplete' => 'organization',
					'maxLength'    => 200,
				],
				'sanitize_callback'          => [ $this, 'sanitize_block_text_field' ],
				'show_in_order_confirmation' => true,
			]
		);

		woocommerce_register_additional_checkout_field(
			[
				'id'                         => self::BLOCK_FIELD_TAX_CODE,
				'label'                      => __( 'Tax code', 'yoohw-vietnam-store-tools' ),
				'optionalLabel'              => __( 'Tax code', 'yoohw-vietnam-store-tools' ),
				'location'                   => 'order',
				'type'                       => 'text',
				'required'                   => $requested_condition,
				'hidden'                     => $hidden_condition,
				'attributes'                 => [
					'pattern'   => '[0-9]{10}(-[0-9]{3})?',
					'maxLength' => 14,
					'title'     => __( 'Enter 10 digits, optionally followed by a hyphen and 3 digits.', 'yoohw-vietnam-store-tools' ),
				],
				'sanitize_callback'          => [ $this, 'sanitize_block_tax_code' ],
				'validate_callback'          => [ $this, 'validate_block_tax_code' ],
				'show_in_order_confirmation' => true,
			]
		);

		woocommerce_register_additional_checkout_field(
			[
				'id'                         => self::BLOCK_FIELD_EMAIL,
				'label'                      => __( 'Invoice recipient email', 'yoohw-vietnam-store-tools' ),
				'optionalLabel'              => __( 'Invoice recipient email', 'yoohw-vietnam-store-tools' ),
				'location'                   => 'order',
				'type'                       => 'text',
				'required'                   => $requested_condition,
				'hidden'                     => $hidden_condition,
				'attributes'                 => [
					'autocomplete'   => 'email',
					'autocapitalize' => 'none',
					'maxLength'      => 254,
				],
				'sanitize_callback'          => [ $this, 'sanitize_block_email' ],
				'validate_callback'          => [ $this, 'validate_block_email' ],
				'show_in_order_confirmation' => true,
			]
		);

		woocommerce_register_additional_checkout_field(
			[
				'id'                         => self::BLOCK_FIELD_COMPANY_ADDRESS,
				'label'                      => __( 'Company address', 'yoohw-vietnam-store-tools' ),
				'optionalLabel'              => __( 'Company address', 'yoohw-vietnam-store-tools' ),
				'location'                   => 'order',
				'type'                       => 'text',
				'required'                   => $requested_condition,
				'hidden'                     => $hidden_condition,
				'attributes'                 => [
					'autocomplete' => 'street-address',
					'maxLength'    => 500,
				],
				'sanitize_callback'          => [ $this, 'sanitize_block_text_field' ],
				'show_in_order_confirmation' => true,
			]
		);
	}

	public function sanitize_block_text_field( $value, $field = [] ) {
		unset( $field );

		return trim( sanitize_text_field( wc_clean( $value ) ) );
	}

	public function sanitize_block_tax_code( $value, $field = [] ) {
		unset( $field );

		return preg_replace( '/\s+/', '', $this->sanitize_block_text_field( $value ) );
	}

	public function sanitize_block_email( $value, $field = [] ) {
		unset( $field );

		return sanitize_email( wc_clean( $value ) );
	}

	public function validate_block_tax_code( $value, $field = [] ) {
		if ( ! empty( $field['required'] ) && '' === (string) $value ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_tax_code_required', __( 'Please enter the tax code for the tax invoice.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( '' !== (string) $value && ! $this->is_valid_vietnam_tax_code( $value ) ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_invalid_tax_code', __( 'Please enter a valid Vietnamese tax code.', 'yoohw-vietnam-store-tools' ) );
		}

		return true;
	}

	public function validate_block_email( $value, $field = [] ) {
		if ( ! empty( $field['required'] ) && '' === (string) $value ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_invoice_email_required', __( 'Please enter the invoice recipient email.', 'yoohw-vietnam-store-tools' ) );
		}

		if ( '' !== (string) $value && ! is_email( $value ) ) {
			return new WP_Error( 'yoohw_vietnam_store_tools_invalid_invoice_email', __( 'Please enter a valid invoice recipient email.', 'yoohw-vietnam-store-tools' ) );
		}

		return true;
	}

	public function save_block_order_fields( $order, $request = null ) {
		unset( $request );

		if ( ! $order instanceof WC_Order || ! $this->should_enable_checkout_fields() ) {
			return;
		}

		$requested = true === $this->get_block_order_field_value( $order, self::BLOCK_FIELD_REQUESTED );

		if ( ! $requested ) {
			$order->update_meta_data( self::META_REQUESTED, 'no' );
			$this->delete_order_invoice_meta( $order, self::META_COMPANY_NAME );
			$this->delete_order_invoice_meta( $order, self::META_TAX_CODE );
			$this->delete_order_invoice_meta( $order, self::META_COMPANY_ADDRESS );
			$this->delete_order_invoice_meta( $order, self::META_EMAIL );
			return;
		}

		$order->update_meta_data( self::META_REQUESTED, 'yes' );
		$order->update_meta_data( self::META_COMPANY_NAME, $this->sanitize_block_text_field( $this->get_block_order_field_value( $order, self::BLOCK_FIELD_COMPANY_NAME ) ) );
		$order->update_meta_data( self::META_TAX_CODE, $this->sanitize_block_tax_code( $this->get_block_order_field_value( $order, self::BLOCK_FIELD_TAX_CODE ) ) );
		$order->update_meta_data( self::META_COMPANY_ADDRESS, $this->sanitize_block_text_field( $this->get_block_order_field_value( $order, self::BLOCK_FIELD_COMPANY_ADDRESS ) ) );
		$order->update_meta_data( self::META_EMAIL, $this->sanitize_block_email( $this->get_block_order_field_value( $order, self::BLOCK_FIELD_EMAIL ) ) );
	}

	public function maybe_migrate_tax_invoice_option() {
		if ( false !== get_option( self::OPTION_ID, false ) ) {
			return;
		}

		$legacy_value = get_option( self::LEGACY_OPTION_ID, false );

		if ( false !== $legacy_value ) {
			update_option( self::OPTION_ID, wc_clean( $legacy_value ), false );
		}
	}

	public function add_invoice_setting( $settings ) {
		$settings = is_array( $settings ) ? $settings : [];

		$settings[] = [
			'title' => __( 'Vietnam business invoices', 'yoohw-vietnam-store-tools' ),
			'desc'  => sprintf(
				/* translators: %s: URL to Vietnam store invoice settings. */
				wp_kses_post( __( 'Invoice requests and electronic invoice workflow are configured separately in <a href="%s">Vietnam store</a>.', 'yoohw-vietnam-store-tools' ) ),
				esc_url( admin_url( 'admin.php?page=' . Yoohw_Vietnam_Store_Tools_Admin_Menu::MENU_SLUG . '#yoohw-vietnam-store-features' ) )
			),
			'id'    => 'yoohw_vietnam_store_tools_invoice_settings_link',
			'type'  => 'title',
		];
		$settings[] = [
			'id'   => 'yoohw_vietnam_store_tools_invoice_settings_link',
			'type' => 'sectionend',
		];

		return $settings;
	}

	public function enqueue_scripts() {
		if ( ! $this->should_enable_checkout_fields() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Blocks_Integration' ) && Yoohw_Vietnam_Store_Tools_Blocks_Integration::is_current_block_page() ) {
			return;
		}

		$handle = 'yoohw-vietnam-store-tools-tax-invoice';

		wp_enqueue_script(
			$handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/frontend/tax-invoice.js',
			[ 'jquery' ],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION,
			true
		);

		$style_handle = $handle . '-style';

		wp_register_style( $style_handle, false, [], YOOHW_VIETNAM_STORE_TOOLS_VERSION );
		wp_enqueue_style( $style_handle );
		wp_add_inline_style(
			$style_handle,
			'.vck-tax-invoice-fields[hidden]{display:none!important;}.vck-tax-invoice-fields{margin:0 0 1.5em;}.vck-tax-invoice-fields h3{margin:1em 0 .65em;}.vck-tax-invoice-fields .form-row-wide{clear:both;}'
		);
	}

	public function render_checkout_fields( $checkout = null ) {
		if ( ! $this->should_enable_checkout_fields() || $this->rendered_checkout_fields ) {
			return;
		}

		$this->rendered_checkout_fields = true;
		$checkout                       = $this->get_checkout( $checkout );
		$requested                      = $this->is_invoice_requested();
		$hidden_attribute               = $requested ? '' : 'hidden';

		echo '<div class="vck-tax-invoice-request">';
		$this->render_request_checkbox( $requested );

		echo '<div class="vck-tax-invoice-fields' . ( $requested ? ' is-visible' : '' ) . '" data-vck-tax-invoice-fields' . ( '' !== $hidden_attribute ? ' ' . esc_attr( $hidden_attribute ) : '' ) . '>';
		echo '<h3>' . esc_html__( 'Tax invoice information', 'yoohw-vietnam-store-tools' ) . '</h3>';

		foreach ( $this->get_checkout_invoice_fields() as $key => $field ) {
			woocommerce_form_field( $key, $field, $this->get_checkout_field_value( $key, $checkout ) );
		}

		echo '</div>';
		echo '</div>';
	}

	public function render_checkout_fields_fallback( $checkout = null ) {
		if ( ! is_user_logged_in() && $checkout && is_callable( [ $checkout, 'is_registration_enabled' ] ) && $checkout->is_registration_enabled() ) {
			return;
		}

		$this->render_checkout_fields( $checkout );
	}

	public function validate_checkout_fields( $data, $errors ) {
		if ( ! $this->should_enable_checkout_fields() || ! Yoohw_Vietnam_Store_Tools_Request_Security::verify_checkout_request() || ! $this->is_invoice_requested() ) {
			return;
		}

		$company_name    = $this->get_posted_text_field( self::FIELD_COMPANY_NAME );
		$tax_code        = $this->get_posted_tax_code();
		$company_address = $this->get_posted_text_field( self::FIELD_COMPANY_ADDRESS );
		$email           = $this->get_posted_email_field( self::FIELD_EMAIL );

		if ( '' === $company_name ) {
			$errors->add(
				self::FIELD_COMPANY_NAME . '_required',
				__( 'Please enter the company legal name for the tax invoice.', 'yoohw-vietnam-store-tools' ),
				[ 'id' => self::FIELD_COMPANY_NAME ]
			);
		}

		if ( '' === $tax_code ) {
			$errors->add(
				self::FIELD_TAX_CODE . '_required',
				__( 'Please enter the tax code for the tax invoice.', 'yoohw-vietnam-store-tools' ),
				[ 'id' => self::FIELD_TAX_CODE ]
			);
		} elseif ( ! $this->is_valid_vietnam_tax_code( $tax_code ) ) {
			$errors->add(
				self::FIELD_TAX_CODE . '_invalid',
				__( 'Please enter a valid Vietnamese tax code.', 'yoohw-vietnam-store-tools' ),
				[ 'id' => self::FIELD_TAX_CODE ]
			);
		}

		if ( '' === $company_address ) {
			$errors->add(
				self::FIELD_COMPANY_ADDRESS . '_required',
				__( 'Please enter the company address for the tax invoice.', 'yoohw-vietnam-store-tools' ),
				[ 'id' => self::FIELD_COMPANY_ADDRESS ]
			);
		}

		if ( '' === $email ) {
			$errors->add(
				self::FIELD_EMAIL . '_required',
				__( 'Please enter the invoice recipient email.', 'yoohw-vietnam-store-tools' ),
				[ 'id' => self::FIELD_EMAIL ]
			);
		} elseif ( ! is_email( $email ) ) {
			$errors->add(
				self::FIELD_EMAIL . '_invalid',
				__( 'Please enter a valid invoice recipient email.', 'yoohw-vietnam-store-tools' ),
				[ 'id' => self::FIELD_EMAIL ]
			);
		}
	}

	public function save_order_fields( $order, $data ) {
		if ( ! $order instanceof WC_Order || ! $this->should_enable_checkout_fields() || ! Yoohw_Vietnam_Store_Tools_Request_Security::verify_checkout_request() ) {
			return;
		}

		if ( ! $this->is_invoice_requested() ) {
			$order->update_meta_data( self::META_REQUESTED, 'no' );
			$this->delete_order_invoice_meta( $order, self::META_COMPANY_NAME );
			$this->delete_order_invoice_meta( $order, self::META_TAX_CODE );
			$this->delete_order_invoice_meta( $order, self::META_COMPANY_ADDRESS );
			$this->delete_order_invoice_meta( $order, self::META_EMAIL );
			return;
		}

		$order->update_meta_data( self::META_REQUESTED, 'yes' );
		$order->update_meta_data( self::META_COMPANY_NAME, $this->get_posted_text_field( self::FIELD_COMPANY_NAME ) );
		$order->update_meta_data( self::META_TAX_CODE, $this->get_posted_tax_code() );
		$order->update_meta_data( self::META_COMPANY_ADDRESS, $this->get_posted_text_field( self::FIELD_COMPANY_ADDRESS ) );
		$order->update_meta_data( self::META_EMAIL, $this->get_posted_email_field( self::FIELD_EMAIL ) );
	}

	public function add_admin_order_metabox() {
		$order = $this->get_current_admin_order();

		if ( ! $this->order_has_tax_invoice_request( $order ) ) {
			return;
		}

		foreach ( $this->get_order_admin_screen_ids() as $screen_id ) {
			add_meta_box(
				'yoohw-vietnam-store-tools-tax-invoice',
				__( 'Tax invoice information', 'yoohw-vietnam-store-tools' ),
				[ $this, 'render_admin_order_metabox' ],
				$screen_id,
				'normal',
				'default'
			);
		}
	}

	public function render_admin_order_metabox( $post_or_order_object ) {
		$order = $this->get_admin_order_from_object( $post_or_order_object );

		if ( ! $this->order_has_tax_invoice_request( $order ) ) {
			return;
		}

		$fields = $this->get_order_tax_invoice_fields( $order );

		echo '<table class="widefat striped vck-admin-tax-invoice-request"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Requested', 'yoohw-vietnam-store-tools' ) . '</th><td>' . esc_html__( 'Yes', 'yoohw-vietnam-store-tools' ) . '</td></tr>';

		foreach ( $fields as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	public function render_new_order_email_invoice_fields( $order, $sent_to_admin, $plain_text = false, $email = null ) {
		if ( ! $sent_to_admin || ! $order instanceof WC_Order || ! $this->is_new_order_email( $email ) || ! $this->order_has_tax_invoice_request( $order ) ) {
			return;
		}

		if ( $plain_text ) {
			$this->render_plain_text_email_invoice_fields( $order );
			return;
		}

		$fields = $this->get_order_tax_invoice_fields( $order );

		echo '<h2 style="font-size:22px; line-height:1.3; margin:0 0 16px; padding:0; color:#111111; font-weight:700;">' . esc_html__( 'Tax invoice information', 'yoohw-vietnam-store-tools' ) . '</h2>';
		echo '<table class="vck-email-tax-invoice-request" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; margin:0 0 32px;"><tbody>';

		foreach ( $fields as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}

			echo '<tr>';
			echo '<th scope="row" style="padding:10px 16px 10px 0; text-align:left; width:34%; vertical-align:top; font-size:14px; line-height:20px; font-weight:600; color:#6f6f6f; border:0;">' . esc_html( $label ) . '</th>';
			echo '<td style="padding:10px 0; text-align:left; vertical-align:top; font-size:15px; line-height:22px; font-weight:400; color:#333333; border:0;">' . nl2br( esc_html( $value ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_request_checkbox( $requested ) {
		echo '<p class="form-row form-row-wide vck-tax-invoice-checkbox" id="' . esc_attr( self::FIELD_REQUESTED ) . '_field">';
		echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD_REQUESTED ) . '" value="0" />';
		echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox vck-tax-invoice-toggle" name="' . esc_attr( self::FIELD_REQUESTED ) . '" id="' . esc_attr( self::FIELD_REQUESTED ) . '" value="1" ' . checked( $requested, true, false ) . ' /> ';
		echo '<span>' . esc_html__( 'Request a VAT invoice', 'yoohw-vietnam-store-tools' ) . '</span>';
		echo '</label>';
		echo '</p>';
	}

	private function get_checkout_invoice_fields() {
		return [
			self::FIELD_COMPANY_NAME => [
				'type'         => 'text',
				'label'        => __( 'Company legal name', 'yoohw-vietnam-store-tools' ),
				'required'     => true,
				'class'        => [ 'form-row-wide' ],
				'autocomplete' => 'organization',
			],
			self::FIELD_TAX_CODE => [
				'type'              => 'text',
				'label'             => __( 'Tax code', 'yoohw-vietnam-store-tools' ),
				'required'          => true,
				'class'             => [ 'form-row-first' ],
				'custom_attributes' => [
					'inputmode' => 'numeric',
					'pattern'   => '[0-9]{10}(-[0-9]{3})?',
				],
			],
			self::FIELD_EMAIL => [
				'type'         => 'email',
				'label'        => __( 'Invoice recipient email', 'yoohw-vietnam-store-tools' ),
				'required'     => true,
				'class'        => [ 'form-row-last' ],
				'validate'     => [ 'email' ],
				'autocomplete' => 'email',
			],
			self::FIELD_COMPANY_ADDRESS => [
				'type'         => 'textarea',
				'label'        => __( 'Company address', 'yoohw-vietnam-store-tools' ),
				'required'     => true,
				'class'        => [ 'form-row-wide' ],
				'autocomplete' => 'street-address',
			],
		];
	}

	private function render_plain_text_email_invoice_fields( $order ) {
		echo "\n" . esc_html__( 'Tax invoice information', 'yoohw-vietnam-store-tools' ) . "\n";

		foreach ( $this->get_order_tax_invoice_fields( $order ) as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$value = preg_replace( '/\s+/', ' ', (string) $value );
			echo esc_html( $label ) . ': ' . esc_html( trim( $value ) ) . "\n";
		}
	}

	private function should_enable_checkout_fields() {
		return self::is_enabled();
	}

	private function get_block_requested_condition() {
		return [
			'type'       => 'object',
			'properties' => [
				'checkout' => [
					'type'       => 'object',
					'properties' => [
						'additional_fields' => [
							'type'       => 'object',
							'properties' => [
								self::BLOCK_FIELD_REQUESTED => [
									'const' => true,
								],
							],
							'required'   => [ self::BLOCK_FIELD_REQUESTED ],
						],
					],
					'required'   => [ 'additional_fields' ],
				],
			],
			'required'   => [ 'checkout' ],
		];
	}

	private function get_block_order_field_value( $order, $field_id ) {
		$package_class         = '\\Automattic\\WooCommerce\\Blocks\\Package';
		$checkout_fields_class = '\\Automattic\\WooCommerce\\Blocks\\Domain\\Services\\CheckoutFields';

		if ( class_exists( $package_class ) && class_exists( $checkout_fields_class ) ) {
			try {
				$checkout_fields = $package_class::container()->get( $checkout_fields_class );

				if ( is_object( $checkout_fields ) && is_callable( [ $checkout_fields, 'get_field_from_object' ] ) ) {
					return $checkout_fields->get_field_from_object( $field_id, $order, 'other' );
				}
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}

		$value = $order->get_meta( '_wc_other/' . $field_id, true );

		if ( self::BLOCK_FIELD_REQUESTED === $field_id ) {
			return in_array( $value, [ true, 1, '1', 'yes' ], true );
		}

		return $value;
	}

	private function get_checkout( $checkout ) {
		if ( $checkout ) {
			return $checkout;
		}

		if ( function_exists( 'WC' ) && WC()->checkout() ) {
			return WC()->checkout();
		}

		return null;
	}

	private function get_checkout_field_value( $key, $checkout ) {
		if ( Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $key ) ) {
			$value = self::FIELD_COMPANY_ADDRESS === $key ? Yoohw_Vietnam_Store_Tools_Request_Security::get_post_textarea( $key ) : Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $key );
			return $this->sanitize_field_value( $key, $value );
		}

		if ( self::FIELD_EMAIL === $key && $checkout && is_callable( [ $checkout, 'get_value' ] ) ) {
			$email = $checkout->get_value( 'billing_email' );

			if ( '' !== $email ) {
				return sanitize_email( $email );
			}
		}

		return '';
	}

	private function is_invoice_requested() {
		return '1' === Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( self::FIELD_REQUESTED );
	}

	private function get_posted_text_field( $key ) {
		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $key ) ) {
			return '';
		}

		$value = self::FIELD_COMPANY_ADDRESS === $key ? Yoohw_Vietnam_Store_Tools_Request_Security::get_post_textarea( $key ) : Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $key );

		return $this->sanitize_field_value( $key, $value );
	}

	private function get_posted_tax_code() {
		$value = $this->get_posted_text_field( self::FIELD_TAX_CODE );

		return preg_replace( '/\s+/', '', $value );
	}

	private function get_posted_email_field( $key ) {
		if ( ! Yoohw_Vietnam_Store_Tools_Request_Security::has_post_key( $key ) ) {
			return '';
		}

		return sanitize_email( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( $key ) );
	}

	private function sanitize_field_value( $key, $value ) {
		$value = wc_clean( $value );

		if ( self::FIELD_COMPANY_ADDRESS === $key ) {
			return trim( sanitize_textarea_field( $value ) );
		}

		return trim( sanitize_text_field( $value ) );
	}

	private function is_valid_vietnam_tax_code( $tax_code ) {
		return 1 === preg_match( '/^\d{10}(?:-\d{3})?$/', $tax_code );
	}

	private function get_order_admin_screen_ids() {
		$screen_ids = [ 'shop_order' ];

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $screen_ids ) ) );
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

		if ( is_numeric( $post_or_order_object ) ) {
			return wc_get_order( absint( $post_or_order_object ) );
		}

		return false;
	}

	private function order_has_tax_invoice_request( $order ) {
		return $order instanceof WC_Order && 'yes' === $this->get_order_invoice_meta( $order, self::META_REQUESTED );
	}

	private function is_new_order_email( $email ) {
		return is_object( $email ) && isset( $email->id ) && 'new_order' === $email->id;
	}

	private function get_order_tax_invoice_fields( $order ) {
		return [
			__( 'Company legal name', 'yoohw-vietnam-store-tools' )      => $this->get_order_invoice_meta( $order, self::META_COMPANY_NAME ),
			__( 'Tax code', 'yoohw-vietnam-store-tools' )                => $this->get_order_invoice_meta( $order, self::META_TAX_CODE ),
			__( 'Invoice recipient email', 'yoohw-vietnam-store-tools' ) => $this->get_order_invoice_meta( $order, self::META_EMAIL ),
			__( 'Company address', 'yoohw-vietnam-store-tools' )         => $this->get_order_invoice_meta( $order, self::META_COMPANY_ADDRESS ),
		];
	}

	private function get_order_invoice_meta( $order, $meta_key ) {
		$value = $order->get_meta( $meta_key, true );

		if ( '' !== (string) $value ) {
			return $value;
		}

		$legacy_key = $this->get_legacy_tax_invoice_meta_key( $meta_key );

		return '' !== $legacy_key ? $order->get_meta( $legacy_key, true ) : '';
	}

	private function delete_order_invoice_meta( $order, $meta_key ) {
		$order->delete_meta_data( $meta_key );

		$legacy_key = $this->get_legacy_tax_invoice_meta_key( $meta_key );

		if ( '' !== $legacy_key ) {
			$order->delete_meta_data( $legacy_key );
		}
	}

	private function get_legacy_tax_invoice_meta_key( $meta_key ) {
		$current_prefix = '_yoohw_vietnam_store_tools_tax_invoice_';
		$legacy_prefix  = '_' . 'v' . 'ck_tax_invoice_';

		if ( 0 !== strpos( $meta_key, $current_prefix ) ) {
			return '';
		}

		return $legacy_prefix . substr( $meta_key, strlen( $current_prefix ) );
	}
}
