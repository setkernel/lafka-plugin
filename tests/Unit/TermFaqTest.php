<?php
/**
 * GX3 per-category FAQ (incl/seo/lafka-term-faq.php + lafka_schema_faq()):
 *
 *   - only complete Q/A pairs are kept (repeater-lite blanks dropped), capped;
 *   - saving needs the core term-form nonce + manage_product_terms, and only
 *     acts when the repeater was on the submitted form; clearing all rows
 *     deletes the meta; answers keep basic HTML (kses), questions are text;
 *   - FAQPage JSON-LD is emitted on a category archive only when its FAQ is
 *     filled.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	require_once __DIR__ . '/Stubs/wp-term-stub.php';
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class TermFaqTest extends TestCase {

		/** @var array<int, array<string, mixed>> term_id => meta */
		private array $meta = array();

		/** @var list<array> */
		private array $writes = array();

		private bool $can = true;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$_POST        = array();
			$this->meta   = array();
			$this->writes = array();
			$this->can    = true;

			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'get_term_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
			Functions\when( 'update_term_meta' )->alias(
				function ( $id, $key, $value ) {
					$this->writes[]           = array( 'update', $id, $key, $value );
					$this->meta[ $id ][ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_term_meta' )->alias(
				function ( $id, $key ) {
					$this->writes[] = array( 'delete', $id, $key );
					unset( $this->meta[ $id ][ $key ] );
					return true;
				}
			);
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'do_action' )->justReturn( null );
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'sanitize_key' )->returnArg();
			Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
			Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => strip_tags( (string) $v, '<a><strong><em>' ) );
			Functions\when( 'current_user_can' )->alias( fn( $cap ) => $this->can && 'manage_product_terms' === $cap );
			Functions\when( 'wp_verify_nonce' )->alias( static fn( $nonce, $action ) => 'nonce:' . $action === $nonce ? 1 : false );

			require_once dirname( __DIR__, 2 ) . '/incl/admin/lafka-term-form-nonce.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-term-faq.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-faq.php';
		}

		protected function tearDown(): void {
			$_POST = array();
			Monkey\tearDown();
			parent::tearDown();
		}

		/** @param array<int, array{q:string,a:string}> $rows */
		private function post_edit_form( int $term_id, array $rows ): void {
			$_POST = array(
				'action'                 => 'editedtag',
				'_wpnonce'               => 'nonce:update-tag_' . $term_id,
				'lafka_term_faq_present' => '1',
				'lafka_term_faq'         => $rows,
			);
		}

		public function test_only_complete_pairs_are_saved_with_safe_markup(): void {
			$this->post_edit_form(
				7,
				array(
					array( 'q' => ' Is the sauce made in-house? <b>', 'a' => 'Yes — <strong>daily</strong>.<script>x()</script>' ),
					array( 'q' => 'Half-filled', 'a' => '' ),
					array( 'q' => '', 'a' => '' ),
				)
			);
			lafka_term_faq_save( 7 );

			self::assertSame(
				array( array( 'q' => 'Is the sauce made in-house?', 'a' => 'Yes — <strong>daily</strong>.x()' ) ),
				lafka_seo_get_term_faqs( 7 )
			);
		}

		public function test_clearing_every_row_deletes_the_meta(): void {
			$this->meta[7]['_lafka_term_faqs'] = array( array( 'q' => 'Q', 'a' => 'A' ) );
			$this->post_edit_form( 7, array( array( 'q' => '', 'a' => '' ) ) );
			lafka_term_faq_save( 7 );
			self::assertSame( array( array( 'delete', 7, '_lafka_term_faqs' ) ), $this->writes );
			self::assertSame( array(), lafka_seo_get_term_faqs( 7 ) );
		}

		public function test_nothing_is_saved_without_nonce_capability_or_the_form(): void {
			$this->post_edit_form( 7, array( array( 'q' => 'Q', 'a' => 'A' ) ) );
			$_POST['_wpnonce'] = 'forged';
			lafka_term_faq_save( 7 );

			$this->post_edit_form( 7, array( array( 'q' => 'Q', 'a' => 'A' ) ) );
			$this->can = false;
			lafka_term_faq_save( 7 );

			$this->can = true;
			$this->post_edit_form( 7, array( array( 'q' => 'Q', 'a' => 'A' ) ) );
			unset( $_POST['lafka_term_faq_present'] );
			lafka_term_faq_save( 7 );

			self::assertSame( array(), $this->writes );
		}

		public function test_pairs_are_capped(): void {
			$rows = array();
			for ( $i = 0; $i < 30; $i++ ) {
				$rows[] = array( 'q' => 'Q' . $i, 'a' => 'A' . $i );
			}
			self::assertCount( LAFKA_TERM_FAQ_MAX, lafka_seo_normalize_faqs( $rows ) );
		}

		public function test_category_archive_emits_faqpage_only_when_filled(): void {
			Functions\when( 'is_page' )->justReturn( false );
			Functions\when( 'is_product_category' )->justReturn( true );
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
			Functions\when( 'get_queried_object' )->justReturn( new \WP_Term( array( 'term_id' => 7 ) ) );

			self::assertNull( lafka_schema_faq() );

			$this->meta[7]['_lafka_term_faqs'] = array( array( 'q' => 'Vegan options?', 'a' => 'Yes, <em>two</em> pizzas.' ) );
			self::assertSame(
				array(
					'@type'      => 'FAQPage',
					'mainEntity' => array(
						array(
							'@type'          => 'Question',
							'name'           => 'Vegan options?',
							'acceptedAnswer' => array(
								'@type' => 'Answer',
								'text'  => 'Yes, two pizzas.',
							),
						),
					),
				),
				lafka_schema_faq()
			);
		}
	}
}
