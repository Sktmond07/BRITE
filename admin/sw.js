// admin/sw.js — Service Worker for Web Push
// Must be at /admin/sw.js so its scope covers /admin/dashboards/*

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {
        title: 'BRITE Notification',
        body: 'You have a new notification.',
        icon: '/BRITE/logo.jpg',
        badge: '/BRITE/logo.jpg',
        url: '/BRITE/admin/dashboards/secretary_dashboard.php',
        tag: 'brite-notification'
    };

    if (event.data) {
        try { data = Object.assign(data, event.data.json()); }
        catch (e) { data.body = event.data.text(); }
    }

    event.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        tag: data.tag,
        renotify: true,
        vibrate: [200, 100, 200],
        data: { url: data.url }
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url)
        || '/BRITE/admin/dashboards/secretary_dashboard.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const c of list) {
                if (c.url.includes('/BRITE/admin/') && 'focus' in c) {
                    c.navigate(target);
                    return c.focus();
                }
            }
            return clients.openWindow(target);
        })
    );
});