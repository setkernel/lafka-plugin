<?php
/**
 * Lafka_Checkout_Mode — the checkout-experience SSOT (NX1-04b).
 *
 * WooCommerce steers every new store to the block-based Cart & Checkout. Lafka
 * now fully supports that path (NX1-04a server gates, NX1-04c addons through the
 * Store API, and this item's block checkout fields + timeslot/free-delivery
 * components), so a FRESH activation defaults to blocks.
 *
 * PRODUCTION PRESERVATION (the hard contract): the operator's live revenue site
 * currently runs the CLASSIC shortcode checkout via the block-cart shim. Updating
 * the plugin must NEVER change that. So:
 *
 *   · FRESH activation (no pre-existing Lafka state) → 'blocks' (set explicitly at
 *     activation, and again by the on-load migration as a safety net).
 *   · EXISTING install (any pre-existing `lafka` option) → migrated to an explicit
 *     'classic' so its behaviour stays byte-identical to before the update.
 *   · The `lafka_checkout_mode` option, once set to a valid value, is never
 *     overridden — the operator's explicit choice wins (idempotent migration).
 *   · The `lafka_force_classic_checkout` filter forces classic at runtime,
 *     overriding everything (option, migration, UI), without mutating the option.
 *   · Runtime default when the option is somehow unset is 'classic' — the safe,
 *     production-preserving value (an unset option at runtime can only mean an
 *     install that upgraded in place before the migration ran).
 *
 * The single pure decision (decide_mode) drives both the activation hook and the
 * on-load migration and is exhaustively unit-tested (CheckoutModeDecisionTest).
 *
 * @package Lafka\Plugin\Checkout
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Checkout_Mode' ) ) {

	/**
	 * Checkout-mode option SSOT + fresh/existing migration.
	 */
	final class Lafka_Checkout_Mode {

		/**
		 * Option name storing the operator's checkout experience ('blocks'|'classic').
		 */
		const OPTION = 'lafka_checkout_mode';

		/**
		 * Modern WooCommerce block Cart/Checkout.
		 */
		const MODE_BLOCKS = 'blocks';

		/**
		 * Classic shortcode Cart/Checkout (via the block-cart shim).
		 */
		const MODE_CLASSIC = 'classic';

		/**
		 * Wire the on-load migration for installs that update in place (a plugin
		 * file update does NOT fire the activation hook). Runs once per admin
		 * request; no-ops the instant the option holds a valid value.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
		}

		/**
		 * Whether a string is one of the two supported modes.
		 *
		 * @param string $mode Candidate mode.
		 * @return bool
		 */
		public static function is_valid_mode( string $mode ): bool {
			return in_array( $mode, array( self::MODE_BLOCKS, self::MODE_CLASSIC ), true );
		}

		/**
		 * The single pure decision that governs migration. No WordPress calls, so
		 * the whole fresh/existing/filter/option table is unit-testable in isolation.
		 *
		 * @param bool   $mode_already_set    Whether the option already holds a valid mode.
		 * @param string $stored_mode         The current stored mode (only meaningful when set).
		 * @param bool   $is_existing_install Whether this install carries pre-existing Lafka state.
		 * @return string The mode to persist.
		 */
		public static function decide_mode( bool $mode_already_set, string $stored_mode, bool $is_existing_install ): string {
			if ( $mode_already_set ) {
				// The operator's explicit choice is authoritative — never override it.
				return $stored_mode;
			}

			// No explicit choice yet: preserve an existing store's classic behaviour;
			// give a brand-new store the modern block default.
			return $is_existing_install ? self::MODE_CLASSIC : self::MODE_BLOCKS;
		}

		/**
		 * Resolve the active checkout mode at runtime. Honours the force-classic
		 * filter (overrides everything) and defaults an unset option to classic.
		 *
		 * @return string self::MODE_BLOCKS or self::MODE_CLASSIC.
		 */
		public static function get_mode(): string {
			/**
			 * Force the classic shortcode checkout regardless of the stored mode.
			 *
			 * Wins over the option and the migration — lets a host/child plugin pin
			 * classic without touching operator settings.
			 *
			 * @since 9.36.0
			 *
			 * @param bool $force_classic Whether to force classic checkout.
			 */
			if ( apply_filters( 'lafka_force_classic_checkout', false ) ) {
				return self::MODE_CLASSIC;
			}

			$raw    = get_option( self::OPTION, '' );
			$stored = is_scalar( $raw ) ? (string) $raw : '';

			// Unset at runtime ⇒ an in-place upgrade that predates the migration:
			// preserve production behaviour (classic) until the migration writes it.
			return self::is_valid_mode( $stored ) ? $stored : self::MODE_CLASSIC;
		}

		/**
		 * Whether the classic shortcode checkout is active.
		 *
		 * @return bool
		 */
		public static function is_classic(): bool {
			return self::MODE_CLASSIC === self::get_mode();
		}

		/**
		 * Whether the block Cart/Checkout is active.
		 *
		 * @return bool
		 */
		public static function is_blocks(): bool {
			return self::MODE_BLOCKS === self::get_mode();
		}

		/**
		 * Persist a mode the operator picked from the Modules screen. Validates the
		 * value and only writes a supported mode (defence against a crafted POST).
		 *
		 * @param string $mode Requested mode.
		 * @return bool Whether a valid mode was written.
		 */
		public static function set_mode( string $mode ): bool {
			if ( ! self::is_valid_mode( $mode ) ) {
				return false;
			}
			update_option( self::OPTION, $mode );
			return true;
		}

		/**
		 * Activation-time decision (fresh vs existing). Registered FIRST among the
		 * plugin's activation hooks so it observes the true pre-seed state: on a
		 * genuinely fresh install no `lafka` option exists yet, so this resolves to
		 * blocks before the defaults seeder writes anything.
		 *
		 * @return void
		 */
		public static function on_activation() {
			$stored           = (string) get_option( self::OPTION, '' );
			$mode_already_set = self::is_valid_mode( $stored );
			if ( $mode_already_set ) {
				return; // Reactivation with an explicit choice already made.
			}

			$decided = self::decide_mode( false, '', self::install_has_prior_lafka_state() );
			update_option( self::OPTION, $decided );
		}

		/**
		 * On-load migration for installs updated in place (no activation hook). Makes
		 * the production-preserving choice explicit so it shows in the Modules UI.
		 * No-ops once the option holds a valid value (fresh installs set it at
		 * activation; a prior run set it here).
		 *
		 * @return void
		 */
		public static function maybe_migrate() {
			if ( self::is_valid_mode( (string) get_option( self::OPTION, '' ) ) ) {
				return;
			}

			$decided = self::decide_mode( false, '', self::install_has_prior_lafka_state() );
			update_option( self::OPTION, $decided );
		}

		/**
		 * Whether this install carries Lafka state that predates the mode option.
		 * The `lafka` flags array is present on every configured/seeded install, so
		 * its presence marks an "existing" install for the migration decision. (At
		 * activation this is read before the defaults seeder runs; on the in-place
		 * upgrade path the option-already-set guard has already excluded fresh
		 * installs before this is consulted.)
		 *
		 * @return bool
		 */
		private static function install_has_prior_lafka_state(): bool {
			return false !== get_option( 'lafka', false );
		}
	}
}
