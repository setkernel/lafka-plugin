/**
 * Deal builder: one step per slot (choose an item, set its options), a live
 * server quote, and one request that adds the whole deal.
 *
 * Markup: Lafka_Deals_Builder::render(). Each slot is its own <form>, so the
 * same add-on field names in two slots never share a radio group. Prices are
 * never computed here: the quote endpoint runs the same resolve() as the add.
 */
(function () {
	'use strict';

	const root = document.querySelector('[data-lafka-deal]');
	if (!root) {
		return;
	}

	let config;
	try {
		config = JSON.parse(root.getAttribute('data-lafka-deal'));
	} catch {
		return;
	}

	const slots = Array.prototype.slice.call(root.querySelectorAll('[data-lafka-deal-slot]'));
	const button = root.querySelector('[data-lafka-deal-add]');
	const total = root.querySelector('[data-lafka-deal-total]');
	const note = root.querySelector('[data-lafka-deal-note]');
	const errorBox = root.querySelector('[data-lafka-deal-error]');
	let quoteTimer = null;
	let quoteSeq = 0;
	let complete = false;

	function format(template, value) {
		return template.replace('%s', value);
	}

	function post(action, data) {
		const body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', config.nonce);
		body.set('deal_id', String(config.dealId));
		Object.keys(data).forEach(function (key) {
			body.set(key, data[key]);
		});
		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		});
	}

	function showError(message) {
		errorBox.textContent = message || '';
		errorBox.hidden = !message;
	}

	function openSlot(slot) {
		slots.forEach(function (other) {
			const isOpen = other === slot;
			other.querySelector('.lafka-deal-slot__head').setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			other.querySelector('.lafka-deal-slot__body').hidden = !isOpen;
		});
		if (slot) {
			slot.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}
	}

	/** Slot index => { product_id, attributes, addons } from each slot form. */
	function choices() {
		const out = {};
		slots.forEach(function (slot) {
			const form = slot.querySelector('[data-lafka-deal-form]');
			const data = new FormData(form);
			const choice = { product_id: Number(data.get('lafka_deal_product')) || 0, attributes: {}, addons: {} };
			data.forEach(function (value, name) {
				if (name.indexOf('attribute_') === 0) {
					choice.attributes[name] = value;
				} else if (name.indexOf('addon-') === 0) {
					const key = name.replace(/\[\]$/, '');
					if (/\[\]$/.test(name)) {
						(choice.addons[key] = choice.addons[key] || []).push(value);
					} else {
						choice.addons[key] = value;
					}
				}
			});
			out[slot.getAttribute('data-lafka-deal-slot')] = choice;
		});
		return out;
	}

	function render(quote) {
		total.textContent = quote.total;
		note.textContent = quote.note || '';
		slots.forEach(function (slot) {
			const index = slot.getAttribute('data-lafka-deal-slot');
			const label = slot.querySelector('[data-lafka-deal-choice]');
			const summary = quote.summaries && quote.summaries[index];
			label.textContent = summary || label.getAttribute('data-empty') || label.textContent;
			slot.classList.toggle('is-chosen', !!summary);
		});
		complete = quote.missing.length === 0 && quote.errors.length === 0;
		button.disabled = !complete;
		button.textContent = complete ? format(config.i18n.add, quote.total) : format(config.i18n.choose, quote.missing[0] || '');
		showError(quote.errors.join(' '));
	}

	function quote() {
		clearTimeout(quoteTimer);
		quoteTimer = setTimeout(function () {
			const seq = ++quoteSeq;
			post('lafka_deal_quote', { choices: JSON.stringify(choices()) }).then(function (response) {
				if (seq === quoteSeq && response && response.success) {
					render(response.data);
				}
			}).catch(function () {
				showError(config.i18n.failed);
			});
		}, 250);
	}

	function nextButton(slot) {
		const index = slots.indexOf(slot);
		const next = slots[index + 1];
		const done = document.createElement('button');
		done.type = 'button';
		done.className = 'lafka-deal-slot__next button';
		done.textContent = next ? format(config.i18n.choose, next.getAttribute('data-label')) : config.i18n.done;
		done.addEventListener('click', function () {
			openSlot(next || null);
			if (!next) {
				button.focus();
			}
		});
		return done;
	}

	function loadItem(slot, productId) {
		const options = slot.querySelector('[data-lafka-deal-options]');
		options.textContent = '';
		if (!productId) {
			quote();
			return;
		}
		options.setAttribute('aria-busy', 'true');
		post('lafka_deal_item', { slot: slot.getAttribute('data-lafka-deal-slot'), product_id: String(productId) }).then(function (response) {
			options.removeAttribute('aria-busy');
			if (!response || !response.success) {
				showError(response && response.data && response.data.message ? response.data.message : config.i18n.failed);
				return;
			}
			// Server-rendered, escaped option markup (attribute selects and the
			// add-on engine's own fields).
			options.innerHTML = response.data.html;
			options.appendChild(nextButton(slot));
			quote();
		}).catch(function () {
			options.removeAttribute('aria-busy');
			showError(config.i18n.failed);
		});
	}

	slots.forEach(function (slot) {
		const head = slot.querySelector('.lafka-deal-slot__head');
		const choice = slot.querySelector('[data-lafka-deal-choice]');
		choice.setAttribute('data-empty', choice.textContent);
		head.addEventListener('click', function () {
			const open = head.getAttribute('aria-expanded') === 'true';
			openSlot(open ? null : slot);
		});
		const form = slot.querySelector('[data-lafka-deal-form]');
		form.addEventListener('submit', function (event) {
			event.preventDefault();
		});
		form.addEventListener('change', function (event) {
			if (event.target.name === 'lafka_deal_product') {
				loadItem(slot, Number(event.target.value));
				return;
			}
			quote();
		});
	});

	button.addEventListener('click', function () {
		if (!complete) {
			return;
		}
		button.disabled = true;
		const label = button.textContent;
		button.textContent = config.i18n.adding;
		post('lafka_deal_add', { choices: JSON.stringify(choices()) }).then(function (response) {
			if (!response || !response.success) {
				button.disabled = false;
				button.textContent = label;
				showError(response && response.data && response.data.message ? response.data.message : config.i18n.failed);
				return;
			}
			showError('');
			button.textContent = config.i18n.added;
			// WooCommerce's add-to-cart contract: themes and the mini cart
			// refresh from these fragments (the cart drawer listens for it).
			if (window.jQuery) {
				window.jQuery(document.body).trigger('added_to_cart', [response.data.fragments, response.data.cart_hash, window.jQuery(button)]);
			}
			document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart'));
			setTimeout(function () {
				button.disabled = false;
				button.textContent = label;
			}, 2500);
		}).catch(function () {
			button.disabled = false;
			button.textContent = label;
			showError(config.i18n.failed);
		});
	});

	quote();
})();
