const { test, expect } = require('@playwright/test');
const { login, fixture, php, toNumber } = require('./helpers');

const setMin = (n) => php(`update_option('boutique_free_ship_min', ${n}); WC_Cache_Helper::get_transient_version('shipping', true);`);

test.describe.serial('ارسال رایگان بالای یک مبلغ', () => {
  test.afterAll(() => setMin(6000000));   // the shop's real setting

  test('زیر حد: «X تومان دیگر»؛ بالای حد: همه روش‌ها رایگان، حتی اسنپ پیک', async ({ page }) => {
    setMin(1500000);
    const f = fixture();   // 800,000 a piece
    php(`wc_update_product_stock(${f.vars['maroon|38']}, 5);`);   // independent of what earlier tests sold
    php(`foreach (['billing', 'shipping'] as $t) { update_user_meta(get_user_by('login', 'manager')->ID, "{$t}_state", 'THR'); update_user_meta(get_user_by('login', 'manager')->ID, "{$t}_city", 'تهران'); } delete_user_meta(get_user_by('login', 'manager')->ID, '_woocommerce_persistent_cart_' . get_current_blog_id()); global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = '" . get_user_by('login', 'manager')->ID . "'");`);
    await login(page, 'manager');
    await page.goto(`/?add-to-cart=${f.id}&variation_id=${f.vars['maroon|38']}&attribute_pa_color=maroon&attribute_pa_size=38`);
    await page.goto('/cart/', { waitUntil: 'networkidle' });
    const bar = page.locator('.cart_totals .b-freeship');
    await expect(bar).toContainText('۷۰۰,۰۰۰ تومان');            // 1,500,000 − 800,000
    await expect(page.locator('#shipping_method')).toContainText('پست پیشتاز');
    expect(toNumber(await page.locator('#shipping_method').innerText())).toBeGreaterThan(0);

    // Second piece: 1,600,000 ≥ 1,500,000.
    await page.goto(`/?add-to-cart=${f.id}&variation_id=${f.vars['maroon|38']}&attribute_pa_color=maroon&attribute_pa_size=38`);
    await page.goto('/cart/', { waitUntil: 'networkidle' });
    await expect(page.locator('.cart_totals .b-freeship')).toContainText('رایگان است');
    const methods = await page.locator('#shipping_method li').allInnerTexts();
    expect(methods.join(' ')).not.toMatch(/[\d۰-۹],[\d۰-۹]{3}/);    // no price on any option
    expect(methods.join(' ')).toContain('اسنپ پیک (رایگان)');

    await page.goto('/checkout/', { waitUntil: 'networkidle' });
    await expect(page.locator('.woocommerce-checkout-review-order .b-freeship')).toContainText('رایگان است');
    php(`delete_user_meta(get_user_by('login', 'manager')->ID, '_woocommerce_persistent_cart_' . get_current_blog_id()); global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = '" . get_user_by('login', 'manager')->ID . "'");`);
  });

  test('مدیر مبلغ را در «هزینه ارسال» عوض می‌کند (۰ = خاموش)', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-shipping');
    await page.fill('#b-free-min', '۷٬۰۰۰٬۰۰۰');
    await page.click('.b-ship__form button[type="submit"]');
    await expect(page.locator('.b-done')).toContainText('ذخیره شد');
    expect(php("echo get_option('boutique_free_ship_min');")).toBe('7000000');
    await page.goto('/');
    await expect(page.locator('.b-promises')).toContainText('ارسال رایگان برای خرید بالای ۷ میلیون تومان');
    setMin(0);
    await page.goto('/');
    await expect(page.locator('.b-promises')).toContainText('ارسال به سراسر ایران');
  });
});
