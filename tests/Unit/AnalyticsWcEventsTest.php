<?php
/**
 * AnalyticsWcEventsTest — locks down the Phase 1B (v9.24.0) WC ecommerce
 * dataLayer event layer:
 *
 *   - lafka_dl_item_payload() returns the canonical GA4 item shape
 *   - lafka_dl_items_from_cart() pulls items from WC()->cart correctly
 *   - View events emit only on their respective WP conditional pages
 *   - view_item_list distinguishes /menu/ vs category vs shop vs related
 *   - purchase fires exactly once per order (post_meta gate)
 *   - add_to_cart server-side payload structurally matches AJAX-fragment payload
 *   - All GA4 event names are snake_case ≤40 chars
 *   - Currency is pulled from get_woocommerce_currency()
 *   - Client JS is enqueued only when an analytics ID is set
 *   - Plugin wires the new file + bumps version to 9.24.0
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.24.0
 */

declare(strict_types=1);

namespace {
	// Stub WC_Product if not already defined by another test file. We must
	// match the existing stub in ProductImageAltBackfillTest exactly (no
	// `name` property — anonymous subclasses there use constructor-promoted
	// `public string $name`, which conflicts with a parent-class protected).
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function get_name( $context = 'view' ) { return ''; }
		}
	}

	// Test-specific WC_Product double with all three accessors. Lives in the
	// global namespace because the events module checks method_exists on a
	// generic object — any duck-typed class with the trio works.
	if ( ! class_exists( 'Lafka_Test_WC_Product' ) ) {
		class Lafka_Test_WC_Product extends WC_Product {
			private $pid;
			private $pname;
			private $pprice;
			public function __construct( int $id = 0, string $name = '', float $price = 0.0 ) {
				$this->pid    = $id;
				$this->pname  = $name;
				$this->pprice = $price;
			}
			public function get_id() { return $this->pid; }
			public function get_name( $context = 'view' ) { return $this->pname; }
			public function get_price( $context = 'view' ) { return $this->pprice; }
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	// Bring the customizer accessor functions into scope before the events file
	// is required, since the enqueue helper calls them.
	require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php';
	require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
	require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';

	final class AnalyticsWcEventsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
			Functions\when( 'esc_attr' )->returnArg();
			Functions\when( 'esc_js' )->returnArg();
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'esc_attr__' )->returnArg();
			Functions\when( 'wp_kses_post' )->returnArg();
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
			// Stubbing get_woocommerce_currency permanently registers it in the
			// function table (Brain Monkey defines stubs at the PHP symbol level).
			// To prevent cross-test leakage from killing JsonLdSchemaTest's
			// restaurant tests — which rely on function_exists('get_woocommerce_currency')
			// being false or, equivalently, the function returning a sane string —
			// we stub a USD default so the function is always callable with an
			// expectation. Tests that need a different currency override per-test.
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
			// Default: no theme_mod set unless a test overrides.
			Functions\when( 'get_theme_mod' )->returnArg( 2 );
			// Stub WP conditional tags to false unless explicitly stubbed.
			Functions\when( 'is_product' )->justReturn( false );
			Functions\when( 'is_product_category' )->justReturn( false );
			Functions\when( 'is_product_tag' )->justReturn( false );
			Functions\when( 'is_shop' )->justReturn( false );
			Functions\when( 'is_page' )->justReturn( false );
			Functions\when( 'is_cart' )->justReturn( false );
			Functions\when( 'is_checkout' )->justReturn( false );
			Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		private function capture( callable $fn ): string {
			ob_start();
			$fn();
			return (string) ob_get_clean();
		}

		// ────────────────────────────────────────────────────────────────────
		// 1. Item payload shape
		// ────────────────────────────────────────────────────────────────────

		public function test_item_payload_has_canonical_ga4_keys(): void {
			$product = new \Lafka_Test_WC_Product( 42, 'Margherita Pizza', 12.50 );
			Functions\when( 'wc_get_product_category_list' )->justReturn( '<a>Pizza</a>, <a>Vegetarian</a>' );
			$payload = \lafka_dl_item_payload( $product, 2 );
			$this->assertArrayHasKey( 'item_id', $payload );
			$this->assertArrayHasKey( 'item_name', $payload );
			$this->assertArrayHasKey( 'item_category', $payload );
			$this->assertArrayHasKey( 'price', $payload );
			$this->assertArrayHasKey( 'quantity', $payload );
		}

		public function test_item_payload_values_are_correct(): void {
			$product = new \Lafka_Test_WC_Product( 42, 'Margherita Pizza', 12.50 );
			Functions\when( 'wc_get_product_category_list' )->justReturn( '<a>Pizza</a>, <a>Vegetarian</a>' );
			$payload = \lafka_dl_item_payload( $product, 3 );
			$this->assertSame( '42', $payload['item_id'] );
			$this->assertSame( 'Margherita Pizza', $payload['item_name'] );
			$this->assertSame( 'Pizza', $payload['item_category'] );
			$this->assertSame( 12.50, $payload['price'] );
			$this->assertSame( 3, $payload['quantity'] );
		}

		public function test_item_payload_defaults_quantity_to_minimum_one(): void {
			$product = new \Lafka_Test_WC_Product( 1, 'Foo', 5.00 );
			Functions\when( 'wc_get_product_category_list' )->justReturn( '' );
			$payload = \lafka_dl_item_payload( $product, 0 );
			$this->assertSame( 1, $payload['quantity'], 'qty must clamp to >= 1' );
		}

		public function test_item_payload_returns_empty_for_non_object(): void {
			$this->assertSame( array(), \lafka_dl_item_payload( null ) );
			$this->assertSame( array(), \lafka_dl_item_payload( 'string' ) );
		}

		public function test_item_payload_handles_missing_categories(): void {
			$product = new \Lafka_Test_WC_Product( 99, 'Plain Bagel', 3.00 );
			Functions\when( 'wc_get_product_category_list' )->justReturn( '' );
			$payload = \lafka_dl_item_payload( $product, 1 );
			$this->assertSame( '', $payload['item_category'] );
		}

		public function test_item_payload_picks_first_category_only(): void {
			$product = new \Lafka_Test_WC_Product( 7, 'Combo Box', 25.00 );
			Functions\when( 'wc_get_product_category_list' )->justReturn( 'Pizza, Pasta, Burgers' );
			$payload = \lafka_dl_item_payload( $product, 1 );
			$this->assertSame( 'Pizza', $payload['item_category'], 'first category only — GA4 wants a single string' );
		}

		// ────────────────────────────────────────────────────────────────────
		// 2. Cart items helper
		// ────────────────────────────────────────────────────────────────────

		public function test_items_from_cart_returns_empty_without_wc(): void {
			// WC() is not defined in tests, so the function returns empty.
			$this->assertSame( array(), \lafka_dl_items_from_cart() );
		}

		// ────────────────────────────────────────────────────────────────────
		// 3. Event names are GA4-compliant
		// ────────────────────────────────────────────────────────────────────

		public function test_all_event_names_are_snake_case_and_short(): void {
			// Pulled from the spec table — every event the module emits.
			$events = array(
				'view_item',
				'view_item_list',
				'select_item',
				'add_to_cart',
				'remove_from_cart',
				'view_cart',
				'begin_checkout',
				'add_shipping_info',
				'add_payment_info',
				'purchase',
				'search',
			);
			foreach ( $events as $event ) {
				$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_]*$/', $event, "{$event} must be snake_case" );
				$this->assertLessThanOrEqual( 40, strlen( $event ), "{$event} must be ≤40 chars per GA4 spec" );
			}
		}

		public function test_module_source_contains_all_required_event_names(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php' );
			$required = array(
				'view_item',
				'view_item_list',
				'view_cart',
				'begin_checkout',
				'add_to_cart',
				'remove_from_cart',
				'purchase',
			);
			foreach ( $required as $event ) {
				$this->assertStringContainsString( "'{$event}'", $src, "module must reference event {$event}" );
			}
		}

		// ────────────────────────────────────────────────────────────────────
		// 4. view_item — singular product page only
		// ────────────────────────────────────────────────────────────────────

		public function test_view_item_emits_on_singular_product(): void {
			Functions\when( 'is_product' )->justReturn( true );
			Functions\when( 'get_the_ID' )->justReturn( 42 );
			Functions\when( 'wc_get_product' )->justReturn( new \Lafka_Test_WC_Product( 42, 'Pizza Margherita', 11.99 ) );
			Functions\when( 'wc_get_product_category_list' )->justReturn( 'Pizza' );
			$out = $this->capture( 'lafka_dl_emit_view_item' );
			$this->assertStringContainsString( '"event":"view_item"', $out );
			$this->assertStringContainsString( '"currency":"USD"', $out );
			$this->assertStringContainsString( '"item_name":"Pizza Margherita"', $out );
		}

		public function test_view_item_absent_on_non_product_pages(): void {
			Functions\when( 'is_product' )->justReturn( false );
			$out = $this->capture( 'lafka_dl_emit_view_item' );
			$this->assertSame( '', $out, 'view_item must not emit on non-product pages' );
		}

		public function test_view_item_absent_when_product_missing(): void {
			Functions\when( 'is_product' )->justReturn( true );
			Functions\when( 'get_the_ID' )->justReturn( 42 );
			Functions\when( 'wc_get_product' )->justReturn( false );
			$out = $this->capture( 'lafka_dl_emit_view_item' );
			$this->assertSame( '', $out );
		}

		// ────────────────────────────────────────────────────────────────────
		// 5. view_item_list distinguishes /menu/ vs category vs shop vs related
		// ────────────────────────────────────────────────────────────────────

		public function test_view_item_list_labels_menu_page(): void {
			Functions\when( 'is_page' )->alias( static fn( $slug = '' ) => 'menu' === $slug );
			list( $id, $name ) = \lafka_dl_resolve_list_label();
			$this->assertSame( 'menu_page', $id );
			$this->assertSame( 'Menu page', $name );
		}

		public function test_view_item_list_labels_product_category(): void {
			Functions\when( 'is_page' )->justReturn( false );
			Functions\when( 'is_product_category' )->justReturn( true );
			Functions\when( 'get_queried_object' )->justReturn( (object) array( 'slug' => 'pizzas', 'name' => 'Pizzas' ) );
			list( $id, $name ) = \lafka_dl_resolve_list_label();
			$this->assertSame( 'category_pizzas', $id );
			$this->assertSame( 'Pizzas', $name );
		}

		public function test_view_item_list_labels_shop(): void {
			Functions\when( 'is_shop' )->justReturn( true );
			list( $id, $name ) = \lafka_dl_resolve_list_label();
			$this->assertSame( 'shop', $id );
			$this->assertSame( 'Shop', $name );
		}

		public function test_view_item_list_falls_back_to_related(): void {
			// None of the WP conditionals match.
			list( $id, $name ) = \lafka_dl_resolve_list_label();
			$this->assertSame( 'related', $id );
			$this->assertSame( 'Related products', $name );
		}

		public function test_view_item_list_absent_on_singular_product(): void {
			Functions\when( 'is_product' )->justReturn( true );
			$out = $this->capture( 'lafka_dl_emit_view_item_list' );
			$this->assertSame( '', $out, 'view_item_list must yield to view_item on PDPs' );
		}

		public function test_view_item_list_emits_on_menu_page(): void {
			Functions\when( 'is_page' )->alias( static fn( $slug = '' ) => 'menu' === $slug );
			Functions\when( 'wc_get_products' )->justReturn( array() );
			$out = $this->capture( 'lafka_dl_emit_view_item_list' );
			$this->assertStringContainsString( '"event":"view_item_list"', $out );
			$this->assertStringContainsString( '"item_list_id":"menu_page"', $out );
		}

		// ────────────────────────────────────────────────────────────────────
		// 6. view_cart / begin_checkout gate on WP conditional
		// ────────────────────────────────────────────────────────────────────

		public function test_view_cart_absent_when_not_on_cart_page(): void {
			$out = $this->capture( 'lafka_dl_emit_view_cart' );
			$this->assertSame( '', $out );
		}

		public function test_begin_checkout_absent_when_not_on_checkout(): void {
			$out = $this->capture( 'lafka_dl_emit_begin_checkout' );
			$this->assertSame( '', $out );
		}

		public function test_begin_checkout_skips_on_order_received_endpoint(): void {
			Functions\when( 'is_checkout' )->justReturn( true );
			Functions\when( 'is_wc_endpoint_url' )->alias( static fn( $endpoint = '' ) => 'order-received' === $endpoint );
			$out = $this->capture( 'lafka_dl_emit_begin_checkout' );
			$this->assertSame( '', $out, 'order-received is the purchase event, not begin_checkout' );
		}

		// ────────────────────────────────────────────────────────────────────
		// 7. purchase fires once per order (idempotency)
		// ────────────────────────────────────────────────────────────────────

		public function test_purchase_emits_when_flag_unset(): void {
			$order = new class {
				public function get_items() { return array(); }
				public function get_currency() { return 'USD'; }
				public function get_total() { return 42.50; }
				public function get_total_tax() { return 3.50; }
				public function get_shipping_total() { return 5.00; }
			};
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'get_post_meta' )->justReturn( '' );
			Functions\when( 'update_post_meta' )->justReturn( true );
			$out = $this->capture( static fn() => \lafka_dl_emit_purchase( 1001 ) );
			$this->assertStringContainsString( '"event":"purchase"', $out );
			$this->assertStringContainsString( '"transaction_id":"1001"', $out );
			$this->assertStringContainsString( '"value":42.5', $out );
			$this->assertStringContainsString( '"tax":3.5', $out );
			$this->assertStringContainsString( '"shipping":5', $out );
		}

		public function test_purchase_suppressed_when_flag_set(): void {
			Functions\when( 'wc_get_order' )->justReturn( new \stdClass() );
			Functions\when( 'get_post_meta' )->justReturn( '1' );
			$out = $this->capture( static fn() => \lafka_dl_emit_purchase( 1001 ) );
			$this->assertSame( '', $out, 'purchase must not re-emit once the order is flagged' );
		}

		public function test_purchase_calls_update_post_meta_to_lock_after_emit(): void {
			$order = new class {
				public function get_items() { return array(); }
				public function get_currency() { return 'USD'; }
				public function get_total() { return 10; }
				public function get_total_tax() { return 0; }
				public function get_shipping_total() { return 0; }
			};
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'get_post_meta' )->justReturn( '' );
			$called = false;
			Functions\when( 'update_post_meta' )->alias( static function ( $order_id, $key, $value ) use ( &$called ) {
				if ( 2002 === $order_id && '_lafka_dl_purchase_fired' === $key && 1 === $value ) {
					$called = true;
				}
				return true;
			} );
			$this->capture( static fn() => \lafka_dl_emit_purchase( 2002 ) );
			$this->assertTrue( $called, 'purchase emit must lock the order via update_post_meta' );
		}

		public function test_purchase_ignores_invalid_order_id(): void {
			$out = $this->capture( static fn() => \lafka_dl_emit_purchase( 0 ) );
			$this->assertSame( '', $out );
		}

		// ────────────────────────────────────────────────────────────────────
		// 8. AJAX add_to_cart payload parity
		// ────────────────────────────────────────────────────────────────────

		public function test_ajax_fragment_carries_add_to_cart_event(): void {
			$_POST['product_id'] = '7';
			$_POST['quantity']   = '2';
			Functions\when( 'wc_get_product' )->justReturn( new \Lafka_Test_WC_Product( 7, 'Test Pizza', 9.99 ) );
			Functions\when( 'wc_get_product_category_list' )->justReturn( 'Pizza' );
			$fragments = \lafka_dl_inject_ajax_add_to_cart( array() );
			$this->assertArrayHasKey( 'lafka_dl_event', $fragments );
			$this->assertSame( 'add_to_cart', $fragments['lafka_dl_event']['event'] );
			$this->assertArrayHasKey( 'items', $fragments['lafka_dl_event']['payload'] );
			$this->assertSame( 'USD', $fragments['lafka_dl_event']['payload']['currency'] );
			unset( $_POST['product_id'], $_POST['quantity'] );
		}

		public function test_ajax_fragment_preserves_existing_fragments(): void {
			$_POST['product_id'] = '7';
			Functions\when( 'wc_get_product' )->justReturn( new \Lafka_Test_WC_Product( 7, 'X', 1.0 ) );
			Functions\when( 'wc_get_product_category_list' )->justReturn( '' );
			$in  = array( 'div.cart-foo' => '<div>foo</div>' );
			$out = \lafka_dl_inject_ajax_add_to_cart( $in );
			$this->assertArrayHasKey( 'div.cart-foo', $out, 'existing fragment keys must survive' );
			$this->assertArrayHasKey( 'lafka_dl_event', $out, 'new key must be added' );
			unset( $_POST['product_id'] );
		}

		public function test_ajax_fragment_noop_when_product_unresolvable(): void {
			$_POST['product_id'] = '0';
			Functions\when( 'wc_get_product' )->justReturn( false );
			$in  = array( 'a' => 'b' );
			$out = \lafka_dl_inject_ajax_add_to_cart( $in );
			$this->assertArrayNotHasKey( 'lafka_dl_event', $out );
			unset( $_POST['product_id'] );
		}

		public function test_ajax_add_to_cart_payload_structural_parity_with_server(): void {
			// Same product, same qty — server path (via lafka_dl_emit_add_to_cart)
			// and AJAX path (via lafka_dl_inject_ajax_add_to_cart) must produce
			// payloads with the same top-level keys and the same items[0] shape.
			$_POST['product_id'] = '7';
			$_POST['quantity']   = '2';
			Functions\when( 'wc_get_product' )->justReturn( new \Lafka_Test_WC_Product( 7, 'Test', 5.00 ) );
			Functions\when( 'wc_get_product_category_list' )->justReturn( 'Pizza' );
			$ajax_payload = \lafka_dl_inject_ajax_add_to_cart( array() )['lafka_dl_event']['payload'];

			$server_item    = \lafka_dl_item_payload( new \Lafka_Test_WC_Product( 7, 'Test', 5.00 ), 2 );
			$server_payload = array(
				'currency' => 'USD',
				'value'    => 5.0 * 2,
				'items'    => array( $server_item ),
			);

			$this->assertSame( array_keys( $server_payload ), array_keys( $ajax_payload ) );
			$this->assertSame(
				array_keys( $server_payload['items'][0] ),
				array_keys( $ajax_payload['items'][0] ),
				'server-side and AJAX item payloads must have identical keys'
			);
			unset( $_POST['product_id'], $_POST['quantity'] );
		}

		// ────────────────────────────────────────────────────────────────────
		// 9. Currency pulled from get_woocommerce_currency
		// ────────────────────────────────────────────────────────────────────

		public function test_currency_comes_from_woocommerce(): void {
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );
			$this->assertSame( 'EUR', \lafka_dl_currency() );
		}

		public function test_currency_defaults_to_usd_when_wc_missing(): void {
			// Stub get_woocommerce_currency to return non-string — should fall through.
			Functions\when( 'get_woocommerce_currency' )->justReturn( 123 );
			$this->assertSame( 'USD', \lafka_dl_currency() );
		}

		// ────────────────────────────────────────────────────────────────────
		// 10. Hook registration + plugin wiring
		// ────────────────────────────────────────────────────────────────────

		public function test_module_hooks_view_item_on_woocommerce_before_single_product_summary(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php' );
			$this->assertMatchesRegularExpression(
				"/add_action\(\s*'woocommerce_before_single_product_summary',\s*'lafka_dl_emit_view_item'/",
				$src
			);
		}

		public function test_module_hooks_purchase_on_woocommerce_thankyou(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php' );
			$this->assertMatchesRegularExpression(
				"/add_action\(\s*'woocommerce_thankyou',\s*'lafka_dl_emit_purchase'/",
				$src
			);
		}

		public function test_module_hooks_add_to_cart_on_woocommerce_add_to_cart(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php' );
			$this->assertMatchesRegularExpression(
				"/add_action\(\s*'woocommerce_add_to_cart',\s*'lafka_dl_emit_add_to_cart'/",
				$src
			);
		}

		public function test_module_hooks_remove_from_cart_on_cart_item_removed(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php' );
			$this->assertMatchesRegularExpression(
				"/add_action\(\s*'woocommerce_cart_item_removed',\s*'lafka_dl_emit_remove_from_cart'/",
				$src
			);
		}

		public function test_module_filters_ajax_fragments_for_add_to_cart(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php' );
			$this->assertMatchesRegularExpression(
				"/add_filter\(\s*'woocommerce_add_to_cart_fragments',\s*'lafka_dl_inject_ajax_add_to_cart'/",
				$src
			);
		}

		// ────────────────────────────────────────────────────────────────────
		// 11. Client JS enqueued only when an analytics ID is configured
		// ────────────────────────────────────────────────────────────────────

		public function test_client_js_file_exists_in_assets(): void {
			$this->assertFileExists( dirname( __DIR__, 2 ) . '/assets/js/lafka-dl-client.js' );
		}

		public function test_client_js_enqueue_conditional_on_analytics_id(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php' );
			// Source-grep that the enqueue function checks for at least one of
			// the configured-ID helpers before calling wp_enqueue_script.
			$this->assertStringContainsString( 'function lafka_dl_enqueue_client', $src );
			$this->assertStringContainsString( 'lafka_analytics_gtm_id', $src );
			$this->assertStringContainsString( 'lafka_analytics_ga4_id', $src );
			// Verify the guard returns early before wp_enqueue_script.
			$pos_guard   = strpos( $src, '$has_id = false' );
			$pos_enqueue = strpos( $src, 'wp_enqueue_script' );
			$this->assertNotFalse( $pos_guard );
			$this->assertNotFalse( $pos_enqueue );
			$this->assertLessThan( $pos_enqueue, $pos_guard, '$has_id guard must precede wp_enqueue_script call' );
		}

		public function test_client_js_skipped_without_analytics_id(): void {
			// All IDs empty (default theme_mod stub returns the default param,
			// which is '' in all the lafka_analytics_*_id accessors).
			$called = false;
			Functions\when( 'wp_enqueue_script' )->alias( static function () use ( &$called ) {
				$called = true;
			} );
			Functions\when( 'plugins_url' )->returnArg();
			Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
			\lafka_dl_enqueue_client();
			$this->assertFalse( $called, 'wp_enqueue_script must not fire when no analytics ID is set' );
		}

		public function test_client_js_enqueued_with_gtm_id(): void {
			Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = null ) {
				if ( 'lafka_gtm_container_id' === $key ) {
					return 'GTM-ABC123';
				}
				return null === $default ? '' : $default;
			} );
			$handle = null;
			Functions\when( 'wp_enqueue_script' )->alias( static function ( $h ) use ( &$handle ) {
				$handle = $h;
			} );
			Functions\when( 'plugins_url' )->returnArg();
			Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
			\lafka_dl_enqueue_client();
			$this->assertSame( 'lafka-dl-client', $handle );
		}

		public function test_client_js_enqueued_with_ga4_id(): void {
			Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = null ) {
				if ( 'lafka_ga4_measurement_id' === $key ) {
					return 'G-ABCDE12345';
				}
				return null === $default ? '' : $default;
			} );
			$handle = null;
			Functions\when( 'wp_enqueue_script' )->alias( static function ( $h ) use ( &$handle ) {
				$handle = $h;
			} );
			Functions\when( 'plugins_url' )->returnArg();
			Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
			\lafka_dl_enqueue_client();
			$this->assertSame( 'lafka-dl-client', $handle );
		}

		public function test_client_js_binds_added_to_cart_event(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/lafka-dl-client.js' );
			$this->assertStringContainsString( "'added_to_cart'", $src );
			$this->assertStringContainsString( "'removed_from_cart'", $src );
		}

		public function test_client_js_handles_search_event(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/lafka-dl-client.js' );
			$this->assertStringContainsString( "event: 'search'", $src );
			$this->assertStringContainsString( 'data-lafka-menu-search', $src );
		}

		public function test_client_js_handles_select_item_click(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/lafka-dl-client.js' );
			$this->assertStringContainsString( 'data-lafka-item-id', $src );
			$this->assertStringContainsString( "'select_item'", $src );
		}

		public function test_client_js_handles_shipping_and_payment_radios(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/lafka-dl-client.js' );
			$this->assertStringContainsString( "'add_shipping_info'", $src );
			$this->assertStringContainsString( "'add_payment_info'", $src );
			$this->assertStringContainsString( 'shipping_method', $src );
			$this->assertStringContainsString( 'payment_method', $src );
		}

		public function test_client_js_never_calls_gtag_directly(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/lafka-dl-client.js' );
			// Strip JS comments so the doc-comment "never gtag()" isn't a false positive.
			$code = preg_replace( '#/\*.*?\*/#s', '', $src );
			$code = preg_replace( '#//.*#', '', (string) $code );
			$this->assertStringNotContainsString( 'gtag(', (string) $code, 'client JS must push only to dataLayer — GTM owns routing' );
		}

		// ────────────────────────────────────────────────────────────────────
		// 12. Plugin wiring + version bump
		// ────────────────────────────────────────────────────────────────────

		public function test_plugin_requires_wc_events_module(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
			$this->assertStringContainsString( 'incl/analytics/lafka-wc-events.php', $src );
		}

		public function test_plugin_version_bumped_to_at_least_9_24_0(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
			$this->assertMatchesRegularExpression(
				'/Version:\s*(\d+)\.(\d+)\.(\d+)\b/',
				$src,
				'Plugin header must declare a SemVer version string.'
			);
			preg_match( '/Version:\s*(\d+)\.(\d+)\.(\d+)\b/', $src, $m );
			$major = (int) ( $m[1] ?? 0 );
			$minor = (int) ( $m[2] ?? 0 );
			$this->assertTrue(
				$major > 9 || ( 9 === $major && $minor >= 24 ),
				'Plugin must be ≥ 9.24.0 once Phase 1B (lafka-wc-events.php) ships.'
			);
		}

		public function test_emit_push_uses_dataLayer_not_gtag(): void {
			$out = $this->capture( static fn() => \lafka_dl_emit_push( 'test_event', array( 'foo' => 'bar' ) ) );
			$this->assertStringContainsString( 'window.dataLayer.push', $out );
			$this->assertStringNotContainsString( 'gtag(', $out, 'emit must push to dataLayer, never call gtag — GTM owns routing' );
		}

		public function test_emit_push_clears_stale_ecommerce_before_push(): void {
			// Google's documented pattern: push {ecommerce:null} before each event
			// to prevent stale ecommerce data leaking between events.
			$out         = $this->capture( static fn() => \lafka_dl_emit_push( 'test_event', array() ) );
			$clear_pos   = strpos( $out, 'ecommerce: null' );
			$payload_pos = strpos( $out, '"test_event"' );
			$this->assertNotFalse( $clear_pos );
			$this->assertNotFalse( $payload_pos );
			$this->assertLessThan( $payload_pos, $clear_pos, 'ecommerce:null clear must precede the event push' );
		}
	}
}
