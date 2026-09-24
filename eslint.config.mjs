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
