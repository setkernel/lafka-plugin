/**
 * Consent mirror (inlined first in <head> by lafka_emit_consent_mirror())
 * for everything that acts on consent server-side: Lafka Insights'
 * consent_required mode and the server-side conversions (GA4 Measurement
 * Protocol, Meta Conversions API).
 *
 * Watches window.dataLayer for the `consent_update` pushes the consent
 * banner already makes — from the head replay for a returning visitor and on
 * every Accept / Reject / Save — and mirrors the choice into:
 *   - first-party cookies (180 days) the server reads, since PHP cannot see
 *     localStorage: `lafka_consent` (analytics, '1' / '0') and
 *     `lafka_consent_ads` (ad_storage and ad_user_data, '1' / '0');
 *   - WooCommerce Order Attribution, via wc_order_attribution.setOrderTracking().
 */
(function (w) {
	const dl = (w.dataLayer = w.dataLayer || []);
	const push = dl.push;

	function setCookie(name, granted) {
		w.document.cookie = name + '=' + (granted ? '1' : '0') + ';path=/;max-age=15552000;SameSite=Lax' + (w.location.protocol === 'https:' ? ';Secure' : '');
	}

	function mirror(state) {
		try {
			setCookie('lafka_consent', state.analytics_storage);
			setCookie('lafka_consent_ads', state.ad_storage && state.ad_user_data);
		} catch {
			// Cookies blocked: server-side events simply stay off.
		}
		const wc = w.wc_order_attribution;
		if (wc && typeof wc.setOrderTracking === 'function') {
			wc.setOrderTracking(!!state.analytics_storage);
		}
	}

	dl.push = function () {
		for (let i = 0; i < arguments.length; i++) {
			const o = arguments[i];
			if (o && o.event === 'consent_update' && o.consent_state) {
				mirror(o.consent_state);
			}
		}
		return push.apply(dl, arguments);
	};
})(window);
