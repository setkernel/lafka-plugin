<?php
/**
 * Regression lock (f016): the Restaurant / LocalBusiness / FoodEstablishment
 * node must NEVER emit a self-serving aggregateRating.
 *
 * Before this fix the sitewide Restaurant node transcribed the decorative
 * social-proof Customizer theme_mods (lafka_social_proof_rating /
 * lafka_social_proof_count) into an AggregateRating with no backing Review
 * entities anywhere on the site. Google does not surface rich-result stars for
 * self-serving LocalBusiness ratings and treats them as a Spammy Structured
 * Markup policy violation (manual-action risk). The only compliant rating
 * surface is the Product node, which is sourced from real WooCommerce reviews.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.22.3
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-restaurant.php';

final class RestaurantSchemaNoSelfRatingTest extends TestCase {

	/**
	 * Fully-populated install fixtures, INCLUDING fabricated social-proof
	 * rating + count. The whole point of this test is to prove that even when
	 * those decorative theme_mods are set, no aggregateRating is emitted.
	 */
	private const FIXTURES = array(
		'lafka_business_name'        => 'Acme Test Cafe',
		'lafka_business_street'      => '123 Test Street',
		'lafka_business_city'        => 'Testville',
		'lafka_business_region'      => 'TS',
		'lafka_business_postal'      => 'T1S 1S1',
		'lafka_business_country'     => 'CA',
		'lafka_business_phone_e164'  => '+15551234567',
		'lafka_business_geo_lat'     => '45.0',
		'lafka_business_geo_lng'     => '-75.0',
		// Fabricated marketing figures — must NOT leak into structured data.
		'lafka_social_proof_rating'  => '4.8',
		'lafka_social_proof_count'   => 1200,
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_populated_install(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return self::FIXTURES[ $key ] ?? $default;
			}
		);
		// Robust against 1- and 2-arg calls: WP's get_option() may be invoked
		// with only a key (e.g. Lafka_Order_Hours::get_schedule_display_hours_map()
		// reads `get_option( 'lafka_order_hours_options' )`). returnArg( 2 ) throws
		// in that case, so alias to "return the supplied default, else false" —
		// the same contract get_option() honours for unset options.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => $default
		);
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8891' );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
	}

	public function test_restaurant_never_emits_aggregate_rating_even_when_social_proof_set(): void {
		$this->stub_populated_install();
		$schema = lafka_schema_restaurant();

		$this->assertIsArray( $schema );
		// Sanity: the social-proof fixtures ARE set, yet no rating must surface.
		$this->assertSame( '4.8', self::FIXTURES['lafka_social_proof_rating'] );
		$this->assertArrayNotHasKey(
			'aggregateRating',
			$schema,
			'Restaurant/LocalBusiness node must never emit a self-serving aggregateRating built from the decorative social-proof theme_mods.'
		);
	}

	public function test_restaurant_schema_source_does_not_reference_social_proof_theme_mods(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-restaurant.php' );
		$this->assertNotFalse( $src );

		// Strip comments before asserting: the source documents WHY it refuses
		// to transcribe the social-proof theme_mods (and that explanation names
		// them), but the executable code must never actually read those keys or
		// emit an aggregateRating. Linting the code-only token stream keeps this
		// regression lock honest without forbidding the explanatory comment.
		$code = self::strip_php_comments( $src );

		$this->assertStringNotContainsString(
			'lafka_social_proof_rating',
			$code,
			'Restaurant schema code must not read the decorative social-proof rating theme_mod.'
		);
		$this->assertStringNotContainsString(
			'lafka_social_proof_count',
			$code,
			'Restaurant schema code must not read the decorative social-proof count theme_mod.'
		);
		$this->assertStringNotContainsString(
			'aggregateRating',
			$code,
			'Restaurant schema code must not emit an aggregateRating key.'
		);
	}

	/**
	 * Return the source with all comments (line, block and docblock) removed,
	 * so assertions target executable code rather than documentation prose.
	 */
	private static function strip_php_comments( string $src ): string {
		$code = '';
		foreach ( token_get_all( $src ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}
				$code .= $token[1];
			} else {
				$code .= $token;
			}
		}
		return $code;
	}
}
