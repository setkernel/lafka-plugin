(function () {
	'use strict';

	var config = window.LAFKA_KDS;
	if (!config) return;

	var knownIds = new Set();
	var firstLoad = true;
	var audio = null;
	var activeAlerts = []; // hold references to prevent GC
	var soundReady = false;
	var pollTimer = null;
	var etaOrderId = null;
	var etaSelectedMinutes = null;
	var failCount = 0;
	var AUTO_RELOAD_MS = 60 * 60 * 1000; // 1 hour

	// --- Helpers ---

	function esc(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	function formatElapsed(seconds) {
		if (seconds < 0) seconds = 0;
		var m = Math.floor(seconds / 60);
		var s = seconds % 60;
		if (m >= 60) {
			var h = Math.floor(m / 60);
			m = m % 60;
			return h + 'h ' + m + 'm';
		}
		return m + 'm ' + (s < 10 ? '0' : '') + s + 's';
	}

	function formatCountdown(seconds) {
		if (seconds <= 0) return config.i18n.overdue;
		var m = Math.floor(seconds / 60);
		var s = seconds % 60;
		return m + ':' + (s < 10 ? '0' : '') + s;
	}

	// --- Clock ---

	function updateClock() {
		var now = new Date();
		var h = now.getHours();
		var m = now.getMinutes();
		var s = now.getSeconds();
		var el = document.getElementById('kds-clock');
		if (el) {
			el.textContent =
				(h < 10 ? '0' : '') + h + ':' +
				(m < 10 ? '0' : '') + m + ':' +
				(s < 10 ? '0' : '') + s;
		}
	}

	// --- Sound & Speech ---

	function initSound() {
		if (!config.soundEnabled) {
			hideSoundOverlay();
			return;
		}

		// Preload the bell sound
		audio = new Audio(config.soundUrl);
		audio.preload = 'auto';

		var overlay = document.getElementById('kds-sound-overlay');
		overlay.addEventListener('click', function () {
			// Play bell to unlock Audio API
			audio.currentTime = 0;
			audio.volume = 1;
			audio.play().then(function () {
				soundReady = true;
			}).catch(function () {
				soundReady = true;
			});

			// Unlock Speech API with the announcement (also serves as test)
			if ('speechSynthesis' in window) {
				// Small delay so bell and speech don't start simultaneously
				setTimeout(function () {
					speakAnnouncement(config.i18n.soundReadyMsg);
				}, 2500);
			}

			hideSoundOverlay();
		});
	}

	function hideSoundOverlay() {
		var overlay = document.getElementById('kds-sound-overlay');
		overlay.classList.add('kds-hidden');
	}

	function playNewOrderSound() {
		if (!soundReady) return;

		// Play bell — hold reference until it finishes
		var bell = new Audio(config.soundUrl);
		bell.volume = 1;
		activeAlerts.push(bell);

		// Issue #13: Fix memory leak - properly clean up when audio ends
		bell.addEventListener('ended', function() {
			var idx = activeAlerts.indexOf(bell);
			if (idx > -1) {
				activeAlerts.splice(idx, 1);
			}
		});

		// Also clean up on error
		bell.addEventListener('error', function() {
			var idx = activeAlerts.indexOf(bell);
			if (idx > -1) {
				activeAlerts.splice(idx, 1);
			}
		});

		bell.play().catch(function () {});

		// Speak announcement after bell starts
		if ('speechSynthesis' in window) {
			setTimeout(function () {
				speakAnnouncement(config.i18n.newOrderAnnouncement);
			}, 1500);
		}

		// Visual flash on New Orders column
		flashColumn('processing');
	}

	function speakAnnouncement(text) {
		if (!('speechSynthesis' in window) || !text) return;
		window.speechSynthesis.cancel();
		var utterance = new SpeechSynthesisUtterance(text);
		utterance.volume = 1;
		utterance.rate = 0.9;
		utterance.pitch = 1.0;
		window.speechSynthesis.speak(utterance);
	}

	function flashColumn(status) {
		var header = document.querySelector('[data-status="' + status + '"] .kds-column-header');
		if (!header) return;
		header.classList.remove('kds-flash');
		void header.offsetWidth; // force reflow to restart animation
		header.classList.add('kds-flash');
	}

	// --- Fullscreen ---

	function initFullscreen() {
		var btn = document.getElementById('kds-fullscreen');
		btn.addEventListener('click', function () {
			if (!document.fullscreenElement) {
				document.documentElement.requestFullscreen().catch(function () {});
			} else {
				document.exitFullscreen();
			}
		});
	}

	// --- Print (Issue #35) ---

	function initPrint() {
		var btn = document.getElementById('kds-print');
		btn.addEventListener('click', function () {
			window.print();
		});
	}

	// --- Rendering ---

	function renderOrders(orders, serverTime) {
		var columns = {
			processing: [],
			accepted: [],
			preparing: [],
			ready: [],
			completed: []
		};

		var newOrderDetected = false;
		var currentIds = new Set();

		orders.forEach(function (order) {
			currentIds.add(order.id);
			if (columns[order.status]) {
				columns[order.status].push(order);
			}

			// Detect truly new orders (not first load)
			if (!firstLoad && !knownIds.has(order.id) && order.status === 'processing') {
				newOrderDetected = true;
			}
		});

		knownIds = currentIds;
		firstLoad = false;

		if (newOrderDetected) {
			playNewOrderSound();
		}

		Object.keys(columns).forEach(function (status) {
			var col = document.getElementById('col-' + status);
			var countEl = document.getElementById('count-' + status);
			var items = columns[status];

			countEl.textContent = items.length;

			if (!items.length) {
				col.innerHTML = '<div class="kds-no-orders">' + esc(config.i18n.noOrders) + '</div>';
				return;
			}

			col.innerHTML = items.map(function (order) {
				return renderCard(order, serverTime);
			}).join('');
		});

		// Bind action buttons
		document.querySelectorAll('[data-action="status"]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var orderId = parseInt(this.getAttribute('data-order-id'), 10);
				var newStatus = this.getAttribute('data-new-status');
				updateOrderStatus(orderId, newStatus);
			});
		});

		document.querySelectorAll('[data-action="eta"]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var orderId = parseInt(this.getAttribute('data-order-id'), 10);
				var orderType = this.getAttribute('data-order-type');
				var orderNum = this.getAttribute('data-order-num');
				openEtaModal(orderId, orderType, orderNum);
			});
		});
	}

	function renderCard(order, serverTime) {
		var elapsed = serverTime - order.date_created;
		var isPickup = order.order_type === 'pickup';
		var typeBadgeClass = isPickup ? 'kds-badge-pickup' : 'kds-badge-delivery';
		var typeLabel = isPickup ? config.i18n.pickup : config.i18n.delivery;
		var payBadgeClass = order.is_paid_online ? 'kds-badge-paid' : 'kds-badge-cod';
		var payLabel;
		if (order.is_paid_online) {
			payLabel = config.i18n.paidOnline;
		} else {
			payLabel = isPickup ? config.i18n.cashOnCounter : config.i18n.cashOnDelivery;
		}

		var html = '<div class="kds-card" data-order-id="' + order.id + '">';

		// Header
		html += '<div class="kds-card-header">';
		html += '<span class="kds-order-num">#' + esc(String(order.number)) + '</span>';
		html += '<span class="kds-elapsed">' + formatElapsed(elapsed) + ' ' + esc(config.i18n.elapsed) + '</span>';
		html += '</div>';

		// Badges
		html += '<div class="kds-badges">';
		html += '<span class="kds-badge ' + typeBadgeClass + '">' + esc(typeLabel) + '</span>';
		html += '<span class="kds-badge ' + payBadgeClass + '">' + esc(payLabel) + '</span>';
		html += '</div>';

		// Scheduled time
		if (order.scheduled) {
			html += '<div class="kds-scheduled">&#128197; ' + esc(config.i18n.scheduled) + ': ' + esc(order.scheduled) + '</div>';
		}

		// Customer
		html += '<div class="kds-customer">';
		html += esc(order.customer_name);
		if (order.customer_phone) {
			html += '<span class="kds-customer-phone">&#128222; ' + esc(order.customer_phone) + '</span>';
		}
		html += '</div>';

		// Items
		html += '<ul class="kds-items">';
		order.items.forEach(function (item) {
			html += '<li class="kds-item">';
			html += '<span class="kds-item-qty">' + item.quantity + 'x</span> ';
			html += esc(item.name);
			item.meta.forEach(function (m) {
				html += '<span class="kds-item-meta">' + esc(m.key) + ': ' + esc(m.value) + '</span>';
			});
			html += '</li>';
		});
		html += '</ul>';

		// Note
		if (order.customer_note) {
			html += '<div class="kds-note"><span class="kds-note-label">' + esc(config.i18n.note) + ':</span> ' + esc(order.customer_note) + '</div>';
		}

		// Total
		html += '<div class="kds-total">' + esc(order.currency_symbol) + '<span class="kds-total-amount">' + esc(order.total) + '</span></div>';

		// ETA
		if (order.eta && (order.status === 'accepted' || order.status === 'preparing')) {
			var remaining = order.eta - serverTime;
			var isOverdue = remaining <= 0;
			html += '<div class="kds-card-eta' + (isOverdue ? ' kds-overdue' : '') + '">';
			html += esc(config.i18n.etaLabel) + ': ' + formatCountdown(remaining);
			html += '</div>';
		}

		// Actions (not for completed orders)
		if (order.status !== 'completed') {
			html += '<div class="kds-card-actions">';
			var actionBtn = getActionButton(order);
			if (actionBtn) {
				html += '<button class="kds-btn kds-btn-action" data-action="status" data-order-id="' + order.id + '" data-new-status="' + actionBtn.status + '">' + esc(actionBtn.label) + '</button>';
			}
			if (order.status !== 'ready') {
				html += '<button class="kds-btn kds-btn-eta" data-action="eta" data-order-id="' + order.id + '" data-order-type="' + esc(order.order_type) + '" data-order-num="' + esc(String(order.number)) + '">' + esc(config.i18n.setEta) + '</button>';
			}
			html += '</div>';
		}

		html += '</div>';
		return html;
	}

	function getActionButton(order) {
		switch (order.status) {
			case 'processing':
				return { status: 'accepted', label: config.i18n.accept };
			case 'accepted':
				return { status: 'preparing', label: config.i18n.startPrep };
			case 'preparing':
				return { status: 'ready', label: config.i18n.markReady };
			case 'ready':
				return { status: 'completed', label: config.i18n.complete };
			default:
				return null;
		}
	}

	// --- AJAX ---

	function fetchOrders() {
		var formData = new FormData();
		formData.append('action', 'lafka_kds_get_orders');
		formData.append('nonce', config.nonce);
		formData.append('kds_token', config.token);

		fetch(config.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
		.then(function (res) {
			if (res.status === 403) {
				window.location.reload();
				return;
			}
			return res.json();
		})
		.then(function (data) {
			if (data && data.success) {
				failCount = 0;
				setConnectionStatus(true);
				renderOrders(data.data.orders, data.data.server_time);
			}
		})
		.catch(function () {
			failCount++;
			if (failCount >= 3) {
				setConnectionStatus(false);
			}
		});
	}

	function setConnectionStatus(connected) {
		var el = document.getElementById('kds-connection-lost');
		if (!el) return;
		if (connected) {
			el.classList.add('kds-hidden');
		} else {
			el.classList.remove('kds-hidden');
		}
	}

	function updateOrderStatus(orderId, newStatus) {
		var formData = new FormData();
		formData.append('action', 'lafka_kds_update_status');
		formData.append('nonce', config.nonce);
		formData.append('kds_token', config.token);
		formData.append('order_id', orderId);
		formData.append('new_status', newStatus);

		fetch(config.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
		.then(function (res) {
			if (res.status === 403) {
				window.location.reload();
				return;
			}
			return res.json();
		})
		.then(function () {
			fetchOrders();
		})
		.catch(function (err) {
			console.error('KDS status update error:', err);
		});
	}

	function setEta(orderId, minutes) {
		var formData = new FormData();
		formData.append('action', 'lafka_kds_set_eta');
		formData.append('nonce', config.nonce);
		formData.append('kds_token', config.token);
		formData.append('order_id', orderId);
		formData.append('minutes', minutes);

		fetch(config.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
		.then(function (res) {
			if (res.status === 403) {
				window.location.reload();
				return;
			}
			return res.json();
		})
		.then(function () {
			fetchOrders();
		})
		.catch(function (err) {
			console.error('KDS ETA set error:', err);
		});
	}

	// --- ETA Modal ---

	function openEtaModal(orderId, orderType, orderNum) {
		etaOrderId = orderId;
		etaSelectedMinutes = null;

		document.getElementById('kds-eta-order-num').textContent = '#' + orderNum;

		var presets = orderType === 'pickup' ? config.pickupTimes : config.deliveryTimes;
		var presetsEl = document.getElementById('kds-eta-presets');
		presetsEl.innerHTML = presets.map(function (m) {
			return '<button class="kds-eta-preset-btn" data-minutes="' + m + '">' + m + ' ' + esc(config.i18n.min) + '</button>';
		}).join('');

		presetsEl.querySelectorAll('.kds-eta-preset-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				presetsEl.querySelectorAll('.kds-eta-preset-btn').forEach(function (b) {
					b.classList.remove('kds-selected');
				});
				this.classList.add('kds-selected');
				etaSelectedMinutes = parseInt(this.getAttribute('data-minutes'), 10);
				document.getElementById('kds-eta-custom-input').value = '';
			});
		});

		document.getElementById('kds-eta-custom-input').value = '';
		document.getElementById('kds-eta-modal').style.display = '';
	}

	function closeEtaModal() {
		document.getElementById('kds-eta-modal').style.display = 'none';
		etaOrderId = null;
		etaSelectedMinutes = null;
	}

	function confirmEta() {
		var customVal = parseInt(document.getElementById('kds-eta-custom-input').value, 10);
		var minutes = customVal > 0 ? customVal : etaSelectedMinutes;

		if (!minutes || minutes < 1 || !etaOrderId) return;

		setEta(etaOrderId, minutes);
		closeEtaModal();
	}

	// --- Init ---

	function init() {
		updateClock();
		setInterval(updateClock, 1000);

		initSound();
		initFullscreen();
		initPrint();

		// ETA modal
		document.getElementById('kds-eta-cancel').addEventListener('click', closeEtaModal);
		document.getElementById('kds-eta-confirm').addEventListener('click', confirmEta);
		document.getElementById('kds-eta-modal').addEventListener('click', function (e) {
			if (e.target === this) closeEtaModal();
		});

		// Custom input clears preset selection
		document.getElementById('kds-eta-custom-input').addEventListener('input', function () {
			document.querySelectorAll('.kds-eta-preset-btn').forEach(function (b) {
				b.classList.remove('kds-selected');
			});
			etaSelectedMinutes = null;
		});

		// First fetch
		fetchOrders();

		// Polling
		pollTimer = setInterval(fetchOrders, config.pollInterval);

		// Safety net: full page reload every hour
		setTimeout(function () { window.location.reload(); }, AUTO_RELOAD_MS);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
