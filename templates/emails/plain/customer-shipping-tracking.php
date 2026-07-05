<?php
/**
 * Customer shipping tracking email plain text template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/customer-shipping-tracking.php.
 *
 * @package VietnamCommerceKit\Templates\Emails\Plain
 */

defined( 'ABSPATH' ) || exit;

if ( ! $order instanceof WC_Order ) {
	return;
}

$first_name = $order->get_billing_first_name();

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( '' !== $first_name ) {
	/* translators: %s: customer first name. */
	echo sprintf( esc_html__( 'Hi %s,', 'yoohw-vietnam-store-tools' ), esc_html( $first_name ) ) . "\n\n";
} else {
	echo esc_html__( 'Hi,', 'yoohw-vietnam-store-tools' ) . "\n\n";
}

printf(
	/* translators: %s: order number. */
	esc_html__( 'The shipping details for order #%s have been updated.', 'yoohw-vietnam-store-tools' ),
	esc_html( $order->get_order_number() )
);
echo "\n\n";

if ( '' !== $provider_name ) {
	echo esc_html__( 'Shipping provider', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $provider_name ) . "\n";
}

echo esc_html__( 'Tracking code', 'yoohw-vietnam-store-tools' ) . ': ' . esc_html( $tracking_code ) . "\n";

if ( '' !== $tracking_url ) {
	echo esc_html__( 'Tracking link', 'yoohw-vietnam-store-tools' ) . ': ' . esc_url( $tracking_url ) . "\n";
}

echo "\n" . esc_html__( 'You can use this information to follow the delivery status with the carrier.', 'yoohw-vietnam-store-tools' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
