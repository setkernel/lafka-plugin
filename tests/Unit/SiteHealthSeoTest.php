<?php
/**
 * GX3 Site Health "Search & AI" checks
 * (incl/site-health/class-lafka-site-health-seo.php):
 *
 *   - NAP: option vs legacy Customizer value disagree (phone digits, text,
 *     geo > 50 m), literal "Array", street line = business name, raw E.164
 *     display phone;
 *   - profile: missing sameAs / review URL / tagline / description / map,
 *     site language vs store country (en_US for a CA store);
 *   - indexing: published legacy food-menu posts, attribute archives filtered
 *     back into the index;
 *   - content: counts of products without short descriptions, categories
 *     without descriptions, product photos without WebP;
 *   - IndexNow off → hint; a failing last ping → recommendation;
 *   - results are escaped, "good"/blue when clean, "recommended"/orange not.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Site_Health_Seo;
	use PHPUnit\Framework\TestCase;

	final class SiteHealthSeoTest extends TestCase {

		/** @var array<string, mixed> */
		private array $options = array();

		/** @var array<string, mixed> */
		private array $theme_mods = array();

		/** @var array<string, mixed> Merged into lafka_get_restaurant_info(). */
		private array $info = array();

		private string $tagline = '';

		private string $locale = 'en_US';

		/** @var array<string, mixed> Filter name => forced value. */
		private array $filters = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->options    = array();
			$this->theme_mods = array();
			$this->info       = array();
			$this->tagline    = 'Wood-fired pizza in Springfield';
			$this->locale     = 'en_US';
			$this->filters    = array();

			Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
			Functions\when( 'get_theme_mod' )->alias( fn( $key, $default = false ) => $this->theme_mods[ $key ] ?? $default );
			Functions\when( 'get_bloginfo' )->alias( fn( $key = '' ) => 'description' === $key ? $this->tagline : '' );
			Functions\when( 'get_locale' )->alias( fn() => $this->locale );
			Functions\when( 'get_site_icon_url' )->justReturn( '' );
			Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.test' . $p );
			Functions\when( 'trailingslashit' )->alias( static fn( $u ) => rtrim( (string) $u, '/' ) . '/' );
			Functions\when( 'apply_filters' )->alias(
				function ( $hook, $value ) {
					if ( 'lafka_restaurant_info' === $hook ) {
						return array_merge( (array) $value, $this->info );
					}
					return array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value;
				}
			);
			Functions\when( '__' )->returnArg();
			Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === (int) $n ? $one : $many );
			Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'is_admin' )->justReturn( false );

			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-nap-migration.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-settings.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-sitemap.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-indexnow.php';
			require_once dirname( __DIR__, 2 ) . '/incl/site-health/class-lafka-site-health-seo.php';
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		private static function has( array $issues, string $needle ): bool {
			foreach ( $issues as $issue ) {
				if ( false !== strpos( $issue, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		// ── NAP ───────────────────────────────────────────────────────────

		public function test_clean_business_record_has_no_issues(): void {
			$this->options = array(
				'lafka_business_name'          => 'Acme Kitchen',
				'lafka_business_street'        => '1 Test Road',
				'lafka_business_phone_e164'    => '+15551234567',
				'lafka_business_phone_display' => '(555) 123-4567',
			);
			// Same phone in the legacy store, formatted differently: not a conflict.
			$this->theme_mods = array( 'lafka_business_phone_e164' => '+1 555 123 4567' );
			self::assertSame( array(), Lafka_Site_Health_Seo::nap_issues() );
		}

		public function test_disagreeing_stores_are_reported(): void {
			$this->options    = array(
				'lafka_business_phone_e164' => '+15551230000',
				'lafka_business_geo_lat'    => '44.7660584',
				'lafka_business_geo_lng'    => '-63.6851393',
			);
			$this->theme_mods = array(
				'lafka_business_phone_e164' => '+15559999999',
				'lafka_business_geo_lat'    => '44.7723',
				'lafka_business_geo_lng'    => '-63.6814',
			);
			$issues = Lafka_Site_Health_Seo::nap_issues();
			self::assertTrue( self::has( $issues, 'lafka_business_phone_e164: the site uses "+15551230000"' ) );
			self::assertTrue( self::has( $issues, 'Map coordinates' ) );
		}

		public function test_array_sentinel_street_as_name_and_raw_e164_display(): void {
			$this->options    = array(
				'lafka_business_name'          => 'Acme Kitchen & Grill',
				'woocommerce_store_address'    => 'Acme Kitchen and Grill',
				'lafka_business_phone_e164'    => '+15551234567',
				'lafka_business_phone_display' => '+15551234567',
			);
			$this->theme_mods = array( 'lafka_business_cuisines' => 'Array' );

			$issues = Lafka_Site_Health_Seo::nap_issues();
			self::assertTrue( self::has( $issues, 'lafka_business_cuisines holds the literal text "Array"' ) );
			self::assertTrue( self::has( $issues, 'The street address "Acme Kitchen and Grill" looks like the business name' ) );
			self::assertTrue( self::has( $issues, 'shown as (555) 123-4567 automatically' ) );
		}

		public function test_street_heuristic(): void {
			self::assertTrue( Lafka_Site_Health_Seo::street_looks_like_name( 'Acme Pizza and Poutine', 'Acme Pizza & Poutine' ) );
			self::assertFalse( Lafka_Site_Health_Seo::street_looks_like_name( '512 Main Street', 'Main Street Pizza' ) );
			self::assertFalse( Lafka_Site_Health_Seo::street_looks_like_name( 'Harbour Road', 'Acme Kitchen' ) );
		}

		// ── Profile ───────────────────────────────────────────────────────

		public function test_missing_profile_facts_and_locale_mismatch(): void {
			$this->tagline                                  = '';
			$this->options['woocommerce_default_country'] = 'CA:NS';

			$issues = Lafka_Site_Health_Seo::profile_issues();
			foreach ( array( 'sameAs', 'review link', 'tagline', 'restaurant description', 'Business Profile link', 'en_US but the store is in CA — switch Settings → General → Site Language to en_CA' ) as $needle ) {
				self::assertTrue( self::has( $issues, $needle ), $needle );
			}
		}

		public function test_complete_profile_is_clean(): void {
			$this->info                                   = array(
				'same_as'     => array( 'https://social.example.test/acme' ),
				'description' => 'Family-run.',
				'map_url'     => 'https://maps.example.test/1',
			);
			$this->theme_mods['lafka_review_target_url']  = 'https://review.example.test/';
			$this->options['woocommerce_default_country'] = 'US:IL';
			self::assertSame( array(), Lafka_Site_Health_Seo::profile_issues() );

			// A pinned Lafka locale settles the mismatch too.
			$this->options['woocommerce_default_country'] = 'CA:NS';
			$this->theme_mods['lafka_default_locale']      = 'en_CA';
			self::assertSame( array(), Lafka_Site_Health_Seo::profile_issues() );
		}

		// ── Indexing ──────────────────────────────────────────────────────

		public function test_legacy_posts_and_reindexed_attribute_archives(): void {
			$this->filters = array(
				'lafka_seo_legacy_post_types'   => array( 'lafka-foodmenu' ),
				// Someone filtered pa_crust back into the index.
				'lafka_seo_excluded_taxonomies' => array( 'pa_size' ),
			);
			Functions\when( 'post_type_exists' )->justReturn( true );
			Functions\when( 'wp_count_posts' )->justReturn( (object) array( 'publish' => 22 ) );
			Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat', 'pa_size', 'pa_crust' ) );

			$issues = Lafka_Site_Health_Seo::indexing_issues();
			self::assertTrue( self::has( $issues, '22 legacy "lafka-foodmenu" entries are published' ) );
			self::assertTrue( self::has( $issues, 'Attribute archive "pa_crust" is indexable' ) );
			self::assertFalse( self::has( $issues, '"pa_size"' ) );
		}

		// ── Content ───────────────────────────────────────────────────────

		public function test_content_gap_counts(): void {
			$dir = sys_get_temp_dir() . '/lafka-webp-' . getmypid();
			@mkdir( $dir ); // phpcs:ignore
			touch( $dir . '/a.png' );
			touch( $dir . '/b.jpg' );
			touch( $dir . '/b.webp' );

			$GLOBALS['wpdb'] = new class() {
				public $posts    = 'wp_posts';
				public $postmeta = 'wp_postmeta';
				public function prepare( $sql, ...$args ) {
					return $sql;
				}
				public function get_var( $sql ) {
					return '5';
				}
				public function get_col( $sql ) {
					return array( '1', '2' );
				}
			};
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'get_terms' )->justReturn(
				array(
					(object) array( 'slug' => 'pizza', 'description' => '' ),
					(object) array( 'slug' => 'wings', 'description' => 'Crispy.' ),
					(object) array( 'slug' => 'uncategorized', 'description' => '' ),
				)
			);
			Functions\when( 'get_attached_file' )->alias( static fn( $id ) => 1 === $id ? $dir . '/a.png' : $dir . '/b.jpg' );

			$gaps = Lafka_Site_Health_Seo::content_gaps();
			self::assertSame(
				array(
					'products_no_short'  => 5,
					'categories_no_desc' => 1,
					'images_not_webp'    => 1,
					'images_checked'     => 2,
				),
				$gaps
			);
			$issues = Lafka_Site_Health_Seo::content_issues( $gaps );
			self::assertTrue( self::has( $issues, '5 products have no short description' ) );
			self::assertTrue( self::has( $issues, '1 menu category has no description' ) );
			self::assertTrue( self::has( $issues, '1 of 2 product photos have no WebP version' ) );

			unset( $GLOBALS['wpdb'] );
			array_map( 'unlink', glob( $dir . '/*' ) );
			rmdir( $dir );
		}

		// ── Results + IndexNow ────────────────────────────────────────────

		public function test_result_shape_and_escaping(): void {
			$bad = Lafka_Site_Health_Seo::result( 't', 'Good', 'Bad', array( '<script>x</script>' ), 'Why' );
			self::assertSame( 'recommended', $bad['status'] );
			self::assertSame( 'orange', $bad['badge']['color'] );
			self::assertStringContainsString( '&lt;script&gt;', $bad['description'] );
			self::assertStringNotContainsString( '<script>', $bad['description'] );

			$good = Lafka_Site_Health_Seo::result( 't', 'Good', 'Bad', array(), 'Why' );
			self::assertSame( 'good', $good['status'] );
			self::assertSame( 'Good', $good['label'] );
		}

		public function test_indexnow_hint_and_failing_ping(): void {
			$off = Lafka_Site_Health_Seo::test_indexnow();
			self::assertSame( 'recommended', $off['status'] );
			self::assertStringContainsString( 'IndexNow is off', $off['description'] );

			$this->options['lafka_seo_indexnow_enabled'] = 'yes';
			self::assertSame( 'good', Lafka_Site_Health_Seo::test_indexnow()['status'] );

			$this->options['lafka_seo_indexnow_last'] = array( 'time' => 1, 'code' => 403, 'sent' => 0 );
			self::assertStringContainsString( 'HTTP 403', Lafka_Site_Health_Seo::test_indexnow()['description'] );
		}

		public function test_all_checks_are_registered(): void {
			$tests = Lafka_Site_Health_Seo::register( array( 'direct' => array() ) );
			self::assertSame(
				array( 'lafka_seo_nap', 'lafka_seo_profile', 'lafka_seo_indexing', 'lafka_seo_content', 'lafka_seo_indexnow' ),
				array_keys( $tests['direct'] )
			);
		}
	}
}
