<?php
/**
 * «سفارش‌ها» and «لباس‌ها»: calm lists in the shop's admin style, replacing WooCommerce's list
 * tables for the shop manager (bulk actions, five filter dropdowns, export buttons, rating nag).
 * WooCommerce's full screens stay one click away («جزئیات» / «نمای کامل»).
 */
defined( 'ABSPATH' ) || exit;

function boutique_orders_page_url( $args = [] ) { return add_query_arg( array_merge( [ 'page' => 'boutique-orders' ], $args ), admin_url( 'admin.php' ) ); }
function boutique_products_page_url( $args = [] ) { return add_query_arg( array_merge( [ 'page' => 'boutique-products' ], $args ), admin_url( 'admin.php' ) ); }

add_action( 'admin_menu', function () {
	$waiting = count( wc_get_orders( [ 'status' => [ 'on-hold', 'processing' ], 'limit' => 50, 'return' => 'ids' ] ) );
	add_menu_page( 'سفارش‌ها', 'سفارش‌ها' . ( $waiting ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $waiting . '</span></span>' : '' ), 'edit_shop_orders', 'boutique-orders', 'boutique_orders_page', 'dashicons-list-view', 4 );
	add_menu_page( 'لباس‌ها', 'لباس‌ها', 'edit_products', 'boutique-products', 'boutique_products_page', 'dashicons-tag', 5 );
	if ( boutique_is_manager() ) {
		// Our lists replace WooCommerce's for her; categories/brands are linked from «لباس‌ها».
		remove_menu_page( 'woocommerce' );
		remove_menu_page( 'edit.php?post_type=product' );
	}
}, 1000 );

/* ===================================================================== Orders */
function boutique_order_tabs() {
	return [
		'all'        => [ 'همه', [ 'status' => array_keys( wc_get_order_statuses() ) ] ],
		'onhold'     => [ 'منتظر واریز', [ 'status' => [ 'on-hold', 'pending' ] ] ],
		'ship'       => [ 'آماده ارسال', [ 'status' => [ 'processing' ] ], fn( $o ) => ! boutique_order_is_pickup( $o ) ],
		'pickup'     => [ 'تحویل حضوری', [ 'status' => [ 'processing' ] ], fn( $o ) => boutique_order_is_pickup( $o ) ],
		'done'       => [ 'ارسال / تحویل شده', [ 'status' => [ 'completed' ] ] ],
		'cancelled'  => [ 'لغو شده', [ 'status' => [ 'cancelled', 'failed', 'refunded' ] ] ],
	];
}

function boutique_status_chip( WC_Order $o ) {
	$map = [
		'pending' => [ 'منتظر واریز', 'is-wait' ], 'on-hold' => [ 'منتظر واریز', 'is-wait' ],
		'processing' => [ boutique_order_is_pickup( $o ) ? ( boutique_pickup_ready_at( $o ) ? 'آماده؛ منتظر مشتری' : 'تحویل حضوری' ) : 'آماده ارسال', 'is-go' ],
		'completed' => [ 'instore' === $o->get_created_via() ? 'فروش حضوری' : ( boutique_order_is_pickup( $o ) ? 'تحویل داده شد' : 'ارسال شد' ), 'is-done' ],
		'cancelled' => [ 'لغو شده', 'is-off' ], 'failed' => [ 'ناموفق', 'is-off' ], 'refunded' => [ 'برگشت وجه', 'is-off' ],
	];
	[ $label, $cls ] = $map[ $o->get_status() ] ?? [ wc_get_order_status_name( $o->get_status() ), '' ];
	return '<span class="b-state ' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
}

function boutique_orders_page() {
	$tabs = boutique_order_tabs();
	$tab  = sanitize_key( $_GET['tab'] ?? 'all' );
	$tab  = isset( $tabs[ $tab ] ) ? $tab : 'all';
	$q    = trim( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ) );
	$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$per   = 20;

	// Counts per tab (small shop: a few hundred orders at most a year).
	$counts = [];
	foreach ( $tabs as $k => $t ) {
		$ids = wc_get_orders( $t[1] + [ 'limit' => -1, 'type' => 'shop_order' ] );
		$counts[ $k ] = isset( $t[2] ) ? count( array_filter( $ids, $t[2] ) ) : count( $ids );
	}

	$args = $tabs[ $tab ][1] + [ 'limit' => -1, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC' ];
	$list = wc_get_orders( $args );
	if ( isset( $tabs[ $tab ][2] ) ) { $list = array_values( array_filter( $list, $tabs[ $tab ][2] ) ); }
	if ( '' !== $q ) {
		$needle = boutique_latin_digits( $q );
		$key    = boutique_phone_key( $needle );
		$list   = array_values( array_filter( $list, function ( $o ) use ( $needle, $key ) {
			$name = $o->get_billing_first_name() . ' ' . $o->get_billing_last_name();
			return (string) $o->get_order_number() === ltrim( $needle, '#' )
				|| false !== mb_stripos( $name, $needle )
				|| ( $key && boutique_phone_key( $o->get_billing_phone() ) === $key )
				|| ( strlen( preg_replace( '/\D/', '', $needle ) ) >= 4 && false !== strpos( preg_replace( '/\D/', '', $o->get_billing_phone() ), preg_replace( '/\D/', '', $needle ) ) );
		} ) );
	}
	$total = count( $list );
	$list  = array_slice( $list, ( $paged - 1 ) * $per, $per );
	?>
	<div class="wrap b-wrap b-list">
		<header class="b-head">
			<div>
				<h1 class="b-title">سفارش‌ها</h1>
				<p class="b-muted">همه سفارش‌های آنلاین و فروش‌های حضوری. کارهای امروز را از صفحه «امروز» انجام دهید.</p>
			</div>
			<div class="b-head__actions">
				<a class="b-btn b-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=boutique-today' ) ); ?>">امروز</a>
			</div>
		</header>

		<nav class="b-tabs" aria-label="وضعیت سفارش">
			<?php foreach ( $tabs as $k => $t ) : ?>
				<a class="<?php echo $k === $tab ? 'is-on' : ''; ?>" href="<?php echo esc_url( boutique_orders_page_url( array_filter( [ 'tab' => $k, 'q' => $q ] ) ) ); ?>"<?php echo $k === $tab ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $t[0] ); ?> <span><?php echo esc_html( boutique_fa_digits( $counts[ $k ] ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<form class="b-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="boutique-orders"><input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<label class="screen-reader-text" for="b-oq">جستجوی سفارش</label>
			<input type="search" id="b-oq" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="شماره سفارش، نام یا موبایل مشتری">
			<button type="submit" class="b-btn">جستجو</button>
			<?php if ( '' !== $q ) : ?><a class="b-btn b-btn--quiet" href="<?php echo esc_url( boutique_orders_page_url( [ 'tab' => $tab ] ) ); ?>">پاک کردن</a><?php endif; ?>
		</form>

		<section class="b-panel b-orders">
			<?php if ( ! $list ) : ?>
				<p class="b-empty"><?php echo '' !== $q ? 'سفارشی با این مشخصات پیدا نشد.' : 'سفارشی در این بخش نیست.'; ?></p>
			<?php else : foreach ( $list as $o ) : ?>
				<article class="b-order">
					<div class="b-order__top"><?php echo boutique_status_chip( $o ); // phpcs:ignore ?></div>
					<?php boutique_order_line( $o ); ?>
					<div class="b-order__actions">
						<a class="b-btn b-btn--ghost" href="<?php echo esc_url( boutique_order_url( $o ) ); ?>">جزئیات</a>
						<?php if ( $o->has_status( [ 'on-hold', 'processing' ] ) ) : ?><a class="b-btn b-btn--quiet" href="<?php echo esc_url( admin_url( 'admin.php?page=boutique-today' ) ); ?>">رسیدگی در «امروز»</a><?php endif; ?>
					</div>
				</article>
			<?php endforeach; endif; ?>
		</section>
		<?php boutique_pager( $total, $per, $paged, fn( $p ) => boutique_orders_page_url( array_filter( [ 'tab' => $tab, 'q' => $q, 'paged' => $p ] ) ) ); ?>
		<p class="b-foot"><a href="<?php echo esc_url( boutique_orders_url() ); ?>">نمای کامل ووکامرس</a></p>
	</div>
	<?php
}

function boutique_pager( $total, $per, $paged, callable $url ) {
	$pages = (int) ceil( $total / $per );
	if ( $pages < 2 ) { return; }
	echo '<nav class="b-pager" aria-label="صفحه‌ها">';
	for ( $p = 1; $p <= $pages; $p++ ) {
		echo $p === $paged ? '<span aria-current="page">' . esc_html( boutique_fa_digits( $p ) ) . '</span>' : '<a href="' . esc_url( $url( $p ) ) . '">' . esc_html( boutique_fa_digits( $p ) ) . '</a>';
	}
	echo '</nav>';
}

/* =================================================================== Products */
function boutique_product_stock_summary( WC_Product $p ) {
	$kids = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : [ $p ];
	$total = $out = 0;
	foreach ( $kids as $k ) {
		$q = $k->managing_stock() ? (int) $k->get_stock_quantity() : ( $k->is_in_stock() ? 1 : 0 );
		$total += max( 0, $q );
		if ( $q <= 0 ) { $out++; }
	}
	return [ $total, $out, count( $kids ) ];
}

/* ------------------------------------------------ Delete a draft */
/** «حذف» button for a draft (published items can't be deleted from here). */
function boutique_draft_delete_form( WC_Product $p, $from = 'list' ) {
	if ( 'publish' === $p->get_status() || ! current_user_can( 'delete_post', $p->get_id() ) ) { return; } ?>
	<form class="b-delform" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( '«' . $p->get_name() . '» حذف شود؟' ); ?>')">
		<input type="hidden" name="action" value="boutique_product_delete">
		<input type="hidden" name="product" value="<?php echo (int) $p->get_id(); ?>">
		<?php wp_nonce_field( 'boutique_product_delete_' . $p->get_id() ); ?>
		<button type="submit" class="b-btn <?php echo 'editor' === $from ? 'b-btn--ghost' : 'b-btn--quiet'; ?> b-btn--del">حذف</button>
	</form>
	<?php
}

add_action( 'admin_post_boutique_product_delete', function () {
	$id = (int) ( $_POST['product'] ?? 0 );
	check_admin_referer( 'boutique_product_delete_' . $id );
	$p = wc_get_product( $id );
	if ( ! $p || 'publish' === $p->get_status() || ! current_user_can( 'delete_post', $id ) ) { wp_die( 'این لباس را نمی‌شود حذف کرد.' ); }
	$name = $p->get_name();
	wp_trash_post( $id ); // to the trash (WordPress empties it after 30 days), so a mistake can still be undone
	wc_delete_product_transients( $id );
	wp_safe_redirect( boutique_products_page_url( [ 'deleted' => rawurlencode( $name ) ] ) );
	exit;
} );

function boutique_products_page() {
	$tab   = sanitize_key( $_GET['tab'] ?? 'all' );
	$cat   = sanitize_title( wp_unslash( $_GET['cat'] ?? '' ) );
	$q     = trim( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ) );
	$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$per   = 24;

	$all = wc_get_products( [ 'status' => [ 'publish', 'draft', 'pending', 'private' ], 'limit' => -1, 'orderby' => 'date', 'order' => 'DESC', 'type' => [ 'variable', 'simple' ] ] );
	$tabs = [
		'all'     => [ 'همه', fn( $p ) => true ],
		'publish' => [ 'در سایت', fn( $p ) => 'publish' === $p->get_status() ],
		'draft'   => [ 'پیش‌نویس', fn( $p ) => 'publish' !== $p->get_status() ],
		'out'     => [ 'تمام‌شده', fn( $p ) => 'publish' === $p->get_status() && 0 === boutique_product_stock_summary( $p )[0] ],
		'sale'    => [ 'حراج', fn( $p ) => $p->is_on_sale() ],
	];
	$tab = isset( $tabs[ $tab ] ) ? $tab : 'all';
	$counts = array_map( fn( $t ) => count( array_filter( $all, $t[1] ) ), $tabs );

	$list = array_values( array_filter( $all, $tabs[ $tab ][1] ) );
	if ( $cat ) { $list = array_values( array_filter( $list, fn( $p ) => has_term( $cat, 'product_cat', $p->get_id() ) ) ); }
	if ( '' !== $q ) { $list = array_values( array_filter( $list, fn( $p ) => false !== mb_stripos( $p->get_name(), $q ) ) ); }
	$total = count( $list );
	$list  = array_slice( $list, ( $paged - 1 ) * $per, $per );
	$cats  = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'exclude' => [ (int) get_option( 'default_product_cat' ) ] ] );
	$keep  = array_filter( [ 'tab' => $tab, 'cat' => $cat, 'q' => $q ] );
	?>
	<div class="wrap b-wrap b-list">
		<header class="b-head">
			<div>
				<h1 class="b-title">لباس‌ها</h1>
				<p class="b-muted">همه لباس‌های فروشگاه. برای تغییر قیمت، عکس، سایز و توضیحات «ویرایش» را بزنید؛ برای تعداد، «موجودی».</p>
			</div>
			<div class="b-head__actions">
				<a class="b-btn b-btn--big" href="<?php echo esc_url( boutique_product_url() ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> لباس جدید</a>
			</div>
		</header>

		<?php
		$saved = sanitize_key( $_GET['saved'] ?? '' );
		$sp    = $saved ? wc_get_product( (int) ( $_GET['item'] ?? 0 ) ) : null;
		if ( $sp ) : ?>
			<div class="b-done" role="status" data-item="<?php echo (int) $sp->get_id(); ?>"><strong>«<?php echo esc_html( $sp->get_name() ); ?>» <?php echo 'publish' === $saved ? 'ذخیره شد و در سایت نمایش داده می‌شود.' : 'به‌صورت پیش‌نویس ذخیره شد (هنوز در سایت نیست).'; ?></strong>
				<a href="<?php echo esc_url( boutique_product_url( $sp->get_id() ) ); ?>">ویرایش دوباره</a> ·
				<?php if ( 'publish' === $saved ) : ?><a href="<?php echo esc_url( $sp->get_permalink() ); ?>" target="_blank" rel="noopener">دیدن در سایت</a> · <?php endif; ?>
				<a href="<?php echo esc_url( boutique_product_url() ); ?>">+ یک لباس دیگر</a></div>
		<?php elseif ( isset( $_GET['deleted'] ) ) : ?>
			<div class="b-done" role="status"><strong>«<?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['deleted'] ) ) ); ?>» حذف شد.</strong></div>
		<?php endif; ?>

		<nav class="b-tabs" aria-label="نوع لباس">
			<?php foreach ( $tabs as $k => $t ) : ?>
				<a class="<?php echo $k === $tab ? 'is-on' : ''; ?>" href="<?php echo esc_url( boutique_products_page_url( array_filter( [ 'tab' => $k, 'cat' => $cat, 'q' => $q ] ) ) ); ?>"<?php echo $k === $tab ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $t[0] ); ?> <span><?php echo esc_html( boutique_fa_digits( $counts[ $k ] ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<form class="b-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="boutique-products"><input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<label class="screen-reader-text" for="b-pq">جستجوی لباس</label>
			<input type="search" id="b-pq" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="اسم لباس…">
			<label class="screen-reader-text" for="b-pc">دسته</label>
			<select id="b-pc" name="cat" onchange="this.form.submit()">
				<option value="">همه دسته‌ها</option>
				<?php foreach ( $cats as $c ) : ?><option value="<?php echo esc_attr( $c->slug ); ?>" <?php selected( $cat, $c->slug ); ?>><?php echo esc_html( $c->name ); ?></option><?php endforeach; ?>
			</select>
			<button type="submit" class="b-btn">جستجو</button>
			<?php if ( $q || $cat ) : ?><a class="b-btn b-btn--quiet" href="<?php echo esc_url( boutique_products_page_url( [ 'tab' => $tab ] ) ); ?>">پاک کردن</a><?php endif; ?>
		</form>

		<?php if ( ! $list ) : ?>
			<section class="b-panel"><p class="b-empty">لباسی اینجا نیست.</p></section>
		<?php else : ?>
			<ul class="b-pgrid" role="list">
				<?php foreach ( $list as $p ) :
					[ $stock, $out, $n ] = boutique_product_stock_summary( $p );
					$draft = 'publish' !== $p->get_status();
					$ask   = function_exists( 'boutique_is_ask_price' ) && boutique_is_ask_price( $p ); ?>
					<li class="b-pcard<?php echo $draft ? ' is-draft' : ''; ?>">
						<a class="b-pcard__img" href="<?php echo esc_url( boutique_product_url( $p->get_id() ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo $p->get_image( 'woocommerce_thumbnail', [ 'alt' => '' ] ); ?></a>
						<div class="b-pcard__body">
							<a class="b-pcard__name" href="<?php echo esc_url( boutique_product_url( $p->get_id() ) ); ?>"><?php echo esc_html( $p->get_name() ); ?></a>
							<div class="b-pcard__price"><?php echo $ask ? 'استعلام قیمت' : wp_kses_post( $p->get_price_html() ); ?></div>
							<div class="b-pcard__chips">
								<?php if ( $draft ) : ?><span class="b-state is-off">پیش‌نویس</span><?php endif; ?>
								<?php // A draft isn't on sale yet, so it can't be "sold out": only published items get those badges. ?>
								<?php if ( $draft ) : ?><?php if ( $stock ) : ?><span class="b-state is-go"><?php echo esc_html( boutique_fa_digits( $stock ) ); ?> عدد</span><?php endif; ?>
								<?php elseif ( 0 === $stock ) : ?><span class="b-state is-out">تمام شد</span>
								<?php else : ?><span class="b-state is-go"><?php echo esc_html( boutique_fa_digits( $stock ) ); ?> عدد</span><?php if ( $out && $n > 1 ) : ?><span class="b-state is-wait"><?php echo esc_html( boutique_fa_digits( $out ) ); ?> سایز تمام</span><?php endif; ?><?php endif; ?>
								<?php if ( $p->is_on_sale() ) : ?><span class="b-state is-sale">حراج</span><?php endif; ?>
							</div>
							<div class="b-pcard__actions">
								<a class="b-btn" href="<?php echo esc_url( boutique_product_url( $p->get_id() ) ); ?>">ویرایش</a>
								<a class="b-btn b-btn--ghost" href="<?php echo esc_url( boutique_stock_url( $p->get_id() ) ); ?>">موجودی</a>
								<?php if ( ! $draft ) : ?><a class="b-btn b-btn--quiet" href="<?php echo esc_url( get_permalink( $p->get_id() ) ); ?>" target="_blank" rel="noopener">در سایت</a><?php endif; ?>
								<?php boutique_draft_delete_form( $p ); ?>
							</div>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php boutique_pager( $total, $per, $paged, fn( $pg ) => boutique_products_page_url( $keep + [ 'paged' => $pg ] ) ); ?>
		<p class="b-foot">
			<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ); ?>">دسته‌بندی‌ها</a> ·
			<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=product_brand&post_type=product' ) ); ?>">برندها</a> ·
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=product-reviews' ) ); ?>">نظرات مشتری‌ها</a> ·
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">نمای کامل ووکامرس</a>
		</p>
	</div>
	<?php
}
