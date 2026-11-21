<?php
/**
 * Adds new product categories and puts them in the main menu under «دسته‌بندی‌ها».
 * Safe to re-run. Local:  docker compose --profile cli run --rm wpcli eval-file /scripts/add-categories.php
 * Live (no WP-CLI):      php add-categories.php /path/to/public_html
 */
if ( ! defined( 'ABSPATH' ) ) {
	$root = rtrim( $argv[1] ?? __DIR__, '/' );
	require $root . '/wp-load.php';
}

$new = [ 'shawl' => 'شال', 'hat' => 'کلاه', 'socks' => 'جوراب' ];

$menu   = wp_get_nav_menu_object( 'منوی اصلی' );
$items  = $menu ? wp_get_nav_menu_items( $menu->term_id ) : [];
$parent = null;
foreach ( $items as $i ) { if ( 'دسته‌بندی‌ها' === $i->title && ! $i->menu_item_parent ) { $parent = $i; } }

foreach ( $new as $slug => $name ) {
	$t = get_term_by( 'slug', $slug, 'product_cat' );
	if ( ! $t ) {
		$r = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
		if ( is_wp_error( $r ) ) { echo "ERROR $slug: " . $r->get_error_message() . "\n"; continue; }
		$id = $r['term_id'];
		echo "created $slug ($id)\n";
	} else {
		$id = $t->term_id;
		echo "exists $slug ($id)\n";
	}
	update_term_meta( $id, 'order', 40 );

	if ( ! $parent ) { echo "no «دسته‌بندی‌ها» menu item, skipped menu\n"; continue; }
	$items = wp_get_nav_menu_items( $menu->term_id );
	$in    = array_filter( $items, fn( $i ) => 'product_cat' === $i->object && (int) $i->object_id === (int) $id );
	if ( $in ) { continue; }
	// Position: right after the last item under «دسته‌بندی‌ها»; push the items after it down by one.
	$pos = (int) $parent->menu_order;
	foreach ( $items as $i ) { if ( (int) $i->menu_item_parent === (int) $parent->ID ) { $pos = max( $pos, (int) $i->menu_order ); } }
	$pos++;
	foreach ( $items as $i ) {
		if ( (int) $i->menu_order >= $pos ) {
			wp_update_post( [ 'ID' => $i->ID, 'menu_order' => (int) $i->menu_order + 1 ] );
		}
	}
	wp_update_nav_menu_item( $menu->term_id, 0, [
		'menu-item-status'    => 'publish',
		'menu-item-object'    => 'product_cat',
		'menu-item-object-id' => $id,
		'menu-item-type'      => 'taxonomy',
		'menu-item-parent-id' => $parent->ID,
		'menu-item-position'  => $pos,
	] );
	echo "menu item added for $slug at $pos\n";
}
