<?php
/**
 * Shared body for the shop's customer emails (on-hold, processing, completed, cancelled, note).
 * Greeting → the step's message box (boutique-admin/customer-emails.php, hooked before the order
 * table) → order summary → customer details → closing line. Replaces WooCommerce's stock intros,
 * whose Persian translations read oddly («در حالت تعلیق است»).
 *
 * Variables from WooCommerce: $order, $email_heading, $additional_content, $sent_to_admin, $plain_text, $email.
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>سلام <?php echo esc_html( $order->get_billing_first_name() ?: 'دوست عزیز' ); ?>،</p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
