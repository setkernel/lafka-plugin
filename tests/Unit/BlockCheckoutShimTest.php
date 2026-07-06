<?php
/**
 * BlockCheckoutShimTest — NX1-04b.
 *
 * Locks the mode-honouring block-cart shim (Lafka_Block_Cart_Shim): in classic
 * mode it rewrites unedited default block Cart/Checkout pages to the classic
 * shortcodes (saving the original for a reversible switch back); in blocks mode it
 * leaves native block pages alone and restores only pages it previously shimmed.
 * Also asserts the plugin declares cart_checkout_blocks compatibility.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Block_Cart_Shim;
use PHPUnit\Framework\TestCase;

final class BlockCheckoutShimTest extends TestCase {

	/** @var array<int,\WP_Post> */
	private array $posts = array();

	/** @var array<int,array<string,mixed>> Captured wp_update_post calls. */
	private array $updated = array();

	/** @var array<string,array<string,mixed>> post_id => meta. */
	private array $meta = array();

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once __DIR__ . '/Stubs/wp-post-stub.php';
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		if ( ! class_exists( 'Lafka_Checkout_Mode', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-checkout-mode.php';
		}
		if ( ! class_exists( 'Lafka_Block_Cart_Shim', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/compat/class-lafka-block-cart-shim.php';
		}

		$this->posts   = array();
		$this->updated = array();
		$this->meta    = array();
		$this->options = array(
			'lafka_block_cart_shim_done'   => false,
			'woocommerce_cart_page_id'     => 10,
			'woocommerce_checkout_page_id' => 11,
		);

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return $this->posts[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $arr ) {
				$this->updated[] = $arr;
				$id              = (int) $arr['ID'];
				if ( isset( $this->posts[ $id ] ) ) {
					$this->posts[ $id ]->post_content = (string) $arr['post_content'];
				}
				return $id;
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				return $this->meta[ (int) $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ (int) $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_page( int $id, string $content ): void {
		$post               = new \WP_Post();
		$post->ID           = $id;
		$post->post_type    = 'page';
		$post->post_content = $content;
		$this->posts[ $id ] = $post;
	}

	/* ----------------------------------------------------------------- *
	 *  Classic mode → rewrite to shortcodes (reversibly)
	 * ----------------------------------------------------------------- */

	public function test_classic_mode_rewrites_block_pages_to_shortcodes(): void {
		$this->options['lafka_checkout_mode'] = 'classic';
		$this->make_page( 10, "\n<!-- wp:woocommerce/cart --><!-- /wp:woocommerce/cart -->" );
		$this->make_page( 11, '<!-- wp:woocommerce/checkout --><!-- /wp:woocommerce/checkout -->' );

		Lafka_Block_Cart_Shim::maybe_swap_pages();

		$this->assertSame( '[woocommerce_cart]', $this->posts[10]->post_content );
		$this->assertSame( '[woocommerce_checkout]', $this->posts[11]->post_content );
		// Original block markup saved for a reversible switch back.
		$this->assertStringContainsString( 'wp:woocommerce/cart', $this->meta[10]['_lafka_shim_original_content'] );
		$this->assertNotFalse( $this->options['lafka_block_cart_shim_done'] );
	}

	public function test_classic_mode_leaves_customised_pages_alone(): void {
		$this->options['lafka_checkout_mode'] = 'classic';
		// No WC cart block marker at all → operator content, must be left untouched.
		$this->make_page( 10, '<p>My custom cart</p>' );
		$this->make_page( 11, '<!-- wp:woocommerce/checkout --><!-- /wp:woocommerce/checkout -->' );

		Lafka_Block_Cart_Shim::maybe_swap_pages();

		$this->assertStringContainsString( 'My custom cart', $this->posts[10]->post_content );
		$this->assertSame( '[woocommerce_checkout]', $this->posts[11]->post_content );
	}

	/* ----------------------------------------------------------------- *
	 *  Blocks mode → leave native pages alone, restore only shimmed ones
	 * ----------------------------------------------------------------- */

	public function test_blocks_mode_leaves_native_block_pages_alone(): void {
		$this->options['lafka_checkout_mode'] = 'blocks';
		$this->make_page( 10, '<!-- wp:woocommerce/cart --><!-- /wp:woocommerce/cart -->' );
		$this->make_page( 11, '<!-- wp:woocommerce/checkout --><!-- /wp:woocommerce/checkout -->' );

		Lafka_Block_Cart_Shim::maybe_swap_pages();

		$this->assertSame( array(), $this->updated, 'Blocks mode must not touch native block pages.' );
	}

	public function test_blocks_mode_restores_previously_shimmed_page(): void {
		$this->options['lafka_checkout_mode'] = 'blocks';
		$original = '<!-- wp:woocommerce/checkout --><!-- /wp:woocommerce/checkout -->';
		$this->make_page( 11, '[woocommerce_checkout]' );
		$this->meta[11]['_lafka_shim_original_content'] = $original;

		Lafka_Block_Cart_Shim::maybe_swap_pages();

		$this->assertSame( $original, $this->posts[11]->post_content );
		$this->assertArrayNotHasKey( '_lafka_shim_original_content', $this->meta[11] ?? array() );
	}

	public function test_blocks_mode_does_not_restore_without_saved_original(): void {
		$this->options['lafka_checkout_mode'] = 'blocks';
		// Shortcode content but NO saved original (operator hand-authored it).
		$this->make_page( 11, '[woocommerce_checkout]' );

		Lafka_Block_Cart_Shim::maybe_swap_pages();

		$this->assertSame( array(), $this->updated );
	}

	/* ----------------------------------------------------------------- *
	 *  Guards
	 * ----------------------------------------------------------------- */

	public function test_done_flag_short_circuits(): void {
		$this->options['lafka_checkout_mode']       = 'classic';
		$this->options['lafka_block_cart_shim_done'] = 1234567890;
		$this->make_page( 10, '<!-- wp:woocommerce/cart -->' );

		Lafka_Block_Cart_Shim::maybe_swap_pages();

		$this->assertSame( array(), $this->updated );
	}

	public function test_reset_clears_the_done_flag(): void {
		$this->options['lafka_block_cart_shim_done'] = 99;
		Lafka_Block_Cart_Shim::reset();
		$this->assertArrayNotHasKey( 'lafka_block_cart_shim_done', $this->options );
	}

	/* ----------------------------------------------------------------- *
	 *  Compatibility declaration
	 * ----------------------------------------------------------------- */

	public function test_plugin_declares_cart_checkout_blocks_compat(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertMatchesRegularExpression(
			"/declare_compatibility\(\s*'cart_checkout_blocks',\s*__FILE__,\s*true\s*\)/",
			$src,
			'lafka-plugin.php must declare cart_checkout_blocks compatibility.'
		);
		// Declared right next to the HPOS declaration.
		$this->assertStringContainsString( "declare_compatibility( 'custom_order_tables', __FILE__, true )", $src );
	}
}
