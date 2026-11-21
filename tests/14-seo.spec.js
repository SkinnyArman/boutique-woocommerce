const { test, expect } = require('@playwright/test');
const { php, shop, re } = require('./helpers');

const jsonld = async (page) => (await page.locator('script[type="application/ld+json"]').allTextContents()).flatMap((t) => {
  const d = JSON.parse(t); return d['@graph'] || [d];
});
const meta = (page, sel) => page.locator(`meta[${sel}]`).getAttribute('content');

test.describe('موتورهای جستجو و پیش‌نمایش لینک', () => {
  test('صفحه اصلی: عنوان، توضیح، پیش‌نمایش، و فروشگاه با آدرس و ساعت کاری', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveTitle(`${shop().name} | ${shop().seo_tagline}`);
    expect((await meta(page, 'name="description"')).length).toBeGreaterThan(80);
    expect(await meta(page, 'property="og:image"')).toMatch(/^http/);
    const store = (await jsonld(page)).find((d) => d['@type'] === 'ClothingStore');
    expect(store.address.streetAddress).toBe(shop().street);
    expect(store.openingHoursSpecification[0]).toMatchObject({ opens: shop().open_hours[0], closes: shop().open_hours[1] });
    expect(store.telephone).toBe('+98' + shop().phone.replace(/^0/, ''));
    expect(store.sameAs.join(' ')).toContain('instagram.com/' + shop().instagram);
  });

  test('صفحه محصول: توضیح با نام و سایز، عکس در پیش‌نمایش، برند؛ «استعلام قیمت» بدون قیمت خالی', async ({ page }) => {
    const [normal, ask] = php(`
      $n = $a = '';
      foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $p) {
        if (!$n && !boutique_is_ask_price($p) && $p->get_price() && $p->get_image_id()) $n = $p->get_permalink();
        if (!$a && boutique_is_ask_price($p)) $a = $p->get_permalink();
      }
      echo $n . ' ' . $a;`).split(' ');
    await page.goto(normal);
    expect(await meta(page, 'name="description"')).toMatch(new RegExp('^خرید .+ از ' + re(shop().name)));
    expect(await meta(page, 'property="og:image"')).toMatch(/uploads/);
    const product = (await jsonld(page)).find((d) => d['@type'] === 'Product');
    expect(product.offers).toBeTruthy();
    // Google merchant listing fields
    const offer = [].concat(product.offers)[0];
    expect(offer.validFrom).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(offer.priceSpecification[0].validFrom).toBe(offer.validFrom);
    expect(offer.hasMerchantReturnPolicy).toMatchObject({ applicableCountry: 'IR', returnPolicyCategory: 'https://schema.org/MerchantReturnNotPermitted' });
    expect(offer.shippingDetails.shippingDestination.addressCountry).toBe('IR');
    expect(offer.shippingDetails.shippingRate.currency).toBe(offer.priceCurrency);
    expect(offer.shippingDetails.shippingRate.value).toBeGreaterThan(0);
    expect(offer.shippingDetails.deliveryTime.transitTime).toMatchObject({ minValue: 1, maxValue: 5, unitCode: 'DAY' });
    expect(await page.locator('.woocommerce-product-gallery img').first().getAttribute('alt')).not.toBe('');

    test.skip(!ask, 'هیچ محصول استعلام قیمتی نیست');
    await page.goto(ask);
    expect(await meta(page, 'name="description"')).toContain('قیمت با استعلام');
    const askProduct = (await jsonld(page)).find((d) => d['@type'] === 'Product');
    expect(askProduct && askProduct.offers).toBeFalsy();
  });

  test('سؤالات متداول: باز و بسته می‌شود و برای گوگل هم علامت‌گذاری شده', async ({ page }) => {
    await page.goto('/faq/');
    const items = page.locator('.b-faq__item');
    expect(await items.count()).toBeGreaterThan(5);
    await items.first().locator('summary').click();
    await expect(items.first().locator('.b-faq__a')).toBeVisible();
    const faq = (await jsonld(page)).find((d) => d['@type'] === 'FAQPage');
    expect(faq.mainEntity.length).toBe(await items.count());
    await page.goto('/');
    await expect(page.locator('.b-footlinks a', { hasText: 'سؤالات متداول' })).toHaveCount(1);
  });

  test('صفحه‌های غیرمحتوایی در گوگل نمی‌آیند؛ نقشه سایت فقط محتوا دارد', async ({ page, request }) => {
    for (const url of ['/track/', '/?s=test', '/shop/?orderby=price']) {
      await page.goto(url);
      expect(await page.locator('meta[name="robots"]').getAttribute('content'), url).toContain('noindex');
    }
    await page.goto('/about/');
    expect(await page.locator('meta[name="robots"]').getAttribute('content') || '').not.toContain('noindex');

    const index = await (await request.get('/wp-sitemap.xml')).text();
    expect(index).toContain('sitemap-posts-product');
    expect(index).not.toContain('sitemap-users');
    const pages = await (await request.get('/wp-sitemap-posts-page-1.xml')).text();
    for (const slug of ['/cart/', '/checkout/', '/my-account/', '/track/', '/sample-page/']) expect(pages).not.toContain(slug);
    expect(pages).toContain('/faq/');
    const robots = await (await request.get('/robots.txt')).text();
    expect(robots).toMatch(/Sitemap: .*wp-sitemap\.xml/);
  });

  test('هر صفحه‌ای که در نقشه سایت است یک آدرس اصلی (canonical) دارد، و نسخه‌های ?مرتب‌سازی به همان اشاره می‌کنند', async ({ page, request }) => {
    const index = await (await request.get('/wp-sitemap.xml')).text();
    const urls = [];
    for (const map of index.match(/https?:[^<]+\.xml/g)) {
      urls.push(...((await (await request.get(new URL(map).pathname)).text()).match(/<loc>[^<]+/g) || []).map((l) => l.slice(5)));
    }
    expect(urls.some((u) => u.includes('/shop/'))).toBe(true);
    for (const u of urls) {
      await page.goto(new URL(u).pathname);
      expect(await page.locator('link[rel="canonical"]').getAttribute('href'), u).toBe(u);
    }
    await page.goto('/shop/?orderby=price');
    expect(await page.locator('link[rel="canonical"]').getAttribute('href')).toMatch(/\/shop\/$/);
  });
});
