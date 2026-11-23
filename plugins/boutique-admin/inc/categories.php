<?php
/**
 * «دسته‌بندی‌ها»: the shop manager sees every category and adds new ones. Categories can't be
 * deleted here (or anywhere, by the manager): products and site links depend on them.
 * A new category, however it's made (this page, an import, WooCommerce), goes to the end of the
 * list and gets a link in the site menu under «دسته‌بندی‌ها», so nothing else needs editing.
 */
defined( 'ABSPATH' ) || exit;

function boutique_admin_categories_url( $args = [] ) {
	return add_query_arg( array_merge( [ 'page' => 'boutique-categories' ], $args ), admin_url( 'admin.php' ) );
}

/** Top-level product categories in the shop's order (without the default «سایر»). */
function boutique_category_list() {
	$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0, 'exclude' => [ (int) get_option( 'default_product_cat' ) ] ] );
	if ( is_wp_error( $terms ) ) { return []; }
	usort( $terms, fn( $a, $b ) => [ (int) get_term_meta( $a->term_id, 'order', true ), $a->term_id ] <=> [ (int) get_term_meta( $b->term_id, 'order', true ), $b->term_id ] );
	return $terms;
}

/* ------------------------------------------------- New category → end of list + site menu */
// (WooCommerce gives every new category order 0 just before this runs, which would put it first.
// Scripts that want a specific place set 'order' themselves after inserting.)
add_action( 'created_product_cat', function ( $term_id ) {
	$max = 0;
	foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ] ) as $id ) {
		if ( (int) $id !== (int) $term_id ) { $max = max( $max, (int) get_term_meta( $id, 'order', true ) ); }
	}
	update_term_meta( $term_id, 'order', $max + 1 );
	boutique_menu_add_category( $term_id );
}, 20 );

/** Adds a top-level category under «دسته‌بندی‌ها» in the main menu (after the last one). Safe to repeat. */
function boutique_menu_add_category( $term_id ) {
	$t = get_term( (int) $term_id, 'product_cat' );
	if ( ! $t || is_wp_error( $t ) || $t->parent || (int) $t->term_id === (int) get_option( 'default_product_cat' ) ) { return false; }
	$menu = wp_get_nav_menu_object( 'منوی اصلی' );
	if ( ! $menu ) { return false; }
	$items  = wp_get_nav_menu_items( $menu->term_id, [ 'post_status' => 'any' ] ) ?: [];
	$parent = null;
	foreach ( $items as $i ) {
		if ( ! $i->menu_item_parent && ( in_array( 'b-menu-cats', (array) $i->classes, true ) || 'دسته‌بندی‌ها' === $i->title ) ) { $parent = $i; }
	}
	if ( ! $parent ) { return false; }
	foreach ( $items as $i ) {
		if ( 'product_cat' === $i->object && (int) $i->object_id === (int) $t->term_id ) { return false; }
	}
	// Right after the last item under «دسته‌بندی‌ها»; the items after it move down one.
	$pos = (int) $parent->menu_order;
	foreach ( $items as $i ) { if ( (int) $i->menu_item_parent === (int) $parent->ID ) { $pos = max( $pos, (int) $i->menu_order ); } }
	$pos++;
	foreach ( $items as $i ) {
		if ( (int) $i->menu_order >= $pos ) { wp_update_post( [ 'ID' => $i->ID, 'menu_order' => (int) $i->menu_order + 1 ] ); }
	}
	return wp_update_nav_menu_item( $menu->term_id, 0, [
		'menu-item-status'    => 'publish',
		'menu-item-object'    => 'product_cat',
		'menu-item-object-id' => $t->term_id,
		'menu-item-type'      => 'taxonomy',
		'menu-item-parent-id' => $parent->ID,
		'menu-item-position'  => $pos,
	] );
}

/* ------------------------------------------------- No deleting (except a full administrator) */
add_filter( 'map_meta_cap', function ( $caps, $cap, $user_id, $args ) {
	if ( 'delete_term' === $cap && ! empty( $args[0] ) && ! user_can( $user_id, 'manage_options' ) ) {
		$t = get_term( (int) $args[0] );
		if ( $t && ! is_wp_error( $t ) && 'product_cat' === $t->taxonomy ) { return [ 'do_not_allow' ]; }
	}
	return $caps;
}, 10, 4 );

/* ------------------------------------------------- Menu + routing */
add_action( 'admin_menu', function () {
	add_menu_page( 'دسته‌بندی‌ها', 'دسته‌بندی‌ها', 'manage_product_terms', 'boutique-categories', 'boutique_categories_page', 'dashicons-category', '5.2' );
} );

// The manager's «دسته‌بندی‌ها» is this page, not WordPress's (which has delete links).
add_action( 'load-edit-tags.php', function () {
	if ( boutique_is_manager() && 'product_cat' === ( $_GET['taxonomy'] ?? '' ) ) {
		wp_safe_redirect( boutique_admin_categories_url() );
		exit;
	}
} );

add_action( 'admin_post_boutique_category_add', function () {
	check_admin_referer( 'boutique_category_add' );
	if ( ! current_user_can( 'manage_product_terms' ) ) { wp_die( 'اجازه ندارید.' ); }
	$name = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) ) );
	if ( '' === $name ) {
		wp_safe_redirect( boutique_admin_categories_url( [ 'err' => 'empty' ] ) );
		exit;
	}
	foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] ) as $t ) {
		if ( $t->name === $name ) {
			wp_safe_redirect( boutique_admin_categories_url( [ 'err' => 'exists', 'name' => rawurlencode( $name ) ] ) );
			exit;
		}
	}
	$r = wp_insert_term( $name, 'product_cat' );
	if ( is_wp_error( $r ) ) {
		wp_safe_redirect( boutique_admin_categories_url( [ 'err' => 'exists', 'name' => rawurlencode( $name ) ] ) );
		exit;
	}
	wp_safe_redirect( boutique_admin_categories_url( [ 'added' => (int) $r['term_id'] ] ) );
	exit;
} );

/* ------------------------------------------------- Page */
function boutique_categories_page() {
	$cats  = boutique_category_list();
	$added = (int) ( $_GET['added'] ?? 0 );
	$err   = sanitize_key( $_GET['err'] ?? '' );
	$name  = sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) );
	$new   = $added ? get_term( $added, 'product_cat' ) : null;
	// Every product (drafts too) per category, so the numbers match «لباس‌ها».
	$count = function ( $t ) {
		return count( wc_get_products( [ 'status' => [ 'publish', 'draft', 'pending', 'private' ], 'limit' => -1, 'return' => 'ids', 'category' => [ $t->slug ] ] ) );
	};
	?>
	<div class="wrap b-wrap b-cats">
		<header class="b-head">
			<div>
				<h1 class="b-title">دسته‌بندی‌ها</h1>
				<p class="b-muted">دسته‌های فروشگاه. دسته جدید فوراً در فرم «لباس جدید» و منوی سایت می‌آید. دسته‌ای که هنوز لباس ندارد، در صفحه اصلی نشان داده نمی‌شود.</p>
			</div>
		</header>

		<?php if ( $new && ! is_wp_error( $new ) ) : ?>
			<div class="b-done" role="status"><strong>دسته «<?php echo esc_html( $new->name ); ?>» اضافه شد.</strong>
				حالا در فرم «لباس جدید» قابل انتخاب است و در منوی سایت هم آمده.
				<a href="<?php echo esc_url( boutique_product_url() ); ?>">+ لباس جدید</a></div>
		<?php elseif ( 'empty' === $err ) : ?>
			<div class="notice notice-error"><p>اسم دسته را بنویسید.</p></div>
		<?php elseif ( 'exists' === $err ) : ?>
			<div class="notice notice-error"><p>دسته «<?php echo esc_html( $name ); ?>» از قبل هست.</p></div>
		<?php endif; ?>

		<section class="b-panel b-cats__add">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="boutique_category_add">
				<?php wp_nonce_field( 'boutique_category_add' ); ?>
				<label for="b-cat-name"><strong>دسته جدید</strong></label>
				<div class="b-cats__row">
					<input type="text" id="b-cat-name" name="name" maxlength="60" required placeholder="مثلاً: جوراب" autocomplete="off">
					<button type="submit" class="b-btn">افزودن</button>
				</div>
			</form>
		</section>

		<section class="b-panel">
			<h2>همه دسته‌ها <span class="b-count"><?php echo esc_html( boutique_fa_digits( count( $cats ) ) ); ?></span></h2>
			<ul class="b-catlist" role="list">
				<?php foreach ( $cats as $t ) : $n = $count( $t ); ?>
					<li class="<?php echo $added === (int) $t->term_id ? 'is-new' : ''; ?>">
						<strong><?php echo esc_html( $t->name ); ?></strong>
						<span class="b-muted"><?php echo $n ? esc_html( boutique_fa_digits( $n ) . ' لباس' ) : 'خالی'; ?></span>
						<span class="b-catlist__links">
							<?php if ( $n ) : ?><a href="<?php echo esc_url( boutique_products_page_url( [ 'cat' => $t->slug ] ) ); ?>">لباس‌ها</a> · <?php endif; ?>
							<a href="<?php echo esc_url( get_term_link( $t ) ); ?>" target="_blank" rel="noopener">در سایت</a>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>
	<?php
}
