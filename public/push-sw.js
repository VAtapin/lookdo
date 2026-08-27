self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (_) {
        payload = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(self.registration.showNotification(payload.title || 'LOOKDO', {
        body: payload.body || '',
        icon: payload.icon || '/icons/icon-192.png',
        badge: payload.badge || '/icons/icon-192.png',
        tag: payload.tag || 'lookdo-message',
        renotify: true,
        data: { url: payload.url || '/activity' },
        actions: payload.action ? [{ action: 'open', title: payload.action }] : [],
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/activity', self.location.origin).href;

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        for (const client of windows) {
            if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                if ('navigate' in client) {
                    client.navigate(target);
                }
                return client.focus();
            }
        }
        return self.clients.openWindow ? self.clients.openWindow(target) : undefined;
    }));
});
