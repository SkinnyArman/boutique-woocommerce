<?php
defined( 'ABSPATH' ) || exit;

/** True for the shop manager (and anyone else who can run the shop but not the site). */
function boutique_is_manager() {
	return current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' );
}

/** Persian digits -> Latin (for anything typed into a form). */
function boutique_latin_digits( $s ) {
	return strtr( (string) $s, [
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	] );
}

/** Latin -> Persian digits for display. */
function boutique_fa_digits( $s ) {
	return strtr( (string) $s, [ '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ] );
}

/** "۲٬۳۵۰٬۰۰۰ تومان" */
function boutique_money( $amount ) {
	return boutique_fa_digits( number_format( (float) $amount ) ) . ' تومان';
}

/** Orders list URL (works with both order storage modes). */
function boutique_orders_url( $args = [] ) {
	$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$base = $hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
	return add_query_arg( $args, $base );
}

function boutique_order_url( WC_Order $order ) {
	return $order->get_edit_order_url();
}

/** "مشکی / ۳۸" for a variation, or '' for a simple product. */
function boutique_variation_label( WC_Product $p ) {
	if ( ! $p->is_type( 'variation' ) ) { return ''; }
	$parts = [];
	foreach ( $p->get_attributes() as $tax => $slug ) {
		$term    = taxonomy_exists( $tax ) ? get_term_by( 'slug', $slug, $tax ) : false;
		$parts[] = $term ? $term->name : $slug;
	}
	return implode( ' / ', $parts );
}

/** Human time like "۱۴:۰۵" or "دیروز". */
function boutique_when( WC_DateTime $d = null ) {
	if ( ! $d ) { return ''; }
	$today = wp_date( 'Y-m-d' );
	$day   = wp_date( 'Y-m-d', $d->getTimestamp() );
	if ( $day === $today ) { return boutique_fa_digits( wp_date( 'H:i', $d->getTimestamp() ) ); }
	return human_time_diff( $d->getTimestamp() ) . ' پیش';
}
