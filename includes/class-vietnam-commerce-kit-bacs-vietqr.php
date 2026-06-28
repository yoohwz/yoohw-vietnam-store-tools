<?php
/**
 * VietQR enhancements for WooCommerce direct bank transfer.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_BACS_VietQR {

	const COUNTRY_CODE = 'VN';
	const GATEWAY_ID   = 'bacs';

	const SETTING_ENABLED          = 'yoohw_vietnam_store_tools_vietqr_enabled';
	const SETTING_TRANSFER_CONTENT = 'yoohw_vietnam_store_tools_vietqr_transfer_content';
	const SETTING_INCLUDE_AMOUNT   = 'yoohw_vietnam_store_tools_vietqr_include_amount';
	const SETTING_IMAGE_TEMPLATE   = 'yoohw_vietnam_store_tools_vietqr_image_template';
	const SETTING_SHOW_EMAIL       = 'yoohw_vietnam_store_tools_vietqr_show_email';
	const SETTING_TITLE            = 'yoohw_vietnam_store_tools_vietqr_title';

	public function __construct() {
		add_filter( 'woocommerce_get_bacs_locale', [ $this, 'add_vietnam_bacs_locale' ] );
		add_filter( 'woocommerce_settings_api_form_fields_' . self::GATEWAY_ID, [ $this, 'add_bacs_vietqr_settings' ] );
		add_filter( 'woocommerce_bacs_accounts', [ $this, 'hide_default_bacs_accounts_when_vietqr_renders' ], 10, 2 );
		add_action( 'init', [ $this, 'maybe_migrate_vietqr_settings' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'wp_ajax_yoohw_vietnam_store_tools_save_bacs_vietqr_settings', [ $this, 'ajax_save_bacs_vietqr_settings' ] );
		add_action( 'woocommerce_thankyou_' . self::GATEWAY_ID, [ $this, 'render_thankyou_vietqr' ], 20 );
		add_action( 'woocommerce_view_order', [ $this, 'render_view_order_vietqr' ], 9 );
		add_action( 'woocommerce_email_before_order_table', [ $this, 'render_email_vietqr' ], 20, 3 );
		add_action( 'add_meta_boxes', [ $this, 'add_admin_order_metabox' ] );
	}

	public function add_vietnam_bacs_locale( $locale ) {
		$locale[ self::COUNTRY_CODE ]['sortcode']['label'] = __( 'Bank BIN', 'yoohw-vietnam-store-tools' );

		return $locale;
	}

	public function add_bacs_vietqr_settings( $fields ) {
		if ( isset( $fields[ self::SETTING_ENABLED ] ) ) {
			return $fields;
		}

		$vietqr_fields = [
			self::SETTING_TITLE => [
				'title'       => __( 'VietQR', 'yoohw-vietnam-store-tools' ),
				'type'        => 'title',
				'description' => __( 'Choose a bank in each bank transfer account to generate VietQR payment QR codes for Vietnamese bank transfers. Existing legacy Bank BIN and Sort code values are still supported.', 'yoohw-vietnam-store-tools' ),
				'default'     => '',
			],
			self::SETTING_ENABLED => [
				'title'   => __( 'VietQR payment QR', 'yoohw-vietnam-store-tools' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show VietQR code for direct bank transfer orders', 'yoohw-vietnam-store-tools' ),
				'default' => 'no',
			],
			self::SETTING_TRANSFER_CONTENT => [
				'title'       => __( 'Transfer content template', 'yoohw-vietnam-store-tools' ),
				'type'        => 'safe_text',
				'description' => __( 'Available placeholders: {order_id}, {order_number}, {site_name}.', 'yoohw-vietnam-store-tools' ),
				'default'     => 'ORDER-{order_number}',
				'desc_tip'    => true,
			],
			self::SETTING_INCLUDE_AMOUNT => [
				'title'   => __( 'Amount', 'yoohw-vietnam-store-tools' ),
				'type'    => 'checkbox',
				'label'   => __( 'Include the order amount in the QR code when the order currency is VND', 'yoohw-vietnam-store-tools' ),
				'default' => 'yes',
			],
			self::SETTING_IMAGE_TEMPLATE => [
				'title'   => __( 'QR template', 'yoohw-vietnam-store-tools' ),
				'type'    => 'select',
				'default' => 'qr_only',
				'options' => [
					'compact2' => __( 'Compact with VietQR logo', 'yoohw-vietnam-store-tools' ),
					'compact'  => __( 'Compact', 'yoohw-vietnam-store-tools' ),
					'qr_only'  => __( 'QR only', 'yoohw-vietnam-store-tools' ),
				],
			],
			self::SETTING_SHOW_EMAIL => [
				'title'   => __( 'Email display', 'yoohw-vietnam-store-tools' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show VietQR code in customer bank transfer emails', 'yoohw-vietnam-store-tools' ),
				'default' => 'yes',
			],
		];

		if ( isset( $fields['account_details'] ) ) {
			$reordered = [];

			foreach ( $fields as $key => $field ) {
				$reordered[ $key ] = $field;

				if ( 'account_details' === $key ) {
					$reordered = array_merge( $reordered, $vietqr_fields );
				}
			}

			return $reordered;
		}

		return array_merge( $fields, $vietqr_fields );
	}

	public function maybe_migrate_vietqr_settings() {
		$migration_option = 'yoohw_vietnam_store_tools_vietqr_settings_migrated';

		if ( 'yes' === get_option( $migration_option, 'no' ) ) {
			return;
		}

		$settings = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		foreach ( $this->get_vietqr_setting_keys() as $setting_key ) {
			$legacy_key = $this->get_legacy_vietqr_setting_key( $setting_key );

			if ( '' !== $legacy_key && ! isset( $settings[ $setting_key ] ) && isset( $settings[ $legacy_key ] ) ) {
				$settings[ $setting_key ] = $settings[ $legacy_key ];
			}
		}

		if ( empty( $settings[ self::SETTING_IMAGE_TEMPLATE ] ) || 'compact2' === $settings[ self::SETTING_IMAGE_TEMPLATE ] ) {
			$settings[ self::SETTING_IMAGE_TEMPLATE ] = 'qr_only';
		}

		update_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', $settings );
		update_option( $migration_option, 'yes' );
	}

	public function hide_default_bacs_accounts_when_vietqr_renders( $accounts, $order_id ) {
		$hide_on_frontend = $this->is_frontend_order_screen();
		$hide_in_email    = doing_action( 'woocommerce_email_before_order_table' ) && 'yes' === $this->get_setting( self::SETTING_SHOW_EMAIL, 'yes' );

		if ( ! $hide_on_frontend && ! $hide_in_email ) {
			return $accounts;
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( empty( $this->get_vietqr_payment_accounts( $order ) ) ) {
			return $accounts;
		}

		return [];
	}

	public function hide_default_bacs_accounts_on_frontend( $accounts, $order_id ) {
		return $this->hide_default_bacs_accounts_when_vietqr_renders( $accounts, $order_id );
	}

	public function enqueue_admin_scripts( $hook_suffix ) {
		if ( $this->is_admin_order_screen() ) {
			$this->enqueue_copy_assets( true );
		}

		if ( ! $this->is_bacs_settings_admin_screen() ) {
			return;
		}

		$handle = 'yoohw-vietnam-store-tools-bacs-vietqr-admin';

		wp_enqueue_script(
			$handle,
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/bacs-vietqr.js',
			[],
			filemtime( YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/js/admin/bacs-vietqr.js' ),
			true
		);

		wp_localize_script(
			$handle,
			'yoohwVietnamStoreToolsBacsVietqr',
			[
				'country'           => self::COUNTRY_CODE,
				'banks'             => $this->get_vietqr_banks_for_script(),
				'accounts'          => $this->get_admin_bank_accounts_for_script(),
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'yoohw_vietnam_store_tools_bacs_vietqr_settings' ),
				'isWcAdminSettings' => $this->is_wc_admin_payments_screen(),
				'isBacsSettings'    => $this->is_wc_admin_bacs_settings_screen(),
				'settings'          => [
					'transferContent' => $this->get_setting( self::SETTING_TRANSFER_CONTENT, 'ORDER-{order_number}' ),
				],
				'i18n'              => [
					'bankBinLabel'              => __( 'Bank BIN', 'yoohw-vietnam-store-tools' ),
					'bankBinHelp'               => __( 'Enter the VietQR/NAPAS bank BIN for this Vietnam bank account, for example 970436.', 'yoohw-vietnam-store-tools' ),
					'bankBinRequired'           => __( 'Bank BIN is required to generate VietQR codes.', 'yoohw-vietnam-store-tools' ),
					'bankSelectHelp'            => __( 'Choose the receiving bank. The VietQR bank BIN will be filled automatically.', 'yoohw-vietnam-store-tools' ),
					'bankSelectLabel'           => __( 'Bank', 'yoohw-vietnam-store-tools' ),
					'bankSelectRequired'        => __( 'Please select a bank to generate VietQR codes.', 'yoohw-vietnam-store-tools' ),
					'countryLockedNote'         => __( 'Vietnam Store Toolkit for WooCommerce uses Vietnam bank transfer accounts for VietQR.', 'yoohw-vietnam-store-tools' ),
					'countryLabel'              => __( 'Country', 'yoohw-vietnam-store-tools' ),
					'vietnamCountryName'        => __( 'Vietnam', 'yoohw-vietnam-store-tools' ),
					'selectBank'                => __( 'Select a bank', 'yoohw-vietnam-store-tools' ),
					'transferTemplateLabel'     => __( 'Transfer content template', 'yoohw-vietnam-store-tools' ),
					'transferTemplateHelp'      => __( 'Available placeholders: {order_id}, {order_number}, {site_name}.', 'yoohw-vietnam-store-tools' ),
					'transferTemplateSaveError' => __( 'Could not save the VietQR transfer content template.', 'yoohw-vietnam-store-tools' ),
					'accountNameLabel'          => __( 'Account Name', 'yoohw-vietnam-store-tools' ),
					'accountNumberLabel'        => __( 'Account Number', 'yoohw-vietnam-store-tools' ),
					'bankNameLabel'             => __( 'Bank Name', 'yoohw-vietnam-store-tools' ),
					'bicSwiftLabel'             => __( 'BIC / SWIFT', 'yoohw-vietnam-store-tools' ),
					'ibanLabel'                 => __( 'IBAN', 'yoohw-vietnam-store-tools' ),
				],
			]
		);
	}

	public function enqueue_frontend_assets() {
		if ( 'yes' !== $this->get_setting( self::SETTING_ENABLED, 'no' ) || ! $this->is_frontend_order_screen() ) {
			return;
		}

		$this->enqueue_copy_assets();
	}

	public function ajax_save_bacs_vietqr_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'You do not have permission to manage WooCommerce settings.', 'yoohw-vietnam-store-tools' ),
				],
				403
			);
		}

		check_ajax_referer( 'yoohw_vietnam_store_tools_bacs_vietqr_settings', 'nonce' );

		$transfer_content = Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'transfer_content' );
		$transfer_content = $this->sanitize_transfer_content_template( $transfer_content );
		$settings         = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', [] );
		$settings         = is_array( $settings ) ? $settings : [];

		$settings[ self::SETTING_TRANSFER_CONTENT ] = $transfer_content;

		update_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', $settings );

		wp_send_json_success(
			[
				'transferContent' => $transfer_content,
			]
		);
	}

	public function render_thankyou_vietqr( $order_id ) {
		$order = wc_get_order( $order_id );

		$this->render_vietqr_payment_details( $order, 'frontend' );
	}

	public function render_view_order_vietqr( $order_id ) {
		$order = wc_get_order( $order_id );

		$this->render_vietqr_payment_details( $order, 'frontend' );
	}

	public function render_email_vietqr( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || 'yes' !== $this->get_setting( self::SETTING_SHOW_EMAIL, 'yes' ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is a WooCommerce core extension point.
		$instructions_order_status = apply_filters( 'woocommerce_bacs_email_instructions_order_status', 'on-hold', $order );

		if ( ! $order->has_status( $instructions_order_status ) ) {
			return;
		}

		if ( $plain_text ) {
			$this->render_plain_text_vietqr_payment_details( $order );
			return;
		}

		$this->render_vietqr_payment_details( $order, 'email' );
	}

	public function add_admin_order_metabox() {
		$order = $this->get_current_admin_order();

		if ( empty( $this->get_vietqr_payment_accounts( $order ) ) ) {
			return;
		}

		foreach ( $this->get_order_admin_screen_ids() as $screen_id ) {
			add_meta_box(
				'yoohw-vietnam-store-tools-bacs-vietqr',
				__( 'VietQR bank transfer', 'yoohw-vietnam-store-tools' ),
				[ $this, 'render_admin_order_metabox' ],
				$screen_id,
				'side',
				'default'
			);
		}
	}

	public function render_admin_order_metabox( $post_or_order_object ) {
		$order = $this->get_admin_order_from_object( $post_or_order_object );

		$this->render_vietqr_payment_details( $order, 'admin' );
	}

	private function render_vietqr_payment_details( $order, $context ) {
		$accounts = $this->get_vietqr_payment_accounts( $order );

		if ( empty( $accounts ) ) {
			return;
		}

		$is_email = 'email' === $context;
		$is_admin = 'admin' === $context;

			$section_style = $is_email ? 'margin:0 0 28px;' : '';

			echo '<section class="vck-vietqr-payment vck-vietqr-payment--' . esc_attr( $context ) . '" aria-label="' . esc_attr__( 'VietQR bank transfer', 'yoohw-vietnam-store-tools' ) . '"' . ( '' !== $section_style ? ' style="' . esc_attr( $section_style ) . '"' : '' ) . '>';

		if ( $is_email ) {
			echo '<h2 style="font-size:22px; line-height:1.3; margin:0 0 16px; padding:0; color:#111111; font-weight:700;">' . esc_html__( 'Pay by bank transfer', 'yoohw-vietnam-store-tools' ) . '</h2>';
		}

		foreach ( $accounts as $account ) {
			if ( $is_email ) {
				$this->render_email_account_payment_details( $account );
				continue;
			}

			echo '<div class="vck-vietqr-payment__account">';
			echo '<div class="vck-vietqr-payment__qr"><img src="' . esc_url( $account['qr_url'] ) . '" alt="' . esc_attr__( 'VietQR payment QR code', 'yoohw-vietnam-store-tools' ) . '" width="' . ( $is_admin ? '180' : '280' ) . '" /></div>';
			echo '<table class="shop_table shop_table_responsive vck-vietqr-payment__details"><tbody>';
				$this->render_detail_row( __( 'Bank', 'yoohw-vietnam-store-tools' ), $account['bank_name'], $is_email );
				$this->render_detail_row( __( 'Account number', 'yoohw-vietnam-store-tools' ), $account['account_number'], $is_email, __( 'Copy account number', 'yoohw-vietnam-store-tools' ) );
				$this->render_detail_row( __( 'Account name', 'yoohw-vietnam-store-tools' ), $account['account_name'], $is_email );
			$this->render_detail_row( __( 'Amount', 'yoohw-vietnam-store-tools' ), $account['amount_display'], $is_email );
			$this->render_detail_row( __( 'Transfer content', 'yoohw-vietnam-store-tools' ), $account['transfer_content'], $is_email, __( 'Copy transfer content', 'yoohw-vietnam-store-tools' ) );
			echo '</tbody></table>';

			echo '</div>';
		}

		echo '</section>';
	}

	private function render_email_account_payment_details( $account ) {
		echo '<table class="vck-vietqr-payment__email-account" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse; margin:0 0 18px;"><tbody><tr>';
		echo '<td width="174" style="width:174px; padding:0 22px 0 0; vertical-align:top;">';
		echo '<img src="' . esc_url( $account['qr_url'] ) . '" alt="' . esc_attr__( 'VietQR payment QR code', 'yoohw-vietnam-store-tools' ) . '" width="152" style="display:block; width:152px; max-width:152px; height:auto; border:0;" />';
		echo '</td>';
		echo '<td style="vertical-align:top;">';
		echo '<table class="vck-vietqr-payment__email-details" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse;"><tbody>';
		$this->render_email_detail_row( __( 'Amount', 'yoohw-vietnam-store-tools' ), $account['amount_display'] );
		$this->render_email_detail_row( __( 'Transfer note', 'yoohw-vietnam-store-tools' ), $account['transfer_content'] );
		$this->render_email_detail_row( __( 'Account number', 'yoohw-vietnam-store-tools' ), $account['account_number'] );
		$this->render_email_detail_row( __( 'Bank', 'yoohw-vietnam-store-tools' ), $account['bank_name'] );
		$this->render_email_detail_row( __( 'Account holder', 'yoohw-vietnam-store-tools' ), $account['account_name'] );
		echo '</tbody></table>';
		echo '</td>';
		echo '</tr></tbody></table>';
	}

	private function render_email_detail_row( $label, $value ) {
		if ( '' === $value ) {
			return;
		}

		echo '<tr>';
		echo '<th scope="row" style="padding:0 14px 8px 0; text-align:left; vertical-align:top; width:38%; font-size:14px; line-height:20px; font-weight:500; color:#6f6f6f;">' . esc_html( $label ) . '</th>';
		echo '<td style="padding:0 0 8px; text-align:left; vertical-align:top; font-size:15px; line-height:20px; font-weight:600; color:#333333;">' . esc_html( $value ) . '</td>';
		echo '</tr>';
	}

	private function render_plain_text_vietqr_payment_details( $order ) {
		$accounts = $this->get_vietqr_payment_accounts( $order );

		if ( empty( $accounts ) ) {
			return;
		}

		echo "\n" . esc_html__( 'Pay by bank transfer', 'yoohw-vietnam-store-tools' ) . "\n\n";

		foreach ( $accounts as $account ) {
			echo esc_html__( 'Amount', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $account['amount_display'] ) . "\n";
			echo esc_html__( 'Transfer content', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $account['transfer_content'] ) . "\n";
			echo esc_html__( 'Account number', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $account['account_number'] ) . "\n";
			echo esc_html__( 'Bank', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $account['bank_name'] ) . "\n";
			echo esc_html__( 'Account name', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $account['account_name'] ) . "\n";
			echo esc_html__( 'QR code', 'yoohw-vietnam-store-tools' ) . ': ' . esc_url( $account['qr_url'] ) . "\n\n";
		}
	}

	private function render_detail_row( $label, $value, $is_email, $copy_label = '' ) {
		if ( '' === $value ) {
			return;
		}

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><span class="vck-vietqr-payment__copyable"><strong>' . esc_html( $value ) . '</strong>';

		if ( ! $is_email && '' !== $copy_label ) {
			$this->render_copy_button( $copy_label, $value );
		}

		echo '</span></td></tr>';
	}

	private function render_copy_button( $label, $value ) {
		echo '<button type="button" class="vck-vietqr-copy" data-vck-copy="' . esc_attr( $value ) . '" data-vck-copy-label="' . esc_attr( $label ) . '" data-vck-copied-label="' . esc_attr__( 'Copied', 'yoohw-vietnam-store-tools' ) . '" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">';
		echo '</button>';
	}

	private function get_vietqr_payment_accounts( $order ) {
		if ( ! $order instanceof WC_Order || self::GATEWAY_ID !== $order->get_payment_method() || 'yes' !== $this->get_setting( self::SETTING_ENABLED, 'no' ) ) {
			return [];
		}

		$accounts = get_option( 'woocommerce_bacs_accounts', [] );
		$accounts = is_array( $accounts ) ? $accounts : [];
		$payment_accounts = [];

		foreach ( $accounts as $account ) {
			$account = wp_parse_args(
				$account,
				[
					'account_name'   => '',
					'account_number' => '',
					'bank_name'      => '',
					'sort_code'      => '',
					'bic'            => '',
				]
			);

			$bank_bin       = $this->get_account_bank_bin( $account );
			$account_number = $this->sanitize_account_number( $account['account_number'] );

			if ( '' === $bank_bin || '' === $account_number ) {
				continue;
			}

			$amount = $this->get_order_vietqr_amount( $order );
			$transfer_content = $this->get_order_transfer_content( $order );

			$payment_accounts[] = [
				'bank_bin'         => $bank_bin,
				'account_number'   => $account_number,
				'account_name'     => wc_clean( wp_unslash( $account['account_name'] ) ),
				'bank_name'        => wc_clean( wp_unslash( $account['bank_name'] ) ),
				'amount'           => $amount,
				'amount_display'   => '' !== $amount ? wp_strip_all_tags( wc_price( (float) $amount, [ 'currency' => $order->get_currency() ] ) ) : __( 'Not included in QR code', 'yoohw-vietnam-store-tools' ),
				'transfer_content' => $transfer_content,
				'qr_url'           => $this->get_vietqr_image_url( $bank_bin, $account_number, $account['account_name'], $amount, $transfer_content ),
			];
		}

		return $payment_accounts;
	}

	private function get_vietqr_image_url( $bank_bin, $account_number, $account_name, $amount, $transfer_content ) {
		$template = $this->get_setting( self::SETTING_IMAGE_TEMPLATE, 'qr_only' );
		$template = in_array( $template, [ 'compact2', 'compact', 'qr_only' ], true ) ? $template : 'qr_only';

		$query = [
			'addInfo'     => $transfer_content,
			'accountName' => wc_clean( wp_unslash( $account_name ) ),
		];

		if ( '' !== $amount ) {
			$query['amount'] = $amount;
		}

		return 'https://img.vietqr.io/image/' . rawurlencode( $bank_bin ) . '-' . rawurlencode( $account_number ) . '-' . rawurlencode( $template ) . '.png?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	private function get_order_vietqr_amount( $order ) {
		if ( 'yes' !== $this->get_setting( self::SETTING_INCLUDE_AMOUNT, 'yes' ) || 'VND' !== $order->get_currency() ) {
			return '';
		}

		return (string) max( 0, absint( round( (float) $order->get_total() ) ) );
	}

	private function get_order_transfer_content( $order ) {
		$template = $this->sanitize_transfer_content_template( $this->get_setting( self::SETTING_TRANSFER_CONTENT, 'ORDER-{order_number}' ) );

		$replacements = [
			'{order_id}'     => (string) $order->get_id(),
			'{order_number}' => (string) $order->get_order_number(),
			'{site_name}'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		];

		$content = strtr( $template, $replacements );
		$content = preg_replace( '/\s+/', ' ', wc_clean( $content ) );

		return trim( $content );
	}

	private function sanitize_transfer_content_template( $value ) {
		$value = preg_replace( '/\s+/', ' ', wc_clean( (string) $value ) );
		$value = trim( $value );

		return '' !== $value ? $value : 'ORDER-{order_number}';
	}

	private function get_setting( $key, $default = '' ) {
		$settings = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		return isset( $settings[ $key ] ) ? wc_clean( $settings[ $key ] ) : $default;
	}

	private function get_vietqr_setting_keys() {
		return [
			self::SETTING_ENABLED,
			self::SETTING_TRANSFER_CONTENT,
			self::SETTING_INCLUDE_AMOUNT,
			self::SETTING_IMAGE_TEMPLATE,
			self::SETTING_SHOW_EMAIL,
		];
	}

	private function get_legacy_vietqr_setting_key( $setting_key ) {
		$current_prefix = 'yoohw_vietnam_store_tools_vietqr_';
		$legacy_prefix  = 'v' . 'ck_vietqr_';

		if ( 0 !== strpos( $setting_key, $current_prefix ) ) {
			return '';
		}

		return $legacy_prefix . substr( $setting_key, strlen( $current_prefix ) );
	}

	private function enqueue_copy_assets( $admin = false ) {
		$style_path  = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/css/bacs-vietqr.css';
		$script_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/js/bacs-vietqr-copy.js';

		if ( $admin ) {
			wp_enqueue_style( 'dashicons' );
		}

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'yoohw-vietnam-store-tools-bacs-vietqr',
				YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/bacs-vietqr.css',
				[],
				filemtime( $style_path )
			);
		}

		if ( file_exists( $script_path ) ) {
			wp_enqueue_script(
				'yoohw-vietnam-store-tools-bacs-vietqr-copy',
				YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/bacs-vietqr-copy.js',
				[],
				filemtime( $script_path ),
				true
			);
		}
	}

	private function sanitize_bank_bin( $value ) {
		return preg_replace( '/\D+/', '', (string) $value );
	}

	private function get_account_bank_bin( $account ) {
		foreach ( [ 'sort_code', 'bic' ] as $field ) {
			if ( empty( $account[ $field ] ) ) {
				continue;
			}

			$bank_bin = $this->sanitize_bank_bin( $account[ $field ] );

			if ( '' !== $bank_bin ) {
				return $bank_bin;
			}
		}

		return '';
	}

	private function sanitize_account_number( $value ) {
		return preg_replace( '/[^A-Za-z0-9]+/', '', (string) $value );
	}

	private function get_order_admin_screen_ids() {
		$screen_ids = [ 'shop_order' ];

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $screen_ids ) ) );
	}

	private function is_admin_order_screen() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && in_array( $screen->id, $this->get_order_admin_screen_ids(), true );
	}

	private function is_frontend_order_screen() {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return true;
		}

		return function_exists( 'is_wc_endpoint_url' ) && (
			is_wc_endpoint_url( 'order-received' )
			|| is_wc_endpoint_url( 'view-order' )
		);
	}

	private function is_bacs_settings_admin_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		$page = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'page' ) );
		$tab  = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'tab' ) );
		$path = Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'path' );

		if ( 'wc-settings' === $page && 'checkout' === $tab ) {
			return true;
		}

		return 'wc-admin' === $page
			&& (
				false !== strpos( $path, 'settings' )
				|| false !== strpos( $path, 'payments' )
			);
	}

	private function is_wc_admin_payments_screen() {
		$page = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'page' ) );
		$path = Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'path' );

		return 'wc-admin' === $page
			&& false !== strpos( $path, 'settings' )
			&& false !== strpos( $path, 'payments' );
	}

	private function is_wc_admin_bacs_settings_screen() {
		$page = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'page' ) );
		$path = Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'path' );

		return 'wc-admin' === $page
			&& false !== strpos( $path, 'settings/payments/' . self::GATEWAY_ID );
	}

	private function get_admin_bank_accounts_for_script() {
		$accounts = get_option( 'woocommerce_bacs_accounts', [] );
		$accounts = is_array( $accounts ) ? $accounts : [];

		return array_values(
			array_map(
				function( $account ) {
					$account = wp_parse_args(
						is_array( $account ) ? $account : [],
						[
							'account_name'   => '',
							'account_number' => '',
							'bank_name'      => '',
							'sort_code'      => '',
							'iban'           => '',
							'bic'            => '',
						]
					);

					return [
						'account_name'   => wc_clean( wp_unslash( $account['account_name'] ) ),
						'account_number' => wc_clean( wp_unslash( $account['account_number'] ) ),
						'bank_name'      => wc_clean( wp_unslash( $account['bank_name'] ) ),
						'sort_code'      => wc_clean( wp_unslash( $account['sort_code'] ) ),
						'iban'           => wc_clean( wp_unslash( $account['iban'] ) ),
						'bic'            => wc_clean( wp_unslash( $account['bic'] ) ),
					];
				},
				$accounts
			)
		);
	}

	private function get_vietqr_banks_for_script() {
		$data = $this->get_vietqr_bank_data();
		$banks = isset( $data['banks'] ) && is_array( $data['banks'] ) ? $data['banks'] : [];

		return array_values(
			array_map(
				function( $bank ) {
					$bank = wp_parse_args(
						is_array( $bank ) ? $bank : [],
						[
							'bin'                => '',
							'code'               => '',
							'short_name'         => '',
							'name'               => '',
							'transfer_supported' => 0,
						]
					);

					return [
						'bin'                => wc_clean( wp_unslash( $bank['bin'] ) ),
						'code'               => wc_clean( wp_unslash( $bank['code'] ) ),
						'short_name'         => wc_clean( wp_unslash( $bank['short_name'] ) ),
						'name'               => wc_clean( wp_unslash( $bank['name'] ) ),
						'transfer_supported' => absint( $bank['transfer_supported'] ),
					];
				},
				$banks
			)
		);
	}

	private function get_vietqr_bank_data() {
		$path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'data/vietqr-banks.php';

		if ( ! file_exists( $path ) ) {
			return [];
		}

		$data = include $path;

		return is_array( $data ) ? $data : [];
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
}
