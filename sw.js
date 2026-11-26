/*
 * Service worker do app MultiLife Care (PWA).
 *
 * Regras:
 *  - páginas do sistema SEMPRE vêm da rede (dados de pacientes não ficam salvos no
 *    aparelho); sem internet aparece a página /offline.html;
 *  - só ícones, fontes e arquivos estáticos (css/js/png) ficam em cache;
 *  - nada de POST, API ou arquivo enviado (uploads) passa pelo cache.
 */
const VERSION = 'ml-pwa-v1';
const STATIC_CACHE = VERSION + '-static';
const PRECACHE = [
  '/offline.html',
  '/manifest.webmanifest',
  '/pwa/icon-192.png',
  '/pwa/icon-512.png',
  '/pwa/apple-touch-icon.png',
  '/pwa/favicon-32.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(STATIC_CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

const isStatic = (url) =>
  url.origin === self.location.origin &&
  /\.(?:png|jpg|jpeg|svg|webp|ico|woff2?|ttf|css|js)$/i.test(url.pathname) &&
  !url.pathname.startsWith('/uploads/') &&
  !url.pathname.startsWith('/storage/') &&
  url.pathname !== '/sw.js';

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // Páginas: rede primeiro; sem internet, a página "sem conexão"
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }

  // Estáticos: cache e atualiza em segundo plano
  if (isStatic(url) || url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    event.respondWith(
      caches.open(STATIC_CACHE).then((cache) =>
        cache.match(req).then((hit) => {
          const net = fetch(req).then((res) => {
            if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone());
            return res;
          }).catch(() => hit);
          return hit || net;
        })
      )
    );
  }
});

// Toque na notificação: abre (ou foca) o app na tela certa
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/internal_chat.php';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if ('focus' in c) { c.navigate(target); return c.focus(); }
      }
      return self.clients.openWindow(target);
    })
  );
});
