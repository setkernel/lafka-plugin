/**
 * Lafka — dataLayer client (Phase 1B, v9.24.0)
 *
 * Mirrors server-side WC events into window.dataLayer for AJAX-driven
 * scenarios and binds custom client events that have no PHP-side hook:
 *
 *   - added_to_cart   (WC core jQuery event) -> add_to_cart
 *   - removed_from_cart                       -> remove_from_cart
 *   - product link click (a[data-lafka-item-id]) -> select_item
 *   - menu search input [data-lafka-menu-search-input] (debounced 350ms) -> search
 *   - checkout shipping radio change          -> add_shipping_info
 *   - checkout payment radio change           -> add_payment_info
 *
 * Architecture:
 *   - All pushes go through window.dataLayer.push() — never gtag().
 *   - GTM handles platform routing (GA4 / Meta / Clarity) per the operator's
 *     container config. Consent gating happens inside GTM (Consent Mode v2
 *     wired in Phase 1A).
 *   - When the AJAX fragment response carries a `lafka_dl_event` payload,
 *     this script picks it up in the same tick the fragment refresh happens.
 *     This keeps server-side payload (lafka_dl_inject_ajax_add_to_cart) and
 *     client-side push in structural parity.
 */

(function () {
	'use strict';

	window.dataLayer = window.dataLayer || [];

	function push(eventName, payload) {
		if (!eventName) {
			return;
		}
		// Google's documented "clear before push" pattern prevents stale
		// ecommerce data leaking between events on the same page.
		window.dataLayer.push({ ecommerce: null });
		window.dataLayer.push({ event: eventName, ecommerce: payload || {} });
	}

	// ------------------------------------------------------------------
	// AJAX add-to-cart — mirror the fragment's lafka_dl_event into dataLayer.
	// ------------------------------------------------------------------
	if (typeof jQuery !== 'undefined') {
		jQuery(document.body).on('added_to_cart', function (event, fragments) {
			if (fragments && fragments.lafka_dl_event && fragments.lafka_dl_event.event) {
				push(fragments.lafka_dl_event.event, fragments.lafka_dl_event.payload);
			}
		});

		// WC core fires removed_from_cart on the cart drawer / cart page when
		// a line is removed via AJAX. The server-side hook also queues a
		// session event for next-page-load; either path lands one push.
		jQuery(document.body).on('removed_from_cart', function (event, fragments) {
			if (fragments && fragments.lafka_dl_event && fragments.lafka_dl_event.event) {
				push(fragments.lafka_dl_event.event, fragments.lafka_dl_event.payload);
			}
		});
	}

	// ------------------------------------------------------------------
	// select_item — product link clicks anywhere in the page.
	// ------------------------------------------------------------------
	document.addEventListener('click', function (ev) {
		var link = ev.target && ev.target.closest ? ev.target.closest('a[data-lafka-item-id]') : null;
		if (!link) {
			return;
		}
		var itemId = link.getAttribute('data-lafka-item-id') || '';
		var itemName = link.getAttribute('data-lafka-item-name') || '';
		var itemCategory = link.getAttribute('data-lafka-item-category') || '';
		var listName = link.getAttribute('data-lafka-list-name') || 'Unknown list';
		var price = parseFloat(link.getAttribute('data-lafka-item-price') || '0') || 0;
		push('select_item', {
			item_list_name: listName,
			items: [{
				item_id: itemId,
				item_name: itemName,
				item_category: itemCategory,
				price: price,
				quantity: 1
			}]
		});
	});

	// ------------------------------------------------------------------
	// search — the menu search text field ([data-lafka-menu-search-input]),
	// debounced. Delegated so it works whenever the field is rendered.
	// ------------------------------------------------------------------
	var searchTimer = null;
	document.addEventListener('input', function (ev) {
		var input = ev.target && ev.target.closest ? ev.target.closest('[data-lafka-menu-search-input]') : null;
		if (!input) {
			return;
		}
		var term = (input.value || '').trim();
		if (searchTimer) {
			clearTimeout(searchTimer);
		}
		searchTimer = setTimeout(function () {
			if (term.length < 2) {
				return;
			}
			window.dataLayer.push({
				event: 'search',
				search_term: term,
				results_count: countSearchResults()
			});
		}, 350);
	});

	/**
	 * Products left showing after the search filtered the menu: every item
	 * inside [data-lafka-menu-results] when the theme marks a results region,
	 * else every rendered (not hidden) [data-lafka-item-id] on the page.
	 */
	function countSearchResults() {
		var container = document.querySelector('[data-lafka-menu-results]');
		var nodes = (container || document).querySelectorAll('[data-lafka-item-id]');
		if (container) {
			return nodes.length;
		}
		var count = 0;
		nodes.forEach(function (el) {
			if (!el.closest('[hidden]')) {
				count++;
			}
		});
		return count;
	}

	// ------------------------------------------------------------------
	// add_shipping_info / add_payment_info — checkout radio changes.
	// ------------------------------------------------------------------
	document.addEventListener('change', function (ev) {
		var target = ev.target;
		if (!target || !target.name) {
			return;
		}
		// Shipping method radio
		if (/^shipping_method/.test(target.name)) {
			var tier = (target.value || '').toString();
			push('add_shipping_info', withCheckoutTotals({
				shipping_tier: tier,
				items: collectCheckoutItemsFromDom()
			}));
			return;
		}
		// Payment method radio
		if (target.name === 'payment_method') {
			var ptype = (target.value || '').toString();
			push('add_payment_info', withCheckoutTotals({
				payment_type: ptype,
				items: collectCheckoutItemsFromDom()
			}));
		}
	});

	/**
	 * Add GA4's currency + value from the server-localized checkout totals.
	 */
	function withCheckoutTotals(payload) {
		var ctx = window.lafkaDlCheckout;
		if (ctx && ctx.currency) {
			payload.currency = ctx.currency;
			payload.value = Number(ctx.value) || 0;
		}
		return payload;
	}

	/**
	 * The checkout's cart items: [data-lafka-checkout-item] rows when the
	 * theme renders them, else the items the server localized for this
	 * checkout page (window.lafkaDlCheckout, same shape as begin_checkout).
	 */
	function collectCheckoutItemsFromDom() {
		var nodes = document.querySelectorAll('[data-lafka-checkout-item]');
		var out = [];
		nodes.forEach(function (el) {
			out.push({
				item_id: el.getAttribute('data-lafka-item-id') || '',
				item_name: el.getAttribute('data-lafka-item-name') || '',
				item_category: el.getAttribute('data-lafka-item-category') || '',
				price: parseFloat(el.getAttribute('data-lafka-item-price') || '0') || 0,
				quantity: parseInt(el.getAttribute('data-lafka-item-quantity') || '1', 10) || 1
			});
		});
		if (!out.length && window.lafkaDlCheckout && Array.isArray(window.lafkaDlCheckout.items)) {
			out = window.lafkaDlCheckout.items.slice();
		}
		return out;
	}
})();
