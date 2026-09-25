<?php
/**
 * Per-post meta description meta box: registered on posts, pages and (when
 * WooCommerce is active) products; saved only with a valid nonce and the
 * edit_post capability, as single-line text under the key the resolver reads.
 * GX3: the same box carries the SEO title override and the noindex flag,
 * written only when the box's own form was submitted.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class MetaDescriptionBoxTest extends TestCase {

	/** @var list<array{0:string, 1:int, 2?:string}> */
	private array $writes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->writes = array();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) ) );
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn( $nonce, $action ) => 'good-nonce' === $nonce && 'lafka_meta_description_save' === $action ? 1 : false
		);
		Functions\when( 'current_user_can' )->alias( static fn( $cap, $post_id ) => 'edit_post' === $cap && 42 === $post_id );
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->writes[] = array( 'update', $post_id, $key, $value );
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				$this->writes[] = array( 'delete', $post_id, $key );
			}
		);
		require_once dirname( __DIR__, 2 ) . '/incl/admin/lafka-meta-description-box.php';
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_saves_sanitized_single_line_text_to_the_resolver_key(): void {
		$_POST = array(
			'lafka_meta_description_nonce' => 'good-nonce',
			'lafka_meta_description'       => "  Wood-fired pizza\nand <b>salads</b>  ",
		);
		lafka_meta_description_save( 42 );
		$this->assertSame( array( array( 'update', 42, '_lafka_meta_description', 'Wood-fired pizza and salads' ) ), $this->writes );
	}

	public function test_empty_value_deletes_the_override(): void {
		$_POST = array(
			'lafka_meta_description_nonce' => 'good-nonce',
			'lafka_meta_description'       => '',
		);
		lafka_meta_description_save( 42 );
		$this->assertSame( array( array( 'delete', 42, '_lafka_meta_description' ) ), $this->writes );
	}

	public function test_nothing_is_written_without_a_valid_nonce_or_capability(): void {
		$_POST = array( 'lafka_meta_description' => 'Injected' );
		lafka_meta_description_save( 42 );

		$_POST['lafka_meta_description_nonce'] = 'forged';
		lafka_meta_description_save( 42 );

		$_POST['lafka_meta_description_nonce'] = 'good-nonce';
		lafka_meta_description_save( 7 ); // Current user can't edit post 7.

		$this->assertSame( array(), $this->writes );
	}

	public function test_box_is_registered_for_products_only_when_the_post_type_exists(): void {
		$screens = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_meta_box' )->alias(
			static function ( $id, $title, $callback, $screen ) use ( &$screens ) {
				$screens[] = $screen;
			}
		);

		Functions\when( 'post_type_exists' )->justReturn( false );
		lafka_meta_description_register_box();
		$this->assertSame( array( 'post', 'page' ), $screens );

		$screens = array();
		Functions\when( 'post_type_exists' )->alias( static fn( $type ) => 'product' === $type );
		lafka_meta_description_register_box();
		$this->assertSame( array( 'post', 'page', 'product' ), $screens );
	}

	public function test_counter_script_targets_the_textarea_not_the_metabox_wrapper(): void {
		// The postbox wrapper WordPress renders carries the metabox id; the
		// field must not reuse it, or the counter reads the wrapper's .value.
		$metabox_id = null;
		Functions\when( '__' )->returnArg();
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'add_meta_box' )->alias(
			static function ( $id ) use ( &$metabox_id ) {
				$metabox_id = $id;
			}
		);
		lafka_meta_description_register_box();

		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->justReturn( null );
		Functions\when( 'esc_attr_e' )->justReturn( null );
		Functions\when( 'checked' )->justReturn( '' );
		if ( function_exists( 'lafka_resolve_meta_description' ) ) {
			// Loaded by another test; its fallback preview is not under test here.
			Functions\when( 'lafka_resolve_meta_description' )->justReturn( '' );
		}
		ob_start();
		lafka_meta_description_render_box( (object) array( 'ID' => 42 ) );
		$html = (string) ob_get_clean();

		$this->assertSame( 1, preg_match( '/<textarea\s+id="([^"]+)"/', $html, $field ) );
		$this->assertNotSame( $metabox_id, $field[1] );
		$this->assertStringContainsString( "getElementById( '{$field[1]}' )", $html );
	}

	public function test_seo_title_and_noindex_are_saved_with_the_box(): void {
		$_POST = array(
			'lafka_meta_description_nonce' => 'good-nonce',
			'lafka_meta_description'       => '',
			'lafka_seo_fields'             => '1',
			'lafka_seo_title'              => "  {title} in {city}<script>x</script> ",
			'lafka_seo_noindex'            => '1',
		);
		lafka_meta_description_save( 42 );
		$this->assertSame(
			array(
				array( 'delete', 42, '_lafka_meta_description' ),
				array( 'update', 42, '_lafka_seo_title', '{title} in {city}x' ),
				array( 'update', 42, '_lafka_seo_noindex', '1' ),
			),
			$this->writes
		);
	}

	public function test_cleared_seo_fields_are_deleted_but_only_from_this_form(): void {
		$_POST = array(
			'lafka_meta_description_nonce' => 'good-nonce',
			'lafka_meta_description'       => 'Kept',
			'lafka_seo_fields'             => '1',
			'lafka_seo_title'              => '',
		);
		lafka_meta_description_save( 42 );
		$this->assertSame(
			array(
				array( 'update', 42, '_lafka_meta_description', 'Kept' ),
				array( 'delete', 42, '_lafka_seo_title' ),
				array( 'delete', 42, '_lafka_seo_noindex' ),
			),
			$this->writes
		);
	}
}
