/* Only the public offline page is cached. Never store sessions, API responses or private HTML. */
const CACHE = 'hamkar-offline-v1';
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(['./offline.html', './assets/icons/app-192.png'])));
  self.skipWaiting();
});
self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('hamkar-offline-') && k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || event.request.mode !== 'navigate') return;
  event.respondWith(fetch(event.request).catch(() => caches.match('./offline.html')));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(self.clients.matchAll({type:'window', includeUncontrolled:true}).then(clients => {
    const scope = new URL(self.registration.scope);
    const target = new URL('./#notifications', scope).href;
    const client = clients.find(c => c.url.startsWith(scope.href) && 'focus' in c);
    if (client) { client.postMessage({ type: 'open-notifications' }); return client.focus(); }
    return self.clients.openWindow(target);
  }));
});
