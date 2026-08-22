<?php
/**
 * Central administration page for Vietnam Store Toolkit.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Admin_Menu {

	const MENU_SLUG = 'yoohw-vietnam-store';
	const ACTION_SAVE_FEATURES = 'yoohw_vietnam_store_tools_save_features';

	const OPTION_ADDRESS_FIELDS             = 'yoohw_vietnam_store_tools_address_fields_enabled';
	const OPTION_PHONE_NORMALIZATION        = 'yoohw_vietnam_store_tools_phone_normalization_enabled';
	const OPTION_CUSTOMER_SHIPMENT_DISPLAY  = 'yoohw_vietnam_store_tools_customer_shipment_display_enabled';
	const OPTION_ORDER_MANAGEMENT           = 'yoohw_vietnam_store_tools_order_management_enabled';
	const OPTION_ELECTRONIC_INVOICE         = 'yoohw_vietnam_store_tools_electronic_invoice_enabled';

	private $page_hook = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 60 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', [ $this, 'position_menu_below_woocommerce' ], 9999 );
		add_filter( 'plugin_action_links_' . YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_BASENAME, [ $this, 'add_plugin_action_links' ] );
		add_action( 'admin_post_' . self::ACTION_SAVE_FEATURES, [ $this, 'handle_save_features' ] );
	}

	public static function is_feature_enabled( $option_id ) {
		return 'yes' === get_option( $option_id, 'yes' );
	}

	public function register_menu() {
		$this->page_hook = add_menu_page(
			__( 'Vietnam store', 'yoohw-vietnam-store-tools' ),
			__( 'Vietnam store', 'yoohw-vietnam-store-tools' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'none',
			'55.5001'
		);
	}

	private function add_menu_icon_style() {
		$icon_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'assets/images/menu-icon.svg';
		$svg       = is_readable( $icon_path ) ? file_get_contents( $icon_path ) : false;

		if ( false === $svg ) {
			return;
		}

		$icon_url = 'data:image/svg+xml;base64,' . base64_encode( $svg );

		wp_add_inline_style(
			'common',
			'#toplevel_page_' . self::MENU_SLUG . ' .wp-menu-image::before{background-color:currentColor;content:"";display:block;height:20px;margin:7px auto 0;-webkit-mask:url("' . $icon_url . '") center/20px 20px no-repeat;mask:url("' . $icon_url . '") center/20px 20px no-repeat;padding:0;width:20px;}'
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		$this->add_menu_icon_style();

		if ( $this->page_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'yoohw-vietnam-store-tools-admin-menu',
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/admin/vietnam-store.css',
			[],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION
		);
	}

	public function add_plugin_action_links( $links ) {
		$links = is_array( $links ) ? $links : [];

		array_unshift(
			$links,
			'<a href="' . esc_url( $this->get_page_url() ) . '">' . esc_html__( 'Settings', 'yoohw-vietnam-store-tools' ) . '</a>'
		);

		return $links;
	}

	public function position_menu_below_woocommerce( $menu_order ) {
		if ( ! is_array( $menu_order ) ) {
			return $menu_order;
		}

		$current_position = array_search( self::MENU_SLUG, $menu_order, true );

		if ( false === $current_position ) {
			return $menu_order;
		}

		unset( $menu_order[ $current_position ] );
		$menu_order           = array_values( $menu_order );
		$woocommerce_position = array_search( 'woocommerce', $menu_order, true );

		if ( false === $woocommerce_position ) {
			$woocommerce_position = array_search( 'wc-admin', $menu_order, true );
		}

		if ( false === $woocommerce_position ) {
			return $menu_order;
		}

		array_splice( $menu_order, $woocommerce_position + 1, 0, [ self::MENU_SLUG ] );

		return $menu_order;
	}

	public function handle_save_features() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage WooCommerce settings.', 'yoohw-vietnam-store-tools' ) );
		}

		check_admin_referer( self::ACTION_SAVE_FEATURES );

		$submitted = isset( $_POST['features'] ) && is_array( $_POST['features'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each submitted feature value is sanitized and allowlisted before persistence below.
			? wp_unslash( $_POST['features'] )
			: [];

		foreach ( array_keys( $this->get_feature_options() ) as $option_id ) {
			$value = isset( $submitted[ $option_id ] ) && 'yes' === sanitize_text_field( $submitted[ $option_id ] ) ? 'yes' : 'no';
			update_option( $option_id, $value, false );
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::MENU_SLUG,
					'updated' => 'true',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage WooCommerce settings.', 'yoohw-vietnam-store-tools' ) );
		}

		$groups = $this->get_setting_groups();
		?>
		<div class="wrap yoohw-vietnam-store">
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag; no state is changed from this query argument. ?>
			<?php if ( isset( $_GET['updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Vietnam store feature settings saved.', 'yoohw-vietnam-store-tools' ); ?></p></div>
			<?php endif; ?>

			<div class="yoohw-vietnam-store__hero">
				<div class="yoohw-vietnam-store__hero-main">
					<div class="yoohw-vietnam-store__hero-icon">
						<img
							src="<?php echo esc_url( YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/images/icon-256x256.png' ); ?>"
							alt=""
							width="96"
							height="96"
						>
					</div>
					<div class="yoohw-vietnam-store__hero-content">
						<span class="yoohw-vietnam-store__eyebrow"><?php esc_html_e( 'Vietnam Store Toolkit for WooCommerce', 'yoohw-vietnam-store-tools' ); ?></span>
						<h1><?php esc_html_e( 'Vietnam store', 'yoohw-vietnam-store-tools' ); ?></h1>
						<p><?php esc_html_e( 'Manage the Vietnam-specific checkout, payments, invoices, shipping, and order tools used by your WooCommerce store.', 'yoohw-vietnam-store-tools' ); ?></p>
					</div>
				</div>
				<span class="yoohw-vietnam-store__version">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: plugin version. */
							__( 'Version %s', 'yoohw-vietnam-store-tools' ),
							YOOHW_VIETNAM_STORE_TOOLS_VERSION
						)
					);
					?>
				</span>
			</div>

			<?php $this->render_feature_settings(); ?>

			<?php foreach ( $groups as $group ) : ?>
				<section class="yoohw-vietnam-store__section" aria-labelledby="<?php echo esc_attr( $group['id'] ); ?>">
					<div class="yoohw-vietnam-store__section-heading">
						<h2 id="<?php echo esc_attr( $group['id'] ); ?>"><?php echo esc_html( $group['title'] ); ?></h2>
						<p><?php echo esc_html( $group['description'] ); ?></p>
					</div>
					<div class="yoohw-vietnam-store__grid">
						<?php foreach ( $group['items'] as $item ) : ?>
							<?php $this->render_setting_card( $item ); ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_feature_settings() {
		$features = $this->get_feature_options();
		?>
		<section class="yoohw-vietnam-store__section" aria-labelledby="yoohw-vietnam-store-features">
			<div class="yoohw-vietnam-store__section-heading">
				<h2 id="yoohw-vietnam-store-features"><?php esc_html_e( 'Core features', 'yoohw-vietnam-store-tools' ); ?></h2>
				<p><?php esc_html_e( 'Enable only the Vietnam store enhancements that fit your workflow. Disabling a feature does not delete its existing data.', 'yoohw-vietnam-store-tools' ); ?></p>
			</div>
			<form class="yoohw-vietnam-store__feature-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_FEATURES ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE_FEATURES ); ?>
				<div class="yoohw-vietnam-store__feature-list">
					<?php foreach ( $features as $option_id => $feature ) : ?>
						<label class="yoohw-vietnam-store__feature" for="<?php echo esc_attr( $option_id ); ?>">
							<span class="yoohw-vietnam-store__feature-content">
								<strong><?php echo esc_html( $feature['title'] ); ?></strong>
								<span><?php echo esc_html( $feature['description'] ); ?></span>
							</span>
							<span class="yoohw-vietnam-store__switch">
								<input
									id="<?php echo esc_attr( $option_id ); ?>"
									name="features[<?php echo esc_attr( $option_id ); ?>]"
									type="checkbox"
									value="yes"
									<?php checked( isset( $feature['enabled'] ) ? $feature['enabled'] : self::is_feature_enabled( $option_id ) ); ?>
								>
								<span aria-hidden="true"></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save feature settings', 'yoohw-vietnam-store-tools' ); ?></button>
				</p>
			</form>
		</section>
		<?php
	}

	private function get_feature_options() {
		return [
			self::OPTION_ADDRESS_FIELDS => [
				'title'       => __( 'Vietnam address fields', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Use Vietnam provinces and wards, compact name fields, address normalization, and compatible Classic or Block checkout behavior.', 'yoohw-vietnam-store-tools' ),
			],
			self::OPTION_PHONE_NORMALIZATION => [
				'title'       => __( 'Vietnam phone validation and normalization', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Validate Vietnamese phone numbers and store them consistently across checkout, customer profiles, and orders.', 'yoohw-vietnam-store-tools' ),
			],
			self::OPTION_CUSTOMER_SHIPMENT_DISPLAY => [
				'title'       => __( 'Customer shipment information', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Show the carrier, tracking code, shipment status, and tracking timeline on order confirmation and My Account pages.', 'yoohw-vietnam-store-tools' ),
			],
			self::OPTION_ORDER_MANAGEMENT => [
				'title'       => __( 'Vietnam order management tools', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Add Vietnam-specific order information, filters, bulk actions, and CSV exports to the WooCommerce order list.', 'yoohw-vietnam-store-tools' ),
			],
			Yoohw_Vietnam_Store_Tools_Tax_Invoice::OPTION_ID => [
				'title'       => __( 'Accept invoice requests at checkout', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Allow customers to submit company and tax details for new orders.', 'yoohw-vietnam-store-tools' ),
				'enabled'     => Yoohw_Vietnam_Store_Tools_Tax_Invoice::accepts_new_requests(),
			],
			self::OPTION_ELECTRONIC_INVOICE => [
				'title'       => __( 'Manage electronic invoice workflow', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Allow staff to update invoice status, references, notes, PDF, and XML files. When disabled, existing invoice data remains read-only.', 'yoohw-vietnam-store-tools' ),
			],
		];
	}

	private function render_setting_card( $item ) {
		$status_class = ! empty( $item['active'] ) ? 'is-active' : 'is-inactive';
		?>
		<article class="yoohw-vietnam-store__card">
			<div class="yoohw-vietnam-store__card-header">
				<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
				<span class="yoohw-vietnam-store__status <?php echo esc_attr( $status_class ); ?>">
					<?php echo esc_html( $item['status'] ); ?>
				</span>
			</div>
			<h3><?php echo esc_html( $item['title'] ); ?></h3>
			<p><?php echo esc_html( $item['description'] ); ?></p>
			<?php if ( ! empty( $item['url'] ) ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( $item['url'] ); ?>">
					<?php echo esc_html( $item['action'] ); ?>
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</a>
			<?php endif; ?>
		</article>
		<?php
	}

	private function get_setting_groups() {
		$enabled_providers  = class_exists( 'Yoohw_Vietnam_Store_Tools_Shipping' ) ? Yoohw_Vietnam_Store_Tools_Shipping::get_providers() : [];
		$service_settings   = class_exists( 'Yoohw_Vietnam_Store_Tools_Internal' );
		$lookup_enabled     = 'yes' === get_option( Yoohw_Vietnam_Store_Tools_Shipment_Tracking::OPTION_LOOKUP_ENABLED, 'yes' );
		$address_enabled    = self::is_feature_enabled( self::OPTION_ADDRESS_FIELDS );
		$phone_enabled      = self::is_feature_enabled( self::OPTION_PHONE_NORMALIZATION );
		$tax_invoice_active = Yoohw_Vietnam_Store_Tools_Tax_Invoice::accepts_new_requests();
		$workflow_active    = self::is_feature_enabled( self::OPTION_ELECTRONIC_INVOICE );
		$bacs_settings      = get_option( 'woocommerce_bacs_settings', [] );
		$bacs_settings      = is_array( $bacs_settings ) ? $bacs_settings : [];
		$vietqr_active      = 'yes' === ( $bacs_settings['enabled'] ?? 'no' )
			&& 'yes' === ( $bacs_settings[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED ] ?? 'no' );
		$email_settings     = get_option( 'woocommerce_yoohw_vietnam_store_tools_customer_shipping_tracking_settings', [] );
		$email_settings     = is_array( $email_settings ) ? $email_settings : [];
		$email_enabled      = 'no' !== ( $email_settings['enabled'] ?? 'yes' );
		$show_devvn_migration = class_exists( 'Yoohw_Vietnam_Store_Tools_DevVN_Migration_Tools' )
			&& Yoohw_Vietnam_Store_Tools_DevVN_Migration_Tools::has_pending_migration_data();

		$shipping_services_status = sprintf(
			/* translators: %d: number of enabled shipping services. */
			_n( '%d service enabled', '%d services enabled', count( $enabled_providers ), 'yoohw-vietnam-store-tools' ),
			count( $enabled_providers )
		);

		$maintenance_items = [
			[
				'title'       => __( 'Order management', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Use Vietnam-specific order columns, filters, shipment actions, and invoice information from the order list.', 'yoohw-vietnam-store-tools' ),
				'status'      => self::is_feature_enabled( self::OPTION_ORDER_MANAGEMENT ) ? __( 'Enabled', 'yoohw-vietnam-store-tools' ) : __( 'Disabled', 'yoohw-vietnam-store-tools' ),
				'active'      => self::is_feature_enabled( self::OPTION_ORDER_MANAGEMENT ),
				'icon'        => 'dashicons-list-view',
				'url'         => $this->get_orders_url(),
				'action'      => __( 'Manage orders', 'yoohw-vietnam-store-tools' ),
			],
		];

		if ( $show_devvn_migration ) {
			$maintenance_items[] = [
				'title'       => __( 'DevVN migration', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Inspect and migrate compatible address and order data using WooCommerce status tools.', 'yoohw-vietnam-store-tools' ),
				'status'      => __( 'Manual tool', 'yoohw-vietnam-store-tools' ),
				'active'      => true,
				'icon'        => 'dashicons-database-import',
				'url'         => admin_url( 'admin.php?page=wc-status&tab=tools' ),
				'action'      => __( 'Open migration tools', 'yoohw-vietnam-store-tools' ),
			];
		}

		return [
			[
				'id'          => 'yoohw-vietnam-store-shipping',
				'title'       => __( 'Shipping and delivery', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Connect carriers, define shipping rules, and control the tracking experience.', 'yoohw-vietnam-store-tools' ),
				'items'       => [
					[
						'title'       => __( 'Shipping services', 'yoohw-vietnam-store-tools' ),
						'description' => $service_settings
							? __( 'Configure carrier accounts, pickup addresses, automatic synchronization, and shipment status mappings.', 'yoohw-vietnam-store-tools' )
							: __( 'No shipping service connector is installed. Manual shipment management remains available on orders.', 'yoohw-vietnam-store-tools' ),
						'status'      => $service_settings ? $shipping_services_status : __( 'Manual only', 'yoohw-vietnam-store-tools' ),
						'active'      => ! empty( $enabled_providers ),
						'icon'        => 'dashicons-location-alt',
						'url'         => $service_settings ? admin_url( 'admin.php?page=wc-settings&tab=shipping&section=shipping-services' ) : '',
						'action'      => __( 'Configure services', 'yoohw-vietnam-store-tools' ),
					],
					[
						'title'       => __( 'Shipping zones and rules', 'yoohw-vietnam-store-tools' ),
						'description' => __( 'Assign rates and the Vietnam shipping rules method to WooCommerce shipping zones.', 'yoohw-vietnam-store-tools' ),
						'status'      => __( 'WooCommerce settings', 'yoohw-vietnam-store-tools' ),
						'active'      => true,
						'icon'        => 'dashicons-admin-site-alt3',
						'url'         => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
						'action'      => __( 'Manage shipping zones', 'yoohw-vietnam-store-tools' ),
					],
					[
						'title'       => __( 'Shipment tracking', 'yoohw-vietnam-store-tools' ),
						'description' => __( 'Configure public order lookup, carrier tracking URL templates, and custom carriers.', 'yoohw-vietnam-store-tools' ),
						'status'      => $lookup_enabled ? __( 'Public lookup enabled', 'yoohw-vietnam-store-tools' ) : __( 'Public lookup disabled', 'yoohw-vietnam-store-tools' ),
						'active'      => $lookup_enabled,
						'icon'        => 'dashicons-search',
						'url'         => admin_url( 'admin.php?page=wc-settings&tab=shipping&section=yoohw_shipment_tracking' ),
						'action'      => __( 'Configure tracking', 'yoohw-vietnam-store-tools' ),
					],
					[
						'title'       => __( 'Shipping tracking email', 'yoohw-vietnam-store-tools' ),
						'description' => __( 'Control the subject, heading, content, and email format for customer shipment updates.', 'yoohw-vietnam-store-tools' ),
						'status'      => $email_enabled ? __( 'Enabled', 'yoohw-vietnam-store-tools' ) : __( 'Disabled', 'yoohw-vietnam-store-tools' ),
						'active'      => $email_enabled,
						'icon'        => 'dashicons-email-alt',
						'url'         => admin_url( 'admin.php?page=wc-settings&tab=email&section=yoohw_vietnam_store_tools_customer_shipping_tracking_email' ),
						'action'      => __( 'Configure email', 'yoohw-vietnam-store-tools' ),
					],
				],
			],
			[
				'id'          => 'yoohw-vietnam-store-checkout',
				'title'       => __( 'Checkout, payments, and invoices', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Manage Vietnam checkout behavior and payment or invoice features.', 'yoohw-vietnam-store-tools' ),
				'items'       => [
					[
						'title'       => __( 'Vietnam address and phone fields', 'yoohw-vietnam-store-tools' ),
						'description' => __( 'Control Vietnam address formatting, checkout compatibility, phone validation, and normalization in Core features.', 'yoohw-vietnam-store-tools' ),
						'status'      => $address_enabled && $phone_enabled
							? __( 'Enabled', 'yoohw-vietnam-store-tools' )
							: ( $address_enabled || $phone_enabled ? __( 'Partially enabled', 'yoohw-vietnam-store-tools' ) : __( 'Disabled', 'yoohw-vietnam-store-tools' ) ),
						'active'      => $address_enabled || $phone_enabled,
						'icon'        => 'dashicons-admin-home',
						'url'         => admin_url( 'admin.php?page=wc-settings&tab=general' ),
						'action'      => __( 'Open store address settings', 'yoohw-vietnam-store-tools' ),
					],
					[
						'title'       => __( 'VietQR bank transfer', 'yoohw-vietnam-store-tools' ),
						'description' => __( 'Configure bank accounts, transfer content, QR layout, order amount, and customer email display.', 'yoohw-vietnam-store-tools' ),
						'status'      => $vietqr_active ? __( 'Enabled', 'yoohw-vietnam-store-tools' ) : __( 'Disabled', 'yoohw-vietnam-store-tools' ),
						'active'      => $vietqr_active,
						'icon'        => 'dashicons-money-alt',
						'url'         => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bacs' ),
						'action'      => __( 'Configure VietQR', 'yoohw-vietnam-store-tools' ),
					],
					[
						'title'       => __( 'Business invoices', 'yoohw-vietnam-store-tools' ),
						'description' => __( 'Collect invoice requests at checkout and manage electronic invoice data on orders as two independent capabilities.', 'yoohw-vietnam-store-tools' ),
						'status'      => $tax_invoice_active && $workflow_active
							? __( 'Requests and workflow enabled', 'yoohw-vietnam-store-tools' )
							: ( $tax_invoice_active ? __( 'Checkout requests enabled', 'yoohw-vietnam-store-tools' ) : ( $workflow_active ? __( 'Workflow enabled', 'yoohw-vietnam-store-tools' ) : __( 'Disabled', 'yoohw-vietnam-store-tools' ) ) ),
						'active'      => $tax_invoice_active || $workflow_active,
						'icon'        => 'dashicons-media-document',
						'url'         => $this->get_page_url() . '#yoohw-vietnam-store-features',
						'action'      => __( 'Configure invoices', 'yoohw-vietnam-store-tools' ),
					],
				],
			],
			[
				'id'          => 'yoohw-vietnam-store-tools',
				'title'       => __( 'Maintenance tools', 'yoohw-vietnam-store-tools' ),
				'description' => __( 'Review operational data and migrate compatible settings from legacy Vietnam checkout plugins.', 'yoohw-vietnam-store-tools' ),
				'items'       => $maintenance_items,
			],
		];
	}

	private function get_orders_url() {
		if (
			class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url( 'admin.php?page=wc-orders' );
		}

		return admin_url( 'edit.php?post_type=shop_order' );
	}

	private function get_page_url() {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}
}
