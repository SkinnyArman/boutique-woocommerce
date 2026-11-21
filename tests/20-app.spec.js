// «افزودن به صفحه اصلی»: the shop icon for customers, the dark «مدیریت …» icon for the manager.
const { test, expect, devices } = require('@playwright/test');
const { login, watchForErrors, shop } = require('./helpers');

const IPHONE = { userAgent: devices['iPhone 13'].userAgent, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true };
const ANDROID = { userAgent: devices['Pixel 7'].userAgent, viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true };

/** Fetch a manifest linked from the current page and check every icon is a real PNG of its size. */
async function readManifest(page) {
  const href = await page.locator('link[rel="manifest"]').getAttribute('href');
  const res = await page.request.get(href);
  expect(res.status()).toBe(200);
  const m = await res.json();
  for (const icon of m.icons) {
    const r = await page.request.get(new URL(icon.src, href).href);
    expect(r.status(), icon.src).toBe(200);
    const b = await r.body();
    expect(b.readUInt32BE(16) + 'x' + b.readUInt32BE(20), icon.src).toBe(icon.sizes);
  }
  return m;
}

test.describe('آیکون فروشگاه برای مشتری', () => {
  test('manifest، آیکون‌ها و تگ‌های آیفون', async ({ page }) => {
    await page.goto('/');
    const m = await readManifest(page);
    expect(m).toMatchObject({ name: shop().name, start_url: '/', scope: '/', display: 'standalone', dir: 'rtl' });
    expect(m.icons.some((i) => i.purpose === 'maskable' && i.sizes === '512x512')).toBe(true);
    await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute('href', /blocksy-child\/app\/icon-180\.png/);
    await expect(page.locator('meta[name="apple-mobile-web-app-title"]')).toHaveAttribute('content', shop().name);
  });

  test('آیفون: از صفحه دوم نوار می‌آید، «چطور؟» مراحل را نشان می‌دهد، بستن یادش می‌ماند', async ({ browser }) => {
    const ctx = await browser.newContext(IPHONE);
    const page = await ctx.newPage();
    const problems = watchForErrors(page);
    await page.goto('/');
    await expect(page.locator('.b-app')).toBeHidden();                    // first page: leave them alone
    await page.goto('/shop/');
    const bar = page.locator('.b-app');
    await expect(bar).toBeVisible();
    await expect(bar.locator('[data-b-install-btn]')).toHaveText('چطور؟');
    await bar.locator('[data-b-install-btn]').click();
    await expect(bar.locator('.b-ios-steps')).toBeVisible();
    await expect(bar.locator('.b-ios-steps')).toContainText('Add to Home Screen');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await bar.locator('[data-b-install-close]').click();
    await expect(bar).toBeHidden();
    await page.goto('/');
    await expect(page.locator('.b-app')).toBeHidden();                    // closed = stays closed
    expect(problems).toEqual([]);
    await ctx.close();
  });

  test('نه روی کامپیوتر، نه در سبد خرید/پرداخت، نه داخل خود اپ، نه در مرورگر اینستاگرام', async ({ browser, page }) => {
    await page.goto('/'); await page.goto('/shop/');
    await expect(page.locator('.b-app')).toBeHidden();                    // desktop

    const ctx = await browser.newContext(IPHONE);
    const p = await ctx.newPage();
    await p.goto('/'); await p.goto('/cart/');
    await expect(p.locator('.b-app')).toHaveCount(0);
    await ctx.close();

    const app = await browser.newContext(IPHONE);
    await app.addInitScript(() => Object.defineProperty(navigator, 'standalone', { get: () => true }));
    const a = await app.newPage();
    await a.goto('/'); await a.goto('/shop/');
    await expect(a.locator('.b-app')).toBeHidden();
    await app.close();

    const ig = await browser.newContext({ ...IPHONE, userAgent: IPHONE.userAgent + ' Instagram 300.0.0' });
    const i = await ig.newPage();
    await i.goto('/'); await i.goto('/shop/');
    await expect(i.locator('.b-app')).toBeHidden();
    await ig.close();
  });

  test('اندروید: دکمه «نصب» پنجره نصب خود مرورگر را باز می‌کند', async ({ browser }) => {
    const ctx = await browser.newContext(ANDROID);
    const page = await ctx.newPage();
    await page.goto('/'); await page.goto('/shop/');
    await expect(page.locator('.b-app')).toBeHidden();                    // no prompt from the browser yet
    // Stand-in for Chrome's event (headless Chrome never fires it on its own).
    await page.evaluate(() => {
      const e = new Event('beforeinstallprompt', { cancelable: true });
      e.prompt = () => { window.__prompted = true; };
      e.userChoice = Promise.resolve({ outcome: 'accepted' });
      window.dispatchEvent(e);
    });
    const bar = page.locator('.b-app');
    await expect(bar).toBeVisible();
    await expect(bar.locator('[data-b-install-btn]')).toHaveText('نصب');
    await bar.locator('[data-b-install-btn]').click();
    expect(await page.evaluate(() => window.__prompted)).toBe(true);
    await expect(bar).toBeHidden();                                       // accepted
    await ctx.close();
  });
});

test.describe('آیکون جدای مدیریت برای مدیر فروشگاه', () => {
  test('صفحه ورود: manifest و آیکون تیره، «مرا به خاطر بسپار» از قبل تیک خورده', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/wp-login.php');
    const m = await readManifest(page);
    expect(m).toMatchObject({ short_name: 'مدیریت ' + shop().short_name, start_url: '/wp-admin/admin.php?page=boutique-today', scope: '/wp-admin/', display: 'standalone' });
    expect(m.id).not.toBe('/');                                           // a different app from the shop
    await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveCount(1);
    await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute('href', /boutique-admin\/assets\/app\/icon-180\.png/);
    await expect(page.locator('meta[name="apple-mobile-web-app-title"]')).toHaveAttribute('content', 'مدیریت ' + shop().short_name);
    await expect(page.locator('#rememberme')).toBeChecked();
  });

  test('ورود با «مرا به خاطر بسپار»: مدیر ۹۰ روز وارد می‌ماند', async ({ page }) => {
    await page.context().clearCookies();
    await page.request.get('/wp-login.php');
    await page.request.post('/wp-login.php', { form: { log: process.env.MANAGER_USER || 'manager', pwd: process.env.MANAGER_PASS || 'manager_local_pw', rememberme: 'forever', 'wp-submit': '1', testcookie: '1' } });
    const auth = (await page.context().cookies()).find((c) => c.name.startsWith('wordpress_logged_in_'));
    const days = (auth.expires * 1000 - Date.now()) / 864e5;
    expect(days).toBeGreaterThan(85);
    expect(days).toBeLessThan(95);
  });

  test('آیفون: کارت «میان‌بر مدیریت» در «امروز» با مراحل؛ «بعداً» آن را می‌بندد', async ({ browser }) => {
    const ctx = await browser.newContext(IPHONE);
    const page = await ctx.newPage();
    const problems = watchForErrors(page);
    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-today');
    const card = page.locator('.b-appcard');
    await expect(card).toBeVisible();
    await expect(card.locator('img')).toHaveAttribute('src', /boutique-admin\/assets\/app\/icon-192\.png/);
    await card.locator('[data-b-install-btn]').click();
    await expect(card.locator('.b-ios-steps')).toBeVisible();
    await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute('href', /boutique-admin/);
    await card.locator('[data-b-install-close]').click();
    await expect(card).toBeHidden();
    await page.reload();
    await expect(page.locator('.b-appcard')).toBeHidden();
    expect(problems).toEqual([]);
    await ctx.close();
  });

  test('روی کامپیوتر کارت نشان داده نمی‌شود', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-today');
    await expect(page.locator('.b-appcard')).toBeHidden();
  });

  test('آیکون مدیریت بدون ورود: اول صفحه ورود، بعد مستقیم «امروز»', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/wp-admin/admin.php?page=boutique-today');
    await expect(page).toHaveURL(/wp-login\.php/);
    await page.fill('#user_login', process.env.MANAGER_USER || 'manager');
    await page.fill('#user_pass', process.env.MANAGER_PASS || 'manager_local_pw');
    await page.click('#wp-submit');
    await expect(page).toHaveURL(/page=boutique-today/);
  });
});
