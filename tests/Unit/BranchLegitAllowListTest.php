<?php
/**
 * BranchLegitAllowListTest — locks down audit f078.
 *
 * select_branch() already constrains the posted id to the lafka_branch_location
 * taxonomy (audit f062). f078 adds defense in depth: even a genuine branch-location
 * term must be an *orderable* branch. The render/show paths only ever expose ids
 * returned by Lafka_Shipping_Areas::get_all_legit_branch_locations() (terms that
 * carry a geocoded address), so a term that exists in the taxonomy but is NOT in
 * that allow-list (e.g. a half-configured branch with no address) must be rejected
 * before it is written to the WC session and later persisted to order meta.
 *
 * This test pins:
 *   - a valid-taxonomy term that is NOT in the legit allow-list is rejected
 *     ('no_branch'), even when it clears the taxonomy and order-type gates, and
 *   - a valid-taxonomy term that IS in the legit allow-list clears the gate and
 *     the handler proceeds to success.
 *
 * @package Lafka_Plugin
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

namespace Lafka\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Branch_Locations;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;
	use stdClass;
	use WP_Error;

	final class BranchLegitAllowListTest extends TestCase {

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
			// wp_send_json_error / wp_send_json_success both halt the real handler
			// (wp_die). Surface them as exceptions so the test can assert exactly
			// which gate fired without booting WordPress.
			Functions\when( 'wp_send_json_error' )->alias(
				static function ( $error = null ) {
					$code = ( $error instanceof WP_Error ) ? $error->code : 'unknown';
					throw new RuntimeException( $code );
				}
			);
			Functions\when( 'wp_send_json_success' )->alias(
				static function () {
					throw new RuntimeException( '__success__' );
				}
			);
			// init() runs at file-include time and calls get_option(); a default
			// empty array makes both the include and get_order_type() resolve to
			// the site-wide 'delivery_pickup' default.
			Functions\when( 'get_option' )->justReturn( array() );
			// Branch capability meta — empty falls back to 'delivery_pickup', so a
			// pickup request clears the order-type allow-list (audit f012) and we
			// reach the f078 legit-branch check.
			Functions\when( 'get_term_meta' )->justReturn( '' );
			// No session/customer/cart objects → every isset() guard in the tail of
			// select_branch() is false and the handler runs to wp_send_json_success.
			Functions\when( 'WC' )->justReturn( new stdClass() );

			if ( ! class_exists( 'Lafka_Branch_Locations', false ) ) {
				require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
			}
			if ( ! class_exists( 'Lafka_Shipping_Areas', false ) ) {
				require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
			}

			unset( $_POST['fields'] );
		}

		protected function tearDown(): void {
			unset( $_POST['fields'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * get_term() returns a genuine lafka_branch_location term for any id, and
		 * get_terms() (the engine behind get_all_legit_branch_locations()) returns
		 * the supplied allow-list keyed by branch id.
		 *
		 * @param array<int, string> $legit_branches Allow-listed id => name map.
		 */
		private function stub_branch_world( array $legit_branches ): void {
			Functions\when( 'get_term' )->alias(
				static function ( $term_id, $taxonomy = '' ) {
					$term           = new stdClass();
					$term->term_id  = (int) $term_id;
					$term->taxonomy = 'lafka_branch_location';
					return $term;
				}
			);
			Functions\when( 'get_terms' )->justReturn( $legit_branches );
		}

		/**
		 * @param array<string, mixed> $fields
		 */
		private function post_fields( array $fields ): void {
			$_POST['fields'] = http_build_query( $fields );
		}

		public function test_valid_taxonomy_term_outside_legit_allow_list_is_rejected(): void {
			// Term 999 is a real branch-location term and pickup is allowed by the
			// branch/site, but 999 is NOT in the orderable allow-list, so f078 must
			// reject it before it can be stored in the session.
			$this->stub_branch_world( array( 5 => 'Downtown' ) );
			$this->post_fields(
				array(
					'lafka_branch_select'     => 999,
					'lafka_branch_order_type' => 'pickup',
				)
			);

			try {
				Lafka_Branch_Locations::select_branch();
				$this->fail( 'select_branch() must reject a non-legit branch id.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame(
					'no_branch',
					$e->getMessage(),
					'A valid-taxonomy term outside the legit allow-list must be rejected.'
				);
			}
		}

		public function test_legit_branch_clears_the_allow_list_gate(): void {
			// Term 5 is both a real branch-location term and an orderable branch, so
			// it must clear the f078 gate and the handler proceeds to success.
			$this->stub_branch_world( array( 5 => 'Downtown' ) );
			$this->post_fields(
				array(
					'lafka_branch_select'     => 5,
					'lafka_branch_order_type' => 'pickup',
				)
			);

			try {
				Lafka_Branch_Locations::select_branch();
				$this->fail( 'select_branch() should reach wp_send_json_success.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame(
					'__success__',
					$e->getMessage(),
					'A legit branch id must clear the allow-list gate.'
				);
			}
		}
	}
}
