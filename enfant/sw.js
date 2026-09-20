/* Service worker de la page enfant : elle doit s'ouvrir sans réseau.
   Tous les horaires sont dans la page elle-même, donc une fois la page
   en cache, l'essentiel fonctionne hors ligne. */
'use strict';

const CACHE = 'car-plugin-v1';
const FICHIERS = [
  './',
  './manifest.php',
  './icones/icone-192.png',
  './icones/icone-512.png',
  './icones/apple-touch-icon.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(FICHIERS))
    .then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((noms) => Promise.all(noms.filter((n) => n !== CACHE)
      .map((n) => caches.delete(n))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  /* Les appels au relais ne se mettent jamais en cache : une heure de
     car périmée serait pire que pas d'heure du tout. */
  if (new URL(req.url).pathname.endsWith('/api.php')) return;

  /* Réseau d'abord pour la page, cache en secours dès que la connexion
     manque. */
  if (req.mode === 'navigate' || req.destination === 'document') {
    e.respondWith(fetch(req)
      .then((r) => {
        const copie = r.clone();
        caches.open(CACHE).then((c) => c.put('./', copie));
        return r;
      })
      .catch(() => caches.match('./')));
    return;
  }

  e.respondWith(caches.match(req).then((c) => c || fetch(req).then((r) => {
    if (r.ok && new URL(req.url).origin === location.origin) {
      const copie = r.clone();
      caches.open(CACHE).then((ca) => ca.put(req, copie));
    }
    return r;
  }).catch(() => c)));
});

/* Notification poussée par le démon (voir resources/card/server/push.py). */
self.addEventListener('push', (e) => {
  let d = { titre: 'Son car', corps: 'Il est temps de partir.' };
  try { d = Object.assign(d, e.data ? e.data.json() : {}); } catch (err) {}
  e.waitUntil(self.registration.showNotification(d.titre, {
    body: d.corps, icon: './icones/icone-192.png',
    badge: './icones/icone-192.png', tag: 'car', renotify: true,
    vibrate: [120, 60, 120],
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true })
    .then((l) => {
      for (const c of l) { if ('focus' in c) return c.focus(); }
      return clients.openWindow('./');
    }));
});
