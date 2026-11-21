const { test, expect } = require('@playwright/test');
const { login, fixture, stockOf, orderInfo, watchForErrors } = require('./helpers');

test.describe('ثبت فروش حضوری', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-instore');
    await page.fill('#b-pos-search', 'تست خودکار');
    await page.locator('.b-prod', { hasText: 'لباس تست خودکار' }).click();
  });

  test('فروش با تخفیف حضوری: سفارش تکمیل‌شده ثبت و موجودی سایت کم می‌شود', async ({ page }) => {
    const problems = watchForErrors(page);
    const vid = fixture().vars['maroon|38'];
    const before = stockOf(vid);

    await expect(page.locator('#b-pos-submit')).toBeDisabled();
    await page.click('[data-color="زرشکی"]');
    await page.click(`[data-var="${vid}"]`);
    await expect(page.locator('#b-pos-total')).toHaveText('۸۰۰,۰۰۰ تومان');

    // Haggled price, typed with Persian digits.
    const price = page.locator('.b-line__price input');
    await price.fill('۷۰۰۰۰۰');
    await price.blur();
    await expect(page.locator('#b-pos-total')).toHaveText('۷۰۰,۰۰۰ تومان');

    await page.click('label:has(input[value="cash"])');
    await page.click('#b-pos-submit');
    await page.waitForURL(/sold=(\d+)/);
    await expect(page.locator('.b-done')).toContainText('فروش ثبت شد');

    const o = orderInfo(Number(page.url().match(/sold=(\d+)/)[1]));
    expect(o).toMatchObject({ status: 'completed', via: 'instore', total: 700000, pay: 'حضوری — نقدی' });
    expect(stockOf(vid)).toBe(before - 1);
    expect(problems).toEqual([]);
  });

  test('سایزی که سایت می‌گوید تمام شده، بدون تأیید اضافه نمی‌شود', async ({ page }) => {
    const vid = fixture().vars['black|40'];
    let asked = false;
    page.once('dialog', (d) => { asked = true; d.dismiss(); });
    await page.click('[data-color="مشکی"]');
    await page.click(`[data-var="${vid}"]`);
    expect(asked, 'باید قبل از فروش سایز ناموجود بپرسد').toBe(true);
    await expect(page.locator('#b-pos-cart')).toContainText('هنوز چیزی اضافه نشده');
    await expect(page.locator('#b-pos-submit')).toBeDisabled();
  });
});
