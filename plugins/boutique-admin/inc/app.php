<?php
/**
 * «مدیریت <short_name>»: a second home-screen icon for the shop manager (dark version of the logo).
 * Tapping it opens «امروز»; if she isn't logged in, WordPress shows the login first and comes back.
 *
 * - Manifest (?boutique_manifest=admin, built in inc/shop.php): its own id/scope (/wp-admin/), so it sits beside the shop's icon.
 * - Admin + login pages carry this manifest and icon, so adding from there gives the admin icon.
 * - A card on «امروز» (phones only, until installed or «بعداً») does the adding; the logic is the
 *   theme's app/install.js.
 * - On iPhone the icon has its own login (separate from Safari), so «مرا به خاطر بسپار» is
 *   ticked by default and staff stay logged in for 90 days instead of 14.
 */
defined( 'ABSPATH' ) || exit;

function boutique_admin_app_url( $file ) {
	return BOUTIQUE_ADMIN_URL . 'assets/app/' . $file;
}

function boutique_admin_app_head() {
	echo '<link rel="manifest" href="' . esc_url( home_url( '/?boutique_manifest=admin' ) ) . '">' . "\n";
	echo '<meta name="theme-color" content="' . esc_attr( boutique_shop( 'colors' )['admin_theme'] ?? '#3b2a2d' ) . '">' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( 'مدیریت ' . boutique_shop( 'short_name' ) ) . '">' . "\n";
}
add_action( 'admin_head', 'boutique_admin_app_head', 1 );
add_action( 'login_head', 'boutique_admin_app_head', 1 );

// The site icon tags are printed on admin + login pages too: there, the iPhone icon is the dark one.
add_filter( 'site_icon_meta_tags', function ( $tags ) {
	if ( ! is_admin() && 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) { return $tags; }
	$tags = array_filter( (array) $tags, fn( $t ) => ! str_contains( $t, 'apple-touch-icon' ) );
	$tags[] = '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( boutique_admin_app_url( 'icon-180.png' ) ) . '">';
	return array_values( $tags );
}, 20 );

/* ------------------------------------------------------------- Login */

add_action( 'login_footer', function () {
	echo "<script>(function(){var r=document.getElementById('rememberme');if(r)r.checked=true;})();</script>\n";
} );

add_filter( 'auth_cookie_expiration', function ( $length, $user_id, $remember ) {
	return ( $remember && user_can( $user_id, 'manage_woocommerce' ) ) ? 90 * DAY_IN_SECONDS : $length;
}, 10, 3 );

/* ------------------------------------------------------ «امروز» card */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'toplevel_page_boutique-today' !== $hook ) { return; }
	$f = get_stylesheet_directory() . '/app/install.js';
	if ( file_exists( $f ) ) {
		wp_enqueue_script( 'boutique-install', get_stylesheet_directory_uri() . '/app/install.js', [], filemtime( $f ), [ 'in_footer' => true ] );
	}
} );

function boutique_app_card() {
	$steps = function_exists( 'boutique_ios_steps' ) ? boutique_ios_steps() : '';
	?>
	<section class="b-panel b-appcard" data-b-install="b-app-admin" data-b-days="14" hidden aria-labelledby="b-appcard-t">
		<img src="<?php echo esc_url( boutique_admin_app_url( 'icon-192.png' ) ); ?>" alt="" width="56" height="56">
		<div class="b-appcard__body">
			<h2 id="b-appcard-t">میان‌بر مدیریت روی گوشی</h2>
			<p>یک آیکون تیره با نام «<?php echo esc_html( 'مدیریت ' . boutique_shop( 'short_name' ) ); ?>» روی صفحه گوشی‌تان می‌آید. با زدنش مستقیم همین صفحه «امروز» باز می‌شود؛ فقط بار اول باید وارد شوید.</p>
			<?php echo $steps; // phpcs:ignore ?>
			<div class="b-appcard__actions">
				<button type="button" class="b-btn" data-b-install-btn data-ios-label="روش اضافه کردن">اضافه کردن به صفحه گوشی</button>
				<button type="button" class="b-btn b-btn--quiet" data-b-install-close>بعداً</button>
			</div>
		</div>
	</section>
	<?php
}
