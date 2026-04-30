<?php
/**
 * Lafka_Addons_Engine — public facade for the engine.
 *
 * Singleton. Lazy-instantiates the resolver, repository, upgrader, and
 * source registry on first access. Phase 2+ admin and cart code reaches
 * the engine through this class — no direct instantiation of internals.
 *
 * Public API:
 *   Lafka_Addons_Engine::instance()->pricing()    → Lafka_Pricing_Resolver
 *   Lafka_Addons_Engine::instance()->repository() → Lafka_Addon_Repository
 *   Lafka_Addons_Engine::instance()->upgrader()   → Lafka_Addons_Upgrader
 *   Lafka_Addons_Engine::instance()->sources()    → array<id, Lafka_Options_Source>
 *
 * Third parties extend by hooking these filters:
 *   lafka_addons_register_pricing_strategy
 *   lafka_addons_register_options_source
 *   lafka_addons_register_migration
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addons_Engine {

	private static ?Lafka_Addons_Engine $instance = null;

	private ?Lafka_Pricing_Resolver $pricing = null;
	private ?Lafka_Addon_Repository $repository = null;
	private ?Lafka_Addons_Upgrader $upgrader = null;
	/** @var array<string, Lafka_Options_Source>|null */
	private ?array $sources = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function pricing(): Lafka_Pricing_Resolver {
		if ( null === $this->pricing ) {
			$this->pricing = new Lafka_Pricing_Resolver();
		}
		return $this->pricing;
	}

	public function upgrader(): Lafka_Addons_Upgrader {
		if ( null === $this->upgrader ) {
			$upgrader = new Lafka_Addons_Upgrader();
			// No built-in migrations as of v8.13.0 — framework is here for
			// when future schema changes need it. Third parties can register
			// migrations via the filter below.
			if ( function_exists( 'apply_filters' ) ) {
				$upgrader = apply_filters( 'lafka_addons_register_migration', $upgrader );
			}
			$this->upgrader = $upgrader;
		}
		return $this->upgrader;
	}

	public function repository(): Lafka_Addon_Repository {
		if ( null === $this->repository ) {
			$this->repository = new Lafka_Addon_Repository( $this->upgrader() );
		}
		return $this->repository;
	}

	/**
	 * @return array<string, Lafka_Options_Source>
	 */
	public function sources(): array {
		if ( null === $this->sources ) {
			$built_in = array(
				Lafka_Addon_Schema::SOURCE_MANUAL    => new Lafka_Manual_Source(),
				Lafka_Addon_Schema::SOURCE_ATTRIBUTE => new Lafka_Attribute_Source(),
			);
			if ( function_exists( 'apply_filters' ) ) {
				$built_in = apply_filters( 'lafka_addons_register_options_source', $built_in );
			}
			$this->sources = $built_in;
		}
		return $this->sources;
	}
}
