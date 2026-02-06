import js from "@eslint/js";
import globals from "globals";

export default [
	js.configs.recommended,
	{
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: "script",
			globals: {
				...globals.browser,
				...globals.jquery,
				wp: "readonly",
				ajaxurl: "readonly",
				wc_add_to_cart_params: "readonly",
				lafka_ajax_object: "readonly",
				lafka_plugin_ajax: "readonly",
				wc_combo_params: "readonly",
				wc_add_to_cart_combo_params: "readonly",
				google: "readonly",
				flatpickr: "readonly",
			},
		},
		rules: {
			"no-unused-vars": "warn",
			"no-undef": "error",
			"eqeqeq": ["warn", "smart"],
			"no-var": "off",
			"prefer-const": "off",
			"no-prototype-builtins": "off",
		},
	},
	{
		ignores: [
			"vendor/**",
			"node_modules/**",
			"eslint.config.mjs",
			// Vendor JS libraries
			"assets/js/flatpickr/**",
			"assets/js/schedule/jquery.schedule.js",
			"assets/js/schedule/jquery.schedule.min.js",
			"incl/combos/assets/js/admin/select2.js",
			"incl/combos/assets/js/admin/select2.min.js",
			// Minified files
			"**/*.min.js",
			// Importer
			"importer/**",
		],
	},
];
