<?php
/**
 * BranchTermTaxonomyConstraintTest — locks down audit f062.
 *
 * Before the fix, select_branch() validated the posted branch id with
 * get_term( $id ) — NO taxonomy argument — so a term id from ANY taxonomy
 * (product_cat, post_tag, …) slipped past the "no such branch location" gate
 * and was stored as the session branch_id. Every downstream branch-scoped read
 * then used get_term( $id, 'lafka_branch_location' ) / get_term_meta and quietly
 * returned empty (blank branch name, no override schedule), silently degrading
 * branch behaviour instead of erroring cleanly.
 *
 * The fix constrains the lookup to the lafka_branch_location taxonomy (and
 * re-asserts the returned term's taxonomy). This test pins that:
 *   - a foreign-taxonomy id is rejected with 'no_branch',
 *   - a non-existent id is rejected with 'no_branch',
 *   - get_term() is queried WITH the 'lafka_branch_location' taxonomy, and
 *   - a genuine branch term clears the no_branch gate.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	// WP_Error is referenced as `\WP_Error` from the un-namespaced plugin source.
	// Guard against another test file having already declared it; we only rely on
	// the public ->code property below, which every stub variant exposes.
	if ( ! class_exists( '\WP_Error' ) ) {
		class WP_Error { // phpcs:ignore
			public string $code;
			public string $message;
			/** @var array */
			public array $data;
			public function __construct( $code = '', $message = '', $data = array() ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
				$this->data    = is_array( $data ) ? $data : array();
			}
			public function get_error_code() {
				return $this->code;
			}
			public function get_error_message() {
				return $this->message;
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Branch_Locations;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;
	use stdClass;
	use WP_Error;

	final class BranchTermTaxonomyConstraintTest extends TestCase {

		/** @var string|null Taxonomy that get_term() was last queried with. */
		private $captured_taxonomy = null;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'sanitize_text_field' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\when( 'is_wp_error' )->alias(
				static function ( $thing ) {
					return $thing instanceof WP_Error;
				}
			);
			// wp_send_json_error halts the real handler (wp_die); surface the
			// WP_Error code as an exception so the test can assert which gate
			// fired without booting WordPress.
			Functions\when( 'wp_send_json_error' )->alias(
				static function ( $error = null ) {
					$code = ( $error instanceof WP_Error ) ? $error->code : 'unknown';
					throw new RuntimeException( $code );
				}
			);
			// init() runs at file-include time and calls get_option(); stub it
			// empty so the include is side-effect free.
			Functions\when( 'get_option' )->justReturn( array() );

			if ( ! class_exists( 'Lafka_Branch_Locations', false ) ) {
				require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
			}

			$this->captured_taxonomy = null;
			unset( $_POST['fields'] );
		}

		protected function tearDown(): void {
			unset( $_POST['fields'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * Emulate WP core get_term(): a term is only returned when queried with
		 * the taxonomy it actually belongs to; a taxonomy mismatch yields null.
		 *
		 * @param string $actual_taxonomy The taxonomy the term really lives in.
		 */
		private function stub_get_term_in_taxonomy( string $actual_taxonomy ): void {
			Functions\when( 'get_term' )->alias(
				function ( $term_id, $taxonomy = '' ) use ( $actual_taxonomy ) {
					$this->captured_taxonomy = $taxonomy;
					if ( '' !== $taxonomy && $taxonomy !== $actual_taxonomy ) {
						return null; // core returns null for an id outside $taxonomy
					}
					$term           = new stdClass();
					$term->term_id  = (int) $term_id;
					$term->taxonomy = $actual_taxonomy;
					return $term;
				}
			);
		}

		/**
		 * @param array<string, mixed> $fields
		 */
		private function post_fields( array $fields ): void {
			$_POST['fields'] = http_build_query( $fields );
		}

		public function test_rejects_term_from_foreign_taxonomy(): void {
			// Term 123 really belongs to product_cat. Pre-fix select_branch()
			// called get_term($id) with no taxonomy and accepted it; the fix
			// queries the branch taxonomy, gets null back, and rejects cleanly.
			$this->stub_get_term_in_taxonomy( 'product_cat' );
			$this->post_fields(
				array(
					'lafka_branch_select'     => 123,
					'lafka_branch_order_type' => 'pickup',
				)
			);

			try {
				Lafka_Branch_Locations::select_branch();
				$this->fail( 'select_branch() should reject a foreign-taxonomy id.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'no_branch', $e->getMessage() );
			}

			$this->assertSame(
				'lafka_branch_location',
				$this->captured_taxonomy,
				'get_term() must be constrained to the lafka_branch_location taxonomy.'
			);
		}

		public function test_rejects_nonexistent_term(): void {
			Functions\when( 'get_term' )->alias(
				function ( $term_id, $taxonomy = '' ) {
					$this->captured_taxonomy = $taxonomy;
					return null;
				}
			);
			$this->post_fields(
				array(
					'lafka_branch_select'     => 999,
					'lafka_branch_order_type' => 'pickup',
				)
			);

			try {
				Lafka_Branch_Locations::select_branch();
				$this->fail( 'select_branch() should reject a non-existent term.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'no_branch', $e->getMessage() );
			}
		}

		public function test_genuine_branch_term_clears_the_no_branch_gate(): void {
			// Term 5 is a real lafka_branch_location term, so it must clear the
			// 'no_branch' gate. We deliberately fail the order-type allow-list
			// (branch is pickup-only, request is delivery) so the handler halts
			// with 'invalid_order_type' — proving the term itself was accepted
			// without exercising the full WC()->session path.
			$this->stub_get_term_in_taxonomy( 'lafka_branch_location' );
			Functions\when( 'get_term_meta' )->justReturn( 'pickup' );
			Functions\when( 'get_option' )->justReturn( array( 'order_type' => 'delivery_pickup' ) );
			$this->post_fields(
				array(
					'lafka_branch_select'              => 5,
					'lafka_branch_order_type'          => 'delivery',
					'lafka_branch_select_user_address' => '1 Main St',
					'lafka_user_country'               => 'US',
				)
			);

			try {
				Lafka_Branch_Locations::select_branch();
				$this->fail( 'select_branch() should halt at the order-type allow-list.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame(
					'invalid_order_type',
					$e->getMessage(),
					'A genuine branch term must clear the no_branch gate.'
				);
			}

			$this->assertSame( 'lafka_branch_location', $this->captured_taxonomy );
		}
	}
}
