<?php
/**
 * StoreApiAddonsTest — NX1-04c.
 *
 * Locks the Store API add-to-cart adapter (Lafka_Engine_Store_Api) that carries
 * addon selections through the block cart / headless path. The adapter never
 * re-parses or re-prices anything — it maps a documented request shape onto the
 * exact classic $post_data and delegates to the engine's own field classes — so
 * these tests prove:
 *
 *   · EXTRACTION: selections are read from extensions.lafka.addons (and only there).
 *   · MAPPING: field-name → `addon-{field}` keys, `add-to-cart` owner id, wp_slash'd
 *     values — i.e. a byte-identical $post_data to what the classic PDP submits.
 *   · PRICE PARITY per strategy: the value the adapter forwards, run through the SAME
 *     field pipeline + apply_attribute_specific_price(), prices every strategy
 *     (flat_per_option, flat_group, flat_per_size, matrix) identically to classic.
 *   · ORDER-META PARITY: the addons built from a mapped selection produce the same
 *     order-item meta writes as classic (same Lafka_Engine_Cart::order_line_item()).
 *   · ADD-ITEM: the Store API add-to-cart data gains cart_item_data['addons'] built
 *     by Lafka_Engine_Cart::add_cart_item_data() for the owner (parent) product,
 *     and the same $post_data is handed to the engine's classic validation.
 *
 * The full HTTP round-trip (RouteException surfacing, item_data render) is exercised
 * by the live wp-env contract checks in the item, matching the StoreApiParityTest
 * (NX1-04a) convention.
 *
 * @package Lafka\Plugin\Tests\Unit\Addons
 */

declare(strict_types=1);

namespace {
	require_once dirname( __DIR__ ) . '/Stubs/wp-error-class.php';

	// Minimal ArrayAccess request stub so extract_selections() can be exercised
	// against the WP_REST_Request shape without booting WordPress.
	if ( ! class_exists( '\Lafka_Test_Store_Api_Request' ) ) {
		class Lafka_Test_Store_Api_Request implements \ArrayAccess { // phpcs:ignore
			private array $data;
			public function __construct( array $data ) {
				$this->data = $data;
			}
			#[\ReturnTypeWillChange]
			public function offsetExists( $offset ): bool {
				return isset( $this->data[ $offset ] );
			}
			#[\ReturnTypeWillChange]
			public function offsetGet( $offset ) {
				return $this->data[ $offset ] ?? null;
			}
			#[\ReturnTypeWillChange]
			public function offsetSet( $offset, $value ): void {
				$this->data[ $offset ] = $value;
			}
			#[\ReturnTypeWillChange]
			public function offsetUnset( $offset ): void {
				unset( $this->data[ $offset ] );
			}
		}
	}

	// Order-item double capturing add_meta_data() so order-meta parity is asserted
	// without a WooCommerce order object.
	if ( ! class_exists( '\Lafka_Test_Order_Item' ) ) {
		class Lafka_Test_Order_Item { // phpcs:ignore
			public array $meta = array();
			public function add_meta_data( $key, $value, $unique = false ) { // phpcs:ignore
				$this->meta[] = array(
					'key'   => $key,
					'value' => $value,
				);
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit\Addons {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Engine_Cart;
	use Lafka_Engine_Field_Factory;
	use Lafka_Engine_Store_Api;
	use Mockery;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

	final class StoreApiAddonsTest extends TestCase {

		private Lafka_Engine_Cart $cart;
		private Lafka_Engine_Store_Api $adapter;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_html' )->returnArg( 1 );
			Functions\when( 'sanitize_text_field' )->returnArg( 1 );
			Functions\when( 'wp_kses_post' )->returnArg( 1 );
			Functions\when( 'sanitize_title' )->alias(
				fn( $s ) => strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $s ) )
			);
			// Real slash round-trip so a value carrying a backslash proves the
			// adapter reproduces the WP-slashed classic $_POST exactly.
			Functions\when( 'wp_slash' )->alias( fn( $v ) => self::deep( $v, 'addslashes' ) );
			Functions\when( 'wp_unslash' )->alias( fn( $v ) => self::deep( $v, 'stripslashes' ) );

			// add_filter/add_action are the bootstrap no-op stubs; constructing the
			// cart + adapter registers hooks harmlessly (AddonCartPriceResolutionTest
			// convention). Reuse the one cart instance so no hook is double-bound.
			$this->cart    = new Lafka_Engine_Cart();
			$this->adapter = new Lafka_Engine_Store_Api( $this->cart );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		private static function deep( $value, string $fn ) {
			if ( is_array( $value ) ) {
				return array_map( static fn( $v ) => self::deep( $v, $fn ), $value );
			}
			return $fn( (string) $value );
		}

		/* ----------------------------------------------------------------- *
		 *  Extraction
		 * ----------------------------------------------------------------- */

		public function test_extract_reads_selections_from_lafka_addons_extension(): void {
			$request = array(
				'id'         => 249,
				'extensions' => array(
					'lafka' => array(
						'addons' => array(
							'94-extra-toppings-0' => array( 'demo-topping-cheese' ),
						),
					),
				),
			);

			$out = Lafka_Engine_Store_Api::extract_selections( $request );
			self::assertSame(
				array( '94-extra-toppings-0' => array( 'demo-topping-cheese' ) ),
				$out,
				'Selections must be read from extensions.lafka.addons.'
			);
		}

		public function test_extract_reads_from_arrayaccess_request(): void {
			$request = new \Lafka_Test_Store_Api_Request(
				array(
					'extensions' => array(
						'lafka' => array( 'addons' => array( '94-make-it-a-combo-1' => 'demo-combo-fries-drink' ) ),
					),
				)
			);
			$out = Lafka_Engine_Store_Api::extract_selections( $request );
			self::assertSame( array( '94-make-it-a-combo-1' => 'demo-combo-fries-drink' ), $out );
		}

		public function test_extract_returns_empty_when_absent(): void {
			self::assertSame( array(), Lafka_Engine_Store_Api::extract_selections( array( 'id' => 5 ) ) );
			self::assertSame( array(), Lafka_Engine_Store_Api::extract_selections( array( 'extensions' => array( 'other' => array() ) ) ) );
			self::assertSame( array(), Lafka_Engine_Store_Api::extract_selections( 'not-a-request' ) );
		}

		public function test_extract_ignores_foreign_namespaces(): void {
			$request = array(
				'extensions' => array(
					'someplugin' => array( 'addons' => array( 'x' => 'y' ) ),
				),
			);
			self::assertSame( array(), Lafka_Engine_Store_Api::extract_selections( $request ) );
		}

		/* ----------------------------------------------------------------- *
		 *  Mapping — identical $post_data to the classic PDP
		 * ----------------------------------------------------------------- */

		public function test_map_prefixes_fields_and_sets_add_to_cart(): void {
			$post = $this->adapter->map_selections_to_post_data(
				94,
				array( '94-extra-toppings-0' => array( 'demo-topping-cheese' ) )
			);
			self::assertSame( 94, $post['add-to-cart'], 'Owner id must ride under add-to-cart like the classic form.' );
			self::assertArrayHasKey( 'addon-94-extra-toppings-0', $post, 'Field must map to the classic addon-{field} key.' );
			self::assertSame( array( 'demo-topping-cheese' ), $post['addon-94-extra-toppings-0'] );
		}

		public function test_map_tolerates_leading_addon_prefix(): void {
			$post = $this->adapter->map_selections_to_post_data(
				94,
				array( 'addon-94-make-it-a-combo-1' => 'demo-combo-fries-drink' )
			);
			self::assertArrayHasKey( 'addon-94-make-it-a-combo-1', $post );
			self::assertArrayNotHasKey( 'addon-addon-94-make-it-a-combo-1', $post, 'A leading addon- must not be doubled.' );
		}

		public function test_map_wp_slashes_values_for_classic_parity(): void {
			// Classic $_POST is WP-slashed; the engine unslashes per field. The
			// adapter must slash too so a backslash survives the round-trip.
			$post = $this->adapter->map_selections_to_post_data(
				94,
				array( '94-note-0' => array( 'msg' => 'a\\b' ) )
			);
			self::assertSame(
				array( 'msg' => 'a\\\\b' ),
				$post['addon-94-note-0'],
				'Textarea values must be wp_slash()ed to reproduce the classic slashed superglobal.'
			);
			// And the engine's per-field unslash reverses it back to the original.
			self::assertSame( array( 'msg' => 'a\\b' ), \wp_unslash( $post['addon-94-note-0'] ) );
		}

		public function test_map_skips_empty_field_keys(): void {
			$post = $this->adapter->map_selections_to_post_data( 94, array( '' => 'x', '   ' => 'y' ) );
			self::assertSame( array( 'add-to-cart' => 94 ), $post, 'Empty/blank field keys must be dropped.' );
		}

		/* ----------------------------------------------------------------- *
		 *  Price parity — every pricing strategy
		 * ----------------------------------------------------------------- */

		public function test_price_parity_flat_per_option(): void {
			$addon = array(
				'name'       => 'Extra Toppings',
				'type'       => 'checkbox',
				'required'   => 0,
				'limit'      => 0,
				'field-name' => '94-extra-toppings-0',
				'options'    => array(
					array( 'id' => 'cheese', 'label' => 'Extra Cheese', 'price' => '1.50' ),
					array( 'id' => 'mushrooms', 'label' => 'Mushrooms', 'price' => '1.00' ),
					array( 'id' => 'olives', 'label' => 'Olives', 'price' => '1.00' ),
				),
			);
			$this->assert_strategy_parity( $addon, array( 'cheese', 'mushrooms' ), array(), 2.50 );
		}

		public function test_price_parity_flat_group(): void {
			// Expanded storage shape: flat_group writes group_flat_price onto every option.
			$addon = array(
				'name'       => 'Make It a Combo',
				'type'       => 'radiobutton',
				'required'   => 0,
				'limit'      => 0,
				'field-name' => '94-make-it-a-combo-1',
				'options'    => array(
					array( 'id' => 'fries', 'label' => 'Add Fries & a Drink', 'price' => '2.50' ),
					array( 'id' => 'salad', 'label' => 'Add a Salad & a Drink', 'price' => '2.50' ),
				),
			);
			$this->assert_strategy_parity( $addon, 'fries', array(), 2.50 );
		}

		public function test_price_parity_flat_per_size(): void {
			// Per-size: one nested matrix keyed by the attribute, shared across options.
			$matrix = array( 'size' => array( 'small' => '0.50', 'medium' => '1.00', 'large' => '1.50' ) );
			$addon  = array(
				'name'       => 'Per-Size Cheese',
				'type'       => 'checkbox',
				'required'   => 0,
				'limit'      => 0,
				'field-name' => '94-per-size-0',
				'options'    => array(
					array( 'id' => 'cheese', 'label' => 'Cheese', 'price' => $matrix ),
				),
			);
			$this->assert_strategy_parity(
				$addon,
				array( 'cheese' ),
				array( 'attribute_size' => 'medium' ),
				1.00
			);
		}

		public function test_price_parity_matrix(): void {
			// Full matrix: each option carries its own per-size grid.
			$addon = array(
				'name'       => 'Matrix Toppings',
				'type'       => 'checkbox',
				'required'   => 0,
				'limit'      => 0,
				'field-name' => '94-matrix-0',
				'options'    => array(
					array( 'id' => 'cheese', 'label' => 'Cheese', 'price' => array( 'size' => array( 'small' => '0.75', 'medium' => '1.25', 'large' => '1.75' ) ) ),
					array( 'id' => 'bacon', 'label' => 'Bacon', 'price' => array( 'size' => array( 'small' => '1.00', 'medium' => '1.50', 'large' => '2.00' ) ) ),
				),
			);
			$this->assert_strategy_parity(
				$addon,
				array( 'cheese', 'bacon' ),
				array( 'attribute_size' => 'medium' ),
				2.75
			);
		}

		/**
		 * The shared parity assertion: the value the adapter forwards (after the
		 * engine's unslash) is byte-identical to the classic $_POST value (after
		 * unslash), and running that value through the SAME field pipeline +
		 * apply_attribute_specific_price() yields the expected total.
		 *
		 * @param array  $addon     Addon definition (expanded storage shape).
		 * @param mixed  $value     Logical selection value.
		 * @param array  $variation Cart-item variation ({} for non-variable).
		 * @param float  $expected  Expected summed price delta.
		 */
		private function assert_strategy_parity( array $addon, $value, array $variation, float $expected ): void {
			$field = 'addon-' . $addon['field-name'];

			// Classic: $_POST is WP-slashed; engine unslashes per field.
			$classic_post    = array( $field => \wp_slash( $value ) );
			$classic_carried = \wp_unslash( $classic_post[ $field ] );

			// Store API: adapter maps the request selection to $post_data.
			$api_post    = $this->adapter->map_selections_to_post_data( 94, array( $addon['field-name'] => $value ) );
			$api_carried = \wp_unslash( $api_post[ $field ] );

			self::assertSame(
				$classic_carried,
				$api_carried,
				'The adapter must forward the selection value byte-identically to the classic $_POST value.'
			);

			$classic_total = $this->resolve_total( $addon, $classic_carried, $variation );
			$api_total     = $this->resolve_total( $addon, $api_carried, $variation );

			self::assertSame( $classic_total, $api_total, 'Store API total must equal classic total for this strategy.' );
			self::assertSame( $expected, $api_total, 'Total must match the expected strategy price.' );
		}

		/**
		 * Run one selection value through the engine field pipeline + the cart's
		 * variation-aware price resolution and sum the addon deltas — the exact
		 * math Lafka_Engine_Cart::add_cart_item() applies on both checkout paths.
		 */
		private function resolve_total( array $addon, $value, array $variation ): float {
			$field  = Lafka_Engine_Field_Factory::create( $addon, $value );
			$addons = $field ? $field->get_cart_item_data() : array();
			$addons = is_array( $addons ) ? $addons : array();

			$cart_item = array( 'variation' => $variation );
			$addons    = $this->cart->apply_attribute_specific_price( $addons, $cart_item );

			$total = 0.0;
			foreach ( $addons as $a ) {
				$total += (float) $a['price'];
			}
			return $total;
		}

		/* ----------------------------------------------------------------- *
		 *  Order-meta parity
		 * ----------------------------------------------------------------- */

		public function test_order_meta_parity(): void {
			// Suppress the price-name suffix so the parity assertion focuses on the
			// name→value writes (the suffix path needs a live product for wc_price).
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $value = null ) {
					return 'lafka_addons_add_price_to_name' === $tag ? false : $value;
				}
			);

			$addon = array(
				'name'       => 'Extra Toppings',
				'type'       => 'checkbox',
				'required'   => 0,
				'limit'      => 0,
				'field-name' => '94-extra-toppings-0',
				'options'    => array(
					array( 'id' => 'cheese', 'label' => 'Extra Cheese', 'price' => '1.50' ),
					array( 'id' => 'mushrooms', 'label' => 'Mushrooms', 'price' => '1.00' ),
				),
			);

			$api_post    = $this->adapter->map_selections_to_post_data( 94, array( '94-extra-toppings-0' => array( 'cheese', 'mushrooms' ) ) );
			$api_carried = \wp_unslash( $api_post['addon-94-extra-toppings-0'] );
			$field       = Lafka_Engine_Field_Factory::create( $addon, $api_carried );
			$addons      = $field->get_cart_item_data();

			$item = new \Lafka_Test_Order_Item();
			$this->cart->order_line_item( $item, 'cart-key', array( 'addons' => $addons ) );

			self::assertSame(
				array(
					array( 'key' => 'Extra Toppings', 'value' => 'Extra Cheese' ),
					array( 'key' => 'Extra Toppings', 'value' => 'Mushrooms' ),
					array( 'key' => '_lafka_addon_keys', 'value' => array( 'Extra Toppings' ) ),
				),
				$item->meta,
				'Store API order-item meta must match the classic addon name→value writes.'
			);
		}

		/* ----------------------------------------------------------------- *
		 *  Add-item hook — delegation to the engine cart + classic validation
		 * ----------------------------------------------------------------- */

		/**
		 * Adapter wired to a mocked engine cart so the delegation contract is
		 * observable: which owner id and $post_data the engine receives.
		 */
		private function adapter_with_cart( $engine_cart ): Lafka_Engine_Store_Api {
			return new Lafka_Engine_Store_Api( $engine_cart );
		}

		private static function add_item_request( array $selections ): array {
			return array(
				'id'         => 250,
				'quantity'   => 1,
				'extensions' => array( 'lafka' => array( 'addons' => $selections ) ),
			);
		}

		public function test_add_item_builds_addons_through_engine_cart_for_parent_product(): void {
			$variation = Mockery::mock( 'WC_Product' );
			$variation->shouldReceive( 'is_type' )->with( 'variation' )->andReturn( true );
			$variation->shouldReceive( 'get_parent_id' )->andReturn( 94 );
			Functions\when( 'wc_get_product' )->justReturn( $variation );

			$expected_post = array(
				'add-to-cart'               => 94,
				'addon-94-extra-toppings-0' => array( 'cheese' ),
			);
			$addons      = array(
				array(
					'name'  => 'Extra Toppings',
					'value' => 'Extra Cheese',
					'price' => '1.50',
				),
			);
			$engine_cart = Mockery::mock( Lafka_Engine_Cart::class );
			$engine_cart->shouldReceive( 'add_cart_item_data' )
				->once()
				->with( array(), 94, $expected_post )
				->andReturn( array( 'addons' => $addons ) );
			$adapter = $this->adapter_with_cart( $engine_cart );

			$data = $adapter->inject_addon_selections(
				array(
					'id'             => 250,
					'quantity'       => 1,
					'cart_item_data' => array( 'gift_note' => 'kept' ),
				),
				self::add_item_request( array( '94-extra-toppings-0' => array( 'cheese' ) ) )
			);

			self::assertSame(
				array(
					'gift_note' => 'kept',
					'addons'    => $addons,
				),
				$data['cart_item_data']
			);
			// The engine's classic add-to-cart validation sees the same selections.
			self::assertSame( $expected_post, $adapter->inject_request_post_data( null ) );
			$adapter->clear_pending();
			self::assertNull( $adapter->inject_request_post_data( null ), 'Pending selections are dropped once the add completes.' );
		}

		public function test_add_item_without_selections_leaves_classic_path_untouched(): void {
			$product = Mockery::mock( 'WC_Product' );
			$product->shouldReceive( 'is_type' )->andReturn( false );
			$product->shouldReceive( 'get_id' )->andReturn( 94 );
			Functions\when( 'wc_get_product' )->justReturn( $product );

			$engine_cart = Mockery::mock( Lafka_Engine_Cart::class );
			$engine_cart->shouldReceive( 'add_cart_item_data' )->once()->andReturn( array( 'addons' => array() ) );
			$adapter = $this->adapter_with_cart( $engine_cart );

			// A first add with selections, then one without: the second must not
			// inherit the first one's $post_data (batch requests).
			$adapter->inject_addon_selections( array( 'id' => 94 ), self::add_item_request( array( '94-note-0' => 'x' ) ) );
			$data = array(
				'id'       => 94,
				'quantity' => 2,
			);

			self::assertSame( $data, $adapter->inject_addon_selections( $data, array( 'id' => 94 ) ) );
			self::assertSame( 'classic', $adapter->inject_request_post_data( 'classic' ) );
		}

		public function test_invalid_selection_surfaces_as_an_error_not_a_silent_add(): void {
			$product = Mockery::mock( 'WC_Product' );
			$product->shouldReceive( 'is_type' )->andReturn( false );
			$product->shouldReceive( 'get_id' )->andReturn( 94 );
			Functions\when( 'wc_get_product' )->justReturn( $product );

			$engine_cart = Mockery::mock( Lafka_Engine_Cart::class );
			$engine_cart->shouldReceive( 'add_cart_item_data' )->andThrow( new \Exception( '"Sauce" is a required field.' ) );

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( '"Sauce" is a required field.' );

			$this->adapter_with_cart( $engine_cart )->inject_addon_selections(
				array( 'id' => 94 ),
				self::add_item_request( array( '94-sauce-0' => '' ) )
			);
		}
	}
}
