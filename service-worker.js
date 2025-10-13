const CACHE_NAME = 'acasmar-pwa-v1';
const OFFLINE_URLS = [
  '/',
  '/index.php',
  '/assets/css/style.css',
  '/assets/js/students.js',
  '/assets/images/logo.jpg',
  '/manifest.json'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(OFFLINE_URLS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  // Bypass non-GET and API calls
  if (request.method !== 'GET' || request.url.includes('/ajax/') || request.url.includes('/download_')) {
    return;
  }
  event.respondWith(
    caches.match(request).then((cached) => {
      const networkFetch = fetch(request).then((response) => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy)).catch(() => {});
        return response;
      }).catch(() => cached);
      return cached || networkFetch;
    })
  );
});


