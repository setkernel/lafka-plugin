(function ($) {
	"use strict";
	$(document).ready(function () {
		/**
		 * Position the Elegant icons iconpicker to be below FontAwasome
		 *
		 * @type @exp;lafka-vc-edit-form_L3@pro;$edit_form_tab_style1|@exp;lafka-vc-edit-form_L3@pro;$edit_form_tab_style2
		 */
		var $parent;
		var $origin;

		var $edit_form_tab_style1 = $('#vc_edit-form-tab-0');
		var $edit_form_tab_style2 = $("div.vc_edit-form-tab.vc_row.vc_ui-flex-row.vc_active");

		if ($edit_form_tab_style1.length) {
			$parent = $edit_form_tab_style1;
			$origin = 'icon';
		} else if ($edit_form_tab_style2.length) {
			$parent = $edit_form_tab_style2;
			$origin = 'i_icon';
		}

		if ($parent.length) {
			var $etline_select = $parent.children("div[data-vc-shortcode-param-name='" + $origin + "_etline']");
			var $flaticon_select = $parent.children("div[data-vc-shortcode-param-name='" + $origin + "_flaticon']");
			var $awesome_select = $parent.children("div[data-vc-shortcode-param-name='" + $origin + "_fontawesome']");

			if ($etline_select.length && $awesome_select.length && $flaticon_select.length) {
				$etline_select.detach().insertAfter($awesome_select);
				$flaticon_select.detach().insertAfter($etline_select);
			}
		}
	});
})(window.jQuery);