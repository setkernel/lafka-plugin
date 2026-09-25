/**
 * Consent mirror for Lafka Insights' consent_required mode (inlined first in
 * <head> by lafka_emit_consent_mirror()).
 *
 * Watches window.dataLayer for the `consent_update` pushes the consent
 * banner already makes — from the head replay for a returning visitor and on
 * every Accept / Reject / Save — and mirrors the analytics choice into:
 *   - the first-party `lafka_consent` cookie ('1' / '0', 180 days), which the
 *     server-side Insights events read (PHP cannot see localStorage);
 *   - WooCommerce Order Attribution, via wc_order_attribution.setOrderTracking()
 *     (tracking starts disabled in this mode).
 */
(function (w) {
	var dl = (w.dataLayer = w.dataLayer || []);
	var push = dl.push;

	function mirror(state) {
		try {
			w.document.cookie = 'lafka_consent=' + (state.analytics_storage ? '1' : '0') + ';path=/;max-age=15552000;SameSite=Lax' + (w.location.protocol === 'https:' ? ';Secure' : '');
		} catch {
			// Cookies blocked: server-side events simply stay off.
		}
		var wc = w.wc_order_attribution;
		if (wc && typeof wc.setOrderTracking === 'function') {
			wc.setOrderTracking(!!state.analytics_storage);
		}
	}

	dl.push = function () {
		for (var i = 0; i < arguments.length; i++) {
			var o = arguments[i];
			if (o && o.event === 'consent_update' && o.consent_state) {
				mirror(o.consent_state);
			}
		}
		return push.apply(dl, arguments);
	};
})(window);
