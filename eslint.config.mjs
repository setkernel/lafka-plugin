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
				// Core WP / WC
				wp: "readonly",
				ajaxurl: "readonly",
				wc_add_to_cart_variation_params: "readonly",
				// Lafka payloads (wp_localize_script)
				lafka_addons_params: "readonly",
				lafka_cat_ordering: "readonly",
				// Per-page-injected vars used in admin scripts
				accounting: "readonly",
				// Third-party libs
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
			// Allow user code to declare locals that shadow our wp_localize_script globals.
			"no-redeclare": ["error", { "builtinGlobals": false }],
			// Codebase pre-dates these modern rules — re-evaluate after a separate cleanup pass.
			"no-useless-assignment": "off",
			"no-useless-escape": "off",
			"no-shadow-restricted-names": "off",
		},
	},
	// Service worker file has its own global scope (NX1-08b order-notification worker).
	{
		files: ["incl/admin/assets/js/lafka-order-notifications-sw.js"],
		languageOptions: {
			globals: {
				self: "readonly",
				caches: "readonly",
				clients: "readonly",
				skipWaiting: "readonly",
			},
		},
	},
	// Shipping-areas / branch scripts: sources recovered by formatting the
	// long-shipped minified builds (WP.org guideline 4). They read the
	// wp_localize_script / inline-script globals below; the minifier's
	// variable reuse trips a few stylistic rules.
	{
		files: ["incl/shipping-areas/assets/js/**/*.js"],
		languageOptions: {
			globals: {
				lafka_branch_locations_front: "readonly",
				lafka_branch_location_properties: "readonly",
				lafka_datetime_options: "readonly",
				lafka_shipping_areas_shortcode_php_variables: "readonly",
				lafka_admin_map_params: "readonly",
				lafka_shipping_properties: "readonly",
				lafka_shipping_destination_address_property: "writable",
				lafka_checkout_map_properties: "writable",
				lafka_set_store_location: "readonly",
				lafka_store_map_location: "readonly",
				lafka_store_address: "readonly",
				lafka_lowest_cost_shipping: "readonly",
				lafka_no_shipping_methods_string: "readonly",
				lafka_debug_mode: "readonly",
				lafka_order_type: "readonly",
				wc_country_select_params: "readonly",
			},
		},
		rules: {
			"no-redeclare": "off",
			"no-unused-vars": "off",
			"no-empty": "off",
		},
	},
	// Node.js build scripts + node:test suites (ES modules).
	{
		files: ["scripts/**/*.mjs", "tests/js/**/*.mjs"],
		languageOptions: {
			sourceType: "module",
			globals: {
				...globals.node,
			},
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
			// Minified files
			"**/*.min.js",
		],
	},
];
