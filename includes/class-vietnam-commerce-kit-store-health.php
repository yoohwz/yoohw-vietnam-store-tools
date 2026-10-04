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
			__( 'Store health', 'yoohw-vietnam-store-tools' ),
			__( 'Store health', 'yoohw-vietnam-store-tools' ),
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
			$checks[] = [ __( 'Store province / city', 'yoohw-vietnam-store-tools' ), $valid ? __( 'Good', 'yoohw-vietnam-store-tools' ) : __( 'Needs attention', 'yoohw-vietnam-store-tools' ), ! $valid, admin_url( 'admin.php?page=wc-settings&tab=general' ), __( 'Open store address settings', 'yoohw-vietnam-store-tools' ) ];
		}
		$settings = get_option( 'woocommerce_bacs_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];
		$bacs     = 'yes' === ( $settings['enabled'] ?? 'no' );
		$vietqr   = 'yes' === ( $settings[ Yoohw_Vietnam_Store_Tools_BACS_VietQR::SETTING_ENABLED ] ?? 'no' );
		$checks[] = [ __( 'Bank transfer (BACS)', 'yoohw-vietnam-store-tools' ), $bacs ? $enabled : $disabled, false ];
		$checks[] = [ __( 'VietQR bank transfer', 'yoohw-vietnam-store-tools' ), $vietqr ? $enabled : $disabled, false ];
		if ( $vietqr ) {
			$usable   = Yoohw_Vietnam_Store_Tools_BACS_VietQR::has_usable_account();
			$checks[] = [ __( 'Usable VietQR bank account', 'yoohw-vietnam-store-tools' ), $usable ? __( 'Good', 'yoohw-vietnam-store-tools' ) : __( 'Needs attention', 'yoohw-vietnam-store-tools' ), ! $usable, Yoohw_Vietnam_Store_Tools_BACS_VietQR::get_settings_url(), __( 'Configure VietQR', 'yoohw-vietnam-store-tools' ) ];
			if ( ! $bacs ) {
				$checks[] = [ __( 'Enable BACS to offer VietQR at checkout.', 'yoohw-vietnam-store-tools' ), __( 'Needs attention', 'yoohw-vietnam-store-tools' ), true, Yoohw_Vietnam_Store_Tools_BACS_VietQR::get_settings_url(), __( 'Configure VietQR', 'yoohw-vietnam-store-tools' ) ];
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
		$checks           = $this->get_checks();
		$attention_checks = array_filter( $checks, static function ( $check ) { return $check[2]; } );
		$attention_count  = count( $attention_checks );
		?>
		<div class="wrap yoohw-vietnam-store vck-store-health">
			<div class="yoohw-vietnam-store__hero">
				<div class="yoohw-vietnam-store__hero-main">
					<div class="yoohw-vietnam-store__hero-icon"><img src="<?php echo esc_url( YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/images/icon-256x256.png' ); ?>" alt="" width="96" height="96" /></div>
					<div class="yoohw-vietnam-store__hero-content">
						<span class="yoohw-vietnam-store__eyebrow"><?php esc_html_e( 'Vietnam Store Toolkit for WooCommerce', 'yoohw-vietnam-store-tools' ); ?></span>
						<h1><?php esc_html_e( 'Store health', 'yoohw-vietnam-store-tools' ); ?></h1>
						<p><?php esc_html_e( 'Review store readiness and scan legacy data only when needed. Nothing is migrated until you confirm the action.', 'yoohw-vietnam-store-tools' ); ?></p>
					</div>
				</div>
				<span class="yoohw-vietnam-store__version"><?php esc_html_e( 'Manual tool', 'yoohw-vietnam-store-tools' ); ?></span>
			</div>
			<section class="yoohw-vietnam-store__section" aria-label="<?php esc_attr_e( 'Health overview', 'yoohw-vietnam-store-tools' ); ?>">
				<div class="vck-health-metrics">
					<div class="vck-health-metric"><span><?php esc_html_e( 'Configuration checks', 'yoohw-vietnam-store-tools' ); ?></span><strong><?php echo esc_html( count( $checks ) ); ?></strong><small><?php esc_html_e( 'Current store settings', 'yoohw-vietnam-store-tools' ); ?></small></div>
					<div class="vck-health-metric"><span><?php esc_html_e( 'Needs attention', 'yoohw-vietnam-store-tools' ); ?></span><strong class="<?php echo $attention_count ? 'is-attention' : ''; ?>"><?php echo esc_html( $attention_count ); ?></strong><small><?php esc_html_e( 'Only actionable configuration issues', 'yoohw-vietnam-store-tools' ); ?></small></div>
					<div class="vck-health-metric"><span><?php esc_html_e( 'Legacy data', 'yoohw-vietnam-store-tools' ); ?></span><strong data-health-metric="legacy">—</strong><small data-health-hint="legacy"><?php esc_html_e( 'Not scanned', 'yoohw-vietnam-store-tools' ); ?></small></div>
					<div class="vck-health-metric"><span><?php esc_html_e( 'Manual review', 'yoohw-vietnam-store-tools' ); ?></span><strong data-health-metric="review">—</strong><small><?php esc_html_e( 'Shown after an explicit scan', 'yoohw-vietnam-store-tools' ); ?></small></div>
				</div>
			</section>
			<section class="yoohw-vietnam-store__section" aria-labelledby="vck-health-readiness">
				<div class="vck-health-heading"><div class="yoohw-vietnam-store__section-heading"><h2 id="vck-health-readiness"><?php esc_html_e( 'Store readiness', 'yoohw-vietnam-store-tools' ); ?></h2><p><?php esc_html_e( 'Configuration checks only. Disabled features may be intentional.', 'yoohw-vietnam-store-tools' ); ?></p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yoohw-vietnam-store' ) ); ?>"><?php esc_html_e( 'Core feature settings', 'yoohw-vietnam-store-tools' ); ?></a></div>
				<div class="vck-health-grid">
					<div class="vck-health-card"><h3><?php esc_html_e( 'Configuration checks', 'yoohw-vietnam-store-tools' ); ?></h3><p><?php esc_html_e( 'Current store settings and feature readiness.', 'yoohw-vietnam-store-tools' ); ?></p><div class="vck-health-checks">
						<?php foreach ( $checks as $check ) : ?>
							<div class="vck-health-check"><strong><?php echo esc_html( $check[0] ); ?></strong><span class="yoohw-vietnam-store__status <?php echo esc_attr( $check[2] ? 'is-attention' : ( in_array( $check[1], [ __( 'Enabled', 'yoohw-vietnam-store-tools' ), __( 'Good', 'yoohw-vietnam-store-tools' ) ], true ) ? 'is-active' : '' ) ); ?>"><?php echo esc_html( $check[1] ); ?></span></div>
						<?php endforeach; ?>
					</div><p class="vck-health-note"><?php esc_html_e( 'Invoice requests and electronic invoice workflow are independent. Manual tracking is available on orders without a carrier connector.', 'yoohw-vietnam-store-tools' ); ?></p></div>
					<div class="vck-health-card <?php echo $attention_count ? 'is-attention' : ''; ?>"><h3><?php esc_html_e( 'Actions requiring attention', 'yoohw-vietnam-store-tools' ); ?></h3>
						<?php if ( $attention_count ) : ?>
							<?php foreach ( $attention_checks as $check ) : ?>
								<div class="vck-health-action"><strong><?php echo esc_html( $check[0] ); ?></strong><p><?php echo esc_html( $check[1] ); ?></p><?php if ( ! empty( $check[3] ) ) : ?><a class="button" href="<?php echo esc_url( $check[3] ); ?>"><?php echo esc_html( $check[4] ); ?></a><?php endif; ?></div>
							<?php endforeach; ?>
						<?php else : ?><p><?php esc_html_e( 'No configuration problems detected by these checks.', 'yoohw-vietnam-store-tools' ); ?></p><?php endif; ?>
					</div>
				</div>
			</section>
			<section class="yoohw-vietnam-store__section" aria-labelledby="vck-health-legacy">
				<div class="yoohw-vietnam-store__section-heading"><h2 id="vck-health-legacy"><?php esc_html_e( 'Legacy data check', 'yoohw-vietnam-store-tools' ); ?></h2><p><?php esc_html_e( 'The scan is explicit and read-only. Opening this page does not scan orders or customers.', 'yoohw-vietnam-store-tools' ); ?></p></div>
				<div class="vck-health-card vck-health-probe"><div><h3><?php esc_html_e( 'Check for migratable legacy data', 'yoohw-vietnam-store-tools' ); ?></h3><p><?php esc_html_e( 'Scan order addresses, customer addresses, and supported shipment metadata. No data is changed.', 'yoohw-vietnam-store-tools' ); ?></p></div><button type="button" class="button button-primary vck-health-scan"><?php esc_html_e( 'Scan legacy data', 'yoohw-vietnam-store-tools' ); ?></button></div>
				<div class="vck-health-scan-result" aria-live="polite" hidden></div>
				<div class="vck-health-progress" aria-live="polite"></div>
				<ul class="vck-health-errors" role="alert"></ul>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=tools' ) ); ?>"><?php esc_html_e( 'WooCommerce Status Tools', 'yoohw-vietnam-store-tools' ); ?></a></p>
			</section>
			<section class="yoohw-vietnam-store__section vck-health-assistant" aria-labelledby="vck-health-assistant-title" hidden>
				<div class="yoohw-vietnam-store__section-heading"><h2 id="vck-health-assistant-title"><?php esc_html_e( 'Migration Assistant', 'yoohw-vietnam-store-tools' ); ?></h2><p><?php esc_html_e( 'Only exact-safe data found by the explicit scan can be synced.', 'yoohw-vietnam-store-tools' ); ?></p></div>
				<div class="vck-health-card"><div class="vck-health-table-wrap"><table class="vck-health-counts">
				<thead><tr><th><?php esc_html_e( 'Data type', 'yoohw-vietnam-store-tools' ); ?></th><th><?php esc_html_e( 'Legacy rows found', 'yoohw-vietnam-store-tools' ); ?></th><th><?php esc_html_e( 'Safe rows remaining', 'yoohw-vietnam-store-tools' ); ?></th><th><?php esc_html_e( 'Needs manual review', 'yoohw-vietnam-store-tools' ); ?></th></tr></thead>
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'order address rows', 'yoohw-vietnam-store-tools' ); ?></th><td data-count="addressesTotal"></td><td data-count="addressesSafe"></td><td data-count="addressesReview"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'customer address rows', 'yoohw-vietnam-store-tools' ); ?></th><td data-count="customerAddressesTotal"></td><td data-count="customerAddressesSafe"></td><td data-count="customerAddressesReview"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'shipment orders', 'yoohw-vietnam-store-tools' ); ?></th><td data-count="trackingTotal"></td><td data-count="trackingRemaining"></td><td>—</td></tr>
				</tbody>
				</table></div>
				<div class="vck-health-warning"><?php esc_html_e( 'Ambiguous rows require manual review and remain unchanged. Original address values are backed up before sync.', 'yoohw-vietnam-store-tools' ); ?></div>
				<div class="vck-health-report" aria-live="polite"></div>
				<p><button type="button" class="button button-primary vck-health-migrate" disabled><?php esc_html_e( 'Sync all safe data', 'yoohw-vietnam-store-tools' ); ?></button></p></div>
			</section>
		</div>
		<?php
	}
}
