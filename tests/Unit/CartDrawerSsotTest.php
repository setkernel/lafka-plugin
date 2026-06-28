<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * f099 SSOT lock: the cart-drawer line-item rows and the subtotal + free-
 * delivery total block must be rendered by ONE pair of plugin callables that
 * BOTH the theme partial (initial server render) and the
 * woocommerce_add_to_cart_fragments filter (AJAX refresh) call — so the markup
 * + strings live once, under a single text domain ('lafka-plugin'), and the
 * two paths can never desync (the failure mode behind the v6.14.0 empty-state
 * and v9.32.0 first-add drawer bugs).
 *
 * The cross-repo assertions read the sibling theme partial; in isolated CI the
 * theme repo is absent, so those tests skip rather than fail (cross-repo
 * isolation trap).
 */
final class CartDrawerSsotTest extends TestCase {

	private string $fragments;

	protected function setUp(): void {
		parent::setUp();
		$this->fragments = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php'
		);
	}

	private function theme_partial(): string {
		$path = dirname( __DIR__, 3 ) . '/lafka-theme/partials/cart-drawer.php';
		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( 'lafka-theme/partials/cart-drawer.php is a sibling-repo file, absent in isolated CI.' );
		}
		return (string) file_get_contents( $path );
	}

	/**
	 * The plugin must define both SSOT callables.
	 */
	public function test_plugin_defines_both_callables(): void {
		$this->assertStringContainsString( 'function lafka_cart_drawer_render_item', $this->fragments );
		$this->assertStringContainsString( 'function lafka_cart_drawer_render_total', $this->fragments );
	}

	/**
	 * The AJAX fragment filter must delegate to the SAME callables (inside
	 * ob_start) rather than re-emitting its own copy of the markup, and must
	 * still key the fragments on the drawer's real selectors.
	 */
	public function test_fragment_filter_delegates_to_callables(): void {
		$this->assertStringContainsString( 'lafka_cart_drawer_render_item(', $this->fragments );
		$this->assertStringContainsString( 'lafka_cart_drawer_render_total()', $this->fragments );
		$this->assertStringContainsString( "\$fragments['ul.lafka-cart-drawer__items']", $this->fragments );
		$this->assertStringContainsString( "\$fragments['div.lafka-cart-drawer__total']", $this->fragments );
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'woocommerce_add_to_cart_fragments',\s*'lafka_pdp_cart_drawer_fragments'/",
			$this->fragments
		);
	}

	/**
	 * The empty-state <li> is folded into the items renderer (so the <ul> can
	 * swap empty<->filled in place), and the total renderer emits the rich
	 * .lafka-fdp component directly — NOT the legacy plain-text
	 * .lafka-cart-drawer__threshold that lafka-fdp-tracker.js used to rewrite.
	 *
	 * @param string $needle Markup the plugin must now own.
	 */
	#[DataProvider('provide_plugin_owns_markup')]
	public function test_plugin_owns_drawer_markup( string $needle ): void {
		$this->assertStringContainsString( $needle, $this->fragments );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_plugin_owns_markup(): array {
		return array(
			'item row'      => array( 'class="lafka-cart-drawer__item" data-cart-key=' ),
			'empty state'   => array( 'class="lafka-cart-drawer__empty"' ),
			'subtotal'      => array( 'class="lafka-cart-drawer__subtotal"' ),
			'rich fdp'      => array( 'class="lafka-fdp lafka-fdp--drawer"' ),
			'fdp data attr' => array( 'data-lafka-fdp' ),
		);
	}

	/**
	 * The plugin no longer emits the plain-text threshold notice — the rich
	 * .lafka-fdp component replaces it in BOTH paths, so the JS post-processor
	 * is unnecessary.
	 */
	public function test_plain_threshold_notice_is_gone(): void {
		$this->assertStringNotContainsString( 'lafka-cart-drawer__threshold', $this->fragments );
	}

	/**
	 * f087 invariant follows the markup to its new home: now that the remove (×)
	 * control is rendered by lafka_cart_drawer_render_item() (it used to live in
	 * the theme partial), the WC-native remove anchor must be preserved here —
	 * the cart nonce URL, the remove_from_cart_button class WC's add-to-cart.js
	 * binds, the data-cart_item_key it reads, and an <a> (never the old dead
	 * <button>). This keeps the f087 fix locked at the render site.
	 */
	public function test_remove_control_is_wc_native_anchor(): void {
		$this->assertStringContainsString( 'class="lafka-cart-drawer__remove remove_from_cart_button"', $this->fragments );
		$this->assertStringContainsString( 'wc_get_cart_remove_url( $cart_item_key )', $this->fragments );
		$this->assertStringContainsString( 'data-cart_item_key="', $this->fragments );
		$this->assertStringContainsString( '>×</a>', $this->fragments );
		$this->assertDoesNotMatchRegularExpression( '/<button[^>]*lafka-cart-drawer__remove/', $this->fragments );
	}

	/**
	 * Every drawer string the plugin renders must use the 'lafka-plugin' text
	 * domain (single catalog), never the theme's 'lafka' domain.
	 */
	public function test_single_text_domain(): void {
		$this->assertStringContainsString( "esc_html_e( 'Your cart is empty', 'lafka-plugin' )", $this->fragments );
		$this->assertStringContainsString( "esc_html_e( 'Subtotal', 'lafka-plugin' )", $this->fragments );
		$this->assertStringNotContainsString( "'lafka' )", $this->fragments );
	}

	/**
	 * The theme partial must delegate to the plugin callables via
	 * function_exists() guards — exactly the upsell SSOT pattern — and must NOT
	 * carry its own copy of the row / total markup any more (the source of the
	 * historical desync).
	 *
	 * @param string $needle Code the theme partial must contain.
	 */
	#[DataProvider('provide_theme_delegates')]
	public function test_theme_partial_delegates( string $needle ): void {
		$this->assertStringContainsString( $needle, $this->theme_partial() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_theme_delegates(): array {
		return array(
			'item guard'  => array( "function_exists( 'lafka_cart_drawer_render_item' )" ),
			'item call'   => array( 'lafka_cart_drawer_render_item(' ),
			'total guard' => array( "function_exists( 'lafka_cart_drawer_render_total' )" ),
			'total call'  => array( 'lafka_cart_drawer_render_total()' ),
		);
	}

	/**
	 * The theme partial must no longer inline the row / total / threshold markup
	 * — if it did, a structural change in one repo could silently desync from
	 * the other. The <ul>/<footer> wrappers stay, but their contents come from
	 * the plugin.
	 *
	 * @param string $needle Inline markup that must have moved to the plugin.
	 */
	#[DataProvider('provide_theme_no_inline_markup')]
	public function test_theme_partial_has_no_inline_markup( string $needle ): void {
		$this->assertStringNotContainsString( $needle, $this->theme_partial() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_theme_no_inline_markup(): array {
		return array(
			'no inline item row'    => array( 'class="lafka-cart-drawer__item" data-cart-key=' ),
			'no inline empty li'    => array( 'class="lafka-cart-drawer__empty"' ),
			'no inline subtotal'    => array( 'class="lafka-cart-drawer__subtotal"' ),
			'no inline remove link' => array( 'wc_get_cart_remove_url' ),
			'no fdp template part'  => array( 'partials/free-delivery-progress' ),
		);
	}

	/**
	 * Structural identity: both the theme partial's <ul> and the plugin's
	 * fragment build the items list by looping WC()->cart->get_cart() and
	 * calling the SAME render_item callable, with the empty state delegated to
	 * the same callable — so the two outputs are structurally identical for any
	 * given cart.
	 */
	public function test_partial_and_fragment_share_item_loop(): void {
		$partial = $this->theme_partial();

		// Both paths loop the cart and call render_item per row.
		foreach ( array( $partial, $this->fragments ) as $src ) {
			$this->assertMatchesRegularExpression(
				'/foreach\s*\(\s*WC\(\)->cart->get_cart\(\)\s+as\s+\$\w+\s*=>\s*\$\w+\s*\)\s*\{\s*\n\s*lafka_cart_drawer_render_item\(/',
				$src
			);
			// Both delegate the empty state to the same callable (no-arg call).
			$this->assertMatchesRegularExpression(
				'/is_empty\(\)|\$lafka_cart_empty/',
				$src
			);
			$this->assertStringContainsString( 'lafka_cart_drawer_render_item();', $src );
		}
	}
}
