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
			"no-var": "error",
			"prefer-const": "error",
			"no-prototype-builtins": "error",
			// Allow user code to declare locals that shadow our wp_localize_script globals.
			"no-redeclare": ["error", { "builtinGlobals": false }],
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
	// Shipping-areas scripts: the map and branch scripts read their
	// wp_localize_script params as properties of `window`; the date picker
	// still reads this global.
	{
		files: ["incl/shipping-areas/assets/js/**/*.js"],
		languageOptions: {
			globals: {
				lafka_datetime_options: "readonly",
			},
		},
	},
	// Node.js build scripts and this config (ES modules).
	{
		files: ["scripts/**/*.mjs", "eslint.config.mjs"],
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
			// Vendor JS libraries
			"assets/js/flatpickr/**",
			"assets/js/leaflet/**",
			"assets/js/schedule/jquery.schedule.js",
			"assets/js/schedule/jquery.schedule.min.js",
			// Minified files
			"**/*.min.js",
		],
	},
];
