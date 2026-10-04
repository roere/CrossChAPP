'use strict';
// Deliberately no fetch/cache handler: this worker handles only notifications.
self.addEventListener('push', event => {
    let payload;
    try { payload = event.data?.json(); } catch { return; }
    if (!payload || typeof payload.title !== 'string' || typeof payload.body !== 'string') return;
    let url;
    try { url = new URL(payload.url || '/', self.location.origin); } catch { return; }
    if (url.origin !== self.location.origin) return;
    event.waitUntil(self.registration.showNotification(payload.title, {
        body: payload.body, tag: String(payload.tag || 'watchlist'), data: {url: url.href},
    }));
});
self.addEventListener('notificationclick', event => {
    event.notification.close();
    let url;
    try { url = new URL(event.notification.data?.url || '/', self.location.origin); } catch { return; }
    if (url.origin !== self.location.origin) return;
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
        for (const client of windows) {
            if (new URL(client.url).origin === self.location.origin) {
                await client.navigate(url.href); await client.focus(); return;
            }
        }
        await self.clients.openWindow(url.href);
    })());
});
