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
require_once __DIR__ . '/class-engine.php';
