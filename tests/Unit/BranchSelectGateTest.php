<?php
/**
 * The branch-selection AJAX handler (select_branch) only stores a branch the
 * customer could actually order from:
 *   - f062: the id must be a lafka_branch_location term (get_term() without a
 *     taxonomy accepted product_cat / post_tag ids);
 *   - f078: the term must be an orderable (legit, geocoded) branch;
 *   - f012: the requested order type must be one the branch and site offer.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Branch_Locations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use WP_Error;

require_once __DIR__ . '/Stubs/wp-error-class.php';

final class BranchSelectGateTest extends TestCase {

	/** @var array<int, string> Taxonomy each term id really belongs to. */
	private array $terms = array(
		5   => 'lafka_branch_location', // Orderable, both order types.
		6   => 'lafka_branch_location', // Orderable, pickup only.
		7   => 'lafka_branch_location', // Branch term without a geocoded address.
		123 => 'product_cat',
	);

	/** @var string[] Taxonomies get_term() was queried with. */
	private array $queried_taxonomies = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof WP_Error );
		// Both responses end the request (wp_die); surface which one fired.
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $error = null ) {
				throw new RuntimeException( $error instanceof WP_Error ? $error->code : 'unknown' );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function () {
				throw new RuntimeException( 'success' );
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_term_meta' )->alias(
			static fn( $term_id, $key ) => 6 === (int) $term_id && 'lafka_branch_order_type' === $key ? 'pickup' : ''
		);
		// Emulates core: a term is only found when queried in its own taxonomy.
		Functions\when( 'get_term' )->alias(
			function ( $term_id, $taxonomy = '' ) {
				$this->queried_taxonomies[] = $taxonomy;
				$actual                     = $this->terms[ (int) $term_id ] ?? null;
				if ( null === $actual || ( '' !== $taxonomy && $taxonomy !== $actual ) ) {
					return null;
				}
				$term           = new stdClass();
				$term->term_id  = (int) $term_id;
				$term->taxonomy = $actual;
				return $term;
			}
		);
		Functions\when( 'get_terms' )->justReturn(
			array(
				5 => 'Downtown',
				6 => 'Harbour',
			)
		);
		// No session/customer/cart: the handler's tail skips straight to success.
		Functions\when( 'WC' )->justReturn( new stdClass() );

		require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
		require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
		unset( $_POST['fields'] );
	}

	protected function tearDown(): void {
		unset( $_POST['fields'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, array{0: array<string, mixed>, 1: string}> */
	public static function selections(): array {
		return array(
			'orderable branch'                 => array(
				array(
					'lafka_branch_select'     => 5,
					'lafka_branch_order_type' => 'pickup',
				),
				'success',
			),
			'term from another taxonomy'       => array(
				array(
					'lafka_branch_select'     => 123,
					'lafka_branch_order_type' => 'pickup',
				),
				'no_branch',
			),
			'unknown term id'                  => array(
				array(
					'lafka_branch_select'     => 999,
					'lafka_branch_order_type' => 'pickup',
				),
				'no_branch',
			),
			'branch term that is not orderable' => array(
				array(
					'lafka_branch_select'     => 7,
					'lafka_branch_order_type' => 'pickup',
				),
				'no_branch',
			),
			'delivery from a pickup-only branch' => array(
				array(
					'lafka_branch_select'              => 6,
					'lafka_branch_order_type'          => 'delivery',
					'lafka_branch_select_user_address' => '1 Example St',
					'lafka_user_country'               => 'US',
				),
				'invalid_order_type',
			),
		);
	}

	/**
	 * @param array<string, mixed> $fields Posted form fields.
	 */
	#[DataProvider( 'selections' )]
	public function test_select_branch_outcome( array $fields, string $expected ): void {
		$_POST['fields'] = http_build_query( $fields );

		try {
			Lafka_Branch_Locations::select_branch();
			$this->fail( 'select_branch() must end with a JSON response.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( $expected, $e->getMessage() );
		}
		$this->assertSame( array( 'lafka_branch_location' ), array_unique( $this->queried_taxonomies ), 'The id is looked up in the branch taxonomy only.' );
	}
}
