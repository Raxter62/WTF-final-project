const CACHE_NAME = 'fitconnect-v1';
const ASSETS_TO_CACHE = [
    './',
    './index.html',
    './public/css/style.css',
    './public/js/main.js',
    './public/js/ai_chat.js',
    './manifest.json'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            // 若某些檔案不存在則忽略失敗
            return cache.addAll(ASSETS_TO_CACHE).catch(err => console.log('Cache addAll error:', err));
        })
    );
});

self.addEventListener('fetch', (event) => {
    event.respondWith(
        caches.match(event.request).then((response) => {
            return response || fetch(event.request);
        })
    );
});
