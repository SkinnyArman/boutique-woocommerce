<?php
/**
 * Load scripts/import/products.json (made by telegram_fetch.py) into WooCommerce.
 *
 *   docker compose --profile cli run --rm wpcli eval-file /scripts/import/import-products.php
 *   ... import-products.php replace     also removes the placeholder dresses + demo orders
 *
 * - Re-running updates the same products (matched by Telegram post number); photos aren't re-uploaded.
 * - Complete products are published. Anything the parser flagged goes in as a draft with the
 *   problem written in the description, for the shop owner to fix in «لباس جدید».
 * - Stock: 1 per listed size (single-piece stock); colors only when the caption names them.
 * - Evening/Turkish dresses posted without a price become «استعلام قیمت» products.
 */
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$dir  = __DIR__;
$data = json_decode( (string) file_get_contents( "$dir/products.json" ), true );
if ( ! is_array( $data ) ) { WP_CLI::error( "No products.json in $dir. Run telegram_fetch.py first." ); }
$replace = in_array( 'replace', $args ?? [], true );
// "only=3352,3357": import just these posts (used on the live site so existing products aren't reset).
foreach ( $args ?? [] as $a ) {
	if ( str_starts_with( $a, 'only=' ) ) {
		$only = array_map( 'intval', explode( ',', substr( $a, 5 ) ) );
		$data = array_values( array_filter( $data, fn( $r ) => in_array( (int) $r['post'], $only, true ) ) );
	}
}

/* ------------------------------------------------ categories + size terms */
$new_cats = [ 'top' => 'تاپ', 'jumpsuit' => 'سرهمی و اورال', 'tunic' => 'تونیک', 'cardigan' => 'ژاکت و پلیور', 'other' => 'سایر' ];
foreach ( $new_cats as $slug => $name ) {
	if ( ! get_term_by( 'slug', $slug, 'product_cat' ) ) {
		$t = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
		if ( ! is_wp_error( $t ) ) { update_term_meta( $t['term_id'], 'order', 30 ); }
	}
}
$casual = get_term_by( 'slug', 'casual', 'product_cat' );
if ( $casual && 'پیراهن روزمره' === $casual->name ) { wp_update_term( $casual->term_id, 'product_cat', [ 'name' => 'پیراهن' ] ); }

$size_order = array_merge( array_map( 'strval', range( 34, 56, 2 ) ), [ 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL' ] );
foreach ( $size_order as $i => $s ) {
	$t = get_term_by( 'slug', strtolower( $s ), 'pa_size' );
	if ( ! $t ) {
		$r = wp_insert_term( $s, 'pa_size', [ 'slug' => strtolower( $s ) ] );
		$t = is_wp_error( $r ) ? null : get_term( $r['term_id'] );
	}
	if ( $t ) { update_term_meta( $t->term_id, 'order', $i ); }
}

/* ------------------------------------------------------------- cleanup */
if ( $replace ) {
	$placeholders = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
		'meta_query' => [ [ 'key' => '_boutique_tg_post', 'compare' => 'NOT EXISTS' ] ] ] );
	foreach ( $placeholders as $pid ) {
		$p = wc_get_product( $pid );
		if ( ! $p || str_contains( $p->get_name(), 'تست خودکار' ) ) { continue; }
		foreach ( array_merge( [ $p->get_image_id() ], $p->get_gallery_image_ids() ) as $att ) { if ( $att ) { wp_delete_attachment( $att, true ); } }
		foreach ( $p->get_children() as $c ) { wp_delete_post( $c, true ); }
		$p->delete( true );
	}
	WP_CLI::log( 'Removed ' . count( $placeholders ) . ' placeholder products.' );
	foreach ( wc_get_orders( [ 'limit' => -1, 'meta_key' => '_boutique_demo', 'meta_value' => '1', 'return' => 'ids' ] ) as $oid ) { wc_get_order( $oid )->delete( true ); }
	foreach ( [ 'manteau', 'sets' ] as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $t ) { wp_delete_term( $t->term_id, 'product_cat' ); }
	}
	delete_option( 'boutique_seeded_products' );
}

/* --------------------------------------------------------------- import */
$photo = function ( $file, $post ) use ( $dir ) {
	$existing = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids',
		'meta_key' => '_boutique_tg_photo', 'meta_value' => $file ] );
	if ( $existing ) { return (int) $existing[0]; }
	$tmp = wp_tempnam( $file );
	copy( "$dir/photos/$file", $tmp );
	$id = media_handle_sideload( [ 'name' => "product-$file", 'tmp_name' => $tmp ], 0 );
	if ( is_wp_error( $id ) ) { WP_CLI::warning( "$file: " . $id->get_error_message() ); return 0; }
	update_post_meta( $id, '_boutique_tg_photo', $file );
	return (int) $id;
};
$term_id = function ( $name, $tax ) {
	$slug = 'pa_size' === $tax ? strtolower( $name ) : sanitize_title( $name );
	$t    = get_term_by( 'slug', $slug, $tax ) ?: get_term_by( 'name', $name, $tax );
	if ( $t ) { return $t->term_id; }
	$r = wp_insert_term( $name, $tax, 'pa_size' === $tax ? [ 'slug' => $slug ] : [] );
	return is_wp_error( $r ) ? 0 : $r['term_id'];
};
$brand_tax = taxonomy_exists( 'product_brand' ) ? 'product_brand' : null;

$made = $drafts = 0;
foreach ( array_reverse( $data ) as $row ) { // oldest first, so "newest" sorting matches the channel
	$found = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
		'meta_key' => '_boutique_tg_post', 'meta_value' => (string) $row['post'] ] );
	$p = $found ? wc_get_product( $found[0] ) : new WC_Product_Variable();
	if ( ! $p->is_type( 'variable' ) ) { continue; }

	$imgs = array_values( array_filter( array_map( fn( $f ) => $photo( $f, $row['post'] ), $row['photos'] ?? [] ) ) );
	$problems = $row['problems'] ?? [];
	$desc = $row['description'];
	if ( $problems ) { $desc = "⚠️ لطفاً بررسی کنید: " . implode( '، ', $problems ) . "\n\nمتن اصلی پست:\n" . $row['caption'] . "\n\n" . $desc; }

	$p->set_name( $row['name'] );
	$p->set_description( $desc );
	$p->set_short_description( $row['description'] );
	$p->set_status( $problems ? 'draft' : 'publish' );
	$cat = get_term_by( 'slug', $row['category'], 'product_cat' );
	$p->set_category_ids( $cat ? [ $cat->term_id ] : [] );
	$p->set_image_id( $imgs[0] ?? 0 );
	$p->set_gallery_image_ids( array_slice( $imgs, 1 ) );
	$p->set_date_created( strtotime( $row['date'] . ' 12:00:00' ) + (int) $row['post'] );

	$colors = $row['colors'] ?: [];
	$sizes  = $row['sizes'] ?: [];
	$attrs  = [];
	if ( $colors ) {
		$a = new WC_Product_Attribute();
		$a->set_id( wc_attribute_taxonomy_id_by_name( 'color' ) ); $a->set_name( 'pa_color' );
		$a->set_options( array_map( fn( $c ) => $term_id( $c, 'pa_color' ), array_keys( $colors ) ) );
		$a->set_visible( true ); $a->set_variation( true ); $attrs[] = $a;
	}
	if ( $sizes ) {
		$a = new WC_Product_Attribute();
		$a->set_id( wc_attribute_taxonomy_id_by_name( 'size' ) ); $a->set_name( 'pa_size' );
		$a->set_options( array_map( fn( $s ) => $term_id( $s, 'pa_size' ), $sizes ) );
		$a->set_visible( true ); $a->set_variation( true ); $attrs[] = $a;
	}
	$p->set_attributes( $attrs );
	$p->update_meta_data( '_boutique_tg_post', (string) $row['post'] );
	$p->update_meta_data( '_boutique_ask_price', ! empty( $row['ask_price'] ) ? 'yes' : '' );
	$pid = $p->save();
	if ( $brand_tax && $row['brand'] ) { wp_set_object_terms( $pid, [ $row['brand'] ], $brand_tax ); }

	// Variations: one per color × size (or per size), stock 1 each.
	foreach ( $p->get_children() as $c ) { wp_delete_post( $c, true ); }
	$combos = [];
	if ( $colors ) { foreach ( $colors as $c => $cs ) { foreach ( $cs as $s ) { $combos[] = [ $c, $s ]; } } }
	else { foreach ( ( $sizes ?: [ null ] ) as $s ) { $combos[] = [ null, $s ]; } }
	foreach ( $combos as [ $c, $s ] ) {
		if ( null === $s && null === $c ) { continue; }
		$v  = new WC_Product_Variation();
		$va = [];
		if ( $c ) { $va['pa_color'] = get_term( $term_id( $c, 'pa_color' ) )->slug; }
		if ( $s ) { $va['pa_size'] = strtolower( $s ); }
		$v->set_parent_id( $pid );
		$v->set_attributes( $va );
		if ( $row['price'] ) { $v->set_regular_price( (string) $row['price'] ); }
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 1 );
		$v->set_status( 'publish' );
		$v->save();
	}
	WC_Product_Variable::sync( $pid );
	wc_delete_product_transients( $pid );
	$problems ? $drafts++ : $made++;
	WP_CLI::log( sprintf( '%s #%d %s', $problems ? 'draft    ' : 'published', $row['post'], $row['name'] ) );
}

// Category tiles use their newest product's photo (full imports only: an only= run on the live
// site must not wipe category photos the owner picked).
if ( empty( $only ) ) {
	foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] ) as $t ) { delete_term_meta( $t->term_id, 'thumbnail_id' ); }
}

WP_CLI::success( "$made published, $drafts drafts to review." );
