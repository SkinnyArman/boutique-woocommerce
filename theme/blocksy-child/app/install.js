/*
 * «افزودن به صفحه اصلی» boxes. Markup-driven, used by the storefront bar and the admin card:
 *   [data-b-install="<storage key>"]  the box (starts hidden)
 *     data-b-after="2"   show from the Nth page view (default 1)
 *     data-b-days="30"   after «بستن», stay away this many days (default 30)
 *   [data-b-install-btn]   install button (Android: real prompt; iPhone: shows the steps;
 *                          data-ios-label replaces its text on iPhone)
 *   [data-b-install-steps] iPhone steps
 *   [data-b-install-close] close / later
 * Phones only; never inside the installed app; never in Instagram/Facebook's in-app browser
 * (it has no "add to home screen").
 */
(function () {
  var root = document.documentElement;
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  if (standalone) { root.classList.add('b-is-app'); return; }

  var ua = navigator.userAgent;
  var ios = /iPhone|iPod|iPad/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var android = /Android/i.test(ua);
  if ((!ios && !android) || /Instagram|FBAN|FBAV/i.test(ua)) return;

  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

  var boxes = [].slice.call(document.querySelectorAll('[data-b-install]'));
  if (!boxes.length) return;
  var deferred = null;

  boxes.forEach(function (box) {
    var key = box.getAttribute('data-b-install');
    var views = (parseInt(get(key + ':views'), 10) || 0) + 1;
    set(key + ':views', String(views));
    var days = parseFloat(box.getAttribute('data-b-days')) || 30;
    var closed = parseInt(get(key + ':closed'), 10) || 0;
    box._ok = views >= (parseInt(box.getAttribute('data-b-after'), 10) || 1) && Date.now() - closed > days * 864e5;
    box._key = key;
  });

  function show(mode) {
    boxes.forEach(function (box) {
      if (!box._ok) return;
      box.setAttribute('data-mode', mode);
      var btn = box.querySelector('[data-b-install-btn]');
      if (btn && mode === 'ios' && btn.hasAttribute('data-ios-label')) btn.textContent = btn.getAttribute('data-ios-label');
      box.hidden = false;
      root.classList.add('b-has-install');
    });
  }
  function hideAll() {
    boxes.forEach(function (box) { box.hidden = true; });
    root.classList.remove('b-has-install');
  }

  // Android / Chrome / Samsung: the browser says when the site can be installed.
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    show('prompt');
  });
  window.addEventListener('appinstalled', function () {
    boxes.forEach(function (box) { set(box._key + ':closed', String(Date.now() + 3650 * 864e5)); });
    hideAll();
  });
  if (ios) show('ios');

  document.addEventListener('click', function (e) {
    var box = e.target.closest && e.target.closest('[data-b-install]');
    if (!box) return;
    if (e.target.closest('[data-b-install-close]')) {
      set(box._key + ':closed', String(Date.now()));
      hideAll();
    } else if (e.target.closest('[data-b-install-btn]')) {
      if (deferred) {
        deferred.prompt();
        deferred.userChoice.then(function (r) {
          deferred = null;
          if (r && r.outcome === 'accepted') hideAll();
        });
      } else {
        var steps = box.querySelector('[data-b-install-steps]');
        if (steps) { steps.hidden = !steps.hidden; box.classList.toggle('is-open', !steps.hidden); }
      }
    }
  });
})();
