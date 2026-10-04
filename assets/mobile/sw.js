// Service worker de l'application ISPAG : l'application elle-même fonctionne sans réseau.
// Les données (projets, livraisons en attente) sont dans IndexedDB, gérées par app.js — jamais ici.
const VERSION = '__VERSION__';
const CACHE = 'ispag-app-' + VERSION;
const SCOPE = self.registration.scope; // https://site/ispag-app/
const SHELL = ['', 'app.js?v=' + VERSION, 'app.css?v=' + VERSION, 'manifest.webmanifest', 'icon-192.png', 'icon-512.png'].map(function (p) { return SCOPE + p; });

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k.indexOf('ispag-app-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (e) {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin || url.href.indexOf(SCOPE) !== 0) return; // API, PDF, etc. : réseau direct

  if (req.mode === 'navigate') {
    // page : réseau d'abord (dernière version), sinon la copie du téléphone
    e.respondWith(
      fetch(req).then(function (res) {
        const copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(SCOPE, copy); });
        return res;
      }).catch(function () { return caches.match(SCOPE); })
    );
    return;
  }
  // fichiers de l'application : cache d'abord
  e.respondWith(caches.match(req, { ignoreSearch: false }).then(function (hit) {
    return hit || fetch(req).then(function (res) {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); }
      return res;
    });
  }));
});
