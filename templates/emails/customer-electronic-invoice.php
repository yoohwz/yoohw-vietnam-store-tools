<?php
/**
 * Customer electronic invoice email.
 *
 * @package VietnamCommerceKit\Templates\Emails
 */

defined( 'ABSPATH' ) || exit;

if ( ! $order instanceof WC_Order ) {
	return;
}

$invoice_data = is_array( $invoice_data ) ? $invoice_data : [];
$first_name   = $order->get_billing_first_name();
$rows         = [
	__( 'Invoice number', 'yoohw-vietnam-store-tools' ) => sanitize_text_field( (string) ( $invoice_data['number'] ?? '' ) ),
	__( 'Invoice symbol', 'yoohw-vietnam-store-tools' ) => sanitize_text_field( (string) ( $invoice_data['symbol'] ?? '' ) ),
	__( 'Invoice provider', 'yoohw-vietnam-store-tools' ) => sanitize_text_field( (string) ( $invoice_data['provider'] ?? '' ) ),
	__( 'Issue date', 'yoohw-vietnam-store-tools' )     => sanitize_text_field( (string) ( $invoice_data['issued_at'] ?? '' ) ),
];

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
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
		esc_html__( 'Your electronic invoice for order #%s is ready.', 'yoohw-vietnam-store-tools' ),
		esc_html( $order->get_order_number() )
	);
	?>
</p>

<?php if ( array_filter( $rows, 'strlen' ) ) : ?>
	<h2><?php esc_html_e( 'Invoice details', 'yoohw-vietnam-store-tools' ); ?></h2>
	<table class="td font-family" cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:24px" border="1">
		<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<?php if ( '' !== $value ) : ?>
					<tr>
						<th class="td text-align-left" scope="row"><?php echo esc_html( $label ); ?></th>
						<td class="td text-align-left"><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( ! empty( $invoice_data['lookup_url'] ) ) : ?>
	<p><a href="<?php echo esc_url( $invoice_data['lookup_url'] ); ?>"><?php esc_html_e( 'Look up electronic invoice', 'yoohw-vietnam-store-tools' ); ?></a></p>
<?php endif; ?>

<?php if ( ! empty( $invoice_data['pdf_attachment_id'] ) || ! empty( $invoice_data['xml_attachment_id'] ) ) : ?>
	<p><?php esc_html_e( 'Available invoice files are attached to this email.', 'yoohw-vietnam-store-tools' ); ?></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
