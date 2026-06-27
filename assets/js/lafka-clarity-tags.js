/**
 * Microsoft Clarity custom tags + identify.
 *
 * Mirrors the dataLayer funnel signals into Clarity custom tags so session
 * replays + heatmaps can be filtered by page type, fulfilment, store state,
 * cart band and funnel step. Safe no-op when Clarity isn't present (e.g. only
 * GA4 configured) — clarity()'s queue stub makes calls before load harmless.
 */
(function () {
	'use strict';

	function clarityReady() { return typeof window.clarity === 'function'; }
	function set(key, val) {
		if (!clarityReady() || val === undefined || val === null || val === '') { return; }
		try { window.clarity('set', key, String(val)); } catch (e) { /* no-op */ }
	}

	// Map page_context dimensions → Clarity tags.
	function applyPageContext(ctx) {
		if (!ctx) { return; }
		set('page_type', ctx.page_type);
		set('fulfilment_method', ctx.fulfilment_method);
		set('store_open', ctx.store_open === true ? 'open' : (ctx.store_open === false ? 'closed' : ''));
		set('cart_value_band', ctx.cart_value_band);
		set('repeat_customer', ctx.customer_is_repeat ? 'yes' : 'no');
	}

	// Funnel step from ecommerce events → a single ordered tag.
	var FUNNEL = {
		view_item_list: 'menu',
		view_item: 'pdp',
		add_to_cart: 'add_to_cart',
		view_cart: 'cart',
		begin_checkout: 'checkout',
		purchase: 'purchase',
	};

	function handleEvent(obj) {
		if (!obj || !obj.event) { return; }
		if (obj.event === 'page_context') { applyPageContext(obj); return; }
		if (FUNNEL[obj.event]) { set('funnel_step', FUNNEL[obj.event]); }
		if (obj.event === 'order_channel_click') { set('order_channel', obj.order_channel); }
		if (obj.event === 'select_promotion' || obj.event === 'coupon_apply') { set('promo_code', obj.coupon_code || obj.promotion_id); }
		if (obj.event === 'purchase' && obj.ecommerce && obj.ecommerce.transaction_id) {
			// Stable, non-PII session correlation (the order id, not the customer).
			try { if (clarityReady()) { window.clarity('identify', 'order_' + obj.ecommerce.transaction_id); } } catch (e) { /* no-op */ }
		}
	}

	var dl = (window.dataLayer = window.dataLayer || []);
	// Replay anything already pushed (page_context emits at wp_head pri 3).
	for (var i = 0; i < dl.length; i++) { handleEvent(dl[i]); }
	// Intercept future pushes without breaking GTM (which also reads dataLayer).
	var origPush = dl.push.bind(dl);
	dl.push = function () {
		for (var j = 0; j < arguments.length; j++) { handleEvent(arguments[j]); }
		return origPush.apply(null, arguments);
	};
})();
