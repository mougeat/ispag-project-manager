// Service worker de l'application ISPAG : l'application elle-même fonctionne sans réseau.
// Les données (projets, livraisons en attente) sont dans IndexedDB, gérées par app.js — jamais ici.
const VERSION = '__VERSION__';
const CACHE = 'ispag-app-' + VERSION;
// Portée sans « / » final (https://site/ispag-app) : couvre aussi l'adresse saisie sans barre oblique, souvent celle de l'icône de l'écran d'accueil
const SCOPE = self.registration.scope.replace(/\/?$/, '/'); // https://site/ispag-app/
const ROOT = SCOPE.slice(0, -1);                              // https://site/ispag-app
const SHELL = ['', 'app.js?v=' + VERSION, 'app.css?v=' + VERSION, 'manifest.webmanifest', 'icon-192.png', 'icon-512.png'].map(function (p) { return SCOPE + p; });

self.addEventListener('install', function (e) {
  // Page, script et feuille de style : indispensables (l'installation échoue sinon, et reprendra) ; icônes et manifeste : au mieux
  e.waitUntil(caches.open(CACHE).then(function (c) {
    return c.addAll(SHELL.slice(0, 3)).then(function () { return Promise.all(SHELL.slice(3).map(function (u) { return c.add(u).catch(function () {}); })); });
  }).then(function () { return self.skipWaiting(); }));
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
  if (url.origin !== location.origin || (url.origin + url.pathname !== ROOT && url.href.indexOf(SCOPE) !== 0)) return; // API, PDF, etc. : réseau direct

  if (req.mode === 'navigate') {
    // page : réseau d'abord (dernière version) ; sans réseau — ou si le réseau ne répond pas en 4 s (couverture faible) — la copie du téléphone
    e.respondWith(caches.match(SCOPE).then(function (cached) {
      const net = fetch(req).then(function (res) {
        if (res.ok && !res.redirected) { const copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(SCOPE, copy); }); }
        return res;
      });
      if (!cached) return net;
      return Promise.race([net, new Promise(function (_, reject) { setTimeout(function () { reject(new Error('timeout')); }, 4000); })]).catch(function () { return cached; });
    }));
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
