/*
 * Service worker de la Quiniela Mundial 2026.
 *
 * Estrategia conservadora porque la app es dinámica y autenticada:
 *  - Navegaciones (HTML): network-first con fallback a /offline.html sin conexión.
 *  - Estáticos (/icons, /styles, /assets): stale-while-revalidate.
 *  - Solo se cachean peticiones GET; nunca POST ni endpoints de autenticación.
 *
 * Sube CACHE_VERSION al cambiar este archivo para invalidar el caché anterior.
 */
const CACHE_VERSION = 'quiniela-v3';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [
    OFFLINE_URL,
    '/img/icon-192.png?v=3',
    '/img/icon-512.png?v=3',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/img/')
        || url.pathname.startsWith('/styles/')
        || url.pathname.startsWith('/assets/');
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Solo GET y mismo origen; deja pasar POST/login/etc. directo a la red.
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Navegaciones (documentos HTML): network-first con fallback offline.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    // Estáticos: stale-while-revalidate.
    if (isStaticAsset(url)) {
        event.respondWith(
            caches.open(CACHE_VERSION).then((cache) =>
                cache.match(request).then((cached) => {
                    const network = fetch(request)
                        .then((response) => {
                            if (response && response.status === 200) {
                                cache.put(request, response.clone());
                            }
                            return response;
                        })
                        .catch(() => cached);
                    return cached || network;
                })
            )
        );
    }
});
