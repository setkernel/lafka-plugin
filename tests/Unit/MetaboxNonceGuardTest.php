<?php
/**
 * C-8: every save_post metabox handler in incl/metaboxes.php refuses to write
 * post meta unless its own nonce is present and valid and the user may edit.
 *
 * The pre-fix guard was `isset( $_POST[nonce] ) && ! wp_verify_nonce(...)`,
 * which only bailed when a nonce was present but wrong — omitting the field
 * skipped the check entirely and the save went through (CSRF).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetaboxNonceGuardTest extends TestCase {

	/** @var array<int, array{0: int, 1: string, 2: mixed}> */
	private array $writes = array();

	private bool $can_edit = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST              = array();
		$GLOBALS['pagenow'] = 'post.php';
		$this->writes       = array();
		$this->can_edit     = true;

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		// A nonce is valid only for the action it was minted for.
		Functions\when( 'wp_verify_nonce' )->alias( static fn( $nonce, $action ) => 'nonce:' . $action === $nonce ? 1 : false );
		Functions\when( 'current_user_can' )->alias( fn() => $this->can_edit );
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->writes[] = array( $post_id, $key, $value );
				return true;
			}
		);

		if ( ! defined( 'LAFKA_PLUGIN_IS_REVOLUTION' ) ) {
			define( 'LAFKA_PLUGIN_IS_REVOLUTION', false );
		}
		require_once dirname( __DIR__, 2 ) . '/incl/metaboxes.php';
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['pagenow'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $fields The metabox's form fields.
	 */
	#[DataProvider( 'save_handler_provider' )]
	public function test_meta_is_written_only_with_own_valid_nonce_and_edit_capability( string $handler, string $nonce_field, array $fields ): void {
		if ( 'lafka_save_foodmenu_postdata' === $handler && class_exists( 'Lafka_Nutrition_Config' ) ) {
			$fields += array_fill_keys( array_keys( \Lafka_Nutrition_Config::$nutrition_meta_fields ), '' );
		}
		$valid_nonce = 'nonce:' . $handler;

		$rejected = array(
			'nonce omitted'            => array( $fields, true ),
			'nonce for another action' => array( $fields + array( $nonce_field => 'nonce:some_other_action' ), true ),
			'user cannot edit'         => array( $fields + array( $nonce_field => $valid_nonce ), false ),
		);
		foreach ( $rejected as $case => $scenario ) {
			$_POST          = $scenario[0];
			$this->can_edit = $scenario[1];
			$handler( 42 );
			$this->assertSame( array(), $this->writes, "{$handler}: {$case} must not write post meta." );
		}

		$_POST          = $fields + array( $nonce_field => $valid_nonce );
		$this->can_edit = true;
		$handler( 42 );
		$this->assertNotSame( array(), $this->writes, "{$handler}: a valid request must save (proves the rejections above were the guard, not a dead path)." );
	}

	public function test_foodmenu_save_leaves_fields_the_form_did_not_send_alone(): void {
		// A request carrying only the core fields (older form, another editor)
		// must neither warn about the missing weight/nutrition keys nor blank them.
		$_POST          = array(
			'lafka_foodmenu_nonce'        => 'nonce:lafka_save_foodmenu_postdata',
			'lafka_item_single_price'     => '9',
			'lafka_item_size1'            => '',
			'lafka_item_price1'           => '',
			'lafka_item_size2'            => '',
			'lafka_item_price2'           => '',
			'lafka_item_size3'            => '',
			'lafka_item_price3'           => '',
			'lafka_ingredients'           => '',
			'lafka_allergens'             => '',
			'lafka_ext_link_button_title' => '',
			'lafka_ext_link_url'          => '',
			'lafka_add_description'       => '',
		);
		$this->can_edit = true;

		lafka_save_foodmenu_postdata( 42 );

		$keys = array_column( $this->writes, 1 );
		$this->assertContains( 'lafka_item_single_price', $keys );
		$this->assertNotContains( 'lafka_item_weight', $keys );
		$this->assertNotContains( 'lafka_item_weight_unit', $keys );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function save_handler_provider(): array {
		return array(
			'layout'               => array( 'lafka_save_layout_postdata', 'layout_nonce', array( 'lafka_layout' => 'full' ) ),
			'page options'         => array( 'lafka_save_page_options_postdata', 'page_options_nonce', array( 'lafka_top_menu' => 'main' ) ),
			'revolution slider'    => array( 'lafka_save_revolution_slider_postdata', 'lafka_revolution_slider', array( 'lafka_rev_slider' => 'home' ) ),
			'video background'     => array( 'lafka_save_video_bckgr_postdata', 'video_bckgr_nonce', array( 'lafka_video_bckgr_url' => 'https://example.test/v.mp4' ) ),
			'foodmenu'             => array(
				'lafka_save_foodmenu_postdata',
				'lafka_foodmenu_nonce',
				array(
					'lafka_item_single_price'     => '9',
					'lafka_item_weight'           => '',
					'lafka_item_weight_unit'      => '',
					'lafka_item_size1'            => '',
					'lafka_item_price1'           => '',
					'lafka_item_size2'            => '',
					'lafka_item_price2'           => '',
					'lafka_item_size3'            => '',
					'lafka_item_price3'           => '',
					'lafka_ingredients'           => '',
					'lafka_allergens'             => '',
					'lafka_ext_link_button_title' => '',
					'lafka_ext_link_url'          => '',
					'lafka_add_description'       => '',
				),
			),
			'featured images'      => array( 'lafka_save_additonal_featured_meta_postdata', 'lafka_featuredmeta', array( 'lafka_featured_imgid_1' => '7' ) ),
			'foodmenu cloud zoom'  => array( 'lafka_save_foodmenu_cz_postdata', 'foodmenu_cz_nonce', array( 'lafka_prtfl_gallery' => '1' ) ),
			'product video'        => array( 'lafka_save_product_video_postdata', 'product_video_nonce', array( 'lafka_product_video_url' => 'https://example.test/v.mp4' ) ),
			'product gallery type' => array( 'lafka_save_product_gallery_type_postdata', 'product_gallery_type_nonce', array( 'lafka_single_product_gallery_type' => 'slider' ) ),
		);
	}
}
