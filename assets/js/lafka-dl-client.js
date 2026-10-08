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
		const link = ev.target && ev.target.closest ? ev.target.closest('a[data-lafka-item-id]') : null;
		if (!link) {
			return;
		}
		const itemId = link.getAttribute('data-lafka-item-id') || '';
		const itemName = link.getAttribute('data-lafka-item-name') || '';
		const itemCategory = link.getAttribute('data-lafka-item-category') || '';
		const listName = link.getAttribute('data-lafka-list-name') || 'Unknown list';
		const price = parseFloat(link.getAttribute('data-lafka-item-price') || '0') || 0;
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
	let searchTimer = null;
	document.addEventListener('input', function (ev) {
		const input = ev.target && ev.target.closest ? ev.target.closest('[data-lafka-menu-search-input]') : null;
		if (!input) {
			return;
		}
		const term = (input.value || '').trim();
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
		const container = document.querySelector('[data-lafka-menu-results]');
		const nodes = (container || document).querySelectorAll('[data-lafka-item-id]');
		if (container) {
			return nodes.length;
		}
		let count = 0;
		nodes.forEach(function (el) {
			if (!el.closest('[hidden]')) {
				count++;
			}
		});
		return count;
	}

	// ------------------------------------------------------------------
	// add_shipping_info / add_payment_info — checkout radio changes, and on
	// "Place order" for whatever was never changed (a single shipping rate
	// or a preselected payment method shows no radio to change).
	// ------------------------------------------------------------------
	const checkoutSent = { shipping: false, payment: false };
	const SHIPPING_SCOPE = '.wc-block-components-shipping-rates-control, .wp-block-woocommerce-checkout-shipping-methods-block, .wp-block-woocommerce-checkout-pickup-options-block';
	const PAYMENT_NAMES = ['payment_method', 'radio-control-wc-payment-method-options'];

	function pushShipping(tier) {
		checkoutSent.shipping = true;
		push('add_shipping_info', withCheckoutTotals({
			shipping_tier: tier,
			items: collectCheckoutItemsFromDom()
		}));
	}

	function pushPayment(ptype) {
		checkoutSent.payment = true;
		push('add_payment_info', withCheckoutTotals({
			payment_type: ptype,
			items: collectCheckoutItemsFromDom()
		}));
	}

	function currentShippingTier() {
		const classic = document.querySelector('input[name^="shipping_method"]:checked, input[type="hidden"][name^="shipping_method"]');
		if (classic) {
			return classic.value;
		}
		const scopes = document.querySelectorAll(SHIPPING_SCOPE);
		for (let i = 0; i < scopes.length; i++) {
			const checked = scopes[i].querySelector('input[type="radio"]:checked');
			if (checked) {
				return checked.value;
			}
		}
		const toggle = document.querySelector('.wc-block-checkout__shipping-method-option--selected');
		return toggle ? toggle.textContent.trim() : '';
	}

	function currentPaymentType() {
		for (let i = 0; i < PAYMENT_NAMES.length; i++) {
			const checked = document.querySelector('input[name="' + PAYMENT_NAMES[i] + '"]:checked');
			if (checked) {
				return checked.value;
			}
		}
		return '';
	}

	document.addEventListener('click', function (ev) {
		const button = ev.target && ev.target.closest && ev.target.closest('#place_order, .wc-block-components-checkout-place-order-button');
		if (!button) {
			return;
		}
		const tier = currentShippingTier();
		if (!checkoutSent.shipping && tier) {
			pushShipping(tier);
		}
		const ptype = currentPaymentType();
		if (!checkoutSent.payment && ptype) {
			pushPayment(ptype);
		}
	}, true);

	document.addEventListener('change', function (ev) {
		const target = ev.target;
		if (!target || !target.name) {
			return;
		}
		// Shipping method radio: classic `shipping_method[n]`; on the block
		// checkout a radio-control input inside its shipping or pickup options.
		const blockShipping = /^radio-control-/.test(target.name) && target.closest && target.closest(SHIPPING_SCOPE);
		if (/^shipping_method/.test(target.name) || blockShipping) {
			pushShipping((target.value || '').toString());
			return;
		}
		if (PAYMENT_NAMES.indexOf(target.name) !== -1) {
			pushPayment((target.value || '').toString());
		}
	});

	/**
	 * Add GA4's currency + value from the server-localized checkout totals.
	 */
	function withCheckoutTotals(payload) {
		const ctx = window.lafkaDlCheckout;
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
		const nodes = document.querySelectorAll('[data-lafka-checkout-item]');
		let out = [];
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
