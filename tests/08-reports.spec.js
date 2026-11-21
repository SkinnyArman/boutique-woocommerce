const { test, expect } = require('@playwright/test');
const { login, fixture, makeOnHoldOrder, php, toNumber, watchForErrors } = require('./helpers');

const hero = async (page) => toNumber(await page.locator('.b-stat--hero strong').innerText());

test.describe('گزارش فروش', () => {
  test('فقط سفارش تأییدشده فروش حساب می‌شود؛ «امروز» و «گزارش» یک عدد نشان می‌دهند', async ({ page }) => {
    const problems = watchForErrors(page);
    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-reports&range=today');
    const before = await hero(page);

    // An unconfirmed card-to-card order is NOT a sale yet.
    const orderId = makeOnHoldOrder(fixture().vars['maroon|40']);
    await page.reload();
    expect(await hero(page)).toBe(before);
    await expect(page.locator('.b-callout')).toContainText('هنوز تأیید نشده');

    // Once confirmed it counts: 800,000.
    php(`wc_get_order(${orderId})->update_status('processing');`);
    await page.reload();
    expect(await hero(page)).toBe(before + 800000);

    await page.goto('/wp-admin/admin.php?page=boutique-today');
    expect(await hero(page)).toBe(before + 800000);
    expect(problems).toEqual([]);
  });

  test('نمودار: هر بازه باز می‌شود و فروش امروز در ستون امروز می‌افتد', async ({ page }) => {
    await login(page, 'manager');
    for (const [range, bars] of [['7', 7], ['30', 30], ['90', 13]]) {
      const res = await page.goto(`/wp-admin/admin.php?page=boutique-reports&range=${range}`);
      expect(res.status()).toBe(200);
      await expect(page.locator('.b-bar')).toHaveCount(bars);
    }
    // Regression: Jalali dates once left every bar empty.
    await page.goto('/wp-admin/admin.php?page=boutique-reports&range=7');
    const todayBar = await page.locator('.b-bar').last().evaluate((el) => parseFloat(getComputedStyle(el).getPropertyValue('--h')));
    expect(todayBar).toBeGreaterThan(0);
    await expect(page.locator('.b-panel', { hasText: 'پرفروش‌ترین لباس‌ها' })).toContainText('لباس تست خودکار');
    await expect(page.locator('.b-panel', { hasText: 'رنگ‌های پرفروش' })).toContainText('زرشکی');
  });

  test('ادمین هم گزارش را می‌بیند؛ موبایل اسکرول افقی ندارد', async ({ page }) => {
    await login(page, 'admin');
    await page.setViewportSize({ width: 390, height: 844 });
    const res = await page.goto('/wp-admin/admin.php?page=boutique-reports&range=30');
    expect(res.status()).toBe(200);
    await expect(page.locator('h1').first()).toHaveText('گزارش فروش');
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  });
});
