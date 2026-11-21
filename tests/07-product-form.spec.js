const { test, expect } = require('@playwright/test');
const { login, phpJson, watchForErrors, fixture, php } = require('./helpers');

const product = (id) => phpJson(`
  $p = wc_get_product(${id}); $vars = [];
  foreach ($p->get_children() as $c) { $v = wc_get_product($c); $a = $v->get_attributes();
    $vars[] = ['color' => get_term_by('slug', $a['pa_color'] ?? '', 'pa_color')->name ?? '', 'size' => $a['pa_size'] ?? '',
      'reg' => (float) $v->get_regular_price(), 'sale' => (float) $v->get_sale_price(), 'stock' => (int) $v->get_stock_quantity(), 'img' => (int) $v->get_image_id('edit')]; }
  echo wp_json_encode(['name' => $p->get_name(), 'status' => $p->get_status(), 'type' => $p->get_type(), 'image' => (int) $p->get_image_id(),
    'cats' => wp_list_pluck(get_the_terms(${id}, 'product_cat') ?: [], 'name'), 'vars' => $vars]);`);

test('فرم ساده «لباس جدید»: ساخت، رنگ جدید، عکسِ رنگ، و ویرایش', async ({ page }) => {
  const problems = watchForErrors(page);
  await login(page, 'manager');
  await page.goto('/wp-admin/admin.php?page=boutique-product');

  // Publishing an empty form explains exactly what is missing.
  await page.click('[data-status="publish"]');
  await expect(page.locator('#b-pe-msg')).toHaveText('لطفاً وارد کنید: اسم لباس، قیمت، حداقل یک عکس، دسته');

  // Photo from the library.
  await page.click('#b-pe-addphoto');
  await page.click('#menu-item-browse');
  await page.locator('.media-modal .attachment').first().click();
  await page.click('.media-modal .media-button-select');
  await expect(page.locator('.b-ph.is-main')).toBeVisible();

  await page.fill('#b-pe-name', 'تست خودکار فرم');
  await page.locator('label.b-chipcheck', { hasText: 'پیراهن مجلسی' }).first().click();
  await page.locator('#b-pe-price').pressSequentially('1850000');
  await page.locator('#b-pe-sale').pressSequentially('۱۵۰۰۰۰۰');
  await expect(page.locator('#b-pe-price')).toHaveValue('۱,۸۵۰,۰۰۰');
  await expect(page.locator('#b-pe-off')).toContainText('۱۹٪ تخفیف');

  await page.locator('#b-pe-colors label', { hasText: 'مشکی' }).click();
  await page.fill('#b-pe-newcolor', 'رنگ‌تست');     // a color the shop never had
  await page.click('[data-add="colors"]');
  await page.locator('#b-pe-sizes label', { hasText: '۳۸' }).click();
  await expect(page.locator('#b-pe-stock input')).toHaveCount(2);
  await page.locator('#b-pe-stock input').nth(0).fill('2');
  await page.locator('#b-pe-stock input').nth(1).fill('۳');
  // Tag the photo as the new color's photo.
  await page.locator('[data-photo="0"]').selectOption({ label: 'رنگ‌تست' });

  await page.click('[data-status="publish"]');
  // (WordPress strips "saved=…" from the address bar after load, so wait for the message itself.)
  await expect(page.locator('.b-done')).toContainText('در سایت نمایش داده می‌شود', { timeout: 30_000 });
  // After saving, the manager lands on the products list «لباس‌ها», not the same form.
  await expect(page).toHaveURL(/page=boutique-products/);
  await expect(page.locator('h1.b-title')).toHaveText('لباس‌ها');
  await expect(page.locator('.b-done')).toContainText('«تست خودکار فرم»');
  const id = Number(await page.locator('.b-done').getAttribute('data-item'));
  await expect(page.locator('.b-done a', { hasText: 'ویرایش دوباره' })).toHaveAttribute('href', new RegExp(`product=${id}`));

  let p = product(id);
  expect(p).toMatchObject({ name: 'تست خودکار فرم', status: 'publish', type: 'variable', cats: ['پیراهن مجلسی'] });
  expect(p.image).toBeGreaterThan(0);
  expect(p.vars).toHaveLength(2);
  const black = p.vars.find((v) => v.color === 'مشکی');
  const fresh = p.vars.find((v) => v.color === 'رنگ‌تست');
  expect(black).toMatchObject({ size: '38', reg: 1850000, sale: 1500000, stock: 2, img: 0 });
  expect(fresh).toMatchObject({ size: '38', reg: 1850000, sale: 1500000, stock: 3 }); // regression: used to save 0
  expect(fresh.img).toBe(p.image);

  // Live on the storefront.
  const res = await page.goto(`/?p=${id}`);
  expect(res.status()).toBe(200);
  await expect(page.locator('h1')).toHaveText('تست خودکار فرم');

  // Edit: form comes back filled; add a size, drop the sale, save as draft.
  await page.goto(`/wp-admin/admin.php?page=boutique-product&product=${id}`);
  await expect(page.locator('#b-pe-name')).toHaveValue('تست خودکار فرم');
  await expect(page.locator('[data-photo="0"]')).toHaveValue(/\d+/);
  await page.locator('#b-pe-sizes label', { hasText: '۴۰' }).click();
  await expect(page.locator('#b-pe-stock input')).toHaveCount(4);
  await page.fill('#b-pe-sale', '');
  await page.click('[data-status="draft"]');
  await expect(page.locator('.b-done')).toContainText('پیش‌نویس ذخیره شد', { timeout: 30_000 });
  await expect(page).toHaveURL(/page=boutique-products/);

  p = product(id);
  expect(p.status).toBe('draft');
  expect(p.vars).toHaveLength(4);
  expect(p.vars.every((v) => v.sale === 0 && v.reg === 1850000)).toBe(true);
  expect(p.vars.find((v) => v.color === 'رنگ‌تست' && v.size === '38').stock).toBe(3); // untouched cell kept
  expect(problems).toEqual([]);
});

test('برند (اختیاری): انتخاب یا نوشتن برند جدید، و خالی کردنش', async ({ page }) => {
  const f = fixture();
  await login(page, 'manager');
  const open = () => page.goto(`/wp-admin/admin.php?page=boutique-product&product=${f.id}`);
  const brandOf = () => php(`echo implode(',', wp_get_post_terms(${f.id}, 'product_brand', ['fields' => 'names']));`);
  await open();
  await page.fill('#b-pe-brand', 'برند آزمایشی');
  await page.click('[data-status="publish"]');
  await expect(page.locator('.b-done')).toContainText('ذخیره شد');
  expect(brandOf()).toBe('برند آزمایشی');
  await open();
  await expect(page.locator('#b-pe-brand')).toHaveValue('برند آزمایشی');
  await page.fill('#b-pe-brand', '');
  await page.click('[data-status="publish"]');
  await expect(page.locator('.b-done')).toContainText('ذخیره شد');
  expect(brandOf()).toBe('');
  php(`$t = get_term_by('name', 'برند آزمایشی', 'product_brand'); if ($t) wp_delete_term($t->term_id, 'product_brand');`);
});

test('جنس و راهنمای سایزِ هر لباس در فرم ذخیره و در سایت دیده می‌شود', async ({ page }) => {
  const f = fixture();
  await login(page, 'manager');
  await page.goto(`/wp-admin/admin.php?page=boutique-product&product=${f.id}`);
  await page.fill('#b-pe-material', 'لینن');
  // Rows follow the product's sizes (38, 40); Persian digits are fine.
  await expect(page.locator('#b-pe-guide tbody tr')).toHaveCount(2);
  await page.fill('input[name="guide[38][waist]"]', '۷۰');
  await page.fill('input[name="guide[40][length]"]', '۱۲۰-۱۲۵');
  await page.fill('#b-pe-guidenote', 'یک سایز بزرگ‌تر بردارید');
  await page.click('[data-status="publish"]');
  await expect(page.locator('.b-done')).toContainText('ذخیره شد');
  const g = JSON.parse(php(`echo wp_json_encode(get_post_meta(${f.id}, '_boutique_size_guide', true));`));
  expect(g.rows['38'].waist).toBe('70');
  expect(g.rows['40'].length).toBe('120-125');
  expect(php(`echo get_post_meta(${f.id}, '_boutique_material', true);`)).toBe('لینن');
  await page.goto(f.url);
  await expect(page.locator('.b-material')).toContainText('لینن');
  await page.locator('.woocommerce-tabs a', { hasText: 'راهنمای سایز' }).click();
  await expect(page.locator('.b-size-table')).toContainText('120-125');
});

test('«استعلام قیمت» زیر قیمت: تیک بخورد قیمت غیرفعال می‌شود و در سایت «استعلام قیمت»؛ تیک برداشته شود قیمت نمایش داده می‌شود', async ({ page }) => {
  const f = fixture();
  await login(page, 'manager');
  const open = () => page.goto(`/wp-admin/admin.php?page=boutique-product&product=${f.id}`);
  const askOf = () => php(`echo get_post_meta(${f.id}, '_boutique_ask_price', true);`);
  const priceBefore = php(`echo wc_get_product(${f.id})->get_price();`);
  const url = php(`echo get_permalink(${f.id});`);

  await open();
  await expect(page.locator('#b-pe-ask')).not.toBeChecked();
  await expect(page.locator('#b-pe-price')).toBeEnabled();
  await page.locator('#b-pe-ask').check();
  await expect(page.locator('#b-pe-price')).toBeDisabled();
  await expect(page.locator('#b-pe-sale')).toBeDisabled();
  await page.click('[data-status="publish"]');
  await expect(page.locator('.b-done')).toContainText('ذخیره شد');
  expect(askOf()).toBe('yes');
  expect(php(`echo wc_get_product(${f.id})->get_price();`)).toBe(priceBefore); // saved price kept
  await page.goto(url);
  await expect(page.locator('.summary .b-ask')).toHaveText('استعلام قیمت');

  await open();
  await expect(page.locator('#b-pe-ask')).toBeChecked();
  await expect(page.locator('#b-pe-price')).toBeDisabled();
  await page.locator('#b-pe-ask').uncheck();
  await expect(page.locator('#b-pe-price')).toBeEnabled();
  await page.click('[data-status="publish"]');
  await expect(page.locator('.b-done')).toContainText('ذخیره شد');
  expect(askOf()).toBe('');
  await page.goto(url);
  await expect(page.locator('.summary .b-ask')).toHaveCount(0);
  await expect(page.locator('.summary .price').first()).toContainText('تومان');
});
