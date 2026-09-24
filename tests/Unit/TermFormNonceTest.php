<?php
/**
 * Lafka term fields entered on the core "Add New" term form must save: that
 * form posts its nonce as `_wpnonce_add-tag`, the edit form as `_wpnonce`.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class TermFormNonceTest extends TestCase {

	/** @var array<int, array{0: int, 1: string, 2: mixed}> */
	private array $saved = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST = array();
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		// A nonce is valid only for the action it was minted for.
		Functions\when( 'wp_verify_nonce' )->alias( static fn( $nonce, $action ) => 'nonce:' . $action === $nonce ? 1 : false );
		Functions\when( 'update_term_meta' )->alias(
			function ( $term_id, $key, $value ) {
				$this->saved[] = array( $term_id, $key, $value );
				return true;
			}
		);
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce-metaboxes.php';
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_add_new_term_form_fields_are_saved(): void {
		$_POST = array(
			'action'                  => 'add-tag',
			'_wpnonce_add-tag'        => 'nonce:add-tag',
			'lafka_term_header_style' => 'dark',
		);

		lafka_woocommerce_custom_cat_fields_save( 12, 0, 'product_cat' );

		$this->assertContains( array( 12, 'lafka_term_header_style', 'dark' ), $this->saved );
	}

	public function test_edit_term_form_fields_are_saved(): void {
		$_POST = array(
			'action'                  => 'editedtag',
			'_wpnonce'                => 'nonce:update-tag_12',
			'lafka_term_header_style' => 'light',
		);

		lafka_woocommerce_custom_cat_fields_save( 12, 0, 'product_cat' );

		$this->assertContains( array( 12, 'lafka_term_header_style', 'light' ), $this->saved );
	}

	public function test_missing_or_foreign_nonce_saves_nothing(): void {
		foreach ( array(
			array( 'action' => 'add-tag' ),
			array( 'action' => 'add-tag', '_wpnonce' => 'nonce:add-tag' ),
			array( 'action' => 'editedtag', '_wpnonce' => 'nonce:update-tag_99' ),
		) as $post ) {
			$_POST = $post + array( 'lafka_term_header_style' => 'dark' );
			lafka_woocommerce_custom_cat_fields_save( 12, 0, 'product_cat' );
		}

		$this->assertSame( array(), $this->saved );
	}
}
