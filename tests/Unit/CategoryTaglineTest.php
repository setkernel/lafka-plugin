<?php
/**
 * GX4 category tagline (incl/woocommerce/lafka-category-tagline.php): one
 * short plain-text line under a menu category heading ("what comes on it"),
 * stored as term meta `lafka_tagline` on product_cat.
 *
 *   - saved from the core Add/Edit category forms only with the term-form
 *     nonce and manage_product_terms, and only when the field was on the form;
 *   - plain text, at most 140 characters; empty deletes the meta;
 *   - registered term meta (single string, in REST for editors);
 *   - lafka_get_category_tagline() reads it (filterable), '' when unset.
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
	use LafkaPlugin\Tests\Unit\Support\Hooks;
	use PHPUnit\Framework\TestCase;
	use WP_Term;

	require_once __DIR__ . '/Support/Hooks.php';

	final class CategoryTaglineTest extends TestCase {

		/** @var array<int, array<string, mixed>> */
		private array $meta = array();

		private bool $can = true;

		private int $changed = 0;

		/** @var array<string, array> */
		private array $registered_meta = array();

		/** @var array<string, callable> */
		private array $filters = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$_POST                 = array();
			$this->meta            = array();
			$this->can             = true;
			$this->changed         = 0;
			$this->registered_meta = array();
			$this->filters         = array();

			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'get_term_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
			Functions\when( 'update_term_meta' )->alias(
				function ( $id, $key, $value ) {
					$this->meta[ $id ][ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_term_meta' )->alias(
				function ( $id, $key ) {
					unset( $this->meta[ $id ][ $key ] );
					return true;
				}
			);
			Functions\when( 'do_action' )->alias(
				function ( $hook ) {
					if ( 'lafka_menu_data_changed' === $hook ) {
						++$this->changed;
					}
				}
			);
			Functions\when( 'apply_filters' )->alias(
				fn( $hook, $value, ...$args ) => isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value
			);
			Functions\when( 'register_term_meta' )->alias(
				function ( $taxonomy, $key, $args ) {
					$this->registered_meta[ $taxonomy . '|' . $key ] = $args;
					return true;
				}
			);
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'sanitize_key' )->returnArg();
			Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) ) );
			Functions\when( 'current_user_can' )->alias( fn( $cap ) => $this->can && 'manage_product_terms' === $cap );
			Functions\when( 'wp_verify_nonce' )->alias( static fn( $nonce, $action ) => 'nonce:' . $action === $nonce ? 1 : false );
			Functions\when( '__' )->returnArg();

			require_once dirname( __DIR__, 2 ) . '/incl/admin/lafka-term-form-nonce.php';
			require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-category-tagline.php';
		}

		protected function tearDown(): void {
			$_POST = array();
			Monkey\tearDown();
			parent::tearDown();
		}

		private function edit_form( int $term_id, string $tagline ): void {
			$_POST = array(
				'action'                => 'editedtag',
				'_wpnonce'              => 'nonce:update-tag_' . $term_id,
				'lafka_tagline_present' => '1',
				'lafka_tagline'         => $tagline,
			);
		}

		public function test_saves_plain_text_from_the_edit_form_and_signals_menu_data_changed(): void {
			$this->edit_form( 5, '  Clear-coated <b>fries</b>, curds and gravy.  ' );

			lafka_category_tagline_save( 5 );

			self::assertSame( 'Clear-coated fries, curds and gravy.', $this->meta[5]['lafka_tagline'] );
			self::assertSame( 1, $this->changed );
		}

		public function test_saves_from_the_add_form_too(): void {
			$_POST = array(
				'action'                => 'add-tag',
				'_wpnonce_add-tag'      => 'nonce:add-tag',
				'lafka_tagline_present' => '1',
				'lafka_tagline'         => 'Hand-stretched and baked to order.',
			);

			lafka_category_tagline_save( 9 );

			self::assertSame( 'Hand-stretched and baked to order.', $this->meta[9]['lafka_tagline'] );
		}

		public function test_caps_at_140_characters(): void {
			$this->edit_form( 5, str_repeat( 'é', 200 ) );

			lafka_category_tagline_save( 5 );

			self::assertSame( 140, mb_strlen( $this->meta[5]['lafka_tagline'] ) );
		}

		public function test_empty_clears_the_line(): void {
			$this->meta[5]['lafka_tagline'] = 'Old line';
			$this->edit_form( 5, '   ' );

			lafka_category_tagline_save( 5 );

			self::assertArrayNotHasKey( 'lafka_tagline', $this->meta[5] );
		}

		public function test_no_nonce_no_capability_or_no_field_changes_nothing(): void {
			$this->meta[5]['lafka_tagline'] = 'Kept';

			$this->edit_form( 5, 'Forged' );
			$_POST['_wpnonce'] = 'nonce:update-tag_6';
			lafka_category_tagline_save( 5 );
			self::assertSame( 'Kept', $this->meta[5]['lafka_tagline'] );

			$this->edit_form( 5, 'Forged' );
			$this->can = false;
			lafka_category_tagline_save( 5 );
			self::assertSame( 'Kept', $this->meta[5]['lafka_tagline'] );

			$this->can = true;
			$this->edit_form( 5, '' );
			unset( $_POST['lafka_tagline_present'] );
			lafka_category_tagline_save( 5 );
			self::assertSame( 'Kept', $this->meta[5]['lafka_tagline'], 'A form without the field (quick edit) leaves it alone.' );
			self::assertSame( 0, $this->changed );
		}

		public function test_reads_the_line_for_a_term_or_id_and_is_filterable(): void {
			$this->meta[5]['lafka_tagline'] = 'Clear-coated fries.';
			$term                           = new WP_Term(
				array(
					'term_id'  => 5,
					'taxonomy' => 'product_cat',
				)
			);

			self::assertSame( 'Clear-coated fries.', lafka_get_category_tagline( $term ) );
			self::assertSame( 'Clear-coated fries.', lafka_get_category_tagline( 5 ) );
			self::assertSame( '', lafka_get_category_tagline( 6 ) );

			$this->filters['lafka_category_tagline_meta'] = static fn( $line, $id ) => 6 === $id ? 'From a filter' : $line;
			self::assertSame( 'From a filter', lafka_get_category_tagline( 6 ) );
		}

		public function test_registers_single_string_term_meta_for_rest_editors(): void {
			lafka_category_tagline_register_meta();

			$args = $this->registered_meta['product_cat|lafka_tagline'];
			self::assertSame( 'string', $args['type'] );
			self::assertTrue( $args['single'] );
			self::assertTrue( $args['show_in_rest'] );
			self::assertSame( 'Clear-coated fries.', ( $args['sanitize_callback'] )( ' Clear-coated <i>fries</i>. ' ) );
			self::assertTrue( ( $args['auth_callback'] )() );
			$this->can = false;
			self::assertFalse( ( $args['auth_callback'] )() );
		}

		public function test_wiring(): void {
			Hooks::reset();
			lafka_category_tagline_init();
			$registered = Hooks::registered();

			self::assertContains( 'init -> lafka_category_tagline_register_meta', $registered );
			self::assertContains( 'created_product_cat -> lafka_category_tagline_save', $registered );
			self::assertContains( 'edited_product_cat -> lafka_category_tagline_save', $registered );
		}
	}
}
