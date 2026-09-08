const STATIC_CACHE = 'sikordik-static-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', event => {
    event.waitUntil(caches.open(STATIC_CACHE).then(cache => cache.addAll([OFFLINE_URL, '/icons/sikordik.svg', '/icons/sikordik-192.png', '/icons/sikordik-512.png'])));
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('sikordik-static-') && key !== STATIC_CACHE).map(key => caches.delete(key)))));
    self.clients.claim();
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET' || new URL(event.request.url).origin !== self.location.origin) return;

    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    const path = new URL(event.request.url).pathname;
    if (path.startsWith('/build/') || path.startsWith('/icons/')) {
        event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request).then(response => {
            if (response.ok) caches.open(STATIC_CACHE).then(cache => cache.put(event.request, response.clone()));
            return response;
        })));
    }
});
