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
		// The social-proof figures are exposed through options too, so a
		// rating read from either store would surface. get_option() may be
		// called with a key only (order-hours map), hence the default.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => self::FIXTURES[ $key ] ?? $default
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

		$this->assertSame( 'Acme Test Cafe', $schema['name'], 'Precondition: the node is built from the populated install.' );
		$this->assertArrayNotHasKey(
			'aggregateRating',
			$schema,
			'Restaurant/LocalBusiness node must never emit a self-serving aggregateRating built from the decorative social-proof theme_mods.'
		);
	}
}
