/**
 * Lafka — custom interaction events (Phase 1C, v9.25.0)
 *
 * Binds eight selector-driven interaction events to window.dataLayer. All
 * routing happens inside GTM — this script never calls gtag() and never
 * branches on consent (Consent Mode v2 was wired in Phase 1A; GTM filters
 * at tag-fire time).
 *
 * Events:
 *   - phone_click            (a[href^="tel:"])
 *   - email_click            (a[href^="mailto:"])
 *   - get_directions_click   (maps URL or "directions" text)
 *   - faq_open               (details.lafka-contact__faq-item open toggle)
 *   - filter_apply           (.lafka-menu__chip / .lafka-menu__category-chip)
 *   - scroll_milestone       (25 / 50 / 75 / 100 %, once per page view)
 *   - outbound_link          (absolute a[href] to a foreign host)
 *   - sticky_cart_open       (.lafka-sticky-cart enters viewport or .is-open)
 *
 * Architecture:
 *   - One IIFE, strict mode, zero globals leaked.
 *   - Click delegation: a single document-level click listener walks up via
 *     .closest() rather than binding to every link.
 *   - Source-determination helper maps the event target to one of seven
 *     known sections: announce_bar / header / footer / contact / cart /
 *     pdp / menu — falls back to 'unknown' if none match.
 *   - Scroll milestones use requestAnimationFrame throttling (no scroll-
 *     event flood). Each milestone fires exactly once per page view, and
 *     the gate resets on popstate so SPA-style soft navigations still emit.
 *   - sticky_cart_open uses IntersectionObserver (one-shot) plus a MutationObserver
 *     for the `is-open` class toggle on the same element.
 *   - All dataLayer.push() calls are guarded by `if (window.dataLayer)` so a
 *     mis-ordered enqueue cannot throw.
 */

(function () {
	'use strict';

	// ─────────────────────────────────────────────────────────────────────
	// 0. Defensive dataLayer push wrapper.
	// ─────────────────────────────────────────────────────────────────────
	function push(event, params) {
		if (!event) {
			return;
		}
		if (window.dataLayer) {
			var payload = { event: event };
			if (params && typeof params === 'object') {
				for (var k in params) {
					if (Object.prototype.hasOwnProperty.call(params, k)) {
						payload[k] = params[k];
					}
				}
			}
			window.dataLayer.push(payload);
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// 1. Source-determination helper.
	//
	//    Walks up from the event target looking for the first ancestor that
	//    matches a known container class / role. Returns one of:
	//      announce_bar | header | footer | contact | cart | pdp | menu | unknown
	//
	//    This lets us tag every interaction event with the section of the
	//    site it happened on, which is the single most useful slice for the
	//    operator (e.g. "header phone clicks vs footer phone clicks").
	// ─────────────────────────────────────────────────────────────────────
	function resolveSource(el) {
		if (!el || !el.closest) {
			return 'unknown';
		}
		// Order matters: more-specific containers checked first.
		var ancestor = el.closest(
			'.lafka-announce-bar,' +
			'.announce_bar,' +
			'.lafka-sticky-cart,' +
			'.lafka-cart,' +
			'.woocommerce-cart,' +
			'.lafka-contact,' +
			'.lafka-contacts,' +
			'.lafka-menu,' +
			'.lafka-pdp,' +
			'.single-product,' +
			'.product-summary,' +
			'.site-header,' +
			'header.site-header,' +
			'header,' +
			'.site-footer,' +
			'footer.site-footer,' +
			'footer'
		);
		if (!ancestor) {
			return 'unknown';
		}
		var cls = ancestor.className || '';
		var tag = (ancestor.tagName || '').toLowerCase();

		if (/(?:^|\s)(lafka-announce-bar|announce_bar)(?:\s|$)/.test(cls)) {
			return 'announce_bar';
		}
		if (/(?:^|\s)lafka-sticky-cart(?:\s|$)/.test(cls)) {
			return 'cart';
		}
		if (/(?:^|\s)(lafka-cart|woocommerce-cart)(?:\s|$)/.test(cls)) {
			return 'cart';
		}
		if (/(?:^|\s)(lafka-contact|lafka-contacts)(?:\s|$)/.test(cls)) {
			return 'contact';
		}
		if (/(?:^|\s)lafka-menu(?:\s|$|__)/.test(cls)) {
			return 'menu';
		}
		if (/(?:^|\s)(lafka-pdp|single-product|product-summary)(?:\s|$|__)/.test(cls)) {
			return 'pdp';
		}
		if (tag === 'header' || /(?:^|\s)site-header(?:\s|$)/.test(cls)) {
			return 'header';
		}
		if (tag === 'footer' || /(?:^|\s)site-footer(?:\s|$)/.test(cls)) {
			return 'footer';
		}
		return 'unknown';
	}

	// ─────────────────────────────────────────────────────────────────────
	// 2. Outbound-link detection.
	//
	//    Returns the foreign host name when href points to an absolute URL
	//    whose host differs from the current host AND from any known
	//    internal subdomain. Returns '' for:
	//      - relative URLs (/foo, foo/bar)
	//      - protocol-relative URLs to the same host
	//      - tel: / mailto: / javascript: / # links (covered by other handlers)
	//      - the same host or a known internal subdomain
	// ─────────────────────────────────────────────────────────────────────
	function detectOutboundHost(href) {
		if (!href || typeof href !== 'string') {
			return '';
		}
		var trimmed = href.trim();
		if (!trimmed) {
			return '';
		}
		// Skip non-http(s) protocols — telephones, emails, anchors are
		// handled by their own listeners.
		if (/^(?:tel:|mailto:|javascript:|#|data:)/i.test(trimmed)) {
			return '';
		}
		// Relative URL: starts with / or word-char (no scheme, no //).
		// Use URL() constructor with current location as base so we get a
		// canonical host either way. Wrapped in a guard so a malformed href
		// (e.g. URL containing whitespace from a CMS paste) can't throw.
		var parsed = null;
		try {
			parsed = new URL(trimmed, window.location.href);
		} catch (err) { // eslint-disable-line no-unused-vars
			return '';
		}
		if (!parsed || !parsed.host) {
			return '';
		}
		// Same-host: not outbound.
		if (parsed.host === window.location.host) {
			return '';
		}
		// Subdomain of current host: treat as internal.
		// e.g. current=example.com  link=blog.example.com  → internal
		var currentRoot = window.location.host.split(':')[0].split('.').slice(-2).join('.');
		var linkRoot    = parsed.host.split(':')[0].split('.').slice(-2).join('.');
		if (currentRoot && linkRoot && currentRoot === linkRoot) {
			return '';
		}
		return parsed.host;
	}

	// ─────────────────────────────────────────────────────────────────────
	// 3. Maps / "Get directions" detection.
	// ─────────────────────────────────────────────────────────────────────
	function isDirectionsLink(link) {
		if (!link) {
			return false;
		}
		var href = (link.getAttribute('href') || '').toLowerCase();
		if (
			href.indexOf('maps.google.') !== -1 ||
			href.indexOf('maps.apple.') !== -1 ||
			href.indexOf('goo.gl/maps') !== -1 ||
			href.indexOf('/maps/dir/') !== -1
		) {
			return true;
		}
		var text = (link.textContent || '').toLowerCase();
		if (/directions/.test(text)) {
			return true;
		}
		return false;
	}

	// ─────────────────────────────────────────────────────────────────────
	// 4. Document-level click delegation.
	//
	//    One listener handles every click-triggered event below. We walk up
	//    via .closest() to find the matching element.
	// ─────────────────────────────────────────────────────────────────────
	document.addEventListener('click', function (ev) {
		var target = ev.target;
		if (!target || !target.closest) {
			return;
		}

		// --- 4a. phone_click ---------------------------------------------
		var tel = target.closest('a[href^="tel:"]');
		if (tel) {
			var rawTel = tel.getAttribute('href') || '';
			push('phone_click', {
				phone_number: rawTel.replace(/^tel:/i, '').trim(),
				source: resolveSource(tel)
			});
			// Don't return — a single click can only match one of these
			// selectors anyway, but the explicit early return keeps the
			// branch boundaries readable.
			return;
		}

		// --- 4b. email_click ---------------------------------------------
		var mail = target.closest('a[href^="mailto:"]');
		if (mail) {
			var rawMail = mail.getAttribute('href') || '';
			push('email_click', {
				email: rawMail.replace(/^mailto:/i, '').split('?')[0].trim(),
				source: resolveSource(mail)
			});
			return;
		}

		// --- 4c. filter_apply (menu chip) --------------------------------
		var chip = target.closest('.lafka-menu__chip, .lafka-menu__category-chip');
		if (chip) {
			var filterValue =
				chip.getAttribute('data-filter-value') ||
				chip.getAttribute('data-value') ||
				(chip.textContent || '').trim();
			var filterType = chip.classList && chip.classList.contains('lafka-menu__category-chip')
				? 'category'
				: 'dietary';
			push('filter_apply', {
				filter_value: filterValue,
				filter_type: filterType
			});
			return;
		}

		// --- 4d. get_directions_click ------------------------------------
		var link = target.closest('a[href]');
		if (link && isDirectionsLink(link)) {
			push('get_directions_click', {
				source: resolveSource(link)
			});
			return;
		}

		// --- 4e. outbound_link -------------------------------------------
		if (link) {
			var outboundHost = detectOutboundHost(link.getAttribute('href') || '');
			if (outboundHost) {
				push('outbound_link', {
					destination_host: outboundHost,
					source: resolveSource(link)
				});
			}
		}
	});

	// ─────────────────────────────────────────────────────────────────────
	// 5. faq_open — delegated `toggle` on details.lafka-contact__faq-item.
	//
	//    The toggle event doesn't bubble in all browsers, so we attach the
	//    listener with the `capture: true` option (which catches it on the
	//    way down) and check the target inside.
	// ─────────────────────────────────────────────────────────────────────
	document.addEventListener('toggle', function (ev) {
		var details = ev.target;
		if (!details || details.tagName !== 'DETAILS') {
			return;
		}
		if (!details.classList || !details.classList.contains('lafka-contact__faq-item')) {
			return;
		}
		if (!details.open) {
			// Only emit on open transitions.
			return;
		}
		var summary = details.querySelector('summary');
		var question = summary ? (summary.textContent || '').trim() : '';

		// Position is 1-indexed among sibling faq items in the same parent.
		var position = 1;
		var sib = details.parentNode ? details.parentNode.children : [];
		for (var i = 0; i < sib.length; i++) {
			if (sib[i] === details) {
				position = i + 1;
				break;
			}
		}
		push('faq_open', {
			question_text: question,
			position: position
		});
	}, true);

	// ─────────────────────────────────────────────────────────────────────
	// 6. scroll_milestone — 25 / 50 / 75 / 100 % once per page view.
	//
	//    requestAnimationFrame throttling — no listener fires more than
	//    once per frame. The fired-set resets on popstate so SPA soft
	//    navigations get fresh milestones (Lafka is not currently SPA, but
	//    cheap insurance).
	// ─────────────────────────────────────────────────────────────────────
	var scrollMilestones = [25, 50, 75, 100];
	var firedMilestones = {};
	var scrollScheduled = false;

	function evaluateScrollDepth() {
		scrollScheduled = false;
		var doc        = document.documentElement || document.body;
		var winHeight  = window.innerHeight || doc.clientHeight || 0;
		var pageHeight = Math.max(
			doc.scrollHeight || 0,
			doc.offsetHeight || 0,
			document.body ? document.body.scrollHeight : 0,
			document.body ? document.body.offsetHeight : 0
		);
		var scrolled = window.pageYOffset || doc.scrollTop || 0;
		var denom    = Math.max(1, pageHeight - winHeight);
		var pct      = Math.min(100, Math.round((scrolled / denom) * 100));

		for (var i = 0; i < scrollMilestones.length; i++) {
			var m = scrollMilestones[i];
			if (pct >= m && !firedMilestones[m]) {
				firedMilestones[m] = true;
				push('scroll_milestone', {
					percent: m,
					page_path: window.location.pathname + window.location.search
				});
			}
		}
	}

	function onScroll() {
		if (scrollScheduled) {
			return;
		}
		scrollScheduled = true;
		window.requestAnimationFrame(evaluateScrollDepth);
	}

	window.addEventListener('scroll', onScroll, { passive: true });
	// Some long-form pages don't need a scroll to satisfy 25% on load.
	window.addEventListener('load', onScroll);

	// SPA-friendly: reset on history navigation so the next "page" gets
	// fresh milestones.
	window.addEventListener('popstate', function () {
		firedMilestones = {};
	});

	// ─────────────────────────────────────────────────────────────────────
	// 7. sticky_cart_open — IntersectionObserver one-shot + is-open class.
	//
	//    The sticky cart can become visible two ways:
	//      a) the user scrolls and the element enters the viewport (mobile
	//         bottom bar pattern);
	//      b) the user clicks a "show cart" trigger and the element flips
	//         from collapsed to expanded by toggling .is-open.
	//
	//    Both paths fire once per page view. We don't try to track close —
	//    GA4 doesn't model close events; the open is the conversion signal.
	// ─────────────────────────────────────────────────────────────────────
	function snapshotCart() {
		var dl    = window.lafkaDataLayer || null;
		var snap  = (dl && dl.cartSnapshot) || {};
		return {
			items_count: parseInt(snap.items_count, 10) || 0,
			value:       parseFloat(snap.value) || 0
		};
	}

	function bindStickyCart() {
		var sticky = document.querySelector('.lafka-sticky-cart');
		if (!sticky) {
			return;
		}
		var fired = false;
		function fireOnce() {
			if (fired) {
				return;
			}
			fired = true;
			var snap = snapshotCart();
			push('sticky_cart_open', {
				items_count: snap.items_count,
				value:       snap.value
			});
		}

		// Path a: IntersectionObserver (only when supported — older
		// browsers silently skip this signal rather than throwing).
		if (typeof window.IntersectionObserver === 'function') {
			var io = new window.IntersectionObserver(function (entries) {
				for (var i = 0; i < entries.length; i++) {
					if (entries[i].isIntersecting) {
						fireOnce();
						io.disconnect();
						return;
					}
				}
			});
			io.observe(sticky);
		}

		// Path b: class-toggle MutationObserver.
		if (typeof window.MutationObserver === 'function') {
			var mo = new window.MutationObserver(function () {
				if (sticky.classList && sticky.classList.contains('is-open')) {
					fireOnce();
				}
			});
			mo.observe(sticky, { attributes: true, attributeFilter: ['class'] });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindStickyCart);
	} else {
		bindStickyCart();
	}
})();
