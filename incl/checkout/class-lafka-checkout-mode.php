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
 * CONFIGURED vs EFFECTIVE mode: the option is the operator's INTENT; the
 * block-cart shim applies it only to unedited default pages, so an edited
 * Checkout page can render the classic [woocommerce_checkout] shortcode while
 * the option says 'blocks' (the live store did exactly that). Runtime consumers
 * must follow what customers actually get, so is_blocks()/is_classic() read the
 * EFFECTIVE mode: the Checkout page's content (checkout block ⇒ blocks, checkout
 * shortcode ⇒ classic), falling back to the option only when the page says
 * neither. get_mode() stays the configured intent (Modules screen, the shim).
 * A Site Health test warns when the two disagree.
 *
 * @package Lafka\Plugin\Checkout
 * @since   10.0.0
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
			add_filter( 'site_status_tests', array( __CLASS__, 'register_health_test' ) );
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
			 * @since 10.0.0
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
		 * The checkout experience a page's content renders: 'blocks' for the
		 * WooCommerce Checkout block, 'classic' for the [woocommerce_checkout]
		 * shortcode, '' when it holds neither. Pure (no WordPress calls).
		 *
		 * @param string $content Page content.
		 * @return string
		 */
		public static function mode_for_content( string $content ): string {
			if ( preg_match( '#<!--\s*wp:woocommerce/checkout(?:\s|/?-->)#', $content ) ) {
				return self::MODE_BLOCKS;
			}
			if ( preg_match( '/\[woocommerce_checkout(?:\s[^\]]*)?\]/', $content ) ) {
				return self::MODE_CLASSIC;
			}

			return '';
		}

		/**
		 * The mode the WooCommerce Checkout page actually renders ('' when it
		 * cannot be told from the page). Not cached: get_post() is served from
		 * the object cache and the few callers run once per request each.
		 *
		 * @return string
		 */
		public static function page_mode(): string {
			// What wc_get_page_id( 'checkout' ) reads (same WooCommerce filter).
			$page_id = (int) apply_filters( 'woocommerce_get_checkout_page_id', get_option( 'woocommerce_checkout_page_id', 0 ) );
			$mode    = '';
			if ( $page_id > 0 && function_exists( 'get_post' ) ) {
				$post = get_post( $page_id );
				if ( is_object( $post ) && isset( $post->post_content ) ) {
					$mode = self::mode_for_content( (string) $post->post_content );
				}
			}

			/**
			 * Filter the checkout experience read from the Checkout page.
			 *
			 * Return 'blocks' or 'classic' when the checkout is rendered some
			 * other way (a block theme template, a page builder), or '' to fall
			 * back to the configured `lafka_checkout_mode` option.
			 *
			 * @since 10.3.0
			 *
			 * @param string $mode    'blocks', 'classic' or ''.
			 * @param int    $page_id Checkout page id (0 = none).
			 */
			$mode = (string) apply_filters( 'lafka_checkout_page_mode', $mode, $page_id );

			return self::is_valid_mode( $mode ) ? $mode : '';
		}

		/**
		 * The checkout experience customers actually get: the force-classic
		 * filter, else the Checkout page's content, else the configured option.
		 *
		 * @return string self::MODE_BLOCKS or self::MODE_CLASSIC.
		 */
		public static function get_effective_mode(): string {
			if ( apply_filters( 'lafka_force_classic_checkout', false ) ) {
				return self::MODE_CLASSIC;
			}
			$page = self::page_mode();

			return '' !== $page ? $page : self::get_mode();
		}

		/**
		 * Whether the classic shortcode checkout is what customers get.
		 *
		 * @return bool
		 */
		public static function is_classic(): bool {
			return self::MODE_CLASSIC === self::get_effective_mode();
		}

		/**
		 * Whether the block Checkout is what customers get.
		 *
		 * @return bool
		 */
		public static function is_blocks(): bool {
			return self::MODE_BLOCKS === self::get_effective_mode();
		}

		/**
		 * Whether the configured option and the Checkout page disagree.
		 *
		 * @return bool
		 */
		public static function has_mismatch(): bool {
			$page = self::page_mode();

			return '' !== $page && $page !== self::get_mode();
		}

		/**
		 * site_status_tests: register the configured-vs-page check.
		 *
		 * @param mixed $tests Site Health tests.
		 * @return mixed
		 */
		public static function register_health_test( $tests ) {
			if ( ! is_array( $tests ) ) {
				return $tests;
			}
			$tests['direct']['lafka_checkout_mode'] = array(
				'label' => __( 'Lafka checkout experience', 'lafka-plugin' ),
				'test'  => array( __CLASS__, 'health_test' ),
			);

			return $tests;
		}

		/**
		 * Site Health: warn when the Checkout page renders a different checkout
		 * than the one chosen under Lafka → Modules.
		 *
		 * @return array<string, mixed>
		 */
		public static function health_test(): array {
			$labels = array(
				self::MODE_BLOCKS  => __( 'block checkout', 'lafka-plugin' ),
				self::MODE_CLASSIC => __( 'classic checkout', 'lafka-plugin' ),
			);
			$result = array(
				'label'       => __( 'The checkout page matches the chosen checkout experience', 'lafka-plugin' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Lafka', 'lafka-plugin' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'Lafka reads the Checkout page to decide which checkout rules apply.', 'lafka-plugin' ) . '</p>',
				'test'        => 'lafka_checkout_mode',
			);
			if ( ! self::has_mismatch() ) {
				return $result;
			}

			$configured = self::get_mode();
			$page       = self::page_mode();

			$result['label']          = __( 'The checkout page does not match the chosen checkout experience', 'lafka-plugin' );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html(
				sprintf(
					/* translators: 1: configured experience (e.g. "block checkout"), 2: what the page renders (e.g. "classic checkout") */
					__( 'Lafka → Modules is set to the %1$s, but the Checkout page renders the %2$s (the page was edited, so it was not switched automatically). Lafka follows the page, so the right checkout rules apply; change the setting or the page so both say the same.', 'lafka-plugin' ),
					$labels[ $configured ] ?? $configured,
					$labels[ $page ] ?? $page
				)
			) . '</p>';

			return $result;
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
