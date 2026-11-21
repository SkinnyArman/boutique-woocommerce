const { test, expect } = require('@playwright/test');
const { login, fixture, php, phpJson, orderInfo, watchForErrors, shop } = require('./helpers');

/** Delivery options WooCommerce offers for an address (state code + city), as titles. */
const ratesFor = (state, city) => phpJson(`
  WC()->frontend_includes(); WC()->session = new WC_Session_Handler(); WC()->session->init(); WC()->customer = new WC_Customer(); WC()->cart = new WC_Cart();
  WC()->cart->add_to_cart(${fixture().id}, 1, ${fixture().vars['black|38']}, ['attribute_pa_color' => 'black', 'attribute_pa_size' => '38']);
  $pkg = ['contents' => WC()->cart->get_cart(), 'contents_cost' => 1000000, 'applied_coupons' => [], 'user' => ['ID' => 0],
          'destination' => ['country' => 'IR', 'state' => '${state}', 'postcode' => '', 'city' => '${city}', 'address' => '', 'address_1' => '', 'address_2' => '']];
  $r = WC()->shipping()->calculate_shipping_for_package($pkg)['rates'];
  echo wp_json_encode(array_values(array_map(fn($x) => $x->get_label(), $r)));`);

async function setSelect(page, selector, value) {
  await page.waitForFunction(([s, v]) => document.querySelector(s) && [...document.querySelector(s).options].some((o) => o.value === v), [selector, value]);
  await page.evaluate(([s, v]) => { const el = document.querySelector(s); el.value = v; window.jQuery(el).trigger('change'); }, [selector, value]);
}
const courierRate = () => 'flat_rate:' + php("echo (int) get_option('boutique_courier_instance');");
const settle = async (page) => { await page.waitForLoadState('networkidle'); await expect(page.locator('.blockUI')).toHaveCount(0); };

async function checkoutWith(page, { city, method, extra }) {
  const f = fixture();
  await page.context().clearCookies();
  await page.goto(`/?add-to-cart=${f.id}&variation_id=${f.vars['black|38']}&attribute_pa_color=black&attribute_pa_size=38`);
  await page.goto('/checkout/', { waitUntil: 'networkidle' });
  await page.fill('#billing_first_name', 'مشتری');
  await page.fill('#billing_last_name', 'تحویل');
  await page.fill('#billing_phone', '09120000001');
  await setSelect(page, '#billing_state', 'THR');
  await setSelect(page, '#billing_city', city);
  await settle(page);
  await page.locator(`input[name^="shipping_method"][value^="${method}"]`).first().check();
  await settle(page);
  if (extra) await extra();
  await expect(page.locator('#payment_method_cod')).toHaveCount(0);   // no paying on delivery
  if (!(await page.locator('#payment_method_bacs').isChecked())) await page.click('label[for="payment_method_bacs"]');
  await page.check('#terms');
  await page.click('#place_order');
  await page.waitForURL(/order-received\/(\d+)/, { timeout: 30_000 });
  return Number(page.url().match(/order-received\/(\d+)/)[1]);
}
const step = (page, orderId, to) =>
  page.locator(`form.b-step:has(input[name="order_id"][value="${orderId}"]):has(input[name="to"][value="${to}"])`);
const track = async (page, order) => {
  await page.goto('/track/');
  await page.fill('input[name="order"]', String(order));
  await page.fill('input[name="phone"]', '09120000001');
  await page.click('.b-track__form button');
};

test.beforeAll(() => php('global $wpdb; $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE \'_transient%b_track_%\'");'));

test('پیک فقط داخل شهر تهران؛ تحویل حضوری همه‌جا', () => {
  const tehran = ratesFor('THR', 'تهران');
  expect(tehran.some((t) => t.includes('پیک'))).toBe(true);
  expect(ratesFor('THR', 'تهران ').some((t) => t.includes('پیک'))).toBe(true);   // stray space
  expect(ratesFor('THR', 'شهریار').some((t) => t.includes('پیک'))).toBe(false); // same province, other city
  const isfahan = ratesFor('ISF', 'اصفهان');
  expect(isfahan.some((t) => t.includes('پیک'))).toBe(false);
  for (const r of [tehran, isfahan]) expect(r).toContain('تحویل حضوری از فروشگاه');
});

test('پیک: مشتری لینک لوکیشن می‌گذارد؛ مدیر «باز کردن در نشان» و «کپی برای پیک» دارد', async ({ page }) => {
  const problems = watchForErrors(page);
  const id = await checkoutWith(page, {
    city: 'تهران', method: courierRate(),
    extra: async () => {
      await expect(page.locator('.b-deliv--courier')).toBeVisible();
      await expect(page.locator('.b-deliv--courier')).toContainText('کرایه پیک را هنگام تحویل');
      await expect(page.locator('#shipping_method')).not.toContainText(/پیک[^\n]*:/);   // no price next to it
      await expect(page.locator('.b-deliv--pickup')).toBeHidden();
      await page.fill('#billing_address_1', 'اقدسیه، کوچه آزمایش، پلاک ۲');
      await page.fill('#billing_postcode', '1234567890');
      await page.fill('#b_loc_link', 'https://www.google.com/maps?q=35.795212,51.479530');
    },
  });
  expect(php(`echo wc_get_order(${id})->get_meta('_boutique_loc');`)).toBe('35.795212,51.47953');
  expect(problems).toEqual([]);

  await login(page, 'manager');
  await step(page, id, 'processing').locator('button').click();   // card-to-card confirmed
  const card = page.locator('.b-order', { has: step(page, id, 'completed') });
  await expect(card.locator('a', { hasText: 'باز کردن در نشان' })).toHaveAttribute('href', /neshan\.org\/maps\/@35\.795212,51\.47953/);
  const copy = await card.locator('.b-copy').getAttribute('data-copy');
  expect(copy).toContain('09120000001');
  expect(copy).toContain('اقدسیه');
  expect(copy).toContain('neshan.org');
});

test('لینک‌های لوکیشن نشان، بلد، گوگل و مختصات خالی خوانده می‌شوند', () => {
  const parse = (s) => php(`echo wp_json_encode(boutique_parse_location(${JSON.stringify(s)}));`);
  expect(parse('https://neshan.org/maps/@35.7952,51.4795,17.0z,0.0p')).toBe('[35.7952,51.4795]');
  expect(parse('https://balad.ir/location?latitude=35.7952&longitude=51.4795&zoom=16')).toBe('[35.7952,51.4795]');
  expect(parse('۳۵.۷۹۵۲، ۵۱.۴۷۹۵')).toBe('[35.7952,51.4795]');
  expect(parse('51.4795, 35.7952')).toBe('[35.7952,51.4795]');   // swapped
  expect(parse('https://nshn.ir/abc')).toBe('null');               // short link: kept as a link only
  expect(parse('48.85, 2.35')).toBe('null');                       // not in Iran
});

test('تحویل حضوری: بدون آدرس؛ «آماده شد» ← مشتری آدرس فروشگاه را می‌بیند ← «تحویل داده شد»', async ({ page }) => {
  const problems = watchForErrors(page);
  const id = await checkoutWith(page, {
    city: 'شهریار', method: 'local_pickup',
    extra: async () => {
      await expect(page.locator('.b-deliv--pickup')).toBeVisible();
      await expect(page.locator('.b-deliv--pickup')).toContainText(shop().address);
      await expect(page.locator('#billing_address_1')).toBeHidden();   // no street address needed
      await expect(page.locator('.b-deliv--courier')).toBeHidden();
    },
  });
  await expect(page.locator('.woocommerce-order')).toContainText(shop().address);
  expect(orderInfo(id).status).toBe('on-hold');      // card-to-card, waiting for the receipt
  await expect(page.locator('.b-deliv--pickup')).toContainText('تماس بگیرید');
  expect(problems).toEqual([]);

  await login(page, 'manager');
  await step(page, id, 'processing').locator('button').click();
  await track(page, id);
  await expect(page.locator('.b-track__steps li')).toHaveCount(4);
  await expect(page.locator('.b-track__steps li.is-now')).toHaveText('ثبت شده');

  await login(page, 'manager');
  await expect(step(page, id, 'completed')).toHaveCount(0);   // not before it's ready
  await step(page, id, 'ready').locator('button').click();
  await expect(page.locator('.b-notice')).toContainText('آماده');
  await expect(step(page, id, 'completed')).toBeVisible();
  await expect(page.locator('.b-order', { has: step(page, id, 'completed') })).not.toContainText('مهلت');   // no hold limit

  await track(page, id);
  await expect(page.locator('.b-track__status')).toContainText('آماده تحویل در فروشگاه');
  await expect(page.locator('.b-track__status')).toContainText(shop().address);
  await expect(page.locator('.b-track__status')).toContainText('تماس بگیرید');
  await expect(page.locator('.b-track__steps li.is-now')).toHaveText('آماده تحویل');

  await login(page, 'manager');
  await step(page, id, 'completed').locator('button').click();
  expect(orderInfo(id).status).toBe('completed');
  await track(page, id);
  await expect(page.locator('.b-track__status')).toContainText('تحویل داده شد');
  await expect(page.locator('.b-track__steps li.is-now')).toHaveText('تحویل داده شد');
});

test('دکمه «موقعیت من» موقعیت گوشی را برای پیک برمی‌دارد', async ({ browser }) => {
  const context = await browser.newContext({ geolocation: { latitude: 35.7951, longitude: 51.4796 }, permissions: ['geolocation'] });
  const page = await context.newPage();
  const f = fixture();
  await page.goto(`/?add-to-cart=${f.id}&variation_id=${f.vars['black|38']}&attribute_pa_color=black&attribute_pa_size=38`);
  await page.goto('/checkout/', { waitUntil: 'networkidle' });
  await setSelect(page, '#billing_state', 'THR');
  await setSelect(page, '#billing_city', 'تهران');
  await settle(page);
  await page.locator(`input[name^="shipping_method"][value="${courierRate()}"]`).check();
  await page.click('.b-deliv__gps');
  await expect(page.locator('.b-deliv__status')).toContainText('موقعیت ثبت شد');
  await expect(page.locator('#b_loc_lat')).toHaveValue('35.795100');
  await context.close();
});
