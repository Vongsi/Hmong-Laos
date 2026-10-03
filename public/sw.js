// Hmong Laos service worker: offline reading of pages already visited, fast repeat visits,
// and push notifications for new events and announcements.
const VERSION = 'hl-v1';
const STATIC = VERSION + '-static';
const PAGES = VERSION + '-pages';
const IMAGES = VERSION + '-images';
const PRECACHE = ['/offline.html', '/css/site.css', '/js/site.js', '/icons/icon-192.png', '/icons/badge-96.png'];
const MAX_PAGES = 40;
const MAX_IMAGES = 80;

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// Never cache the control panel, sign-in, live features or anything that changes data.
function skip(url) {
  return /^\/(cp|livewire|broadcasting|oauth|push|manifest|!)/.test(url.pathname) || url.pathname.includes('/livewire');
}

async function trim(cacheName, max) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - max; i++) await cache.delete(keys[i]);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin || skip(url)) return;

  // Pages: always try the network first so news is fresh; fall back to the saved copy offline.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).then((res) => {
        if (res.ok && !res.redirected) {
          const copy = res.clone();
          caches.open(PAGES).then((c) => c.put(req, copy)).then(() => trim(PAGES, MAX_PAGES));
        }
        return res;
      }).catch(() => caches.match(req).then((hit) => hit || caches.match('/offline.html')))
    );
    return;
  }

  // Photos (Glide) and uploaded assets: cache first, they don't change at the same URL.
  if (url.pathname.startsWith('/img/') || url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(IMAGES).then((c) => c.put(req, copy)).then(() => trim(IMAGES, MAX_IMAGES));
        }
        return res;
      }))
    );
    return;
  }

  // CSS, JS and icons: serve the saved copy right away and refresh it in the background.
  if (/^\/(css|js|icons|images)\//.test(url.pathname)) {
    event.respondWith(
      caches.open(STATIC).then((cache) => cache.match(req).then((hit) => {
        const fresh = fetch(req).then((res) => { if (res.ok) cache.put(req, res.clone()); return res; });
        return hit || fresh;
      }))
    );
  }
});

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: event.data && event.data.text() }; }
  const title = data.title || 'Hmong Laos';
  event.waitUntil(self.registration.showNotification(title, {
    body: data.body || '',
    icon: data.icon || '/icons/icon-192.png',
    badge: '/icons/badge-96.png',
    tag: data.tag,
    data: { url: (data.data && data.data.url) || data.url || '/' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = new URL(event.notification.data.url || '/', self.location.origin).href;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      for (const w of wins) if (w.url === url && 'focus' in w) return w.focus();
      return self.clients.openWindow(url);
    })
  );
});
