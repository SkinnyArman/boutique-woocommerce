const { test, expect } = require('@playwright/test');
const { fixture, watchForErrors, fa, php, shop, re } = require('./helpers');

test.describe('ویترین سایت', () => {
  test('صفحه اصلی: بخش‌ها به ترتیب درست، بدون خطا و بدون وابستگی به سرور خارجی', async ({ page }) => {
    const problems = watchForErrors(page);
    const external = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if (u.protocol.startsWith('http') && u.hostname !== 'localhost') external.push(r.url());
    });
    await page.goto('/', { waitUntil: 'networkidle' });

    await expect(page).toHaveTitle(new RegExp(re(shop().name)));
    await expect(page.locator('link[rel="icon"]').first()).toHaveAttribute('href', /favicon/);
    await expect(page.locator('h1')).toContainText(new RegExp(re(shop().short_name) + '؛\\s*' + re(shop().hero_title)));
    // Categories: every category with something in stock, as arches (plus a hidden copy for the loop).
    const stocked = Number(php('echo count(boutique_stocked_categories());'));
    await expect(page.locator('.b-arcade__item:not([data-clone])')).toHaveCount(stocked);
    // Photo wall: three columns of real product photos, each linking to its product; no name/price tag.
    await expect(page.locator('.b-wall__col')).toHaveCount(3);
    expect(await page.locator('.b-wall a[aria-label]').count()).toBeGreaterThanOrEqual(9);
    await expect(page.locator('.b-hero2__tag')).toHaveCount(0);

    // Sale band must come right after the hero + ribbon, before categories.
    const order = await page.evaluate(() =>
      ['.b-hero2', '.b-promises', '.b-sale', '.b-cats', '.b-new', '.b-visit'].map((s) => {
        const el = document.querySelector(s);
        return el ? el.getBoundingClientRect().top + scrollY : -1;
      }));
    expect(order.every((y) => y >= 0), 'همه بخش‌های صفحه اصلی باید وجود داشته باشند').toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);

    // Must keep working during international internet cutoffs.
    expect(external, 'درخواست به سرور خارجی').toEqual([]);
    expect(problems).toEqual([]);
  });

  test('حالت تیره: پیش‌فرض روشن، با دکمه عوض می‌شود و یادش می‌ماند', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', 'dark');
    const bg = () => page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    const light = await bg();
    await page.locator('.b-theme:visible').click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await bg()).not.toBe(light);
    await page.goto('/contact/');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');   // remembered
    await expect(page.locator('.b-theme:visible')).toHaveAttribute('aria-pressed', 'true');
    await page.locator('.b-theme:visible').click();
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', 'dark');
  });

  test('حراج: درصد تخفیف از قیمت واقعی حساب می‌شود', async ({ page }) => {
    await page.goto('/');
    const card = page.locator('.b-sale__item', { hasText: 'لباس تست خودکار' });
    await expect(card.locator('.b-sale__off')).toHaveText('۲۰٪ تخفیف'); // 1,000,000 -> 800,000
    await expect(card.locator('.b-sale__price')).toContainText('800,000'); // digits are Latin in the HTML; the font draws them Persian
  });

  test('منو: همه دسته‌ها در بازشو هستند و «دسته‌بندی‌ها» به صفحه دسته‌ها می‌رود', async ({ page }) => {
    await page.goto('/');
    const top = await page.locator('#header [data-id="menu"] .menu > li > a').allInnerTexts();
    expect(top.map((t) => t.trim())).toEqual(['خانه', 'همه لباس‌ها', 'دسته‌بندی‌ها', 'حراج', 'درباره ما', 'تماس با ما']);
    const sub = (await page.locator('#header .b-menu-cats .sub-menu a').allTextContents()).map((t) => t.trim());
    for (const name of ['شلوار', 'شومیز', 'پیراهن مجلسی', 'بیکینی', 'هودی', 'دامن']) expect(sub).toContain(name);

    await page.locator('#header .b-menu-cats > a').click();
    await expect(page).toHaveURL(/\/categories\//);
    await expect(page.locator('.b-allcat')).toHaveCount(sub.length);
    await expect(page.locator('.b-allcat', { hasText: 'بیکینی' })).toContainText('به‌زودی'); // empty category
    await page.locator('.b-allcat', { hasText: 'پیراهن مجلسی' }).click();
    await expect(page).toHaveURL(/product-category\/evening/);
  });

  test('اینستاگرام و تلگرام با آیکون در صفحه اصلی و تماس', async ({ page }) => {
    for (const path of ['/', '/contact/']) {
      await page.goto(path);
      await expect(page.locator(`.b-social a[href="https://instagram.com/${shop().instagram}"] svg`)).toBeVisible();
      await expect(page.locator(`.b-social a[href="https://t.me/${shop().telegram_channel}"] svg`)).toBeVisible();
    }
    await expect(page.locator('main')).not.toContainText('اینماد');
  });

  test('صفحه‌های اصلی باز می‌شوند', async ({ page }) => {
    for (const path of ['/shop/', '/product-category/evening/', '/cart/', '/about/', '/contact/', '/terms/', '/my-account/']) {
      const res = await page.goto(path);
      expect(res.status(), path).toBe(200);
    }
    await page.goto('/contact/');
    await expect(page.locator('main')).toContainText('@' + shop().instagram);
    await expect(page.locator('main')).toContainText('@' + shop().telegram_channel);
  });

  test('صفحه محصول: عکس هر رنگ، سایز تمام‌شده، راهنمای سایز', async ({ page }) => {
    const f = fixture();
    const problems = watchForErrors(page);
    await page.goto(f.url, { waitUntil: 'networkidle' });

    // Each color carries its own photo.
    const variations = JSON.parse(await page.locator('form.variations_form').getAttribute('data-product_variations'));
    const img = (color) => variations.find((v) => v.attributes.attribute_pa_color === color).image.src;
    expect(img('black')).not.toBe(img('maroon'));

    // In-stock combination can be bought...
    await page.selectOption('select[name="attribute_pa_color"]', 'maroon');
    await page.selectOption('select[name="attribute_pa_size"]', '38');
    await expect(page.locator('.single_add_to_cart_button')).not.toHaveClass(/disabled/);
    // ...a sold-out one can't.
    await page.selectOption('select[name="attribute_pa_color"]', 'black');
    await page.selectOption('select[name="attribute_pa_size"]', '40');
    await expect(page.locator('.single_add_to_cart_button')).toHaveClass(/disabled/);

    // Fabric under the price; this product's own size guide (empty "waist" column dropped).
    await expect(page.locator('.b-material')).toContainText('ساتن');
    await page.locator('.woocommerce-tabs a', { hasText: 'راهنمای سایز' }).click();
    const table = page.locator('.b-size-table');
    await expect(table).toBeVisible();
    await expect(table.locator('thead')).toContainText('دور سینه');
    await expect(table.locator('thead')).not.toContainText('دور کمر');
    await expect(table.locator('tbody tr').first()).toContainText('88');
    await expect(page.locator('.b-size-note')).toContainText('قالب کمی کوچک است');
    expect(problems).toEqual([]);

    // A product without a guide has no size-guide tab (no shared, one-size-fits-all table).
    const other = php(`foreach (wc_get_products(['status' => 'publish', 'limit' => -1]) as $p) { if (!get_post_meta($p->get_id(), '_boutique_size_guide', true)) { echo $p->get_permalink(); break; } }`);
    await page.goto(other);
    await expect(page.locator('.woocommerce-tabs a', { hasText: 'راهنمای سایز' })).toHaveCount(0);
  });

  test('موبایل: هیچ صفحه‌ای اسکرول افقی ندارد', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    for (const path of ['/', '/shop/', fixture().url, '/cart/', '/contact/']) {
      await page.goto(path, { waitUntil: 'networkidle' });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      expect(overflow, `اسکرول افقی در ${path}`).toBeLessThanOrEqual(0);
    }
  });
});
