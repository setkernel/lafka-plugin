<?php
/**
 * Lafka Addons Engine v2 — Bootstrap
 *
 * Loads the new engine alongside the legacy addon system. Phase 1: dormant.
 * Phase 2: admin form rewires to it. Phase 3: cart/display rewires to it.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

// Engine schema version. Bumped when migrations land.
if ( ! defined( 'LAFKA_ADDONS_ENGINE_VERSION' ) ) {
	define( 'LAFKA_ADDONS_ENGINE_VERSION', 2 );
}

if ( ! defined( 'LAFKA_ADDONS_ENGINE_PATH' ) ) {
	define( 'LAFKA_ADDONS_ENGINE_PATH', __DIR__ );
}

// Autoload the engine's classes. The engine intentionally does not bootstrap
// hooks at file-load time — Phase 2+ controllers will instantiate what they
// need via the public Lafka_Addons_Engine facade (added in Task 18).
require_once __DIR__ . '/interfaces/interface-pricing-strategy.php';
require_once __DIR__ . '/interfaces/interface-options-source.php';
require_once __DIR__ . '/data/class-addon-schema.php';
require_once __DIR__ . '/data/class-addon-option.php';
require_once __DIR__ . '/data/class-addon-group.php';
require_once __DIR__ . '/data/class-addon-repository.php';
require_once __DIR__ . '/pricing/abstract-pricing-strategy.php';
require_once __DIR__ . '/pricing/class-flat-group-pricing.php';
require_once __DIR__ . '/pricing/class-flat-per-option-pricing.php';
require_once __DIR__ . '/pricing/class-flat-per-size-pricing.php';
require_once __DIR__ . '/pricing/class-matrix-pricing.php';
require_once __DIR__ . '/pricing/class-pricing-resolver.php';
require_once __DIR__ . '/sources/abstract-options-source.php';
require_once __DIR__ . '/sources/class-manual-source.php';
require_once __DIR__ . '/sources/class-attribute-source.php';
// Migration framework — interface + upgrader are kept for future schema
// changes. v8.13.0 has no v1→v2 migration class because legacy addon data
// is intentionally not preserved (fresh start per operator decision).
require_once __DIR__ . '/migrations/abstract-migration.php';
require_once __DIR__ . '/migrations/class-upgrader.php';
require_once __DIR__ . '/admin/class-engine-admin.php';
require_once __DIR__ . '/admin/class-engine-editor.php';
require_once __DIR__ . '/admin/class-engine-ajax.php';
require_once __DIR__ . '/admin/class-engine-product-panel.php';
// List table is required by class-engine-admin's render_list(); we lazy-require
// it from there because WP_List_Table itself is admin-only.
require_once __DIR__ . '/class-engine.php';
require_once __DIR__ . '/class-engine-privacy.php';
require_once __DIR__ . '/class-engine-resolver.php';
require_once __DIR__ . '/class-engine-helper.php';
require_once __DIR__ . '/cart/fields/abstract-engine-field.php';
require_once __DIR__ . '/cart/fields/class-engine-field-list.php';
require_once __DIR__ . '/cart/fields/class-engine-field-textarea.php';
require_once __DIR__ . '/cart/fields/class-engine-field-factory.php';
require_once __DIR__ . '/cart/class-engine-cart.php';
require_once __DIR__ . '/cart/class-engine-store-api.php';
require_once __DIR__ . '/display/class-engine-display.php';

// Cache invalidation: any save/trash/delete of an addon CPT post invalidates
// both the resolver's per-request VO cache and the helper's legacy-shape
// cache so admin pages reading on the same request reflect current data.
if ( function_exists( 'add_action' ) ) {
	$lafka_addons_clear_caches = static function () {
		Lafka_Engine_Resolver::clear_cache();
		Lafka_Engine_Helper::clear_cache();
	};
	add_action( 'save_post_lafka_glb_addon', $lafka_addons_clear_caches );
	add_action(
		'trashed_post',
		static function ( $post_id ) use ( $lafka_addons_clear_caches ) {
			if ( 'lafka_glb_addon' === get_post_type( $post_id ) ) {
				$lafka_addons_clear_caches();
			}
		}
	);
	add_action(
		'deleted_post',
		static function ( $post_id ) use ( $lafka_addons_clear_caches ) {
			if ( 'lafka_glb_addon' === get_post_type( $post_id ) ) {
				$lafka_addons_clear_caches();
			}
		}
	);
}

// Privacy exporter/eraser registration. The filters only fire inside admin
// (Tools → Export/Erase Personal Data), so we register on admin_init.
if ( function_exists( 'add_action' ) ) {
	add_action(
		'admin_init',
		static function () {
			( new Lafka_Engine_Privacy() )->register();
		}
	);
}

// REST controller is loaded lazily on rest_api_init because it extends
// WP_REST_Controller, which is only defined when WP's REST stack is loaded.
// Loading the file at bootstrap time would fatal under unit tests + CLI.
if ( function_exists( 'add_action' ) ) {
	add_action(
		'rest_api_init',
		static function () {
			if ( class_exists( 'WP_REST_Controller' ) ) {
				require_once __DIR__ . '/api/class-rest-groups-controller.php';
				( new Lafka_Addons_REST_Groups_Controller() )->register_routes();
			}
		}
	);
}

// WP-CLI command surface — file no-ops when WP_CLI isn't defined.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/cli/class-cli-commands.php';
}
