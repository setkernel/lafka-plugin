(function () {
	'use strict';

	var config = window.LAFKA_KDS_CUSTOMER;
	if (!config) return;

	var steps = ['processing', 'accepted', 'preparing', 'ready', 'completed'];
	var pollTimer = null;

	function updateProgressBar(status, eta, serverTime) {
		var currentIdx = steps.indexOf(status);
		if (currentIdx === -1) return;

		var container = document.getElementById('lafka-kds-progress');
		if (!container) return;

		container.setAttribute('data-status', status);

		// Update step classes
		steps.forEach(function (step, idx) {
			var stepEl = container.querySelector('[data-step="' + step + '"]');
			if (!stepEl) return;

			stepEl.className = 'lafka-kds-step';
			if (idx < currentIdx) {
				stepEl.classList.add('lafka-kds-step-done');
			} else if (idx === currentIdx) {
				stepEl.classList.add('lafka-kds-step-active');
			}
		});

		// Update ETA
		var etaContainer = document.getElementById('lafka-kds-eta');
		if (eta && (status === 'accepted' || status === 'preparing')) {
			if (!etaContainer) {
				etaContainer = document.createElement('div');
				etaContainer.className = 'lafka-kds-eta';
				etaContainer.id = 'lafka-kds-eta';
				etaContainer.innerHTML = '<span class="lafka-kds-eta-label">' + escHtml(config.i18n.estimated) + '</span> <span class="lafka-kds-eta-value" id="lafka-kds-eta-value"></span>';
				container.appendChild(etaContainer);
			}
			etaContainer.style.display = '';
			etaContainer.setAttribute('data-eta', eta);
			updateEtaCountdown(eta, serverTime);
		} else if (etaContainer) {
			etaContainer.style.display = 'none';
		}

		// Stop polling on completed
		if (status === 'completed' && pollTimer) {
			clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	function updateEtaCountdown(eta, serverTime) {
		var valueEl = document.getElementById('lafka-kds-eta-value');
		if (!valueEl) return;

		var remaining = eta - serverTime;
		if (remaining <= 0) {
			valueEl.textContent = '...';
			return;
		}

		var m = Math.floor(remaining / 60);
		var s = remaining % 60;
		valueEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
	}

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str || ''));
		return div.innerHTML;
	}

	function poll() {
		var formData = new FormData();
		formData.append('action', 'lafka_kds_customer_status');
		formData.append('nonce', config.nonce);
		formData.append('order_id', config.orderId);
		formData.append('order_key', config.orderKey);

		fetch(config.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
		.then(function (res) { return res.json(); })
		.then(function (data) {
			if (data && data.success) {
				updateProgressBar(data.data.status, data.data.eta, data.data.server_time);
			}
		})
		.catch(function () {});
	}

	function initEtaCountdownTick() {
		setInterval(function () {
			var etaEl = document.getElementById('lafka-kds-eta');
			if (!etaEl) return;
			var eta = parseInt(etaEl.getAttribute('data-eta'), 10);
			if (!eta) return;
			var now = Math.floor(Date.now() / 1000);
			updateEtaCountdown(eta, now);
		}, 1000);
	}

	function init() {
		// Initial ETA display
		var etaEl = document.getElementById('lafka-kds-eta');
		if (etaEl) {
			var eta = parseInt(etaEl.getAttribute('data-eta'), 10);
			if (eta) {
				var now = Math.floor(Date.now() / 1000);
				updateEtaCountdown(eta, now);
			}
		}

		initEtaCountdownTick();

		// Start polling
		if (config.pollInterval) {
			pollTimer = setInterval(poll, config.pollInterval);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
