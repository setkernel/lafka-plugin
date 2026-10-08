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
 *     localStorage (analytics and ad_storage + ad_user_data, '1' / '0'; the
 *     names are lafka_consent_cookie_name() in PHP, passed in as
 *     window.lafkaConsentCookies);
 *   - WooCommerce Order Attribution, via wc_order_attribution.setOrderTracking().
 */
(function (w) {
	const dl = (w.dataLayer = w.dataLayer || []);
	const push = dl.push;

	function setCookie(name, granted) {
		w.document.cookie = name + '=' + (granted ? '1' : '0') + ';path=/;max-age=15552000;SameSite=Lax' + (w.location.protocol === 'https:' ? ';Secure' : '');
	}

	// Cookie names come from PHP (lafka_consent_cookie_name()) in window.lafkaConsentCookies.
	const names = w.lafkaConsentCookies;

	function mirror(state) {
		if (!names) {
			return;
		}
		try {
			setCookie(names.analytics, state.analytics_storage);
			setCookie(names.ads, state.ad_storage && state.ad_user_data);
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
