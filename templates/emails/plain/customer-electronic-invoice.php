<?php
/**
 * Customer electronic invoice email (plain text).
 *
 * @package VietnamCommerceKit\Templates\Emails\Plain
 */

defined( 'ABSPATH' ) || exit;

// WooCommerce email templates intentionally use injected local variables and core WooCommerce hook names.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

if ( ! $order instanceof WC_Order ) {
	return;
}

$invoice_data = is_array( $invoice_data ) ? $invoice_data : [];
$first_name   = $order->get_billing_first_name();

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
	esc_html__( 'Your electronic invoice for order #%s is ready.', 'yoohw-vietnam-store-tools' ),
	esc_html( $order->get_order_number() )
);
echo "\n\n";

$rows = [
	__( 'Invoice number', 'yoohw-vietnam-store-tools' ) => sanitize_text_field( (string) ( $invoice_data['number'] ?? '' ) ),
	__( 'Invoice symbol', 'yoohw-vietnam-store-tools' ) => sanitize_text_field( (string) ( $invoice_data['symbol'] ?? '' ) ),
	__( 'Invoice provider', 'yoohw-vietnam-store-tools' ) => sanitize_text_field( (string) ( $invoice_data['provider'] ?? '' ) ),
	__( 'Issue date', 'yoohw-vietnam-store-tools' )     => sanitize_text_field( (string) ( $invoice_data['issued_at'] ?? '' ) ),
];

foreach ( $rows as $label => $value ) {
	if ( '' !== $value ) {
		echo esc_html( $label ) . ': ' . esc_html( $value ) . "\n";
	}
}

if ( ! empty( $invoice_data['lookup_url'] ) ) {
	echo esc_html__( 'Invoice lookup URL', 'yoohw-vietnam-store-tools' ) . ': ' . esc_url( $invoice_data['lookup_url'] ) . "\n";
}

if ( ! empty( $invoice_data['pdf_attachment_id'] ) || ! empty( $invoice_data['xml_attachment_id'] ) ) {
	echo "\n" . esc_html__( 'Available invoice files are attached to this email.', 'yoohw-vietnam-store-tools' ) . "\n";
}

if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
}

echo "\n\n----------------------------------------\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
