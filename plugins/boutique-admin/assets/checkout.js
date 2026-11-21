/* Checkout: show the courier-location box or the pickup box for the chosen delivery method. */
jQuery(function ($) {
  var $courier = $('.b-deliv--courier'), $pickup = $('.b-deliv--pickup').first();
  var courierId = $courier.data('b-courier');
  var addressRows = '#billing_address_1_field, #billing_address_2_field, #billing_postcode_field';

  function chosen() {
    var $m = $('input[name^="shipping_method"]');
    return ($m.filter(':checked').val() || $m.filter('[type="hidden"]').val() || '');
  }
  function sync() {
    var m = chosen(), isPickup = /^(local_pickup|pickup_location)/.test(m);
    $courier.prop('hidden', m !== courierId);
    $pickup.prop('hidden', !isPickup);
    $(addressRows).toggle(!isPickup);
  }
  $(document.body).on('updated_checkout', sync);
  $(document.body).on('change', 'input[name^="shipping_method"]', sync);
  // Courier is only for the city of Tehran: re-price when the city changes.
  $(document.body).on('change', '#billing_city, #shipping_city', function () { $(document.body).trigger('update_checkout'); });
  sync();

  var $status = $courier.find('.b-deliv__status');
  $courier.on('click', '.b-deliv__gps', function () {
    if (!navigator.geolocation) { $status.text('این مرورگر موقعیت را نمی‌دهد؛ لینک لوکیشن را بگذارید.'); return; }
    $status.text('در حال گرفتن موقعیت…');
    navigator.geolocation.getCurrentPosition(function (p) {
      var lat = p.coords.latitude.toFixed(6), lng = p.coords.longitude.toFixed(6);
      $('#b_loc_lat').val(lat); $('#b_loc_lng').val(lng);
      $status.html('موقعیت ثبت شد ✓ <a target="_blank" rel="noopener" href="https://neshan.org/maps/@' + lat + ',' + lng + ',17.0z,0.0p">دیدن روی نقشه</a>');
    }, function () {
      $status.text('موقعیت گرفته نشد (اجازه داده نشد یا GPS خاموش است). لینک لوکیشن را بگذارید.');
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
  });
});

/* Terms open in a dialog instead of unfolding inside the form. */
(function () {
  var d = document.getElementById('b-terms');
  if (!d || !d.showModal) return;
  document.body.addEventListener('click', function (e) {
    var a = e.target.closest('a.woocommerce-terms-and-conditions-link');
    if (a) { e.preventDefault(); e.stopPropagation(); d.showModal(); return; }
    if (e.target.closest('.b-terms__close') || e.target === d) d.close();
  }, true);
})();
