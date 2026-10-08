/**
 * Deal product screen: show the price fields for the Deal type and add /
 * remove slot rows in the "Deal slots" panel.
 */
(function ($) {
	'use strict';

	// WooCommerce shows General → price and tax fields per product type by
	// class; a Deal uses them for the deal price.
	$('.options_group.pricing, ._tax_status_field, ._tax_class_field')
		.closest('.options_group')
		.addClass('show_if_lafka_deal');
	$('.options_group.pricing').addClass('show_if_lafka_deal');
	$('#general_product_data').addClass('show_if_lafka_deal');
	$(document.body).trigger('woocommerce-product-type-change', [$('#product-type').val()]);
	$('#product-type').trigger('change');

	const list = document.querySelector('[data-lafka-deal-slots]');
	const tmpl = document.getElementById('tmpl-lafka-deal-slot');
	if (!list || !tmpl) {
		return;
	}

	document.addEventListener('click', function (event) {
		const add = event.target.closest('[data-lafka-deal-add]');
		if (add) {
			event.preventDefault();
			const index = Number(list.getAttribute('data-next-index')) || list.children.length;
			list.setAttribute('data-next-index', String(index + 1));
			list.insertAdjacentHTML('beforeend', tmpl.innerHTML.split('__i__').join(String(index)));
			$(document.body).trigger('wc-enhanced-select-init');
			return;
		}
		const remove = event.target.closest('[data-lafka-deal-remove]');
		if (remove) {
			event.preventDefault();
			const row = remove.closest('[data-lafka-deal-slot]');
			if (row && list.querySelectorAll('[data-lafka-deal-slot]').length > 1) {
				row.remove();
			}
		}
	});
})(window.jQuery);
