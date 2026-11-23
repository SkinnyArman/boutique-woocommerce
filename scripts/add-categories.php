<?php
/**
 * Adds the shop's newer categories, splits «ژاکت و پلیور» into «ژاکت» + «پلیور», and moves the
 * products that belong in the new categories (by their name, or their description if the name
 * says nothing). Prints every change. Safe to re-run.
 * Local:  docker compose --profile cli run --rm wpcli eval-file /scripts/add-categories.php
 * Live (no WP-CLI): run once through a temporary web runner that includes wp-load.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	$root = rtrim( $argv[1] ?? __DIR__, '/' );
	require $root . '/wp-load.php';
}

$new = [
	'shawl'          => 'شال',
	'hat'            => 'کلاه',
	'socks'          => 'جوراب',
	'sport-dress'    => 'پیراهن اسپرت',
	'leggings'       => 'لگ',
	'sport-leggings' => 'لگ ورزشی',
	'pullover'       => 'پلیور',
];

$ids = [];
$created = [];
foreach ( $new as $slug => $name ) {
	$t = get_term_by( 'slug', $slug, 'product_cat' ) ?: get_term_by( 'name', $name, 'product_cat' );
	if ( $t ) {
		$ids[ $slug ] = (int) $t->term_id;
		echo "exists $slug ({$t->term_id})\n";
		continue;
	}
	$r = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );   // the plugin puts it last + in the menu
	if ( is_wp_error( $r ) ) { echo "ERROR $slug: " . $r->get_error_message() . "\n"; continue; }
	$ids[ $slug ] = (int) $r['term_id'];
	$created[ $slug ] = true;
	echo "created $slug ({$r['term_id']})\n";
}
// Terms made before the plugin hook existed: make sure each has its menu link.
foreach ( $ids as $slug => $id ) {
	if ( function_exists( 'boutique_menu_add_category' ) && boutique_menu_add_category( $id ) ) { echo "menu link added for $slug\n"; }
}

/* ------------------------------------------------ «ژاکت و پلیور» → «ژاکت» */
$cardigan = get_term_by( 'slug', 'cardigan', 'product_cat' );
if ( $cardigan && 'ژاکت و پلیور' === $cardigan->name ) {
	wp_update_term( $cardigan->term_id, 'product_cat', [ 'name' => 'ژاکت' ] );
	echo "renamed cardigan: ژاکت و پلیور → ژاکت\n";
}
// A new «پلیور» sits next to «ژاکت» in the list.
if ( $cardigan && ! empty( $created['pullover'] ) ) {
	update_term_meta( $ids['pullover'], 'order', (int) get_term_meta( $cardigan->term_id, 'order', true ) );
}

/* ------------------------------------------------ Move products by keyword */
$all_status = [ 'publish', 'draft', 'pending', 'private' ];
$text_of    = function ( WC_Product $p ) {
	return [ $p->get_name(), wp_strip_all_tags( $p->get_description() . ' ' . $p->get_short_description() ) ];
};
/**
 * $from: slug the products are in now; $rules: [ regex => target slug ] (first match wins);
 * $keep: regex that, if the text also matches it, keeps the product in $from too.
 */
$move = function ( $from, array $rules, $keep = null ) use ( $ids, $all_status, $text_of ) {
	$src = get_term_by( 'slug', $from, 'product_cat' );
	if ( ! $src ) { return; }
	foreach ( wc_get_products( [ 'status' => $all_status, 'limit' => -1, 'category' => [ $from ] ] ) as $p ) {
		[ $name, $desc ] = $text_of( $p );
		$target = null;
		foreach ( [ $name, $desc ] as $hay ) {           // the name decides; the description only if the name is silent
			foreach ( $rules as $re => $slug ) {
				if ( preg_match( $re, $hay ) ) { $target = $slug; break 2; }
			}
			if ( $keep && preg_match( $keep, $hay ) ) { break; }
		}
		if ( ! $target || empty( $ids[ $target ] ) ) { continue; }
		$cats = $p->get_category_ids();
		$cats[] = $ids[ $target ];
		if ( ! ( $keep && preg_match( $keep, $hay ) ) ) { $cats = array_diff( $cats, [ $src->term_id ] ); }
		$cats = array_values( array_unique( array_map( 'intval', $cats ) ) );
		if ( $cats == array_map( 'intval', $p->get_category_ids() ) ) { continue; }
		$p->set_category_ids( $cats );
		$p->save();
		echo "moved #{$p->get_id()} «{$name}»: $from → $target\n";
	}
};

$move( 'cardigan', [ '/پلیور|پليور|پولیور/u' => 'pullover' ], '/ژاکت|کاردیگان/u' );
$move( 'pants', [ '/لگ\s*ورزشی/u' => 'sport-leggings', '/(^|\s)لگ(\s|$)/u' => 'leggings' ] );
$move( 'casual', [ '/(پیراهن|پیرهن)\s*اسپرت/u' => 'sport-dress' ] );

foreach ( [ 'cardigan', 'pullover', 'leggings', 'sport-leggings', 'sport-dress' ] as $slug ) {
	$t = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $t ) { echo "now in «{$t->name}»: " . count( wc_get_products( [ 'status' => $all_status, 'limit' => -1, 'return' => 'ids', 'category' => [ $slug ] ] ) ) . "\n"; }
}
echo "done\n";
