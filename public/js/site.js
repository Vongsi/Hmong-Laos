// Theme switch: remembers the visitor's choice, otherwise follows the phone or computer setting.
(function () {
  var root = document.documentElement;
  function stored() { try { return localStorage.getItem('hl-theme'); } catch (e) { return null; } }
  function setTheme(t) {
    root.setAttribute('data-theme', t);
    try { localStorage.setItem('hl-theme', t); } catch (e) {}
    var btn = document.getElementById('theme-btn');
    if (btn) btn.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
  }
  var initial = stored() || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  setTheme(initial);

  document.addEventListener('click', function (e) {
    if (e.target.closest('#theme-btn')) {
      setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    }
  });

  // Share: "Copy link" buttons.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var url = btn.getAttribute('data-copy');
    var label = btn.textContent;
    function done() { btn.textContent = btn.getAttribute('data-done'); setTimeout(function () { btn.textContent = label; }, 2000); }
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(url).then(done, function () { window.prompt('', url); });
    else window.prompt('', url);
  });

  // Site map: "Open all" / "Close all" for the tree's sections.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-tree-all]');
    if (!btn) return;
    var open = btn.getAttribute('data-tree-all') === 'open';
    document.querySelectorAll('.site-tree details').forEach(function (d) { d.open = open; });
  });

  // Language dropdown: each option's value is the same page in that language.
  document.addEventListener('change', function (e) {
    if (e.target.id === 'lang-select' && e.target.value) window.location.href = e.target.value;
    if (e.target.matches('[data-filter-select]')) e.target.form.submit();
  });
})();

// Home page pop-up (Globals → Home page pop-up): opens once; closing it, by the ×, the Close button,
// Escape or a click outside, hides this announcement for good on this device. A new one shows again.
(function () {
  var dialog = document.querySelector('dialog[data-popup]');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  var key = 'hl-popup';
  var version = dialog.getAttribute('data-popup');
  function remember() { try { localStorage.setItem(key, version); } catch (e) {} }
  try { if (localStorage.getItem(key) === version) return; } catch (e) {}

  dialog.addEventListener('close', remember);
  // The backdrop belongs to the dialog element itself; its content sits in child elements.
  dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });
  dialog.querySelectorAll('[data-popup-seen]').forEach(function (a) { a.addEventListener('click', remember); });
  setTimeout(function () { dialog.showModal(); }, 600);
})();

// "Track on Anousith" also copies the tracking number, ready to paste into the courier's tracking box.
document.addEventListener('click', function (e) {
  var link = e.target.closest('[data-copy-also]');
  if (link && navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(link.getAttribute('data-copy-also')).catch(function () {});
  }
});

// Full-screen photo viewer, a native <dialog> (focus, Escape and screen readers work as usual): the whole
// photo, zoom (tap the photo or +), swipe or arrow keys for the next one. `t` holds the labels (data-t-open,
// -prev, -next, -zoom, -close, -of on the page); onClose gets the photo that was showing.
function photoViewer(t, onClose) {
  var photos = [];
  var current = 0;
  var dialog = document.createElement('dialog');
  dialog.className = 'viewer';
  dialog.setAttribute('aria-label', t.tOpen);
  dialog.innerHTML =
    '<div class="viewer-stage"><img alt=""></div>' +
    '<p class="viewer-count" aria-live="polite"></p>' +
    '<button type="button" class="viewer-btn viewer-close">×</button>' +
    '<button type="button" class="viewer-btn viewer-zoom">+</button>' +
    '<button type="button" class="viewer-btn viewer-prev">‹</button>' +
    '<button type="button" class="viewer-btn viewer-next">›</button>';
  document.body.appendChild(dialog);
  var stage = dialog.querySelector('.viewer-stage');
  var img = stage.querySelector('img');
  var zoomBtn = dialog.querySelector('.viewer-zoom');
  dialog.querySelector('.viewer-close').setAttribute('aria-label', t.tClose);
  zoomBtn.setAttribute('aria-label', t.tZoom);
  dialog.querySelector('.viewer-prev').setAttribute('aria-label', t.tPrev);
  dialog.querySelector('.viewer-next').setAttribute('aria-label', t.tNext);

  function zoom(on, e) {
    dialog.classList.toggle('zoomed', on);
    zoomBtn.textContent = on ? '−' : '+';
    zoomBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (on && e) {
      // Zoom in on the spot that was tapped.
      var r = stage.getBoundingClientRect();
      stage.scrollLeft = ((e.clientX - r.left) / r.width) * stage.scrollWidth - r.width / 2;
      stage.scrollTop = ((e.clientY - r.top) / r.height) * stage.scrollHeight - r.height / 2;
    }
  }
  function show(i) {
    current = (i + photos.length) % photos.length;
    zoom(false);
    img.src = photos[current].src;
    img.alt = photos[current].alt || '';
    dialog.querySelector('.viewer-count').textContent = t.tOf.replace(':n', current + 1).replace(':total', photos.length);
  }
  dialog.querySelector('.viewer-close').addEventListener('click', function () { dialog.close(); });
  dialog.querySelector('.viewer-prev').addEventListener('click', function () { show(current - 1); });
  dialog.querySelector('.viewer-next').addEventListener('click', function () { show(current + 1); });
  zoomBtn.addEventListener('click', function () { zoom(!dialog.classList.contains('zoomed')); });
  img.addEventListener('click', function (e) { zoom(!dialog.classList.contains('zoomed'), e); });
  dialog.addEventListener('click', function (e) { if (e.target === dialog || e.target === stage) dialog.close(); });
  dialog.addEventListener('close', function () { zoom(false); if (onClose) onClose(current); });
  dialog.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') show(current - 1);
    if (e.key === 'ArrowRight') show(current + 1);
  });
  // Zoomed with a mouse: the picture follows the pointer. Touch screens pan by dragging.
  stage.addEventListener('mousemove', function (e) {
    if (!dialog.classList.contains('zoomed')) return;
    var r = stage.getBoundingClientRect();
    stage.scrollLeft = ((e.clientX - r.left) / r.width) * (stage.scrollWidth - r.width);
    stage.scrollTop = ((e.clientY - r.top) / r.height) * (stage.scrollHeight - r.height);
  });
  // Swipe to change photo (not while zoomed: then a swipe moves around the photo).
  var startX = null;
  stage.addEventListener('touchstart', function (e) { startX = dialog.classList.contains('zoomed') ? null : e.touches[0].clientX; }, { passive: true });
  stage.addEventListener('touchend', function (e) {
    if (startX === null) return;
    var dx = e.changedTouches[0].clientX - startX;
    if (Math.abs(dx) > 50) show(current + (dx < 0 ? 1 : -1));
  });

  return {
    /** @param list [{ src, alt }] */
    open: function (list, i) {
      photos = list;
      dialog.classList.toggle('single', photos.length < 2);
      show(i);
      dialog.showModal();
    },
  };
}

// Product photos: thumbnails under the gallery, the full-screen viewer, and jumping to a colour's photo when
// the buyer picks that colour.
(function () {
  var wrap = document.querySelector('[data-gallery]');
  var track = wrap && wrap.querySelector('.gallery');
  var slides = track ? [].slice.call(track.querySelectorAll('.gallery-slide')) : [];
  if (!slides.length) return;
  var t = wrap.dataset;
  var current = 0;
  function counter(i) { return t.tOf.replace(':n', i + 1).replace(':total', slides.length); }

  function goTo(i) {
    current = (i + slides.length) % slides.length;
    track.scrollTo({ left: slides[current].offsetLeft - track.offsetLeft, behavior: 'smooth' });
    markThumb();
  }

  // Thumbnails.
  var thumbs = [];
  if (slides.length > 1) {
    var row = document.createElement('div');
    row.className = 'gallery-thumbs';
    slides.forEach(function (slide, i) {
      var b = document.createElement('button');
      var pic = document.createElement('img');
      b.type = 'button';
      b.setAttribute('aria-label', counter(i));
      pic.alt = '';
      pic.loading = 'lazy';
      pic.src = slide.dataset.thumb;
      b.appendChild(pic);
      b.addEventListener('click', function () { goTo(i); });
      row.appendChild(b);
      thumbs.push(b);
    });
    wrap.appendChild(row);
  }
  function markThumb() {
    thumbs.forEach(function (b, i) { b.setAttribute('aria-current', i === current ? 'true' : 'false'); });
  }
  // Keep the thumbnails in step when the buyer swipes the gallery itself.
  var settle;
  track.addEventListener('scroll', function () {
    clearTimeout(settle);
    settle = setTimeout(function () {
      var i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
      if (i !== current && slides[i]) { current = i; markThumb(); }
    }, 80);
  });
  markThumb();

  // After the viewer closes, the gallery shows the photo that was last enlarged.
  var viewer = photoViewer(t, goTo);
  var list = slides.map(function (slide) { return { src: slide.dataset.full, alt: slide.querySelector('img').alt }; });
  slides.forEach(function (slide, i) {
    slide.setAttribute('aria-label', t.tOpen);
    slide.addEventListener('click', function () { viewer.open(list, i); });
  });

  // Picking a colour shows its photo.
  document.addEventListener('change', function (e) {
    if (!e.target.matches('[data-colour-choice]')) return;
    for (var i = 0; i < slides.length; i++) {
      if (slides[i].dataset.colour === e.target.value) { goTo(i); break; }
    }
  });
})();

// My account menu (header and phone tab bar): one open at a time; a tap elsewhere or Escape closes it.
(function () {
  function open() { return [].slice.call(document.querySelectorAll('details.account-menu[open]')); }
  document.addEventListener('click', function (e) {
    open().forEach(function (menu) { if (!menu.contains(e.target)) menu.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    open().forEach(function (menu) { menu.open = false; menu.querySelector('summary').focus(); });
  });
})();

// Orders on this phone: each order page the customer opens is remembered in this browser only (their own secret
// links), so "Find your order" can list them without an account. Newest first, at most 10.
(function () {
  var KEY = 'dah-orders';
  function read() {
    try { var list = JSON.parse(localStorage.getItem(KEY)); return Array.isArray(list) ? list : []; } catch (e) { return []; }
  }
  var here = document.querySelector('[data-order-remember]');
  if (here) {
    var d = here.dataset;
    var list = read().filter(function (o) { return o && o.number !== d.number; });
    list.unshift({ number: d.number, url: location.pathname + location.search, iso: d.iso, date: d.date, total: d.total });
    try { localStorage.setItem(KEY, JSON.stringify(list.slice(0, 10))); } catch (e) {}
  }
  var box = document.querySelector('[data-device-orders]');
  if (!box) return;
  var orders = read().filter(function (o) { return o && typeof o.url === 'string' && /^\/[^\/]/.test(o.url); });
  if (!orders.length) return;
  // The date in this page's language (it was saved on the order page, maybe in another language).
  var lang = document.documentElement.lang || 'en';
  function day(o) {
    if (!o.iso) return o.date || '';
    var date = new Date(o.iso + 'T12:00:00');
    try {
      return lang === 'hmn' ? date.toLocaleDateString('en-GB') : date.toLocaleDateString(lang, { day: 'numeric', month: 'short', year: 'numeric' });
    } catch (e) { return o.date || o.iso; }
  }
  var ul = box.querySelector('ul');
  orders.forEach(function (o) {
    var li = document.createElement('li');
    var a = document.createElement('a');
    a.className = 'order-line';
    a.href = o.url;
    var b = document.createElement('b');
    b.textContent = o.number;
    var small = document.createElement('small');
    small.className = 'muted';
    small.textContent = [day(o), o.total].filter(Boolean).join(' · ');
    a.appendChild(b);
    a.appendChild(small);
    li.appendChild(a);
    ul.appendChild(li);
  });
  box.hidden = false;
})();

// The customer's receipts in the payment history on their order page (inside [data-receipts]): tap one to see it
// large. Listens on the page, because Livewire redraws the history when they add a receipt.
(function () {
  var viewer;
  document.addEventListener('click', function (e) {
    var button = e.target.closest('[data-receipts] .receipt-thumb');
    if (!button) return;
    var wrap = button.closest('[data-receipts]');
    var buttons = [].slice.call(wrap.querySelectorAll('.receipt-thumb'));
    viewer = viewer || photoViewer(wrap.dataset);
    viewer.open(buttons.map(function (one) {
      var img = one.querySelector('img');
      return { src: img.currentSrc || img.src, alt: img.alt };
    }), buttons.indexOf(button));
  });
})();

// Photos in news posts, events and stories (inside [data-photos]): tap or click one to see it large, then move
// through all the page's photos. data-full is an uncropped large version; photos in the text are shown as they are.
(function () {
  var wrap = document.querySelector('[data-photos]');
  if (!wrap) return;
  var imgs = [].slice.call(wrap.querySelectorAll('img[data-full], .prose img, .qa img')).filter(function (img) {
    return !img.closest('a, button') && img.getAttribute('src');
  });
  if (!imgs.length) return;
  var t = wrap.dataset;
  var viewer = photoViewer(t);
  imgs.forEach(function (img, i) {
    img.classList.add('zoomable');
    img.tabIndex = 0;
    img.setAttribute('role', 'button');
    img.setAttribute('aria-label', img.alt ? img.alt + ' · ' + t.tOpen : t.tOpen);
    function open() {
      viewer.open(imgs.map(function (one) { return { src: one.dataset.full || one.currentSrc || one.src, alt: one.alt }; }), i);
    }
    img.addEventListener('click', open);
    img.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
    });
  });
})();

// Private messages (App\Livewire\Messages): the unread badges on My account follow the Messages page and live.js
// (messages-unread); on the page, Enter sends on a computer (Shift+Enter for a new line; phones keep Enter for new
// lines), the box grows with the text, and photos open full screen.
(function () {
  window.addEventListener('messages-unread', function (e) {
    var n = e.detail ? Number(e.detail.count) || 0 : 0;
    document.querySelectorAll('[data-unread-messages]').forEach(function (el) {
      el.textContent = n > 99 ? '99+' : String(n);
      el.hidden = !n;
    });
  });

  function isBox(el) { return el && el.matches && el.matches('[data-chat-input]'); }
  document.addEventListener('keydown', function (e) {
    if (!isBox(e.target) || e.key !== 'Enter' || e.shiftKey || e.isComposing) return;
    if (!window.matchMedia('(pointer: fine)').matches) return;
    e.preventDefault();
    if (e.target.form) e.target.form.requestSubmit();
  });
  document.addEventListener('input', function (e) {
    if (!isBox(e.target)) return;
    e.target.style.height = 'auto';
    e.target.style.height = Math.min(e.target.scrollHeight, 160) + 'px';
  });
  window.addEventListener('chat-sent', function () {
    document.querySelectorAll('[data-chat-input]').forEach(function (box) { box.style.height = ''; });
  });
  // Editing one's message: its text is in the box (after Livewire's redraw), ready to change.
  window.addEventListener('chat-edit', function () {
    requestAnimationFrame(function () {
      var box = document.querySelector('[data-chat-input]');
      if (!box) return;
      box.focus();
      box.setSelectionRange(box.value.length, box.value.length);
      box.style.height = 'auto';
      box.style.height = Math.min(box.scrollHeight, 160) + 'px';
    });
  });
  // A message's menu (⋯) closes after choosing, or on a tap elsewhere.
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.bubble-menu[open], .chat-menu[open]').forEach(function (menu) {
      if (!menu.contains(e.target) || e.target.closest('.menu button')) menu.removeAttribute('open');
    });
  });

  var viewer;
  document.addEventListener('click', function (e) {
    var button = e.target.closest && e.target.closest('.chat-photo');
    if (!button) return;
    var imgs = [].slice.call(document.querySelectorAll('.chat-messages .chat-photo img'));
    var wrap = button.closest('[data-chat-photos]');
    viewer = viewer || photoViewer(wrap ? wrap.dataset : {});
    viewer.open(imgs.map(function (img) { return { src: img.currentSrc || img.src, alt: '' }; }), imgs.indexOf(button.querySelector('img')));
  });
})();

// Emojis for the message box (the 😊 button, [data-emoji-for]): a small panel of common ones, chosen to show on older
// phones too (nothing newer than 2018), with the ones this device used last first. It sits on the page itself, so
// Livewire's redraws of the chat leave it alone. Phones also have emojis on their own keyboard.
(function () {
  var SETS = {
    smileys: '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😋 😛 😜 🤪 🤗 🤭 🤔 🤫 😐 😶 🙄 😏 😴 😌 😢 😭 😤 😠 😡 🥺 😱 😳 🥳 😎 🤩 😮 🤒 🤧 🥶 🥵 😷',
    people: '👍 👎 👏 🙌 🙏 🤝 👋 ✌️ 🤞 👌 🤙 💪 👀 👶 👧 👦 👩 👨 👵 👴 👪 💃 🕺 🙋 🤷 🙆 🙅 🤦',
    hearts: '❤️ 🧡 💛 💚 💙 💜 🖤 💕 💖 💞 💯 ✨ 🔥 ⭐ 🌟 🎉 🎊 🎁 🎂 🎈 🎶 ✅ ❌ ❗ ❓ 💤',
    nature: '🍚 🍜 🍲 🍛 🥗 🍗 🍖 🌶️ 🥭 🍌 🍉 🍊 🍍 🥥 ☕ 🍵 🍺 🌸 🌺 🌻 🌾 🌿 🍀 🌳 🌄 ☀️ 🌧️ 🌙 🐓 🐃 🐖 🐕 🐈 🐟',
    life: '🏠 🏡 🚗 🛵 🚌 ✈️ 📱 📞 📷 💰 🎓 📚 ✏️ ⚽ 🏐 🏆 🥇 🧵 👘 🎵 🎤 🌏 🇱🇦 🎋 🕯️ ⛰️ 🏞️'
  };
  var TABS = [['recent', '🕘'], ['smileys', '😊'], ['people', '👋'], ['hearts', '❤️'], ['nature', '🍜'], ['life', '🏠']];
  var STORE = 'hl-emoji';
  var panel, grid, button;

  function recent() {
    try { return JSON.parse(localStorage.getItem(STORE) || '[]'); } catch (e) { return []; }
  }
  function remember(emoji) {
    var list = recent().filter(function (one) { return one !== emoji; });
    list.unshift(emoji);
    try { localStorage.setItem(STORE, JSON.stringify(list.slice(0, 24))); } catch (e) { /* private window */ }
  }
  function build() {
    panel = document.createElement('div');
    panel.className = 'emoji-panel';
    panel.hidden = true;
    panel.innerHTML = '<div class="emoji-tabs" role="tablist"></div><div class="emoji-grid" role="tabpanel"></div>';
    grid = panel.querySelector('.emoji-grid');
    TABS.forEach(function (tab) {
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.dataset.tab = tab[0];
      b.textContent = tab[1];
      panel.querySelector('.emoji-tabs').appendChild(b);
    });
    panel.addEventListener('click', function (e) {
      var tab = e.target.closest('[data-tab]');
      if (tab) { show(tab.dataset.tab); return; }
      var pick = e.target.closest('[data-emoji]');
      if (pick) insert(pick.dataset.emoji);
    });
    document.body.appendChild(panel);
  }
  function show(name) {
    var list = name === 'recent' ? recent() : SETS[name].split(' ');
    panel.querySelectorAll('[data-tab]').forEach(function (tab) {
      // The tab's name in the page's language, from the button (data-t-recent, data-t-smileys…).
      var label = button.dataset['t' + tab.dataset.tab.charAt(0).toUpperCase() + tab.dataset.tab.slice(1)] || '';
      tab.setAttribute('aria-selected', tab.dataset.tab === name ? 'true' : 'false');
      tab.setAttribute('aria-label', label);
      tab.title = label;
    });
    grid.innerHTML = '';
    list.forEach(function (emoji) {
      var b = document.createElement('button');
      b.type = 'button';
      b.dataset.emoji = emoji;
      b.textContent = emoji;
      grid.appendChild(b);
    });
  }
  function place() {
    var r = button.getBoundingClientRect();
    var width = Math.min(336, window.innerWidth - 16);
    panel.style.width = width + 'px';
    panel.style.left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8)) + 'px';
    var height = panel.offsetHeight;
    panel.style.top = (r.top - height - 8 > 8 ? r.top - height - 8 : r.bottom + 8) + 'px';
  }
  function open(b) {
    if (!panel) build();
    button = b;
    show(recent().length ? 'recent' : 'smileys');
    panel.hidden = false;
    place();
    b.setAttribute('aria-expanded', 'true');
  }
  function close() {
    if (!panel || panel.hidden) return;
    panel.hidden = true;
    if (button) button.setAttribute('aria-expanded', 'false');
  }
  // Into the box where the cursor was, telling Livewire (and the "is typing" line) as typing would.
  function insert(emoji) {
    var box = button && document.querySelector(button.dataset.emojiFor);
    if (!box) return;
    var start = typeof box.selectionStart === 'number' ? box.selectionStart : box.value.length;
    var end = typeof box.selectionEnd === 'number' ? box.selectionEnd : start;
    box.setRangeText(emoji, start, end, 'end');
    box.dispatchEvent(new Event('input', { bubbles: true }));
    remember(emoji);
    // On a computer the cursor goes back to the box; on phones that would open the keyboard over the emojis.
    if (window.matchMedia('(pointer: fine)').matches) box.focus();
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-emoji-for]');
    if (b) {
      e.preventDefault();
      if (panel && !panel.hidden && button === b) close(); else open(b);
      return;
    }
    if (panel && !panel.hidden && !panel.contains(e.target)) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  window.addEventListener('resize', close);
  // Sending closes it.
  window.addEventListener('chat-sent', close);
})();
