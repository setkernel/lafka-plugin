/**
 * Lafka JS error beacon (A6) — inlined in <head>, ≤ 700 bytes minified.
 *
 * Reports uncaught `error` and `unhandledrejection` events to
 * POST /wp-json/lafka/v1/diag with navigator.sendBeacon (text/plain, so no
 * CORS preflight). Only errors thrown by scripts served from this site's own
 * origin are reported: "Script error." (opaque cross-origin), extension
 * frames and third-party scripts are ignored. Duplicates (same message, file
 * and line) are sent once; at most 3 reports per page and 10 per tab session.
 *
 * Config (inline, page-cache safe): window.lafkaDiagCfg = { u: endpoint,
 * s: sample rate 0–1 (always sent), t: page type }.
 */
(function (w) {
	var c = w.lafkaDiagCfg || {};
	var n = w.navigator;
	var o = w.location.origin + '/';
	var seen = {};
	var count = 0;
	// c.s is the sample rate (the server always sends it; 1 = everything).
	if (!c.u || !n.sendBeacon || Math.random() >= c.s) {
		return;
	}

	function send(msg, file, line, col) {
		msg = String(msg || '').slice(0, 200);
		file = String(file || '');
		var key = msg + file + line;
		if (count > 2 || !msg || /^Script error\.?$/.test(msg) || file.indexOf(o) || seen[key]) {
			return;
		}
		seen[key] = 1;
		try {
			var ss = w.sessionStorage;
			var s = +ss.lafka_diag || 0;
			if (s > 9) {
				return;
			}
			ss.lafka_diag = s + 1;
		} catch {
			// Storage blocked: the per-page cap still applies.
		}
		count++;
		// The server reduces the file URL to its path (query string dropped).
		n.sendBeacon(c.u, JSON.stringify({ m: msg, f: file, l: line | 0, c: col | 0, t: c.t }));
	}

	w.addEventListener('error', function (e) {
		send(e.message, e.filename, e.lineno, e.colno);
	});
	w.addEventListener('unhandledrejection', function (e) {
		var r = e.reason || {};
		var at = /(https?:[^\s)]+):(\d+):(\d+)/.exec(r.stack || '') || [];
		send(r.message || r, at[1], at[2], at[3]);
	});
})(window);
