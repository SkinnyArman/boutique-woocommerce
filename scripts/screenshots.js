// Screenshots for the README: storefront (desktop + phone) and the shop manager's screens.
//   docker compose up -d && node scripts/screenshots.js        → docs/screenshots/*.png
// Uses the same Chromium as the tests (PW_CHROME=/path/to/chrome to choose one).
const path = require('path');
const fs = require('fs');
const { chromium, devices } = require('@playwright/test');
const { login } = require('../tests/helpers');

const OUT = path.join(__dirname, '..', 'docs', 'screenshots');
const BASE = process.env.SITE_URL || 'http://localhost:8080';
const PHONE = { ...devices['iPhone 13'], defaultBrowserType: undefined };

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const exe = process.env.PW_CHROME || require('../tests/playwright.config.js').use.launchOptions.executablePath;
  const browser = await chromium.launch(exe ? { executablePath: exe } : {});
  const shot = async (page, name, opts = {}) => {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(600); // let entrance animations settle
    await page.screenshot({ path: path.join(OUT, name + '.png'), ...opts });
    console.log('  ' + name + '.png');
  };
  const hideAppBar = (page) => page.addStyleTag({ content: '.b-app{display:none!important}' });
  // Admin screens show real customers' orders: swap names, phones and addresses for made-up ones
  // (in the page only; the database is not touched) and hide WordPress update notices.
  const anonymize = (page) => page.evaluate(() => {
    const names = ['سارا محمدی', 'مریم رضایی', 'نگار احمدی', 'لیلا کریمی', 'الهام حسینی', 'شیما نوری'];
    document.querySelectorAll('.notice, .update-nag, #wp-admin-bar-updates').forEach((el) => el.remove());
    document.querySelectorAll('.b-order').forEach((card, i) => {
      const name = card.querySelector('.b-order__main strong');
      if (name) name.textContent = names[i % names.length];
      const phone = card.querySelector('.b-order__phone');
      if (phone) { phone.textContent = '۰۹۱۲۰۰۰۰۰۰' + (i % 10).toLocaleString('fa'); phone.removeAttribute('href'); }
      const ship = card.querySelector('.b-order__ship');
      if (ship) ship.innerHTML = names[i % names.length] + '<br>تهران<br>خیابان نمونه، پلاک ' + '۱۲۳۴۵۶'[i % 6];
      card.querySelectorAll('.b-loc').forEach((el) => el.remove());
    });
  });

  // Storefront, desktop.
  const desk = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  let p = await desk.newPage();
  await p.goto('/'); await shot(p, 'home-desktop');
  const product = await p.locator('.b-new a.woocommerce-LoopProduct-link').first().getAttribute('href');
  await p.goto(product); await shot(p, 'product-desktop');
  await p.goto('/contact/'); await shot(p, 'contact-desktop');

  // Storefront, phone.
  const phone = await browser.newContext({ ...PHONE, baseURL: BASE });
  p = await phone.newPage();
  await p.goto('/'); await hideAppBar(p); await shot(p, 'home-phone');
  await p.goto(product); await hideAppBar(p); await shot(p, 'product-phone');

  // Shop manager (Persian, simplified admin).
  const admin = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } });
  p = await admin.newPage();
  await login(p, 'manager');
  for (const [slug, name] of [['boutique-today', 'admin-today'], ['boutique-instore', 'admin-instore'], ['boutique-stock', 'admin-stock'], ['boutique-reports', 'admin-reports']]) {
    await p.goto('/wp-admin/admin.php?page=' + slug); await anonymize(p); await shot(p, name);
  }
  await p.goto('/wp-admin/edit.php?post_type=product&page=boutique-product'); await anonymize(p); await shot(p, 'admin-new-product');

  const adminPhone = await browser.newContext({ ...PHONE, baseURL: BASE });
  p = await adminPhone.newPage();
  await login(p, 'manager');
  await p.goto('/wp-admin/admin.php?page=boutique-today'); await anonymize(p); await shot(p, 'admin-today-phone');

  await browser.close();
})();
