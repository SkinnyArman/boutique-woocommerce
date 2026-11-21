const { test, expect } = require('@playwright/test');
const { login, fixture, php, phpJson } = require('./helpers');

const methods = () => phpJson(`$o = []; foreach (WC_Shipping_Zones::get_zones() as $z) foreach ($z['shipping_methods'] as $m) $o[] = ['id' => $m->get_instance_id(), 'title' => $m->get_title(), 'cost' => (int) $m->get_option('cost', 0), 'on' => $m->enabled]; echo wp_json_encode($o);`);

test('هزینه ارسال: مدیر قیمت پست را عوض می‌کند و مشتری همان را می‌بیند؛ پیک پس‌کرایه است', async ({ page }) => {
  const courier = methods().find((m) => m.title.includes('پست')); // Tehran zone's Post (listed first)
  const f = fixture();
  const row = `.b-ship__row[data-instance="${courier.id}"]`;
  const save = async (cost, on = true) => {
    await page.goto('/wp-admin/admin.php?page=boutique-shipping');
    await page.fill(`${row} .b-ship__cost input`, cost);
    await page.locator(`${row} .b-ship__on input`).setChecked(on);
    await page.click('.b-ship__form button[type="submit"]');
    await expect(page.locator('.b-done')).toContainText('ذخیره شد');
  };
  const cartText = async () => {
    await page.goto(`/?add-to-cart=${f.id}&variation_id=${f.vars['black|38']}&attribute_pa_color=black&attribute_pa_size=38`);
    await page.goto('/cart/', { waitUntil: 'networkidle' });
    return page.locator('.cart_totals');
  };

  // The cart prices delivery to the shopper's saved address; courier is offered in the city of Tehran only.
  php(`foreach (['billing', 'shipping'] as $t) { update_user_meta(get_user_by('login', 'manager')->ID, "{$t}_state", 'THR'); update_user_meta(get_user_by('login', 'manager')->ID, "{$t}_city", 'تهران'); }`);
  try {
    await login(page, 'manager');
    await save('۱۳۵۰۰۰');                               // Persian digits
    expect(methods().find((m) => m.id === courier.id).cost).toBe(135000);
    await expect(await cartText()).toContainText('135,000');

    await save('135000', false);                        // switched off: customers don't see it
    await expect(await cartText()).not.toContainText('پست پیشتاز');

    // Courier: no price box; the customer pays the rider.
    const pk = methods().find((m) => m.title.includes('پیک'));
    await page.goto('/wp-admin/admin.php?page=boutique-shipping');
    await expect(page.locator(`.b-ship__row[data-instance="${pk.id}"] .b-ship__cost`)).toHaveCount(0);
    await expect(page.locator(`.b-ship__row[data-instance="${pk.id}"]`)).toContainText('پس‌کرایه');
    expect(pk.cost).toBe(0);
  } finally {
    await save(String(courier.cost), true);             // put the shop's real price back
  }
  expect(methods().find((m) => m.id === courier.id)).toMatchObject({ cost: courier.cost, on: 'yes' });
});
