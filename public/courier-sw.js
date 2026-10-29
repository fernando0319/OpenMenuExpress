/*
 * Service worker of the courier app (/entregador). It makes the app installable and shows
 * the notifications the server pushes (new run, run cancelled, registration reviewed),
 * even with the screen off or the app closed. Pages are always loaded from the network:
 * offers and runs must never come from a cache.
 */
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) =>
    event.waitUntil(self.clients.claim()),
);

self.addEventListener('fetch', () => {
    // Network only (the handler exists so browsers treat the app as installable).
});

self.addEventListener('push', (event) => {
    let message = {};

    try {
        message = event.data ? event.data.json() : {};
    } catch {
        message = { body: event.data ? event.data.text() : '' };
    }

    const isOffer = String(message.tag ?? '').startsWith('offer-');

    event.waitUntil(
        self.registration.showNotification(message.title ?? 'OpenMenuExpress', {
            body: message.body ?? '',
            tag: message.tag ?? 'menu-express',
            renotify: true,
            // An offer stays on screen until the courier looks at it.
            requireInteraction: isOffer,
            vibrate: isOffer ? [400, 150, 400, 150, 400] : [200],
            icon: '/images/courier-icon-192.png',
            badge: '/images/courier-icon-192.png',
            data: { url: message.url ?? '/entregador' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url ?? '/entregador';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((windows) => {
                const open = windows.find((client) =>
                    new URL(client.url).pathname.startsWith('/entregador'),
                );

                if (open) {
                    open.navigate(url).catch(() => {});

                    return open.focus();
                }

                return self.clients.openWindow(url);
            }),
    );
});
