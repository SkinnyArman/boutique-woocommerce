<?php
/**
 * Home page sections, rendered from live store data.
 * Used as shortcodes inside the "خانه" page (see scripts/pages.php):
 *   [boutique_hero] [boutique_ribbon] [boutique_sale] [boutique_categories]
 *   [boutique_new] [boutique_visit]
 *
 * Signature motif: the arch (Persian doorway/window): hero photo, sale photos, category page.
 */

defined( 'ABSPATH' ) || exit;

/* Shop facts (address, hours, phone, socials, card…) come from config/shop.php: see plugins/boutique-admin/inc/shop.php. */

/** Evening/Turkish dresses posted without a price: shown as «استعلام قیمت», not buyable online. */
function boutique_is_ask_price( $p ) {
	if ( ! $p instanceof WC_Product ) { return false; }
	$id = $p->get_parent_id() ?: $p->get_id();
	return 'yes' === get_post_meta( $id, '_boutique_ask_price', true );
}

/** Price text for our own templates (hero, sale cards). */
function boutique_price_label( WC_Product $p ) {
	return boutique_is_ask_price( $p ) ? '<span class="b-ask">استعلام قیمت</span>' : wc_price( $p->get_price() );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( is_front_page() ) {
		$f = get_stylesheet_directory() . '/css/home.css';
		wp_enqueue_style( 'boutique-home', get_stylesheet_directory_uri() . '/css/home.css', [ 'boutique' ], filemtime( $f ) );
	}
}, 30 );

/** Left-pointing arrow (RTL "forward"), drawn, not a glyph. */
function boutique_arrow() {
	return '<svg class="b-arrow" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/** Largest real discount on a product, in whole percent (0 if not on sale). */
function boutique_discount( WC_Product $p ) {
	if ( ! $p->is_on_sale() ) { return 0; }
	if ( $p->is_type( 'variable' ) ) {
		$best = 0;
		foreach ( $p->get_variation_prices()['regular_price'] as $vid => $reg ) {
			$sale = $p->get_variation_prices()['price'][ $vid ];
			if ( $reg > 0 ) { $best = max( $best, round( ( $reg - $sale ) / $reg * 100 ) ); }
		}
		return (int) $best;
	}
	$reg = (float) $p->get_regular_price();
	return $reg > 0 ? (int) round( ( $reg - (float) $p->get_price() ) / $reg * 100 ) : 0;
}

/** Persian digits for numbers we print ourselves (the font handles the rest). */
function boutique_fa( $n ) {
	return strtr( (string) $n, [ '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ] );
}

/* ----------------------------------------------------------------- Hero */
add_shortcode( 'boutique_hero', function () {
	// The photo wall: newest pieces with a photo, evening/Turkish dresses first (what the shop
	// is known for), then everything else; spread over three columns.
	$seen = $photos = [];
	$lists = [
		wc_get_products( [ 'status' => 'publish', 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC', 'category' => [ 'evening', 'turkish-dress' ] ] ),
		wc_get_products( [ 'status' => 'publish', 'limit' => 30, 'orderby' => 'date', 'order' => 'DESC' ] ),
	];
	foreach ( $lists as $list ) {
		foreach ( $list as $p ) {
			if ( count( $photos ) >= 15 || isset( $seen[ $p->get_id() ] ) || ! $p->get_image_id() ) { continue; }
			$seen[ $p->get_id() ] = true;
			$photos[] = $p;
		}
	}
	// Interleave so each column mixes evening and casual pieces.
	$cols = [ [], [], [] ];
	foreach ( $photos as $i => $p ) { $cols[ $i % 3 ][] = $p; }
	$has_sale = (bool) wc_get_product_ids_on_sale();
	$shop     = get_permalink( wc_get_page_id( 'shop' ) );

	ob_start(); ?>
	<section class="b-hero2" aria-label="معرفی فروشگاه">
		<div class="b-hero2__text">
			<h1 class="b-hero2__title"><em><?php echo esc_html( boutique_shop( 'short_name' ) ); ?></em>؛<br><?php echo esc_html( boutique_shop( 'hero_title' ) ); ?></h1>
			<p class="b-hero2__lede"><?php echo esc_html( boutique_shop( 'hero_lede' ) ); ?></p>
			<div class="b-hero2__actions">
				<a class="b-btn b-btn--solid" href="<?php echo esc_url( $shop ); ?>">دیدن همه لباس‌ها <?php echo boutique_arrow(); ?></a>
				<?php if ( $has_sale ) : ?><a class="b-btn b-btn--ghost" href="#b-sale">حراج</a><?php else : ?><a class="b-btn b-btn--ghost" href="<?php echo esc_url( boutique_categories_url() ); ?>">دسته‌بندی‌ها</a><?php endif; ?>
			</div>
		</div>
		<?php if ( $photos ) : ?>
		<div class="b-hero2__stage">
			<div class="b-wall">
				<?php foreach ( $cols as $c => $list ) : if ( ! $list ) { continue; } ?>
					<div class="b-wall__col">
						<?php foreach ( [ 0, 1 ] as $copy ) : // twice, for the seamless loop
							foreach ( $list as $i => $p ) :
								$first = 0 === $copy && $i < 2; ?>
								<a href="<?php echo esc_url( $p->get_permalink() ); ?>"<?php echo $copy ? ' tabindex="-1" aria-hidden="true"' : ' aria-label="' . esc_attr( $p->get_name() ) . '"'; ?>>
									<?php echo $p->get_image( 'woocommerce_thumbnail', [ 'alt' => '', 'loading' => $first ? 'eager' : 'lazy', 'fetchpriority' => ( 0 === $c && 0 === $i && ! $copy ) ? 'high' : 'auto', 'sizes' => '(max-width: 689px) 33vw, 220px' ] ); ?>
								</a>
							<?php endforeach;
						endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<script>
		(function () {
			var w = document.querySelector('.b-wall');
			if (!w || !('IntersectionObserver' in window)) return;
			new IntersectionObserver(function (e) { w.classList.toggle('is-off', !e[0].isIntersecting); }).observe(w);
			document.addEventListener('visibilitychange', function () { w.classList.toggle('is-off', document.hidden); });
		})();
		</script>
		<?php endif; ?>
	</section>
	<?php
	return ob_get_clean();
} );

/* --------------------------------------------------------------- Ribbon */
add_shortcode( 'boutique_ribbon', function () {
	$free  = function_exists( 'boutique_free_ship_min' ) ? boutique_free_ship_min() : 0;
	$items = [ $free ? 'ارسال رایگان برای خرید بالای ' . boutique_money_short( $free ) : 'ارسال به سراسر ایران', '۲۲ سال تجربه در لباس عروس', 'خرید حضوری و پرو در فروشگاه', 'برندهای اورجینال اروپایی' ];
	// A plain row of four promises. (Was a scrolling marquee: motion nobody asked for.)
	return '<ul class="b-promises" role="list"><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
} );

/* ----------------------------------------------------------------- Sale */
add_shortcode( 'boutique_sale', function () {
	$ids = wc_get_product_ids_on_sale();
	if ( ! $ids ) { return ''; }
	$products = wc_get_products( [ 'include' => $ids, 'status' => 'publish', 'limit' => 8, 'orderby' => 'date', 'order' => 'DESC' ] );
	$products = array_filter( $products, fn( $p ) => ! $p->get_parent_id() );
	if ( ! $products ) { return ''; }
	$max = max( array_map( 'boutique_discount', $products ) );

	ob_start(); ?>
	<section class="b-sale" id="b-sale" aria-labelledby="b-sale-title">
		<div class="b-sale__inner">
			<header class="b-sale__head">
				<h2 class="b-sale__title" id="b-sale-title">حراج</h2>
				<p class="b-sale__big">تا <span><?php echo boutique_fa( $max ); ?>٪</span> تخفیف</p>
				<p class="b-sale__note">تکه‌های آخر، قیمت آخر</p>
				<a class="b-btn b-btn--light" href="<?php echo esc_url( add_query_arg( 'orderby', 'price', get_permalink( wc_get_page_id( 'shop' ) ) ) ); ?>">همه لباس‌ها <?php echo boutique_arrow(); ?></a>
			</header>
			<ul class="b-sale__rail" role="list">
				<?php foreach ( $products as $p ) : $d = boutique_discount( $p ); ?>
					<li class="b-sale__item">
						<a href="<?php echo esc_url( $p->get_permalink() ); ?>" class="b-sale__card">
							<span class="b-sale__media">
								<?php echo $p->get_image( 'woocommerce_thumbnail' ); ?>
								<?php if ( $d ) : ?><span class="b-sale__off"><?php echo boutique_fa( $d ); ?>٪ تخفیف</span><?php endif; ?>
							</span>
							<span class="b-sale__name"><?php echo esc_html( $p->get_name() ); ?></span>
							<span class="b-sale__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
	<?php
	return ob_get_clean();
} );

/* ----------------------------------------------------------- Categories */
/** Top-level categories that have at least one in-stock product: [ [WP_Term, in-stock count], ... ], most first. */
function boutique_stocked_categories() {
	$out   = [];
	$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => 0, 'exclude' => [ (int) get_option( 'default_product_cat' ) ] ] );
	foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
		$n = count( wc_get_products( [ 'status' => 'publish', 'limit' => -1, 'return' => 'ids', 'category' => [ $t->slug ], 'stock_status' => 'instock' ] ) );
		if ( $n ) { $out[] = [ $t, $n ]; }
	}
	usort( $out, fn( $a, $b ) => $b[1] <=> $a[1] );
	return $out;
}

add_shortcode( 'boutique_categories', function () {
	// Two designs: 'arcade' (default: a drifting row of arches, every category with stock) or
	// 'index' (six names + one arch window). Preview with ?cats=index / ?cats=arcade.
	$layout = sanitize_key( $_GET['cats'] ?? get_option( 'boutique_cats_layout', 'arcade' ) );
	$all    = boutique_stocked_categories();
	if ( ! $all ) { return ''; }

	ob_start(); ?>
	<section class="b-cats" aria-labelledby="b-cats-title">
		<header class="b-row-head">
			<h2 id="b-cats-title">دسته‌بندی‌ها</h2>
			<a href="<?php echo esc_url( boutique_categories_url() ); ?>">همه دسته‌ها <?php echo boutique_arrow(); ?></a>
		</header>
		<?php if ( 'index' !== $layout ) : ?>
			<div class="b-arcade" data-b-arcade>
				<ol class="b-arcade__track" role="list">
					<?php foreach ( [ 0, 1 ] as $copy ) : // the second copy makes the loop seamless
						foreach ( $all as $i => [ $t, $n ] ) : $img = boutique_category_image_id( $t ); ?>
							<li class="b-arcade__item"<?php echo $copy ? ' aria-hidden="true" data-clone' : ''; ?>>
								<a class="b-idx__row" href="<?php echo esc_url( get_term_link( $t ) ); ?>"<?php echo $copy ? ' tabindex="-1"' : ''; ?>>
									<span class="b-arcade__media"><?php echo $img ? wp_get_attachment_image( $img, 'woocommerce_single', false, [ 'alt' => '', 'loading' => ( ! $copy && $i < 5 ) ? 'eager' : 'lazy' ] ) : ''; ?></span>
									<span class="b-arcade__label"><strong><?php echo esc_html( $t->name ); ?></strong><span><?php echo boutique_fa( $n ); ?> مدل</span></span>
								</a>
							</li>
						<?php endforeach;
					endforeach; ?>
				</ol>
			</div>
			<script>
			(function () {
				var rail = document.querySelector('[data-b-arcade]'); if (!rail) return;
				var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
				var SPEED = 22;            // px per second: a slow drift, not a ticker
				var pos = 0, last = 0, paused = false, visible = true, resumeAt = 0;
				var first = rail.querySelector('.b-arcade__item'), clone = rail.querySelector('[data-clone]');
				// One set's width (items + gaps): how far the first arch is from its copy.
				function setWidth() { return Math.abs(first.getBoundingClientRect().right - clone.getBoundingClientRect().right); }
				function fits() { return setWidth() > rail.clientWidth; }
				// Too few categories to loop: show one centred set, no motion.
				if (!fits()) { rail.classList.add('is-static'); return; }
				if (reduce) return;
				function tick(t) {
					var dt = last ? Math.min(t - last, 64) / 1000 : 0; last = t;
					if (!paused && visible && t >= resumeAt) {
						pos -= SPEED * dt;                     // RTL: forward = more negative scrollLeft
						var w = setWidth();
						if (pos <= -w) pos += w;               // wrap onto the identical copy
						rail.scrollLeft = pos;
					}
					requestAnimationFrame(tick);
				}
				rail.addEventListener('mouseenter', function () { paused = true; });
				rail.addEventListener('mouseleave', function () { paused = false; pos = rail.scrollLeft; });
				rail.addEventListener('focusin', function () { paused = true; });
				rail.addEventListener('focusout', function () { paused = false; pos = rail.scrollLeft; });
				// Touch: the visitor's swipe wins; drifting resumes a few seconds later from where they left it.
				rail.addEventListener('touchstart', function () { paused = true; }, { passive: true });
				rail.addEventListener('touchend', function () { setTimeout(function () { pos = rail.scrollLeft; paused = false; resumeAt = performance.now() + 2500; }, 50); }, { passive: true });
				if ('IntersectionObserver' in window) new IntersectionObserver(function (e) { visible = e[0].isIntersecting; }).observe(rail);
				requestAnimationFrame(tick);
			})();
			</script>
		<?php else :
			$cats = array_map( fn( $r ) => $r[0], array_slice( $all, 0, 6 ) ); ?>
			<div class="b-idx" data-b-idx>
				<ol class="b-idx__list" role="list">
					<?php foreach ( $cats as $i => $t ) : $img = boutique_category_image_id( $t ); ?>
						<li>
							<a class="b-idx__row<?php echo 0 === $i ? ' is-on' : ''; ?>" data-i="<?php echo (int) $i; ?>" href="<?php echo esc_url( get_term_link( $t ) ); ?>">
								<span class="b-idx__thumb" aria-hidden="true"><?php echo $img ? wp_get_attachment_image( $img, 'woocommerce_thumbnail', false, [ 'alt' => '', 'loading' => 'lazy' ] ) : ''; ?></span>
								<strong><?php echo esc_html( $t->name ); ?></strong>
								<span class="b-idx__count"><?php echo boutique_fa( $t->count ); ?> مدل</span>
								<?php echo boutique_arrow(); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ol>
				<div class="b-idx__window" aria-hidden="true">
					<?php foreach ( $cats as $i => $t ) : $img = boutique_category_image_id( $t ); ?>
						<span class="b-idx__img<?php echo 0 === $i ? ' is-on' : ''; ?>" data-i="<?php echo (int) $i; ?>"><?php echo $img ? wp_get_attachment_image( $img, 'woocommerce_single', false, [ 'alt' => '', 'loading' => 0 === $i ? 'eager' : 'lazy' ] ) : ''; ?></span>
					<?php endforeach; ?>
				</div>
			</div>
			<script>
			(function () {
				var root = document.querySelector('[data-b-idx]'); if (!root) return;
				function show(i) {
					root.querySelectorAll('.is-on').forEach(function (el) { el.classList.remove('is-on'); });
					root.querySelectorAll('[data-i="' + i + '"]').forEach(function (el) { el.classList.add('is-on'); });
				}
				root.querySelectorAll('.b-idx__row').forEach(function (row) {
					var go = function () { show(row.getAttribute('data-i')); };
					row.addEventListener('mouseenter', go); row.addEventListener('focus', go);
				});
			})();
			</script>
		<?php endif; ?>
	</section>
	<?php
	return ob_get_clean();
} );

/* --------------------------------------------------------- New arrivals */
add_shortcode( 'boutique_new', function () {
	return '<section class="b-new" aria-labelledby="b-new-title"><header class="b-row-head"><h2 id="b-new-title">تازه رسیده‌ها</h2><a href="'
		. esc_url( add_query_arg( 'orderby', 'date', get_permalink( wc_get_page_id( 'shop' ) ) ) ) . '">همه ' . boutique_arrow() . '</a></header>'
		. do_shortcode( '[products limit="8" columns="4" orderby="date" order="DESC"]' ) . '</section>';
} );

/* ---------------------------------------------------------------- Visit */
add_shortcode( 'boutique_visit', function () {
	$contact = get_permalink( get_page_by_path( 'contact' ) );
	ob_start(); ?>
	<section class="b-visit" aria-labelledby="b-visit-title">
		<div class="b-visit__inner">
			<h2 id="b-visit-title"><?php echo wp_kses( boutique_shop( 'visit_title' ), [ 'br' => [] ] ); ?></h2>
			<dl class="b-visit__facts">
				<div><dt>آدرس</dt><dd><?php echo esc_html( BOUTIQUE_ADDRESS ); ?></dd></div>
				<div><dt>ساعات کاری</dt><dd><?php echo esc_html( BOUTIQUE_HOURS ); ?></dd></div>
			</dl>
			<div class="b-visit__actions">
				<?php if ( BOUTIQUE_MAP_URL ) : ?><a class="b-btn b-btn--light" href="<?php echo esc_url( BOUTIQUE_MAP_URL ); ?>" target="_blank" rel="noopener">مسیریابی با نشان</a><?php endif; ?>
				<a class="b-btn b-btn--light" href="<?php echo esc_url( 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( BOUTIQUE_MAP_QUERY ) ); ?>" target="_blank" rel="noopener">مسیریابی با گوگل‌مپ</a>
				<?php echo boutique_social_links( 'dark' ); ?>
			</div>
		</div>
	</section>
	<?php
	return ob_get_clean();
} );

/* --------------------------------------------------------------- Social */
function boutique_social_icon( $which ) {
	$paths = [
		'instagram' => '<rect x="3.2" y="3.2" width="17.6" height="17.6" rx="5"/><circle cx="12" cy="12" r="4.1"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/>',
		'telegram'  => '<path d="M20.8 4.2 3.4 10.9c-.6.2-.6 1 0 1.2l4.4 1.5 1.7 5.2c.2.5.8.6 1.2.3l2.5-2.3 4.3 3.1c.5.3 1.1.1 1.2-.5l2.9-14.3c.1-.6-.4-1-.8-.9Z"/><path d="m7.8 13.6 9.4-6.2-7.1 7.6"/>',
	];
	return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $which ] . '</svg>';
}

/** Instagram + Telegram as icon buttons. $tone: 'light' (on cream) or 'dark' (on the ink band). */
function boutique_social_links( $tone = 'light' ) {
	$items = [
		'instagram' => [ 'https://instagram.com/' . BOUTIQUE_INSTAGRAM, 'اینستاگرام', '@' . BOUTIQUE_INSTAGRAM ],
		'telegram'  => [ 'https://t.me/' . BOUTIQUE_TELEGRAM, 'کانال تلگرام', '@' . BOUTIQUE_TELEGRAM ],
	];
	$items = array_filter( $items, fn( $i ) => '@' !== $i[2] );
	$out   = '<ul class="b-social b-social--' . esc_attr( $tone ) . '" role="list">';
	foreach ( $items as $key => [ $url, $label, $handle ] ) {
		$out .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . boutique_social_icon( $key )
			. '<span><strong>' . esc_html( $label ) . '</strong><bdi dir="ltr">' . esc_html( $handle ) . '</bdi></span></a></li>';
	}
	return $out . '</ul>';
}
add_shortcode( 'boutique_social', fn() => boutique_social_links( 'light' ) );

/** Contact details list (contact page), straight from config/shop.php so it never goes stale. */
add_shortcode( 'boutique_contact', function () {
	$c    = boutique_shop();
	$ltr  = fn( $href, $text ) => '<a href="' . esc_url( $href ) . '" dir="ltr">' . esc_html( $text ) . '</a>';
	$rows = array_filter( [
		'آدرس فروشگاه'         => $c['address'] ? esc_html( $c['address'] ) : '',
		'کد پستی'              => $c['postcode'] ? '<bdi dir="ltr">' . esc_html( boutique_fa( $c['postcode'] ) ) . '</bdi>' : '',
		'موبایل و هماهنگی خرید' => $c['phone'] ? $ltr( 'tel:' . $c['phone'], boutique_fa( $c['phone'] ) ) : '',
		'سفارش و پرو در تلگرام' => $c['telegram_order'] ? $ltr( 'https://t.me/' . $c['telegram_order'], '@' . $c['telegram_order'] ) : '',
		'ایمیل'                => $c['email'] ? $ltr( 'mailto:' . $c['email'], $c['email'] ) : '',
		'ساعات کاری'           => $c['hours'] ? esc_html( $c['hours'] ) : '',
		'تحویل حضوری و پرو'    => 'سفارش آنلاین را می‌توانید از فروشگاه تحویل بگیرید و همان‌جا پرو کنید.',
	] );
	$out = '<ul class="wp-block-list">';
	foreach ( $rows as $label => $html ) { $out .= '<li><strong>' . esc_html( $label ) . ':</strong> ' . $html . '</li>'; }
	return $out . '</ul>';
} );

/* ------------------------------------------------------- All categories */
function boutique_categories_url() {
	$page = get_page_by_path( 'categories' );
	return $page ? get_permalink( $page ) : get_permalink( wc_get_page_id( 'shop' ) );
}

/** A category's picture: its own thumbnail, else its newest product's photo, else 0. */
function boutique_category_image_id( WP_Term $t ) {
	$thumb = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
	if ( $thumb ) { return $thumb; }
	$p = wc_get_products( [ 'status' => 'publish', 'limit' => 1, 'category' => [ $t->slug ], 'orderby' => 'date', 'order' => 'DESC' ] );
	return $p ? (int) $p[0]->get_image_id() : 0;
}

add_shortcode( 'boutique_all_categories', function () {
	$terms = get_terms( [
		'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0,
		'exclude'  => [ (int) get_option( 'default_product_cat' ) ],
		'orderby'  => 'meta_value_num', 'meta_key' => 'order',
	] );
	if ( is_wp_error( $terms ) || ! $terms ) { return ''; }
	ob_start(); ?>
	<ul class="b-allcats" role="list">
		<?php foreach ( $terms as $t ) : $img = boutique_category_image_id( $t ); ?>
			<li>
				<a class="b-allcat<?php echo $img ? '' : ' b-allcat--plain'; ?>" href="<?php echo esc_url( get_term_link( $t ) ); ?>">
					<span class="b-allcat__media"><?php echo $img ? wp_get_attachment_image( $img, 'woocommerce_thumbnail', false, [ 'alt' => '' ] ) : ''; ?></span>
					<strong><?php echo esc_html( $t->name ); ?></strong>
					<span class="b-allcat__count"><?php echo $t->count ? esc_html( boutique_fa( $t->count ) . ' مدل' ) : 'به‌زودی'; ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
	return ob_get_clean();
} );

/* ------------------------------------------------------------------ Map */
/**
 * Google map of the shop (contact page). Loaded lazily, after the rest of the page, so if
 * Google is slow or cut off in Iran the page still works; the buttons below always do.
 */
add_shortcode( 'boutique_map', function () {
	$q      = rawurlencode( BOUTIQUE_MAP_QUERY );
	$google = 'https://www.google.com/maps/search/?api=1&query=' . $q;
	ob_start(); ?>
	<figure class="b-map">
		<div class="b-map__frame">
			<iframe title="نقشه: <?php echo esc_attr( BOUTIQUE_MAP_QUERY ); ?>" src="https://maps.google.com/maps?q=<?php echo $q; ?>&amp;z=16&amp;hl=fa&amp;output=embed" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
		</div>
		<figcaption class="b-map__bar">
			<strong><?php echo esc_html( boutique_shop( 'map_label' ) ); ?></strong>
			<span class="b-map__links">
				<?php if ( BOUTIQUE_MAP_URL ) : ?><a class="button" href="<?php echo esc_url( BOUTIQUE_MAP_URL ); ?>" target="_blank" rel="noopener">مسیریابی با نشان</a><?php endif; ?>
				<a class="button b-map__alt" href="<?php echo esc_url( $google ); ?>" target="_blank" rel="noopener">گوگل‌مپ</a>
			</span>
		</figcaption>
	</figure>
	<?php
	return ob_get_clean();
} );
