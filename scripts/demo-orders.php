<?php
/**
 * Demo orders for checking the reports page. Tagged _boutique_demo; stock untouched.
 * Create:  docker compose --profile cli run --rm wpcli eval-file /scripts/demo-orders.php
 * Remove:  ... eval-file /scripts/demo-orders.php delete
 */
$ids = wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'meta_key' => '_boutique_demo', 'meta_value' => '1' ] );
foreach ( $ids as $id ) { wc_get_order( $id )->delete( true ); }
WP_CLI::log( 'Removed ' . count( $ids ) . ' demo orders.' );
if ( 'delete' === ( $args[0] ?? '' ) ) { return; }

add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );

mt_srand( 7 );
$vars = [];
foreach ( wc_get_products( [ 'type' => 'variable', 'limit' => -1 ] ) as $p ) { $vars = array_merge( $vars, $p->get_children() ); }
$pay = [ 'bacs' => 'کارت به کارت', 'cod' => 'پرداخت در محل (هنگام تحویل)', 'instore_pos' => 'حضوری — کارت‌خوان', 'instore_cash' => 'حضوری — نقدی' ];
for ( $i = 0; $i < 45; $i++ ) {
	$o = wc_create_order();
	$n = mt_rand( 1, 10 ) > 8 ? 2 : 1;
	for ( $j = 0; $j < $n; $j++ ) { $o->add_product( wc_get_product( $vars[ array_rand( $vars ) ] ), 1 ); }
	$pm = array_rand( $pay );
	$o->set_payment_method( $pm );
	$o->set_payment_method_title( $pay[ $pm ] );
	$o->set_created_via( str_starts_with( $pm, 'instore' ) ? 'instore' : 'checkout' );
	$o->set_billing_first_name( 'مشتری نمونه' );
	$o->set_date_created( time() - mt_rand( 1, 60 ) * DAY_IN_SECONDS - mt_rand( 0, 36000 ) );
	$o->update_meta_data( '_boutique_demo', '1' );
	$o->calculate_totals( false );
	$o->set_status( 'completed' );
	$o->save();
}
WP_CLI::success( 'Created 45 demo orders over the last 60 days.' );
