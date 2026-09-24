<?php
/**
 * Per-post meta description meta box: registered on posts, pages and (when
 * WooCommerce is active) products; saved only with a valid nonce and the
 * edit_post capability, as single-line text under the key the resolver reads.
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
}
