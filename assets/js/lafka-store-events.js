/**
 * Lafka store-specific dataLayer events.
 *
 * Pushes the restaurant-specific funnel signals that GA4/Clarity segment on.
 * dataLayer-only (GTM routes); no gtag(). Enqueued only when an analytics
 * destination is configured. All bindings are delegated + null-safe, so they
 * no-op cleanly on pages where the markup isn't present.
 *
 * Data-attribute contracts (kept in sync with docs/TRACKING.md):
 *   [data-lafka-order-channel="direct|ubereats|skipthedishes|doordash|phone"]
 *       [data-lafka-order-source]   → order_channel_click   (the "order direct,
 *       skip the 30% app fees" CTAs vs aggregator buttons — core growth signal)
 *   [data-lafka-fulfilment="delivery|pickup"] [data-lafka-fulfilment-source]
 *       → select_fulfilment
 *   .lafka-store-closed-card[data-lafka-closed-context]
 *       → store_closed_view (one-shot, on view)
 *   .product-addon (addons-engine markup) input/select/textarea
 *       → select_addon
 */
(function () {
	'use strict';

	var dl = (window.dataLayer = window.dataLayer || []);
	function push(obj) { dl.push(obj); }

	function closest(el, selector) {
		while (el && el.nodeType === 1) {
			if (el.matches && el.matches(selector)) { return el; }
			el = el.parentElement;
		}
		return null;
	}

	// ── order_channel_click — direct vs UberEats/Skip/DoorDash/phone ──────────
	document.addEventListener('click', function (e) {
		var el = closest(e.target, '[data-lafka-order-channel]');
		if (!el) { return; }
		push({
			event: 'order_channel_click',
			order_channel: el.getAttribute('data-lafka-order-channel') || 'unknown',
			order_source: el.getAttribute('data-lafka-order-source') || 'unknown',
		});
	});

	// ── select_fulfilment — delivery vs pickup ────────────────────────────────
	document.addEventListener('click', function (e) {
		var el = closest(e.target, '[data-lafka-fulfilment]');
		if (!el) { return; }
		push({
			event: 'select_fulfilment',
			fulfilment_method: el.getAttribute('data-lafka-fulfilment') || 'unknown',
			fulfilment_source: el.getAttribute('data-lafka-fulfilment-source') || 'unknown',
		});
	});

	// ── select_addon — addons-engine option changes ───────────────────────────
	document.addEventListener('change', function (e) {
		var input = e.target;
		if (!input || !input.closest) { return; }
		var addon = input.closest('.product-addon');
		if (!addon) { return; }
		var price = input.getAttribute('data-price') || input.getAttribute('data-raw-price') || '';
		push({
			event: 'select_addon',
			product_id: addon.getAttribute('data-product-id') || '',
			addon_name: addon.getAttribute('data-addon-name') || addon.getAttribute('data-name') || '',
			addon_value: (input.value || '').toString().slice(0, 80),
			price_delta: price ? parseFloat(price) : 0,
		});
	});

	// ── store_closed_view — one-shot when the closed card scrolls into view ───
	function watchStoreClosed() {
		var cards = document.querySelectorAll('.lafka-store-closed-card');
		if (!cards.length || typeof IntersectionObserver !== 'function') { return; }
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) { return; }
				push({
					event: 'store_closed_view',
					closed_context: entry.target.getAttribute('data-lafka-closed-context') || 'page',
				});
				io.unobserve(entry.target);
			});
		}, { threshold: 0.4 });
		cards.forEach(function (c) { io.observe(c); });
	}

	if (document.readyState !== 'loading') {
		watchStoreClosed();
	} else {
		document.addEventListener('DOMContentLoaded', watchStoreClosed);
	}
})();
