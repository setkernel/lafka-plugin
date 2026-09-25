<?php
/**
 * JSON-LD node generators: WebSite, Restaurant + its NAP/geo/hours helpers,
 * Product (offers, description fallback, review-backed rating), BreadcrumbList
 * and the price-currency helper. The @graph emitter is covered in
 * JsonLdEmitTest; the Restaurant no-self-rating lock in
 * RestaurantSchemaNoSelfRatingTest.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	// Same minimal stub as AnalyticsWcEventsTest / ProductImageAltBackfillTest
	// (whichever loads first wins); the generators type-hint WC_Product.
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function get_name( $context = 'view' ) {
				return '';
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
	require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-restaurant.php';
	require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-website.php';
	require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-breadcrumb.php';
	require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php';

	final class JsonLdSchemaTest extends TestCase {

		private const FIXTURES = array(
			'lafka_business_name'            => 'Acme Test Cafe',
			'lafka_business_street'          => '123 Test Street',
			'lafka_business_city'            => 'Testville',
			'lafka_business_region'          => 'TS',
			'lafka_business_postal'          => 'T1S 1S1',
			'lafka_business_country'         => 'CA',
			'lafka_business_phone_e164'      => '+15551234567',
			'lafka_business_phone_display'   => '+1 555-123-4567',
			'lafka_business_email'           => 'hello@example.test',
			'lafka_business_geo_lat'         => '45.0',
			'lafka_business_geo_lng'         => '-75.0',
			'lafka_business_price_range'     => '$$',
			'lafka_business_cuisines'        => 'Pizza, Italian',
			'lafka_business_payment_methods' => 'Cash, Visa',
			'lafka_business_same_as'         => "https://example.test/page1\nhttps://example.test/page2",
			'lafka_business_hours_mon'       => '11:00-23:00',
			'lafka_business_hours_tue'       => '11:00-23:00',
			'lafka_business_hours_wed'       => '11:00-23:00',
			'lafka_business_hours_thu'       => '11:00-23:00',
			'lafka_business_hours_fri'       => '11:00-23:00',
			'lafka_business_hours_sat'       => '11:00-23:00',
			'lafka_business_hours_sun'       => '11:00-23:00',
		);

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * get_woocommerce_currency is stubbed even where the code checks
		 * function_exists(): once any suite stubs it, the symbol exists for the
		 * rest of the process.
		 *
		 * GX3: business facts live in ONE store — the lafka_business_* options.
		 *
		 * @param array<string, mixed> $options
		 */
		private function stub_install( array $options ): void {
			Functions\when( 'get_theme_mod' )->alias( static fn( $key, $default = null ) => $default );
			Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $options[ $key ] ?? $default );
			Functions\when( 'get_bloginfo' )->justReturn( '' );
			Functions\when( 'get_site_icon_url' )->justReturn( '' );
			Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
			Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		}

		private function stub_populated_install(): void {
			$this->stub_install( self::FIXTURES );
		}

		private function stub_unconfigured_install(): void {
			$this->stub_install( array() );
		}

		// ── WebSite ─────────────────────────────────────────────────────────

		public function test_website_node_has_searchaction(): void {
			$this->stub_populated_install();
			Functions\when( 'get_bloginfo' )->alias(
				static fn( $k = '' ) => 'name' === $k ? 'Example Restaurant' : ''
			);
			$node = lafka_schema_website();
			self::assertSame( 'WebSite', $node['@type'] );
			self::assertSame( 'Example Restaurant', $node['name'] );
			self::assertSame( 'SearchAction', $node['potentialAction']['@type'] );
			self::assertSame( 'https://example.test/?s={search_term_string}', $node['potentialAction']['target']['urlTemplate'] );
			self::assertSame( 'https://example.test/#website', $node['@id'] );
		}

		public function test_website_links_restaurant_as_publisher_when_configured(): void {
			$this->stub_populated_install();
			self::assertSame( array( '@id' => 'https://example.test/#restaurant' ), lafka_schema_website()['publisher'] );
		}

		public function test_website_has_no_publisher_on_a_fresh_install(): void {
			// A site title exists (so the resolver has a name) but no NAP: the
			// #restaurant node is not emitted, so publisher must not dangle to it.
			$this->stub_unconfigured_install();
			Functions\when( 'get_bloginfo' )->alias( static fn( $k = '' ) => 'name' === $k ? 'My Fresh Site' : '' );
			self::assertArrayNotHasKey( 'publisher', lafka_schema_website() );
		}

		// ── Restaurant helpers ──────────────────────────────────────────────

		public function test_geo_is_emitted_only_when_both_coordinates_are_set(): void {
			$this->stub_populated_install();
			self::assertSame(
				array( '@type' => 'GeoCoordinates', 'latitude' => 45.0, 'longitude' => -75.0 ),
				lafka_schema_get_geo()
			);

			$this->stub_unconfigured_install();
			self::assertNull( lafka_schema_get_geo() );
		}

		public function test_opening_hours_one_block_per_configured_day(): void {
			$this->stub_populated_install();
			$hours = lafka_schema_get_opening_hours();
			self::assertCount( 7, $hours );
			foreach ( $hours as $spec ) {
				self::assertSame( 'OpeningHoursSpecification', $spec['@type'] );
				self::assertSame( '11:00', $spec['opens'] );
				self::assertSame( '23:00', $spec['closes'] );
			}

			$this->stub_unconfigured_install();
			self::assertSame( array(), lafka_schema_get_opening_hours() );
		}

		public function test_same_as_is_a_list_of_the_configured_urls(): void {
			$this->stub_populated_install();
			self::assertSame( array( 'https://example.test/page1', 'https://example.test/page2' ), lafka_schema_get_same_as() );
		}

		public function test_postal_address_is_built_from_nap_or_omitted(): void {
			$this->stub_populated_install();
			self::assertSame(
				array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => '123 Test Street',
					'addressLocality' => 'Testville',
					'addressRegion'   => 'TS',
					'postalCode'      => 'T1S 1S1',
					'addressCountry'  => 'CA',
				),
				lafka_schema_get_postal_address()
			);

			$this->stub_unconfigured_install();
			self::assertNull( lafka_schema_get_postal_address() );
		}

		// ── Restaurant node ─────────────────────────────────────────────────

		public function test_restaurant_node_carries_the_configured_business(): void {
			$this->stub_populated_install();
			$schema = lafka_schema_restaurant();

			self::assertSame( array( 'Restaurant', 'LocalBusiness', 'FoodEstablishment' ), array_values( (array) $schema['@type'] ) );
			self::assertSame( 'https://example.test/#restaurant', $schema['@id'] );
			self::assertSame( 'Acme Test Cafe', $schema['name'] );
			self::assertSame( '+15551234567', $schema['telephone'] );
			self::assertSame( '$$', $schema['priceRange'] );
			self::assertFalse( $schema['acceptsReservations'] );
			self::assertSame( 'PostalAddress', $schema['address']['@type'] );
			self::assertSame( 'GeoCoordinates', $schema['geo']['@type'] );
			self::assertCount( 7, $schema['openingHoursSpecification'] );
			self::assertSame( array( 'https://example.test/page1', 'https://example.test/page2' ), $schema['sameAs'] );
		}

		public function test_restaurant_node_skips_unconfigured_fields(): void {
			$this->stub_unconfigured_install();
			$schema = lafka_schema_restaurant();

			foreach ( array( 'address', 'geo', 'openingHoursSpecification', 'sameAs', 'servesCuisine' ) as $key ) {
				self::assertArrayNotHasKey( $key, $schema );
			}
		}

		public function test_restaurant_name_is_not_html_escaped(): void {
			$this->stub_install( array( 'lafka_business_name' => 'Test & Co.' ) + self::FIXTURES );
			self::assertSame( 'Test & Co.', lafka_schema_restaurant()['name'] );
		}

		// ── Product node ────────────────────────────────────────────────────

		/**
		 * @param array<string, mixed> $props
		 */
		private function product( array $props ): \WC_Product {
			return new class( $props ) extends \WC_Product {
				/** @param array<string, mixed> $p */
				public function __construct( private array $p ) {
				}
				public function get_name( $context = 'view' ) {
					return $this->p['name'] ?? 'Garden Salad';
				}
				public function get_short_description() {
					return $this->p['short'] ?? '';
				}
				public function get_description() {
					return $this->p['long'] ?? '';
				}
				public function get_image_id() {
					return 0;
				}
				public function get_sku() {
					return '';
				}
				public function is_in_stock() {
					return $this->p['in_stock'] ?? true;
				}
				public function is_type( $type ) {
					return isset( $this->p['variation_prices'] ) && 'variable' === $type;
				}
				public function get_variation_prices( $for_display = false ) {
					return array( 'price' => $this->p['variation_prices'] ?? array() );
				}
				public function get_price() {
					return $this->p['price'] ?? '12.5';
				}
				public function get_review_count() {
					return $this->p['review_count'] ?? 0;
				}
				public function get_average_rating() {
					return $this->p['rating'] ?? 0;
				}
			};
		}

		/**
		 * @param array<string, mixed> $props
		 * @return array<string, mixed>
		 */
		private function product_schema( array $props ): array {
			$this->stub_populated_install();
			$product = $this->product( $props );
			Functions\when( 'get_queried_object_id' )->justReturn( 7 );
			Functions\when( 'wc_get_product' )->justReturn( $product );
			Functions\when( 'get_permalink' )->justReturn( 'https://example.test/product/garden-salad/' );
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
			return lafka_schema_product();
		}

		public function test_product_node_offer_brand_and_identity(): void {
			$schema = $this->product_schema( array( 'price' => '12.5', 'in_stock' => false ) );

			self::assertSame( 'Product', $schema['@type'] );
			self::assertSame( 'https://example.test/product/garden-salad/#product', $schema['@id'] );
			self::assertSame( array( '@type' => 'Brand', 'name' => 'Acme Test Cafe' ), $schema['brand'] );
			self::assertSame( 'Offer', $schema['offers']['@type'] );
			self::assertSame( '12.50', $schema['offers']['price'] );
			self::assertSame( 'USD', $schema['offers']['priceCurrency'] );
			self::assertSame( 'https://schema.org/OutOfStock', $schema['offers']['availability'] );
			self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $schema['offers']['priceValidUntil'] );
		}

		public function test_variable_product_with_a_price_range_gets_an_aggregate_offer(): void {
			$offers = $this->product_schema( array( 'variation_prices' => array( 9 => '8', 10 => '14.5' ) ) )['offers'];
			self::assertSame( 'AggregateOffer', $offers['@type'] );
			self::assertSame( '8.00', $offers['lowPrice'] );
			self::assertSame( '14.50', $offers['highPrice'] );

			$offers = $this->product_schema( array( 'variation_prices' => array( 9 => '8', 10 => '8' ) ) )['offers'];
			self::assertSame( 'Offer', $offers['@type'] );
			self::assertSame( '8.00', $offers['price'] );
		}

		public function test_product_without_a_price_has_no_offer(): void {
			self::assertArrayNotHasKey( 'offers', $this->product_schema( array( 'price' => '' ) ) );
		}

		public function test_description_prefers_short_description(): void {
			$schema = $this->product_schema( array( 'short' => '<p>Crisp greens.</p>', 'long' => 'Long text.' ) );
			self::assertSame( 'Crisp greens.', $schema['description'] );
		}

		public function test_description_falls_back_to_a_capped_long_description(): void {
			// Regression: an empty short description used to emit description: null.
			$long   = "<p>Fresh\n\n   leaves " . str_repeat( 'and crunchy croutons ', 20 ) . '</p>';
			$schema = $this->product_schema( array( 'long' => $long ) );

			self::assertLessThanOrEqual( 160, mb_strlen( $schema['description'] ) );
			self::assertStringStartsWith( 'Fresh leaves and crunchy', $schema['description'] );
			self::assertStringEndsWith( '...', $schema['description'] );

			self::assertSame( 'Short one.', $this->product_schema( array( 'long' => 'Short one.' ) )['description'] );
			self::assertArrayNotHasKey( 'description', $this->product_schema( array() ) );
		}

		public function test_product_rating_only_from_real_reviews(): void {
			self::assertArrayNotHasKey( 'aggregateRating', $this->product_schema( array() ) );
			self::assertArrayNotHasKey( 'aggregateRating', $this->product_schema( array( 'review_count' => 3, 'rating' => 0 ) ) );

			self::assertSame(
				array(
					'@type'       => 'AggregateRating',
					'ratingValue' => '4.3',
					'reviewCount' => 3,
					'bestRating'  => '5',
					'worstRating' => '1',
				),
				$this->product_schema( array( 'review_count' => 3, 'rating' => 4.33 ) )['aggregateRating']
			);
		}

		// ── BreadcrumbList ──────────────────────────────────────────────────

		public function test_breadcrumb_is_skipped_on_the_front_page(): void {
			Functions\when( 'is_front_page' )->justReturn( true );
			self::assertNull( lafka_schema_breadcrumb() );
		}

		public function test_breadcrumb_labels_are_translatable_and_menu_crumb_targets_menu_page(): void {
			$this->stub_unconfigured_install();
			Functions\when( '__' )->alias( static fn( $text ) => 'xx-' . $text );
			Functions\when( 'is_front_page' )->justReturn( false );
			Functions\when( 'get_queried_object' )->justReturn( null );
			Functions\when( 'is_product' )->justReturn( false );
			Functions\when( 'is_product_category' )->justReturn( false );
			Functions\when( 'is_shop' )->justReturn( true );

			self::assertSame(
				array(
					'@type'           => 'BreadcrumbList',
					'itemListElement' => array(
						array( '@type' => 'ListItem', 'position' => 1, 'name' => 'xx-Home', 'item' => 'https://example.test/' ),
						array( '@type' => 'ListItem', 'position' => 2, 'name' => 'xx-Menu', 'item' => 'https://example.test/menu/' ),
					),
				),
				lafka_schema_breadcrumb()
			);
		}

		// ── Price currency ──────────────────────────────────────────────────

		public function test_currency_comes_from_woocommerce(): void {
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			self::assertSame( 'EUR', \lafka_schema_get_price_currency() );
		}

		public function test_currency_filter_can_override(): void {
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'CAD' );
			Functions\when( 'apply_filters' )->alias(
				static fn( $hook, $value ) => 'lafka_schema_price_currency' === $hook ? 'GBP' : $value
			);
			self::assertSame( 'GBP', \lafka_schema_get_price_currency() );
		}

		public function test_empty_wc_currency_falls_back_to_usd(): void {
			// Headless WC setups can return '' during early bootstrap; '' is invalid schema.
			Functions\when( 'get_woocommerce_currency' )->justReturn( '' );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			self::assertSame( 'USD', \lafka_schema_get_price_currency() );
		}

		// ── Menu cache invalidation ─────────────────────────────────────────

		/**
		 * The Menu node is cached for 12h; it must be busted when anything that
		 * changes it changes (v9.7.5: out-of-stock items kept reading InStock).
		 * The buster is a closure registered at include time, unreachable while
		 * add_action is a no-op, so the registration is read from source.
		 */
		public function test_menu_cache_is_busted_by_every_menu_affecting_hook(): void {
			$src     = (string) file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-menu.php' );
			$missing = array();
			foreach ( array( 'save_post_product', 'delete_post', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_update_product', 'edited_product_cat', 'created_product_cat', 'delete_product_cat' ) as $hook ) {
				if ( ! preg_match( "/add_action\(\s*'" . preg_quote( $hook, '/' ) . "'/", $src ) ) {
					$missing[] = $hook;
				}
			}
			self::assertSame( array(), $missing );
			self::assertMatchesRegularExpression( "/'product'\s*===\s*get_post_type/", $src, 'delete_post must only bust for products.' );
		}
	}
}
