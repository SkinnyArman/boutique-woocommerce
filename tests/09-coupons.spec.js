const { test, expect } = require('@playwright/test');
const { login, fixture, php, phpJson, watchForErrors } = require('./helpers');

const CODE = 'e2etest' + Math.floor(Math.random() * 9000 + 1000);
const coupon = () => phpJson(`$c = new WC_Coupon('${CODE}'); echo wp_json_encode(['id' => $c->get_id(), 'type' => $c->get_discount_type(), 'amount' => (float) $c->get_amount(), 'min' => (float) $c->get_minimum_amount(), 'limit' => (int) $c->get_usage_limit(), 'once' => (int) $c->get_usage_limit_per_user()]);`);

test.describe.serial('کد تخفیف', () => {
  test('مدیر: ساخت کد درصدی با حداقل سبد و ظرفیت محدود', async ({ page }) => {
    const problems = watchForErrors(page);
    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-coupons');

    await page.fill('#b-cp-code', CODE);
    await page.locator('#b-cp-amount').pressSequentially('10');
    await page.locator('#b-cp-min').pressSequentially('۱۵۰۰۰۰۰');
    await expect(page.locator('#b-cp-min')).toHaveValue('۱,۵۰۰,۰۰۰');
    await page.click('label:has(input[name="cap"][value="limited"])');
    await page.locator('input[name="limit"]').pressSequentially('1');
    await page.check('input[name="once"]');
    await page.click('#b-cp-form button[type="submit"]');

    await expect(page.locator('.b-done')).toContainText(CODE.toUpperCase());
    const row = page.locator(`tr[data-code="${CODE}"]`);
    await expect(row).toContainText('۱۰٪');
    await expect(row).toContainText('سبد بالای ۱,۵۰۰,۰۰۰ تومان');
    await expect(row).toContainText('۰ از ۱');
    await expect(row.locator('.b-stock')).toHaveText('فعال');
    expect(coupon()).toMatchObject({ type: 'percent', amount: 10, min: 1500000, limit: 1, once: 1 });

    // Same code twice is refused.
    await page.fill('#b-cp-code', CODE);
    await page.locator('#b-cp-amount').pressSequentially('5');
    await page.click('#b-cp-form button[type="submit"]');
    await expect(page.locator('.notice-error')).toContainText('قبلاً ساخته شده');
    expect(problems).toEqual([]);
  });

  test('مشتری: زیر حداقل سبد رد می‌شود، بالای آن تخفیف می‌گیرد، ظرفیت که پر شد دیگر کار نمی‌کند', async ({ page }) => {
    const f = fixture();
    const add = (qty) => page.goto(`/?add-to-cart=${f.id}&variation_id=${f.vars['black|38']}&attribute_pa_color=black&attribute_pa_size=38&quantity=${qty}`);
    const apply = async () => {
      await page.goto('/cart/', { waitUntil: 'networkidle' });
      await page.fill('#coupon_code', CODE);
      await page.click('button[name="apply_coupon"]');
      await page.waitForLoadState('networkidle');
    };

    await add(1);                                   // 800,000 < 1,500,000
    await apply();
    await expect(page.locator('.woocommerce-cart-form')).toContainText('حداقل هزینه'); // tells the customer why
    await expect(page.locator('.cart-discount')).toHaveCount(0);

    await add(1);                                   // now 2 × 800,000 = 1,600,000
    await apply();
    await expect(page.locator('.cart-discount')).toContainText('160,000');   // 10%

    // Capacity of 1 already used by someone else.
    php(`$c = new WC_Coupon('${CODE}'); $c->set_usage_count(1); $c->save();`);
    await page.goto('/checkout/', { waitUntil: 'networkidle' });
    await expect(page.locator('.cart-discount')).toHaveCount(0);

    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-coupons');
    const row = page.locator(`tr[data-code="${CODE}"]`);
    await expect(row).toContainText('۱ از ۱');
    await expect(row.locator('.b-stock')).toHaveText('ظرفیت پر شد');

    page.once('dialog', (d) => d.accept());
    await row.locator('button', { hasText: 'حذف' }).click();
    await expect(page.locator(`tr[data-code="${CODE}"]`)).toHaveCount(0);
  });
});
