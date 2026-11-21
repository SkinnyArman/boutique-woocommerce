const { test, expect } = require('@playwright/test');
const { php, phpJson, shop } = require('./helpers');

// Evening/Turkish dresses imported without a price: shown, not buyable, contact instead.
test('«استعلام قیمت»: بدون دکمه خرید، با سایزهای موجود و راه تماس؛ از راه آدرس هم به سبد نمی‌رود', async ({ page }) => {
  const p = phpJson(`$ids = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_boutique_ask_price', 'meta_value' => 'yes']);
    $pr = $ids ? wc_get_product($ids[0]) : null; echo wp_json_encode($pr ? ['id' => $pr->get_id(), 'url' => get_permalink($pr->get_id()), 'var' => $pr->get_children()[0]] : null);`);
  test.skip(!p, 'no ask-price product in the catalog');

  await page.goto(p.url);
  await expect(page.locator('.summary .price').first()).toHaveText('استعلام قیمت');
  await expect(page.locator('.b-askbox')).toContainText('موجود در سایز');
  await expect(page.locator(`.b-askbox a[href^="https://t.me/${shop().telegram_order}"]`)).toBeVisible();
  await expect(page.locator('.b-askbox a[href^="tel:"]')).toBeVisible();
  await expect(page.locator('.single_add_to_cart_button:visible')).toHaveCount(0);
  await expect(page.getByText('موجود نیست و در دسترس').filter({ visible: true })).toHaveCount(0);

  await page.goto(`/?add-to-cart=${p.id}&variation_id=${p.var}`);
  await page.goto('/cart/');
  await expect(page.locator('.woocommerce-cart-form')).toHaveCount(0); // cart stays empty
});
