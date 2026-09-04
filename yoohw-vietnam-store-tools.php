<?php
/**
 * Plugin Name: Vietnam Store Toolkit for WooCommerce
 * Plugin URI: https://vietnamstore.org/
 * Description: WooCommerce Vietnam toolkit for address fields, checkout UX, VAT invoice requests, VietQR bank transfer, shipping, and phone normalization.
 * Version: 1.1.5
 * Author: YoOhw Studio
 * Author URI: https://yoohw.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * WC requires at least: 8.9
 * WC tested up to: 11.0
 * Text Domain: yoohw-vietnam-store-tools
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$plugin_data    = get_file_data( __FILE__, [ 'Version' => 'Version' ], false );
		$plugin_version = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '1.1.5';

		if ( ! defined( 'YOOHW_VIETNAM_STORE_TOOLS_VERSION' ) ) {
			define( 'YOOHW_VIETNAM_STORE_TOOLS_VERSION', $plugin_version );
		}

		if ( ! defined( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_FILE' ) ) {
			define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_FILE', __FILE__ );
		}

		if ( ! defined( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR' ) ) {
			define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		}

		if ( ! defined( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL' ) ) {
			define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
		}

		if ( ! defined( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_BASENAME' ) ) {
			define( 'YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
		}

		$this->register_translation_path();

		add_action( 'before_woocommerce_init', [ $this, 'declare_woocommerce_compatibility' ] );

		$this->includes();
	}

	/**
	 * Register bundled translations as the fallback for WordPress JIT loading.
	 *
	 * WordPress language packs remain authoritative because the textdomain
	 * registry checks WP_LANG_DIR/plugins before this custom path.
	 */
	private function register_translation_path() {
		global $wp_textdomain_registry, $wp_version;

		if ( ! is_object( $wp_textdomain_registry ) || ! is_callable( [ $wp_textdomain_registry, 'set_custom_path' ] ) ) {
			return;
		}

		$wp_textdomain_registry->set_custom_path(
			'yoohw-vietnam-store-tools',
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'languages'
		);

		if ( version_compare( (string) $wp_version, '6.5', '<' ) ) {
			add_filter( 'determine_locale', [ $this, 'prefer_wordpress_language_pack' ], PHP_INT_MAX );
		}
	}

	/**
	 * Preserve language-pack precedence in the WordPress 6.3-6.4 registry.
	 *
	 * Those releases continue scanning after finding WP_LANG_DIR/plugins and
	 * can otherwise replace that result with the bundled custom path.
	 *
	 * @param string $locale Current locale.
	 * @return string
	 */
	public function prefer_wordpress_language_pack( $locale ) {
		global $wp_textdomain_registry;

		$language_pack_dir = WP_LANG_DIR . '/plugins';
		$language_pack     = $language_pack_dir . '/yoohw-vietnam-store-tools-' . $locale . '.mo';

		if (
			is_readable( $language_pack )
			&& is_object( $wp_textdomain_registry )
			&& is_callable( [ $wp_textdomain_registry, 'set' ] )
		) {
			$wp_textdomain_registry->set( 'yoohw-vietnam-store-tools', $locale, $language_pack_dir );
		}

		return $locale;
	}

	public function declare_woocommerce_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}

	private function includes() {
		$files = [
			'includes/class-vietnam-commerce-kit-request-security.php',
			'includes/class-vietnam-commerce-kit-security.php',
			'includes/class-vietnam-commerce-kit-logger.php',
			'includes/class-vietnam-commerce-kit-admin-menu.php',
			'includes/class-vietnam-commerce-kit-vietnam-address-data.php',
			'includes/class-vietnam-commerce-kit-legacy-plugin-guard.php',
			'includes/class-vietnam-commerce-kit-blocks-integration.php',
			'includes/class-vietnam-commerce-kit-address-fields.php',
			'includes/class-vietnam-commerce-kit-phone-normalization.php',
			'includes/class-vietnam-commerce-kit-shipping.php',
			'includes/class-vietnam-commerce-kit-shipment-tracking.php',
			'includes/class-vietnam-commerce-kit-shipping-zones.php',
			'includes/class-vietnam-commerce-kit-shipping-rules.php',
			'includes/class-vietnam-commerce-kit-bacs-vietqr.php',
			'includes/class-vietnam-commerce-kit-tax-invoice.php',
			'includes/class-vietnam-commerce-kit-electronic-invoice.php',
			'includes/class-vietnam-commerce-kit-order-management.php',
			'includes/class-vietnam-commerce-kit-admin-address-fields.php',
			'includes/class-vietnam-commerce-kit-admin-order-fields.php',
			'includes/class-vietnam-commerce-kit-devvn-migration-tools.php',
		];

		foreach ( $files as $file ) {
			$path = plugin_dir_path( __FILE__ ) . $file;

			if ( file_exists( $path ) ) {
				include_once $path;
			}
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Legacy_Plugin_Guard' ) ) {
			new Yoohw_Vietnam_Store_Tools_Legacy_Plugin_Guard();
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Admin_Menu' ) ) {
			new Yoohw_Vietnam_Store_Tools_Admin_Menu();
		}

		if (
			class_exists( 'Yoohw_Vietnam_Store_Tools_Blocks_Integration' )
			&& Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ADDRESS_FIELDS )
		) {
			new Yoohw_Vietnam_Store_Tools_Blocks_Integration();
		}

		if (
			class_exists( 'Yoohw_Vietnam_Store_Tools_Address_Fields' )
			&& Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ADDRESS_FIELDS )
		) {
			new Yoohw_Vietnam_Store_Tools_Address_Fields();
		}

		if (
			class_exists( 'Yoohw_Vietnam_Store_Tools_Phone_Normalization' )
			&& Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_PHONE_NORMALIZATION )
		) {
			new Yoohw_Vietnam_Store_Tools_Phone_Normalization();
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Shipping' ) ) {
			new Yoohw_Vietnam_Store_Tools_Shipping();
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Shipment_Tracking' ) ) {
			new Yoohw_Vietnam_Store_Tools_Shipment_Tracking();
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Shipping_Zones' ) ) {
			new Yoohw_Vietnam_Store_Tools_Shipping_Zones();
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Shipping_Rules' ) ) {
			new Yoohw_Vietnam_Store_Tools_Shipping_Rules();
		}

		if (
			class_exists( 'Yoohw_Vietnam_Store_Tools_Admin_Address_Fields' )
			&& Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ADDRESS_FIELDS )
		) {
			new Yoohw_Vietnam_Store_Tools_Admin_Address_Fields();
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_BACS_VietQR' ) ) {
			new Yoohw_Vietnam_Store_Tools_BACS_VietQR();
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Tax_Invoice' ) ) {
			new Yoohw_Vietnam_Store_Tools_Tax_Invoice();
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_Electronic_Invoice' ) ) {
			new Yoohw_Vietnam_Store_Tools_Electronic_Invoice();
		}

		if (
			class_exists( 'Yoohw_Vietnam_Store_Tools_Order_Management' )
			&& Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ORDER_MANAGEMENT )
		) {
			new Yoohw_Vietnam_Store_Tools_Order_Management();
		}

		if (
			class_exists( 'Yoohw_Vietnam_Store_Tools_Admin_Order_Fields' )
			&& Yoohw_Vietnam_Store_Tools_Admin_Menu::is_feature_enabled( Yoohw_Vietnam_Store_Tools_Admin_Menu::OPTION_ADDRESS_FIELDS )
		) {
			new Yoohw_Vietnam_Store_Tools_Admin_Order_Fields();
		}

		if ( class_exists( 'Yoohw_Vietnam_Store_Tools_DevVN_Migration_Tools' ) ) {
			new Yoohw_Vietnam_Store_Tools_DevVN_Migration_Tools();
		}
	}
}

Yoohw_Vietnam_Store_Tools::instance();
