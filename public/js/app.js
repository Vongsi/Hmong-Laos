// Installable app: registers the service worker, shows the "Install" button where the
// browser supports it (Android/desktop Chrome, Edge), an "Add to Home Screen" hint on iPhone,
// and lets signed-in members turn phone notifications on or off.
(function () {
  if (!('serviceWorker' in navigator)) return;
  var reg = navigator.serviceWorker.register('/sw.js', { scope: '/' });

  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  var deferred = null;

  function $(sel) { return document.querySelector(sel); }
  function show(el, on) { if (el) el.hidden = !on; }

  function refreshInstall() {
    var panel = $('[data-app-panel]');
    if (!panel) return;
    show($('[data-app-installed]'), standalone);
    show($('[data-app-install]'), !standalone && !!deferred);
    show($('[data-app-ios]'), !standalone && isIos);
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    refreshInstall();
  });
  window.addEventListener('appinstalled', function () { deferred = null; standalone = true; refreshInstall(); });

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-app-install]') && deferred) {
      deferred.prompt();
      deferred.userChoice.finally(function () { deferred = null; refreshInstall(); });
    }
  });

  // ---- Push notifications (signed-in members only) ----
  var key = document.querySelector('meta[name="vapid-key"]');
  var csrf = document.querySelector('meta[name="csrf-token"]');
  var btn = $('[data-push-toggle]');
  var pushOk = key && btn && 'PushManager' in window && 'Notification' in window;

  function b64ToBytes(s) {
    var pad = '='.repeat((4 - (s.length % 4)) % 4);
    var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  function send(method, body) {
    return fetch('/push/subscriptions', {
      method: method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.content : '' },
      body: JSON.stringify(body)
    }).then(function (r) { if (!r.ok) throw new Error('push ' + r.status); });
  }

  function setLabel(on) {
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.textContent = on ? btn.dataset.labelOff : btn.dataset.labelOn;
  }

  function current() {
    return reg.then(function () { return navigator.serviceWorker.ready; })
      .then(function (r) { return r.pushManager.getSubscription(); });
  }

  if (pushOk) {
    show(btn, true);
    // iPhones only allow notifications inside the installed app.
    if (isIos && !standalone) { btn.disabled = true; show($('[data-push-ios]'), true); }
    current().then(function (sub) { setLabel(!!sub && Notification.permission === 'granted'); });

    btn.addEventListener('click', function () {
      btn.disabled = true;
      current().then(function (sub) {
        if (sub) {
          return send('DELETE', { endpoint: sub.endpoint }).then(function () { return sub.unsubscribe(); }).then(function () { setLabel(false); });
        }
        return Notification.requestPermission().then(function (perm) {
          if (perm !== 'granted') { show($('[data-push-denied]'), true); return; }
          return navigator.serviceWorker.ready
            .then(function (r) {
              // Some browsers never answer when their push service is unreachable; give up after 20 s.
              return Promise.race([
                r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key.content) }),
                new Promise(function (_, reject) { setTimeout(function () { reject(new Error('push timeout')); }, 20000); })
              ]);
            })
            .then(function (s) {
              var json = s.toJSON();
              var enc = (PushManager.supportedContentEncodings || ['aes128gcm'])[0];
              return send('POST', { endpoint: json.endpoint, keys: json.keys, contentEncoding: enc });
            })
            .then(function () { setLabel(true); });
        });
      }).catch(function () { show($('[data-push-error]'), true); })
        .finally(function () { btn.disabled = false; });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', refreshInstall);
  else refreshInstall();
})();
