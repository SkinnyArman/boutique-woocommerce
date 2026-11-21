const { test, expect } = require('@playwright/test');
const { fixture, stockOf, orderInfo, watchForErrors } = require('./helpers');

/** Pick the first real option of a (possibly select2-enhanced) select. */
async function pickFirst(page, selector) {
  await page.waitForFunction((s) => document.querySelector(s) && [...document.querySelector(s).options].some((o) => o.value), selector);
  await page.evaluate((s) => {
    const el = document.querySelector(s);
    el.value = [...el.options].find((o) => o.value).value;
    el.dispatchEvent(new Event('change', { bubbles: true }));
    if (window.jQuery) window.jQuery(el).trigger('change');
  }, selector);
}
const settle = async (page) => {
  await page.waitForLoadState('networkidle');
  await expect(page.locator('.blockUI')).toHaveCount(0);
};

test('خرید مهمان با کارت به کارت: سفارش ثبت و موجودی کم می‌شود', async ({ page }) => {
  const f = fixture();
  const vid = f.vars['maroon|38'];
  const before = stockOf(vid);
  const problems = watchForErrors(page);

  // Add to cart from the product page, like a customer.
  await page.goto(f.url, { waitUntil: 'networkidle' });
  await page.selectOption('select[name="attribute_pa_color"]', 'maroon');
  await page.selectOption('select[name="attribute_pa_size"]', '38');
  await expect(page.locator('.single_add_to_cart_button')).not.toHaveClass(/disabled/);
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.ok()),
    page.click('.single_add_to_cart_button'),
  ]);
  await page.waitForLoadState('networkidle');

  await page.goto('/checkout/', { waitUntil: 'networkidle' });
  await expect(page.locator('.woocommerce-checkout-review-order')).toContainText('لباس تست خودکار');
  // Shipping + offline payment options exist.
  await expect(page.locator('#shipping_method li').first()).toBeVisible();
  await expect(page.locator('.wc_payment_methods')).toContainText('کارت به کارت');
  await expect(page.locator('.wc_payment_methods')).not.toContainText('پرداخت در محل');   // no card reader
  // Terms open in a dialog, not inside the form.
  await page.click('a.woocommerce-terms-and-conditions-link');
  await expect(page.locator('dialog#b-terms')).toBeVisible();
  await expect(page.locator('dialog#b-terms')).toContainText('ثبت سفارش');
  await page.click('.b-terms__close');
  await expect(page.locator('dialog#b-terms')).toBeHidden();
  expect(page.url()).toMatch(/checkout/);
  await expect(page.locator('label[for="billing_phone"]')).toContainText('شماره موبایل');

  await page.fill('#billing_first_name', 'مشتری');
  await page.fill('#billing_last_name', 'تست خودکار');
  await page.fill('#billing_address_1', 'خیابان آزمایش، پلاک ۱');
  await pickFirst(page, '#billing_city');
  await page.fill('#billing_postcode', '1234567890');
  await settle(page);
  await page.check('#payment_method_bacs');
  await page.check('#terms');

  // Mobile number is required.
  await page.click('#place_order');
  await expect(page.locator('ul.woocommerce-error').first()).toContainText('شماره موبایل');
  expect(stockOf(vid), 'سفارش ناقص نباید موجودی را کم کند').toBe(before);

  await page.fill('#billing_phone', '09120000000');
  await settle(page);
  if (!(await page.isChecked('#terms'))) await page.check('#terms');
  await page.click('#place_order');
  await page.waitForURL(/order-received\/(\d+)/, { timeout: 30_000 });
  const orderId = Number(page.url().match(/order-received\/(\d+)/)[1]);

  const o = orderInfo(orderId);
  expect(o.status).toBe('on-hold');            // waits for the shop to confirm the transfer
  expect(o.pay).toBe('کارت به کارت');
  expect(o.phone).toBe('09120000000');
  expect(stockOf(vid)).toBe(before - 1);       // reserved immediately
  expect(problems.filter((p) => !p.includes('شماره موبایل'))).toEqual([]);
});
