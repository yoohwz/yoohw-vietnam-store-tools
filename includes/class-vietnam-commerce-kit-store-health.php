<?php
/**
 * Read-only store configuration and explicit legacy migration assistant.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Store_Health {
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 61 );
	}

	public function register_menu() {
		add_submenu_page(
			Yoohw_Vietnam_Store_Tools_Admin_Menu::MENU_SLUG,
			__( 'Store Health / Migration', 'yoohw-vietnam-store-tools' ),
			__( 'Store Health / Migration', 'yoohw-vietnam-store-tools' ),
			'manage_woocommerce',
			'yoohw-store-health',
			[ $this, 'render_page' ]
		);
	}

	/** Internal configuration snapshot. Never queries orders or customers. */
	public function get_checks() {
		$menu     = 'Yoohw_Vietnam_Store_Tools_Admin_Menu';
		$enabled  = __( 'Enabled', 'yoohw-vietnam-store-tools' );
		$disabled = __( 'Disabled', 'yoohw-vietnam-store-tools' );
		$checks   = [];
		$features = [
			$menu::OPTION_ADDRESS_FIELDS            => __( 'Vietnam address fields', 'yoohw-vietnam-store-tools' ),
			$menu::OPTION_PHONE_NORMALIZATION       => __( 'Vietnam phone validation and normalization', 'yoohw-vietnam-store-tools' ),
			$menu::OPTION_ELECTRONIC_INVOICE         => __( 'Manage electronic invoice workflow', 'yoohw-vietnam-store-tools' ),
			$menu::OPTION_CUSTOMER_SHIPMENT_DISPLAY => __( 'Customer shipment information', 'yoohw-vietnam-store-tools' ),
		];
		foreach ( $features as $option => $label ) {
			$checks[] = [ $label, $menu::is_feature_enabled( $option ) ? $enabled : $disabled, false ];
		}
		$location = wc_get_base_location();
		$checks[] = [ __( 'Store country', 'yoohw-vietnam-store-tools' ), $location['country'], false ];
		if ( 'VN' === $location['country'] ) {
			$valid    = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $location['state'] );
			$checks[] = [ __( 'Store province / city', 'yoohw-vietnam-store-tools' ), $valid ? __( 'Good', 'yoohw-vietnam-store-tools' ) : __( 'Needs attention', 'yoohw-vietnam-store-tools' ), ! $valid ];
		}
		$settings = get_option( 'woocommerce_bacs_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];
		$bacs     = 'yes' === ( $settings['enabled'] ?? 'no' );
		$vietqr   = 'yes' === ( $settings[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED ] ?? 'no' );
		$checks[] = [ __( 'Bank transfer (BACS)', 'yoohw-vietnam-store-tools' ), $bacs ? $enabled : $disabled, false ];
		$checks[] = [ __( 'VietQR bank transfer', 'yoohw-vietnam-store-tools' ), $vietqr ? $enabled : $disabled, false ];
		if ( $vietqr ) {
			$usable   = Yoohw_Vietnam_Store_Tools_BACS_VietQR::has_usable_account();
			$checks[] = [ __( 'Usable VietQR bank account', 'yoohw-vietnam-store-tools' ), $usable ? __( 'Good', 'yoohw-vietnam-store-tools' ) : __( 'Needs attention', 'yoohw-vietnam-store-tools' ), ! $usable ];
			if ( ! $bacs ) {
				$checks[] = [ __( 'Enable BACS to offer VietQR at checkout.', 'yoohw-vietnam-store-tools' ), __( 'Needs attention', 'yoohw-vietnam-store-tools' ), true ];
			}
		}
		$checks[] = [ __( 'Accept invoice requests at checkout', 'yoohw-vietnam-store-tools' ), Yoohw_Vietnam_Store_Tools_Tax_Invoice::accepts_new_requests() ? $enabled : $disabled, false ];
		$checks[] = [ __( 'Public order lookup', 'yoohw-vietnam-store-tools' ), 'yes' === get_option( Yoohw_Vietnam_Store_Tools_Shipment_Tracking::OPTION_LOOKUP_ENABLED, 'yes' ) ? $enabled : $disabled, false ];
		$checks[] = [ __( 'Manual shipment tracking', 'yoohw-vietnam-store-tools' ), __( 'Action available', 'yoohw-vietnam-store-tools' ), false ];
		return $checks;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage WooCommerce settings.', 'yoohw-vietnam-store-tools' ) );
		}
		$checks = $this->get_checks();
		?>
		<div class="wrap vck-store-health">
			<h1><?php esc_html_e( 'Store Health / Migration', 'yoohw-vietnam-store-tools' ); ?></h1>
			<p><?php esc_html_e( 'Configuration checks are informational. Disabled features may be intentional; no settings or stored data are changed by this page.', 'yoohw-vietnam-store-tools' ); ?></p>
			<h2><?php esc_html_e( 'Configuration readiness', 'yoohw-vietnam-store-tools' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<?php foreach ( $checks as $check ) : ?>
						<tr><th scope="row"><?php echo esc_html( $check[0] ); ?></th><td><?php echo esc_html( $check[1] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><?php esc_html_e( 'Invoice requests and electronic invoice workflow are independent. Manual tracking is available on orders without a carrier connector.', 'yoohw-vietnam-store-tools' ); ?></p>
			<h2><?php esc_html_e( 'Actions requiring attention', 'yoohw-vietnam-store-tools' ); ?></h2>
			<ul>
				<?php $attention = false; ?>
				<?php foreach ( $checks as $check ) : ?>
					<?php if ( $check[2] ) : ?>
						<?php $attention = true; ?>
						<li><?php echo esc_html( $check[0] . ': ' . $check[1] ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( ! $attention ) : ?>
					<li><?php esc_html_e( 'No configuration problems detected by these checks.', 'yoohw-vietnam-store-tools' ); ?></li>
				<?php endif; ?>
			</ul>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=yoohw-vietnam-store' ) ); ?>"><?php esc_html_e( 'Core features', 'yoohw-vietnam-store-tools' ); ?></a> |
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ); ?>"><?php esc_html_e( 'Open store address settings', 'yoohw-vietnam-store-tools' ); ?></a> |
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bacs' ) ); ?>"><?php esc_html_e( 'Configure VietQR', 'yoohw-vietnam-store-tools' ); ?></a>
			</p>
			<h2><?php esc_html_e( 'Data / migration readiness', 'yoohw-vietnam-store-tools' ); ?></h2>
			<p><?php esc_html_e( 'Scan legacy Vietnam Checkout / DevVN data on demand. Only exact-safe rows can be migrated; rows needing manual review remain unchanged. Address values are backed up before migration.', 'yoohw-vietnam-store-tools' ); ?></p>
			<p><button type="button" class="button button-secondary vck-health-scan"><?php esc_html_e( 'Scan data', 'yoohw-vietnam-store-tools' ); ?></button></p>
			<table class="widefat striped vck-health-counts" hidden>
				<thead><tr><th><?php esc_html_e( 'Data type', 'yoohw-vietnam-store-tools' ); ?></th><th><?php esc_html_e( 'Legacy rows found', 'yoohw-vietnam-store-tools' ); ?></th><th><?php esc_html_e( 'Safe rows remaining', 'yoohw-vietnam-store-tools' ); ?></th><th><?php esc_html_e( 'Needs manual review', 'yoohw-vietnam-store-tools' ); ?></th></tr></thead>
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'order address rows', 'yoohw-vietnam-store-tools' ); ?></th><td data-count="addressesTotal"></td><td data-count="addressesSafe"></td><td data-count="addressesReview"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'customer address rows', 'yoohw-vietnam-store-tools' ); ?></th><td data-count="customerAddressesTotal"></td><td data-count="customerAddressesSafe"></td><td data-count="customerAddressesReview"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'shipment orders', 'yoohw-vietnam-store-tools' ); ?></th><td data-count="trackingTotal"></td><td data-count="trackingRemaining"></td><td>—</td></tr>
				</tbody>
			</table>
			<div class="vck-health-report" aria-live="polite"><p><?php esc_html_e( 'Not scanned. Run a scan to see legacy data readiness.', 'yoohw-vietnam-store-tools' ); ?></p></div>
			<p><button type="button" class="button button-primary vck-health-migrate" disabled><?php esc_html_e( 'Sync all safe data', 'yoohw-vietnam-store-tools' ); ?></button></p>
			<div class="vck-health-progress" aria-live="polite"></div>
			<ul class="vck-health-errors" role="alert"></ul>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=tools' ) ); ?>"><?php esc_html_e( 'WooCommerce Status Tools', 'yoohw-vietnam-store-tools' ); ?></a></p>
		</div>
		<?php
	}
}
