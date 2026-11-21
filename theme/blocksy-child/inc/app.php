<?php
/**
 * «افزودن به صفحه اصلی»: the shop as an app icon on customers' phones.
 *
 * - Manifest (?boutique_manifest=shop): name, colors, icons from config/shop.php (plugins/boutique-admin/inc/shop.php).
 * - A small bar on phones from the 2nd page view: Android/Chrome gets the real install prompt,
 *   iPhone gets the two Share-menu steps (Safari has no prompt). Closing it hides it for 30 days.
 * - app/install.js is generic (data-b-install markup); the admin plugin reuses it for the
 *   shop manager's separate «مدیریت …» icon.
 * No service worker: nothing is cached offline, so prices and stock are always live.
 */
defined( 'ABSPATH' ) || exit;

function boutique_app_url( $file ) {
	return get_stylesheet_directory_uri() . '/app/' . $file;
}

add_action( 'wp_head', function () {
	echo '<link rel="manifest" href="' . esc_url( home_url( '/?boutique_manifest=shop' ) ) . '">' . "\n";
	echo '<meta name="theme-color" content="' . esc_attr( boutique_shop( 'colors' )['theme'] ?? '#a85562' ) . '">' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( boutique_shop( 'name' ) ) . '">' . "\n";
}, 2 );

add_action( 'wp_enqueue_scripts', function () {
	$f = get_stylesheet_directory() . '/app/install.js';
	wp_enqueue_script( 'boutique-install', boutique_app_url( 'install.js' ), [], filemtime( $f ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
} );

/** Share-menu icon as iOS draws it (square with an up arrow). */
function boutique_ios_share_icon() {
	return '<svg class="b-share-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 10H6v10h12V10h-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
}

/** The iPhone steps, shared with the admin card. */
function boutique_ios_steps() {
	return '<ol class="b-ios-steps" data-b-install-steps hidden>'
		. '<li>دکمه اشتراک‌گذاری ' . boutique_ios_share_icon() . ' را بزنید (پایین صفحه در سافاری، یا بالای صفحه در کروم).</li>'
		. '<li>کمی پایین بروید و <b>Add to Home Screen</b> (افزودن به صفحه اصلی) را بزنید.</li>'
		. '<li>بالای صفحه <b>Add</b> (افزودن) را بزنید.</li>'
		. '</ol>';
}

add_action( 'wp_footer', function () {
	// Never in the middle of buying or in the account pages.
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) { return; }
	?>
	<div class="b-app" data-b-install="b-app-shop" data-b-after="2" data-b-days="30" hidden>
		<div class="b-app__row">
			<img src="<?php echo esc_url( boutique_app_url( 'icon-192.png' ) ); ?>" alt="" width="44" height="44">
			<p class="b-app__text"><strong><?php echo esc_html( boutique_shop( 'name' ) ); ?> روی گوشی شما</strong><span>یک آیکون روی صفحه گوشی؛ با یک لمس، لباس‌های جدید را ببینید.</span></p>
			<button type="button" class="b-app__go" data-b-install-btn data-ios-label="چطور؟">نصب</button>
			<button type="button" class="b-app__close" data-b-install-close aria-label="بستن">
				<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
			</button>
		</div>
		<?php echo boutique_ios_steps(); // phpcs:ignore ?>
	</div>
	<?php
}, 20 );
