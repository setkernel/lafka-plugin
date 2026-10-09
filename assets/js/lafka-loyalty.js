/**
 * Loyalty points on the classic checkout: use or return points, then let
 * WooCommerce recalculate the order review (Lafka_Loyalty_Redeem::render_classic()).
 */
(function ($) {
	'use strict';

	if (!$ || !window.lafkaLoyalty) {
		return;
	}

	function send(box, action, points) {
		const error = box.querySelector('[data-lafka-loyalty-error]');
		const buttons = box.querySelectorAll('button');
		buttons.forEach(function (button) {
			button.disabled = true;
		});
		$.post(window.lafkaLoyalty.ajaxUrl, {
			action: action,
			nonce: box.getAttribute('data-lafka-loyalty'),
			points: points
		}).done(function (response) {
			if (response && response.success) {
				$(document.body).trigger('update_checkout');
				return;
			}
			if (error) {
				error.textContent = response && response.data && response.data.message ? response.data.message : '';
				error.hidden = false;
			}
			buttons.forEach(function (button) {
				button.disabled = false;
			});
		}).fail(function () {
			buttons.forEach(function (button) {
				button.disabled = false;
			});
		});
	}

	document.addEventListener('click', function (event) {
		const button = event.target.closest ? event.target.closest('[data-lafka-loyalty-apply], [data-lafka-loyalty-remove]') : null;
		const box = button ? button.closest('[data-lafka-loyalty]') : null;
		if (!box) {
			return;
		}
		event.preventDefault();
		if (button.hasAttribute('data-lafka-loyalty-remove')) {
			send(box, 'lafka_loyalty_remove', 0);
			return;
		}
		const input = box.querySelector('[data-lafka-loyalty-points]');
		send(box, 'lafka_loyalty_apply', input ? parseInt(input.value, 10) || 0 : 0);
	});
})(window.jQuery);
