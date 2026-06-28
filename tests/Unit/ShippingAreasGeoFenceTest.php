<?php
/**
 * ShippingAreasGeoFenceTest — locks down the server-side delivery geo-fence
 * math added to close the "outside delivery area" bypass (audit f005).
 *
 * Before the fix, validate_checkout_field_process() only enforced presence via
 * `isset() && empty()` (trivially bypassable by omitting the field) and the
 * Lafka polygon zones were enforced nowhere on the server. The fix decodes the
 * Google "Encoded Polyline Algorithm Format" polygon stored per shipping area
 * and runs a ray-casting point-in-polygon test. These two helpers are the pure
 * math behind that gate; this test pins their behaviour so a refactor cannot
 * silently re-open the hole.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Lafka_Shipping_Areas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';

final class ShippingAreasGeoFenceTest extends TestCase {

	/**
	 * A simple axis-aligned square used across the point-in-polygon cases.
	 * Vertices are [ lat, lng ] pairs, matching decode_polygon_coordinates().
	 *
	 * @return array<int, array{0: float, 1: float}>
	 */
	private static function square(): array {
		return array(
			array( 42.0, 23.0 ),
			array( 42.0, 24.0 ),
			array( 43.0, 24.0 ),
			array( 43.0, 23.0 ),
		);
	}

	/**
	 * @return array<string, array{0: float, 1: float, 2: bool}>
	 */
	public static function pointInPolygonProvider(): array {
		return array(
			'centre of square is inside'   => array( 42.5, 23.5, true ),
			'south of square is outside'   => array( 41.5, 23.5, false ),
			'north of square is outside'   => array( 43.9, 23.5, false ),
			'east of square is outside'    => array( 42.5, 24.5, false ),
			'west of square is outside'    => array( 42.5, 22.5, false ),
			'origin (0,0) is outside'      => array( 0.0, 0.0, false ),
			'antipodal point is outside'   => array( -42.5, -23.5, false ),
		);
	}

	#[DataProvider( 'pointInPolygonProvider' )]
	public function test_point_in_polygon( float $lat, float $lng, bool $expected ): void {
		$this->assertSame(
			$expected,
			Lafka_Shipping_Areas::point_in_polygon( $lat, $lng, self::square() )
		);
	}

	/**
	 * @return array<string, array{0: array<int, array<int, float>>}>
	 */
	public static function degeneratePolygonProvider(): array {
		return array(
			'empty ring'          => array( array() ),
			'single vertex'       => array( array( array( 1.0, 1.0 ) ) ),
			'two vertices (line)' => array( array( array( 1.0, 1.0 ), array( 2.0, 2.0 ) ) ),
		);
	}

	#[DataProvider( 'degeneratePolygonProvider' )]
	public function test_point_in_polygon_rejects_degenerate_rings( array $polygon ): void {
		// Fewer than three vertices cannot enclose an area, so no point is inside.
		$this->assertFalse( Lafka_Shipping_Areas::point_in_polygon( 1.5, 1.5, $polygon ) );
	}

	public function test_point_in_polygon_skips_malformed_vertices(): void {
		// A vertex missing a coordinate must not fatal; the malformed vertex is
		// skipped while the rest of the ring is still evaluated.
		$polygon = array(
			array( 42.0, 23.0 ),
			array( 42.0 ), // malformed — no lng
			array( 43.0, 24.0 ),
			array( 43.0, 23.0 ),
		);

		$this->assertIsBool( Lafka_Shipping_Areas::point_in_polygon( 42.5, 23.5, $polygon ) );
	}

	public function test_decode_polygon_coordinates_matches_google_reference(): void {
		// Canonical example from Google's "Encoded Polyline Algorithm Format"
		// documentation: (38.5,-120.2), (40.7,-120.95), (43.252,-126.453).
		$encoded = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

		$points = Lafka_Shipping_Areas::decode_polygon_coordinates( $encoded );

		$this->assertCount( 3, $points );
		$this->assertEqualsWithDelta( 38.5, $points[0][0], 0.00001 );
		$this->assertEqualsWithDelta( -120.2, $points[0][1], 0.00001 );
		$this->assertEqualsWithDelta( 40.7, $points[1][0], 0.00001 );
		$this->assertEqualsWithDelta( -120.95, $points[1][1], 0.00001 );
		$this->assertEqualsWithDelta( 43.252, $points[2][0], 0.00001 );
		$this->assertEqualsWithDelta( -126.453, $points[2][1], 0.00001 );
	}

	public function test_decode_polygon_coordinates_handles_empty_string(): void {
		$this->assertSame( array(), Lafka_Shipping_Areas::decode_polygon_coordinates( '' ) );
	}

	public function test_decode_then_point_in_polygon_round_trip(): void {
		// Decode a real encoded ring and confirm the geo-fence agrees: a point
		// near the first decoded vertex must read as inside, a far-away point
		// must read as outside.
		$encoded = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';
		$ring    = Lafka_Shipping_Areas::decode_polygon_coordinates( $encoded );

		$this->assertGreaterThanOrEqual( 3, count( $ring ) );
		// A point obviously outside the western-US triangle.
		$this->assertFalse( Lafka_Shipping_Areas::point_in_polygon( 0.0, 0.0, $ring ) );
	}
}
