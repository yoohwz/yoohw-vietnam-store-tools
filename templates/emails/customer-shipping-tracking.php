<?php
/**
 * Customer shipping tracking email.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/customer-shipping-tracking.php.
 *
 * @package VietnamCommerceKit\Templates\Emails
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

// WooCommerce email templates intentionally use injected local variables and core WooCommerce hook names.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

if ( ! $order instanceof WC_Order ) {
	return;
}

$email_improvements_enabled = class_exists( FeaturesUtil::class ) && FeaturesUtil::feature_is_enabled( 'email_improvements' );
$first_name                 = $order->get_billing_first_name();
$heading_class              = $email_improvements_enabled ? 'email-order-detail-heading' : '';
$table_class                = $email_improvements_enabled ? ' email-order-details' : '';
$table_cellpadding          = $email_improvements_enabled ? '0' : '6';
$table_border               = $email_improvements_enabled ? '0' : '1';
$table_margin               = $email_improvements_enabled ? '24px' : '40px';
$tracking_code_display      = '' !== $tracking_url
	? '<a class="link" href="' . esc_url( $tracking_url ) . '">' . esc_html( $tracking_code ) . '</a>'
	: '<strong>' . esc_html( $tracking_code ) . '</strong>';

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( '' !== $first_name ) {
	/* translators: %s: customer first name. */
	printf( esc_html__( 'Hi %s,', 'yoohw-vietnam-store-tools' ), esc_html( $first_name ) );
} else {
	esc_html_e( 'Hi,', 'yoohw-vietnam-store-tools' );
}
?>
</p>

<p>
<?php
printf(
	/* translators: %s: order number. */
	esc_html__( 'The shipping details for order #%s have been updated.', 'yoohw-vietnam-store-tools' ),
	esc_html( $order->get_order_number() )
);
?>
</p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<h2 class="<?php echo esc_attr( $heading_class ); ?>"><?php esc_html_e( 'Shipping details', 'yoohw-vietnam-store-tools' ); ?></h2>

<div style="margin-bottom: <?php echo esc_attr( $table_margin ); ?>;">
	<table class="td font-family<?php echo esc_attr( $table_class ); ?>" cellspacing="0" cellpadding="<?php echo esc_attr( $table_cellpadding ); ?>" style="width: 100%;" border="<?php echo esc_attr( $table_border ); ?>">
		<tbody>
			<?php if ( '' !== $provider_name ) : ?>
				<tr class="order-totals vck-shipping-tracking-row">
					<th class="td text-align-left" scope="row"><?php esc_html_e( 'Shipping provider', 'yoohw-vietnam-store-tools' ); ?></th>
					<td class="td text-align-left"><?php echo esc_html( $provider_name ); ?></td>
				</tr>
			<?php endif; ?>
			<tr class="order-totals vck-shipping-tracking-row order-totals-last">
				<th class="td text-align-left" scope="row"><?php esc_html_e( 'Tracking code', 'yoohw-vietnam-store-tools' ); ?></th>
				<td class="td text-align-left"><?php echo wp_kses_post( $tracking_code_display ); ?></td>
			</tr>
			<?php foreach ( $shipment_details as $detail ) : ?>
				<tr class="order-totals vck-shipping-tracking-row">
					<th class="td text-align-left" scope="row"><?php echo esc_html( $detail['label'] ); ?></th>
					<td class="td text-align-left"><?php echo wp_kses_post( $detail['value'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<p><?php esc_html_e( 'You can use this information to follow the delivery status with the carrier.', 'yoohw-vietnam-store-tools' ); ?></p>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
