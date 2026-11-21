<?php
/**
 * Turn a COPY of the local database into the production one. Never run on the local site.
 * Keeps: products/photos/categories/brands from the Telegram import, pages, FAQ, menus, settings.
 * Removes: every order (putting back any stock they held), coupons, sale prices, test leftovers,
 * customer accounts, sessions, transients.
 *
 * Run: docker compose --profile cli run --rm -e WORDPRESS_DB_NAME=shop_prod wpcli eval-file /scripts/deploy/prod-clean.php
 */
if ( DB_NAME === 'shop' ) { WP_CLI::error( 'Refusing to run on the local database.' ); }

// 1. Orders: return reserved stock first, then delete (incl. refunds).
$n = 0;
foreach ( wc_get_orders( [ 'limit' => -1, 'status' => 'any', 'type' => 'shop_order' ] ) as $o ) {
	if ( ! $o->has_status( [ 'cancelled', 'refunded', 'failed' ] ) && $o->get_data_store()->get_stock_reduced( $o->get_id() ) ) {
		wc_increase_stock_levels( $o );
	}
	$o->delete( true );
	$n++;
}
WP_CLI::log( "Orders deleted: $n" );

// 2. Coupons.
$c = get_posts( [ 'post_type' => 'shop_coupon', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
foreach ( $c as $id ) { wp_delete_post( $id, true ); }
WP_CLI::log( 'Coupons deleted: ' . count( $c ) );

// 3. Test leftovers: products named «…تست…», test colors.
$t = 0;
foreach ( wc_get_products( [ 'limit' => -1, 'status' => 'any' ] ) as $p ) {
	if ( false !== mb_strpos( $p->get_name(), 'تست' ) ) {
		foreach ( $p->get_children() as $vid ) { wp_delete_post( $vid, true ); }
		$p->delete( true );
		$t++;
	}
}
foreach ( get_terms( [ 'taxonomy' => 'pa_color', 'hide_empty' => false ] ) as $term ) {
	if ( false !== mb_strpos( $term->name, 'تست' ) ) { wp_delete_term( $term->term_id, 'pa_color' ); }
}
WP_CLI::log( "Test products deleted: $t" );

// 4. Sale prices (the ones added for testing).
$s = 0;
foreach ( wc_get_products( [ 'limit' => -1, 'status' => 'any', 'type' => [ 'simple', 'variable' ] ] ) as $p ) {
	foreach ( $p->is_type( 'variable' ) ? $p->get_children() : [ $p->get_id() ] as $vid ) {
		$v = wc_get_product( $vid );
		if ( $v && '' !== $v->get_sale_price() ) {
			$v->set_sale_price( '' ); $v->set_date_on_sale_from( null ); $v->set_date_on_sale_to( null ); $v->save(); $s++;
		}
	}
	if ( $p->is_type( 'variable' ) ) { WC_Product_Variable::sync( $p->get_id() ); }
	wc_delete_product_transients( $p->get_id() );
}
delete_transient( 'wc_products_onsale' );
WP_CLI::log( "Sale prices removed: $s" );

// 5. Customer accounts (keep admin + manager).
require_once ABSPATH . 'wp-admin/includes/user.php';
$u = 0;
foreach ( get_users( [ 'role__not_in' => [ 'administrator', 'shop_manager' ] ] ) as $user ) { wp_delete_user( $user->ID ); $u++; }
WP_CLI::log( "Customer accounts deleted: $u" );

// 6. Sessions, transients, receipt metadata, logs of finished background jobs.
global $wpdb;
$wpdb->query( "TRUNCATE {$wpdb->prefix}woocommerce_sessions" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE status IN ('complete','failed','canceled')" );
$wpdb->query( "TRUNCATE {$wpdb->prefix}actionscheduler_logs" );
foreach ( get_users( [ 'role__in' => [ 'administrator', 'shop_manager' ] ] ) as $user ) { WP_Session_Tokens::get_instance( $user->ID )->destroy_all(); }

// 7. Production settings.
update_option( 'admin_email', boutique_shop( 'email' ) );
update_option( 'new_admin_email', boutique_shop( 'email' ) );
update_option( 'woocommerce_email_from_address', boutique_shop( 'email' ) );
update_option( 'woocommerce_email_from_name', boutique_shop( 'name' ) );
update_option( 'blog_public', 1 );
update_option( 'woocommerce_coming_soon', 'no' );
delete_option( 'boutique_courier_instance_check' );
WP_CLI::success( 'Production copy cleaned.' );
