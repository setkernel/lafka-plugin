/**
 * Lafka order-notification service worker (NX1-08b).
 *
 * Dedicated, self-contained worker for the admin new-order poller so the plugin
 * ships its own feature JS and does not depend on any theme-provided worker. The
 * poller (lafka-order-notifications.js) postMessages the payload here; this worker
 * renders the browser notification and deep-links to the order on click.
 */
self.addEventListener('message', function (event) {
	const payload = event.data || {};
	const title   = payload.title ? String(payload.title) : '';
	const options = {
		body: payload.body ? String(payload.body) : '',
		icon: payload.icon ? String(payload.icon) : undefined,
		data: {
			url: (payload.data && payload.data.url) ? String(payload.data.url) : '/'
		}
	};

	event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	const targetUrl = (event.notification && event.notification.data && event.notification.data.url)
		? event.notification.data.url
		: '/';

	event.waitUntil(
		clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
			for (let i = 0; i < clientList.length; i += 1) {
				const client = clientList[i];
				if (client.url === targetUrl && 'focus' in client) {
					return client.focus();
				}
			}
			if (clients.openWindow) {
				return clients.openWindow(targetUrl);
			}
			return null;
		})
	);
});
