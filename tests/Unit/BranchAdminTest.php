<?php
/**
 * Branch-manager admin surfaces: the Orders menu badge, the orders-list
 * branch filter, and the delivery-area polygon field.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Branch_Locations_Admin;
use Lafka_Shipping_Areas_Admin;
use PHPUnit\Framework\TestCase;

final class BranchAdminTest extends TestCase {

	/** @var array<int, string> Branches returned by get_terms(). */
	private array $branches = array( 3 => 'Downtown' );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_terms' )->alias( fn() => $this->branches );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
		if ( ! class_exists( 'Lafka_Branch_Locations', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
		}
		require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations-admin.php';
		require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/includes/class-lafka-shipping-areas-admin.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_orders_badge_counts_only_orders_awaiting_the_branch(): void {
		$orders = array(
			array( 'wc-processing', '3' ),
			array( 'wc-preparing', '3' ),
			array( 'wc-completed', '3' ),
			array( 'wc-cancelled', '3' ),
			array( 'wc-processing', '9' ),
		);
		Functions\when( 'wc_get_orders' )->alias(
			static function ( $args ) use ( $orders ) {
				$branches = array_map( 'strval', $args['meta_query'][0]['value'] );
				return array_keys(
					array_filter(
						$orders,
						static fn( $o ) => in_array( $o[0], $args['status'] ?? array( $o[0] ), true ) && in_array( $o[1], $branches, true )
					)
				);
			}
		);

		$this->assertSame( 2, Lafka_Branch_Locations_Admin::menu_order_count_for_user( 99 ) );
	}

	public function test_orders_filter_offers_no_branch_dropdown_without_branches(): void {
		$this->branches = array();

		ob_start();
		Lafka_Branch_Locations_Admin::add_fields_to_orders_list_filter( 'shop_order', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'branch_location_filter', $html );
		$this->assertStringContainsString( 'order_type_filter', $html );
	}

	public function test_orders_filter_lists_the_managers_branches(): void {
		ob_start();
		Lafka_Branch_Locations_Admin::add_fields_to_orders_list_filter( 'shop_order', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Downtown', $html );
	}

	/**
	 * @param string $screen_id Current admin screen id.
	 * @return string[] Handles enqueued by both admin enqueue callbacks.
	 */
	private function admin_assets_on( string $screen_id ): array {
		$enqueued = array();
		$record   = static function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		};
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => $screen_id ) );
		Functions\when( 'wp_enqueue_style' )->alias( $record );
		Functions\when( 'wp_enqueue_script' )->alias( $record );
		Functions\when( 'wp_enqueue_media' )->alias( static fn() => $record( 'media' ) );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		Functions\when( 'wc_placeholder_img_src' )->justReturn( '' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		Lafka_Shipping_Areas_Admin::enqueue_scripts();
		Lafka_Branch_Locations_Admin::admin_enqueue_scripts();
		return $enqueued;
	}

	public function test_admin_assets_stay_off_unrelated_screens(): void {
		$this->assertSame( array(), $this->admin_assets_on( 'dashboard' ) );
		$this->assertSame( array(), $this->admin_assets_on( 'edit-post' ) );
	}

	public function test_admin_assets_load_where_their_markup_is(): void {
		$this->assertContains( 'lafka-shipping-areas-admin', $this->admin_assets_on( 'edit-shop_order' ), 'Orders-list columns are styled.' );
		$this->assertContains( 'media', $this->admin_assets_on( 'edit-lafka_branch_location' ), 'Branch image picker needs the media modal.' );
	}

	public function test_delivery_area_polygon_field_is_a_complete_element(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'abc' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		ob_start();
		Lafka_Shipping_Areas_Admin::shipping_areas_define_map_html( (object) array( 'ID' => 1 ) );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#<input type="hidden" name="lafka_shipping_area_polygon_coordinates"[^<>]*value="abc"\s*/>#', $html );
	}
}
