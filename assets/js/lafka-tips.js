/**
 * Tips on the classic checkout: store the choice, then let WooCommerce
 * recalculate the order review (Lafka_Tips::render_classic()).
 */
(function ($) {
	'use strict';

	if (!$ || !window.lafkaTips || !window.lafka) {
		return;
	}

	function save() {
		const box = document.querySelector('[data-lafka-tips]');
		const picked = box ? box.querySelector('input[name="lafka_tip"]:checked') : null;
		if (!box || !picked) {
			return;
		}
		const amount = box.querySelector('input[name="lafka_tip_amount"]');
		$.post(window.lafkaTips.ajaxUrl, {
			action: 'lafka_set_tip',
			nonce: box.getAttribute('data-lafka-tips'),
			tip: picked.value,
			amount: amount ? amount.value : ''
		}).always(function () {
			$(document.body).trigger('update_checkout');
		});
	}

	const markOtherAndSave = window.lafka.debounce(function (field) {
		const other = field.closest('label').querySelector('input[type="radio"]');
		if (other) {
			other.checked = true;
		}
		save();
	}, 600);

	document.addEventListener('change', function (event) {
		if (event.target.closest && event.target.closest('[data-lafka-tips]')) {
			if (event.target.name === 'lafka_tip_amount') {
				const other = event.target.closest('label').querySelector('input[type="radio"]');
				if (other) {
					other.checked = true;
				}
			}
			save();
		}
	});

	document.addEventListener('input', function (event) {
		if (event.target.name === 'lafka_tip_amount' && event.target.closest('[data-lafka-tips]')) {
			markOtherAndSave(event.target);
		}
	});
})(window.jQuery);
