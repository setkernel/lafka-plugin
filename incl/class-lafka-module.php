<?php
/**
 * Lafka_Module: value object describing a single gated Lafka module (NX1-01).
 *
 * Registered and queried through Lafka_Module_Registry
 * (incl/class-lafka-module-registry.php).
 *
 * @package Lafka
 * @since   10.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Module' ) ) {

	/**
	 * Value object describing a single gated Lafka module.
	 *
	 * Enable/disable, configuration and settings-URL resolution all flow
	 * through callbacks supplied at registration, so the registry never has to
	 * know HOW a given module stores its state — only that it can ask.
	 */
	final class Lafka_Module {

		/** @var string */
		private $id;

		/** @var string i18n label. */
		private $label;

		/** @var string i18n one-line description. */
		private $description;

		/** @var string Grouping slug (ordering / fulfilment / operations / conversion / analytics). */
		private $category;

		/** @var string Where the enable flag lives — 'lafka_option' | 'theme_mod' | 'derived'. */
		private $storage;

		/** @var bool Product default state (documentation / wizard seed). */
		private $default_enabled;

		/** @var callable():bool */
		private $get_enabled_cb;

		/** @var callable(bool):void|null Null == read-only (state is derived, not toggleable). */
		private $set_enabled_cb;

		/** @var callable():bool|null Null == always considered configured. */
		private $is_configured_cb;

		/** @var string Relative admin path to the module's deeper settings (wrapped with admin_url()). */
		private $settings_path;

		/** @var string Docs slug (resolved to a URL via Lafka_Module_Registry::docs_url()). */
		private $docs_slug;

		/**
		 * @param array<string,mixed> $args Descriptor. See register_builtin_modules() for the shape.
		 */
		public function __construct( array $args ) {
			$this->id               = (string) ( $args['id'] ?? '' );
			$this->label            = (string) ( $args['label'] ?? $this->id );
			$this->description      = (string) ( $args['description'] ?? '' );
			$this->category         = (string) ( $args['category'] ?? 'general' );
			$this->storage          = (string) ( $args['storage'] ?? 'derived' );
			$this->default_enabled  = (bool) ( $args['default_enabled'] ?? false );
			$this->get_enabled_cb   = $args['get_enabled'] ?? null;
			$this->set_enabled_cb   = $args['set_enabled'] ?? null;
			$this->is_configured_cb = $args['is_configured'] ?? null;
			$this->settings_path    = (string) ( $args['settings_path'] ?? '' );
			$this->docs_slug        = (string) ( $args['docs_slug'] ?? '' );
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_label(): string {
			return $this->label;
		}

		public function get_description(): string {
			return $this->description;
		}

		public function get_category(): string {
			return $this->category;
		}

		public function get_storage(): string {
			return $this->storage;
		}

		public function default_enabled(): bool {
			return $this->default_enabled;
		}

		public function get_docs_slug(): string {
			return $this->docs_slug;
		}

		/**
		 * A module is read-only when it has no set callback — its enabled state
		 * is derived from other configuration (e.g. analytics is "on" whenever a
		 * tracking destination is configured), so there is nothing to toggle.
		 */
		public function is_read_only(): bool {
			return ! is_callable( $this->set_enabled_cb );
		}

		/**
		 * Live enabled state, read from the module's real storage.
		 */
		public function is_enabled(): bool {
			return is_callable( $this->get_enabled_cb )
				? (bool) call_user_func( $this->get_enabled_cb )
				: false;
		}

		/**
		 * Write the module's enabled state to its real storage.
		 *
		 * @param bool $enabled Desired state.
		 * @return bool True if the write happened; false for read-only modules.
		 */
		public function set_enabled( bool $enabled ): bool {
			if ( $this->is_read_only() ) {
				return false;
			}
			call_user_func( $this->set_enabled_cb, $enabled );
			return true;
		}

		/**
		 * Whether the module has the extra configuration it needs to actually
		 * function (e.g. push needs VAPID keys). Modules with no extra
		 * requirement are always considered configured.
		 */
		public function is_configured(): bool {
			return is_callable( $this->is_configured_cb )
				? (bool) call_user_func( $this->is_configured_cb )
				: true;
		}

		/**
		 * Absolute admin URL to the module's deeper settings, or '' when the
		 * module has no settings screen beyond the toggle.
		 */
		public function get_settings_url(): string {
			if ( '' === $this->settings_path || ! function_exists( 'admin_url' ) ) {
				return '';
			}
			return admin_url( $this->settings_path );
		}
	}
}
