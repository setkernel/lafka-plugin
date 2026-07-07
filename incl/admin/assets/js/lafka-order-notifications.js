/**
 * Lafka admin new-order notification poller.
 *
 * Moved from the parent theme's lafka-back.js (NX1-08b). Behaviour is preserved:
 * on first run it asks the operator for browser-notification permission via a
 * jQuery UI dialog, then registers the plugin service worker and polls the
 * `lafka_new_orders_notification` AJAX endpoint every 30s. When the endpoint
 * returns a new processing order it posts the payload to the worker, which
 * renders the notification.
 */
(function ($) {
	"use strict";

	var params = window.lafka_order_notifications_params || {};

	if (params.new_orders_push_notifications !== 'yes') {
		return;
	}

	$(document).ready(function () {
		if (typeof Notification !== "undefined" && Notification.permission === 'default') {
			$("#lafka-push-confirm").dialog({
				resizable: false,
				height: "auto",
				width: 400,
				modal: true,
				buttons: [
					{
						text: params.allow_label,
						click: function () {
							$(this).trigger('lafka-push-confirm');
							$(this).dialog("close");
						}
					},
					{
						text: params.cancel_label,
						click: function () {
							$(this).dialog("close");
						}
					}
				]
			});

			$(window).on('lafka-push-confirm', function () {
				Notification.requestPermission().then(function (result) {
					if (result === 'denied') {
						console.log('Permission wasn\'t granted. Allow a retry.');
						return;
					}
					if (result === 'default') {
						console.log('The permission request was dismissed.');
						return;
					}
				});
			});
		}
	});

	window.addEventListener('load', function () {
		if (typeof Notification !== "undefined" && 'serviceWorker' in navigator) {
			navigator.serviceWorker.register(params.service_worker_path).then(function (registration) {
				// Registration was successful
				setInterval(function () {
					$.ajax({
						type: 'POST',
						data: {
							action: params.action,
							security: params.nonce
						},
						url: ajaxurl,
						success: function (response) {
							if (response !== '') {
								var data = {
									title: response.title,
									body: response.body,
									icon: response.icon,
									data: { url: response.url },
								};

								if (registration.active) {
									registration.active.postMessage(data);
								}
							}
						},
						complete: function () {
						}
					});
				}, 30000);
			}, function (err) {
				// registration failed :(
				console.log('ServiceWorker registration failed: ', err);
			});
		}
	});
})(window.jQuery);
