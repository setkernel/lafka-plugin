/**
 * Lafka Insights — first-party, cookieless page beacon (GX2).
 *
 * Reads the dataLayer events the plugin already emits (page_context,
 * view_item_list, view_item, select_item, search, store_closed_view,
 * select_fulfilment, order_channel_click, add_shipping_info) — both the ones
 * pushed before this deferred script ran and every later push — keeps a small
 * allowlisted queue in memory, and sends ONE navigator.sendBeacon per page on
 * pagehide / visibilitychange→hidden to POST /wp-json/lafka/v1/i.
 *
 * The page view carries the referrer HOST only, utm_source / utm_medium /
 * utm_campaign, the page type and a viewport device class. Nothing is stored
 * in the browser: no cookie, no localStorage, no identifier.
 *
 * Consent (window.lafkaInsightsCfg.m): 'a' aggregate — skipped when the
 * browser sends Global Privacy Control / Do Not Track; 'c' consent_required —
 * sent only when lafka_consent_v1 grants analytics_storage at send time.
 *
 * Config (inline, identical for every anonymous visitor so it is page-cache
 * safe): { u: endpoint, m: 'a'|'c', n: wp_rest nonce for logged-in users }.
 */
(function (w, d) {
	'use strict';

	var cfg = w.lafkaInsightsCfg || {};
	var nav = w.navigator || {};
	if (!cfg.u || typeof nav.sendBeacon !== 'function') {
		return;
	}

	var MAX = 30;
	var ALLOW = { view_item_list: 'l', view_item: 'v', select_item: 'i', search: 's', store_closed_view: 'c', select_fulfilment: 'f', order_channel_click: 'o', add_shipping_info: 'h' };
	var queue = [];
	var pageType = '';
	var sent = false;

	function str(v, n) {
		return String(v == null ? '' : v).slice(0, n);
	}

	function itemId(o) {
		var items = o.ecommerce && o.ecommerce.items;
		return items && items[0] ? str(items[0].item_id, 20) : '';
	}

	function take(o) {
		if (!o || typeof o !== 'object' || !o.event) {
			return;
		}
		if (o.event === 'page_context') {
			pageType = str(o.page_type, 16);
			return;
		}
		var code = ALLOW[o.event];
		if (!code || queue.length >= MAX) {
			return;
		}
		var e = [code];
		if (code === 'v' || code === 'i') {
			var id = itemId(o);
			if (!/^\d+$/.test(id)) {
				return;
			}
			e.push(id);
		} else if (code === 's') {
			e.push(str(o.search_term, 64), parseInt(o.results_count, 10) || 0);
			// The search box pushes while the visitor types ("pi", "piz",
			// "pizza"): keep only the latest refinement of the same search.
			var last = queue[queue.length - 1];
			if (last && last[0] === 's' && (e[1].indexOf(last[1]) === 0 || last[1].indexOf(e[1]) === 0)) {
				queue.pop();
			}
		} else if (code === 'f') {
			e.push(str(o.fulfilment_method, 24));
		} else if (code === 'o') {
			e.push(str(o.order_channel, 24));
		}
		queue.push(e);
	}

	var dl = (w.dataLayer = w.dataLayer || []);
	for (var i = 0; i < dl.length; i++) {
		take(dl[i]);
	}
	var push = dl.push;
	dl.push = function () {
		for (var j = 0; j < arguments.length; j++) {
			take(arguments[j]);
		}
		return push.apply(dl, arguments);
	};

	function allowed() {
		if (cfg.m === 'c') {
			try {
				var c = JSON.parse(w.localStorage.getItem('lafka_consent_v1') || 'null');
				return !!(c && c.analytics_storage);
			} catch {
				return false;
			}
		}
		return !(nav.globalPrivacyControl || nav.doNotTrack === '1' || w.doNotTrack === '1');
	}

	function payload() {
		var loc = w.location;
		var ref = '';
		try {
			ref = d.referrer ? new URL(d.referrer).hostname : '';
		} catch {
			ref = '';
		}
		var q = new URLSearchParams(loc.search || '');
		var width = w.innerWidth || 0;
		var body = {
			v: 1,
			p: str(loc.pathname, 100),
			t: pageType,
			r: str(ref, 64),
			us: str(q.get('utm_source'), 64),
			um: str(q.get('utm_medium'), 32),
			uc: str(q.get('utm_campaign'), 64),
			d: width && width < 768 ? 'm' : width && width < 1024 ? 't' : 'd',
			e: queue
		};
		var json = JSON.stringify(body);
		while (json.length > 2000 && body.e.length) {
			body.e.pop();
			json = JSON.stringify(body);
		}
		return json;
	}

	function flush() {
		if (sent || !allowed()) {
			return;
		}
		sent = true;
		nav.sendBeacon(cfg.u + (cfg.n ? (cfg.u.indexOf('?') < 0 ? '?' : '&') + '_wpnonce=' + encodeURIComponent(cfg.n) : ''), payload());
	}

	w.addEventListener('pagehide', flush);
	d.addEventListener('visibilitychange', function () {
		if (d.visibilityState === 'hidden') {
			flush();
		}
	});
})(window, document);
