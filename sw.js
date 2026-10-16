/*
 * Service Worker des Nordbahn Monitors: nimmt Push-Nachrichten entgegen und
 * zeigt sie als Benachrichtigung, auch wenn die Seite geschlossen ist. Kein
 * Offline-Cache, die Seite lebt von aktuellen Daten.
 */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
	var data = {};
	try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
	var options = {
		body: data.body || '',
		icon: 'icon-192.png',
		badge: 'icon-192.png',
		lang: 'de',
		tag: data.tag || undefined,
		data: { url: data.url || './' }
	};
	if (data.ts) options.timestamp = data.ts * 1000;
	event.waitUntil(self.registration.showNotification(data.title || 'Nordbahn Monitor', options));
});

self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	var url = new URL((event.notification.data && event.notification.data.url) || './', self.location.href).href;
	event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
		for (var i = 0; i < list.length; i++) {
			if (list[i].url.indexOf(self.registration.scope) === 0 && 'focus' in list[i]) return list[i].focus();
		}
		return self.clients.openWindow(url);
	}));
});

/* Der Browser hat das Abo erneuert: neu abonnieren und dem Server den alten Endpunkt nennen. */
self.addEventListener('pushsubscriptionchange', function (event) {
	var alt = event.oldSubscription ? event.oldSubscription.endpoint : '';
	event.waitUntil(fetch('api/push-key', { cache: 'no-cache' }).then(function (r) { return r.json(); }).then(function (k) {
		if (!k.key) return;
		var raw = atob((k.key + '==='.slice((k.key.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
		var key = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) key[i] = raw.charCodeAt(i);
		return self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }).then(function (sub) {
			return fetch('api/subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'subscribe', subscription: sub.toJSON(), alt: alt }) });
		});
	}));
});
