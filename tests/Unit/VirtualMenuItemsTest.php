<?php
/**
 * Menu items marked Virtual (incl/site-health/class-lafka-virtual-menu-items.php).
 *
 * A cart holding only virtual items needs no shipping, so WooCommerce offers
 * no pickup/delivery choice, keeps the billing address required and charges
 * no delivery fee. The module:
 *
 *   - finds published products + variations with virtual=yes through the WC
 *     data store (every product type in use, legacy ones too, + variations),
 *     groups variations under their product, and skips downloadable items,
 *     items the operator marked "meant to be virtual", and anything the
 *     `lafka_virtual_ok_product_ids` / `lafka_virtual_ok_terms` filters exempt;
 *   - caches that in a transient, dropped on product save/trash/delete;
 *   - reports it in Site Health (count, ≤10 linked titles, guidance) only when
 *     the store offers pickup or delivery;
 *   - shows a one-line notice on a flagged product's edit screen, dismissible
 *     per product (nonce + capability checked) via post meta;
 *   - plans/applies `wp lafka products unvirtual` (dry run unless --yes).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Virtual_Menu_Items;
	use LafkaPlugin\Tests\Unit\Support\Hooks;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use PHPUnit\Framework\TestCase;

	/**
	 * Minimal product double for the write path.
	 */
	final class VirtualProductDouble {

		public bool $virtual = true;
		public int $saves    = 0;

		public function is_virtual(): bool {
			return $this->virtual;
		}

		public function set_virtual( $virtual ): void {
			$this->virtual = (bool) $virtual;
		}

		public function save(): int {
			++$this->saves;
			return 1;
		}
	}

	final class VirtualMenuItemsTest extends TestCase {

		/** @var array<string,mixed> Transient store. */
		private array $transients = array();

		/** @var int[] What wc_get_products() returns. */
		private array $virtual_ids = array();

		/** @var array<int,array> Every wc_get_products() call's args. */
		private array $queries = array();

		/** @var array<int,array<string,mixed>> post id => meta key => value. */
		private array $meta = array();

		/** @var array<int,int> variation id => parent id. */
		private array $parents = array();

		/** @var array<int,string> post id => status (default publish). */
		private array $statuses = array();

		/** @var array<int,array<string,string[]>> post id => taxonomy => slugs. */
		private array $terms = array();

		/** @var array<string,mixed> Filter name => forced value. */
		private array $filters = array();

		/** @var string[] Fulfilment modes the store offers. */
		private array $modes = array( 'pickup', 'delivery' );

		/** @var array<int,VirtualProductDouble> */
		private array $products = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->reset_state();

			require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-fulfilment.php';
			require_once dirname( __DIR__, 2 ) . '/incl/site-health/class-lafka-virtual-menu-items.php';

			Functions\when( 'lafka_fulfilment_modes' )->alias( fn() => $this->modes );
			Functions\when( 'wc_shipping_enabled' )->justReturn( true );
			Functions\when( 'wc_get_product_types' )->justReturn(
				array(
					'simple'   => 'Simple product',
					'grouped'  => 'Grouped product',
					'external' => 'External/Affiliate product',
					'variable' => 'Variable product',
				)
			);
			Functions\when( 'get_terms' )->justReturn( array( 'simple', 'variable', 'combo' ) );
			Functions\when( 'is_wp_error' )->justReturn( false );
			Functions\when( 'wc_get_products' )->alias(
				function ( $args ) {
					$this->queries[] = $args;
					return $this->virtual_ids;
				}
			);
			Functions\when( 'wc_get_product' )->alias( fn( $id ) => $this->products[ $id ] ?? false );
			Functions\when( 'wp_get_post_parent_id' )->alias( fn( $id ) => $this->parents[ $id ] ?? 0 );
			Functions\when( 'get_post_status' )->alias( fn( $id ) => $this->statuses[ $id ] ?? 'publish' );
			Functions\when( 'get_post_meta' )->alias( fn( $id, $key = '', $single = false ) => $this->meta[ $id ][ $key ] ?? '' );
			Functions\when( 'update_post_meta' )->alias(
				function ( $id, $key, $value ) {
					$this->meta[ $id ][ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'has_term' )->alias(
				function ( $wanted, $taxonomy, $id ) {
					return array() !== array_intersect( (array) $wanted, $this->terms[ $id ][ $taxonomy ] ?? array() );
				}
			);
			Functions\when( 'get_the_title' )->alias( static fn( $id ) => 'Item <' . $id . '>' );
			Functions\when( 'get_edit_post_link' )->alias( static fn( $id ) => 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit' );
			Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.test/wp-admin/' . $p );
			Functions\when( 'add_query_arg' )->alias(
				static fn( $args, $url ) => $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args )
			);
			Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action ) => $url . '&_wpnonce=nonce-' . $action );
			Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
			Functions\when( 'set_transient' )->alias(
				function ( $key, $value ) {
					$this->transients[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				function ( $key ) {
					unset( $this->transients[ $key ] );
					return true;
				}
			);
			Functions\when( 'get_post_type' )->alias( static fn( $id ) => $id >= 1000 ? 'page' : 'product' );
			Functions\when( 'apply_filters' )->alias(
				fn( $hook, $value ) => array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value
			);
			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === (int) $n ? $one : $many );
			Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
			Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
			Functions\when( 'esc_url' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
			Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
			Functions\when( 'wp_unslash' )->returnArg();
		}

		protected function tearDown(): void {
			$this->reset_state();
			Monkey\tearDown();
			parent::tearDown();
		}

		private function reset_state(): void {
			$this->transients  = array();
			$this->virtual_ids = array();
			$this->queries     = array();
			$this->meta        = array();
			$this->parents     = array();
			$this->statuses    = array();
			$this->terms       = array();
			$this->filters     = array();
			$this->modes       = array( 'pickup', 'delivery' );
			$this->products    = array();
			unset( $_GET['post'] );
		}

		/**
		 * A store with: a virtual simple deal (11), a variable product (20)
		 * with two virtual variations, a downloadable e-book (31), a gift card
		 * exempted by id (41), a product in an exempt category (51) and a
		 * variation whose product is a draft (61 → 60).
		 */
		private function seed_store(): void {
			$this->virtual_ids = array( 11, 21, 22, 31, 41, 51, 61 );
			$this->parents     = array(
				21 => 20,
				22 => 20,
				61 => 60,
			);
			$this->statuses    = array( 60 => 'draft' );
			$this->meta        = array( 31 => array( '_downloadable' => 'yes' ) );
			$this->terms       = array( 51 => array( 'product_cat' => array( 'e-gift' ) ) );
			$this->filters     = array(
				'lafka_virtual_ok_product_ids' => array( 41 ),
				'lafka_virtual_ok_terms'       => array( 'product_cat' => array( 'e-gift' ) ),
			);
		}

		// ── Wiring ────────────────────────────────────────────────────────

		public function test_init_wires_site_health_notice_dismissal_and_cache_busting(): void {
			Hooks::reset();
			Lafka_Virtual_Menu_Items::init();
			$registered = Hooks::registered();
			Hooks::reset();

			foreach ( array(
				'site_status_tests -> register_test',
				'admin_notices -> render_notice',
				'admin_post_' . Lafka_Virtual_Menu_Items::DISMISS_ACTION . ' -> handle_dismiss',
				'woocommerce_update_product -> flush',
				'woocommerce_update_product_variation -> flush',
				'woocommerce_new_product -> flush',
				'save_post_product -> flush',
				'trashed_post -> flush_for_post',
				'deleted_post -> flush_for_post',
			) as $expected ) {
				self::assertContains( $expected, $registered );
			}
		}

		// ── Collector ─────────────────────────────────────────────────────

		public function test_flagged_items_group_variations_and_skip_exempt_products(): void {
			$this->seed_store();

			self::assertSame(
				array(
					11 => array( 11 ),
					20 => array( 21, 22 ),
				),
				Lafka_Virtual_Menu_Items::flagged_items()
			);
		}

		public function test_query_asks_the_data_store_for_published_virtual_ids_of_every_type_in_use(): void {
			Lafka_Virtual_Menu_Items::flagged_items();

			self::assertCount( 1, $this->queries );
			$args = $this->queries[0];
			self::assertTrue( $args['virtual'] );
			self::assertSame( 'publish', $args['status'] );
			self::assertSame( 'ids', $args['return'] );
			self::assertSame( -1, $args['limit'] );
			// Registered types, a legacy type still in use, and variations.
			foreach ( array( 'simple', 'variable', 'combo', 'variation' ) as $type ) {
				self::assertContains( $type, $args['type'] );
			}
		}

		public function test_operator_marked_item_is_not_flagged(): void {
			$this->virtual_ids = array( 11, 12 );
			$this->meta        = array( 12 => array( Lafka_Virtual_Menu_Items::META_OK => '1' ) );

			self::assertSame( array( 11 => array( 11 ) ), Lafka_Virtual_Menu_Items::flagged_items() );
		}

		public function test_result_is_cached_until_a_product_is_saved(): void {
			$this->virtual_ids = array( 11 );
			Lafka_Virtual_Menu_Items::flagged_items();
			$this->virtual_ids = array( 11, 12 );

			self::assertSame( array( 11 => array( 11 ) ), Lafka_Virtual_Menu_Items::flagged_items() );
			self::assertCount( 1, $this->queries );

			Lafka_Virtual_Menu_Items::flush();
			self::assertSame(
				array(
					11 => array( 11 ),
					12 => array( 12 ),
				),
				Lafka_Virtual_Menu_Items::flagged_items()
			);
		}

		public function test_trash_or_delete_of_a_non_product_leaves_the_cache_alone(): void {
			$this->virtual_ids = array( 11 );
			Lafka_Virtual_Menu_Items::flagged_items();

			Lafka_Virtual_Menu_Items::flush_for_post( 1001 ); // a page
			self::assertNotEmpty( $this->transients );

			Lafka_Virtual_Menu_Items::flush_for_post( 11 );
			self::assertSame( array(), $this->transients );
		}

		// ── Site Health ───────────────────────────────────────────────────

		public function test_check_is_registered_only_when_the_store_offers_pickup_or_delivery(): void {
			$tests = Lafka_Virtual_Menu_Items::register_test( array( 'direct' => array() ) );
			self::assertArrayHasKey( Lafka_Virtual_Menu_Items::TEST_ID, $tests['direct'] );
			self::assertSame( 'Menu items that skip pickup and delivery', $tests['direct'][ Lafka_Virtual_Menu_Items::TEST_ID ]['label'] );

			$this->modes = array();
			self::assertSame( array( 'direct' => array() ), Lafka_Virtual_Menu_Items::register_test( array( 'direct' => array() ) ) );

			$this->filters['lafka_virtual_items_check_enabled'] = true;
			$tests = Lafka_Virtual_Menu_Items::register_test( array( 'direct' => array() ) );
			self::assertArrayHasKey( Lafka_Virtual_Menu_Items::TEST_ID, $tests['direct'] );
		}

		public function test_clean_store_is_good(): void {
			$result = Lafka_Virtual_Menu_Items::test();

			self::assertSame( 'good', $result['status'] );
			self::assertSame( Lafka_Virtual_Menu_Items::TEST_ID, $result['test'] );
		}

		public function test_flagged_items_are_recommended_with_count_links_and_guidance(): void {
			$this->seed_store();

			$result = Lafka_Virtual_Menu_Items::test();

			self::assertSame( 'recommended', $result['status'] );
			self::assertSame( 'orange', $result['badge']['color'] );
			self::assertStringContainsString( '2 menu items skip pickup and delivery', $result['label'] );
			self::assertStringContainsString( 'Untick Virtual so customers can choose pickup or delivery', $result['description'] );
			self::assertStringContainsString( '<a href="https://example.test/wp-admin/post.php?post=20&amp;action=edit">Item &lt;20&gt;</a>', $result['description'] );
			self::assertStringContainsString( 'Item &lt;11&gt;', $result['description'] );
			self::assertStringNotContainsString( 'Item <', $result['description'] );
			self::assertStringContainsString( 'wp lafka products unvirtual', $result['description'] );
		}

		public function test_long_lists_show_ten_titles_and_the_remainder(): void {
			$this->virtual_ids = range( 101, 113 );

			$result = Lafka_Virtual_Menu_Items::test();

			self::assertStringContainsString( '13 menu items skip pickup and delivery', $result['label'] );
			self::assertSame( 10, substr_count( $result['description'], '<a href=' ) );
			self::assertStringContainsString( 'Item &lt;110&gt;', $result['description'] );
			self::assertStringNotContainsString( 'Item &lt;111&gt;', $result['description'] );
			self::assertStringContainsString( '…and 3 more.', $result['description'] );
		}

		// ── Product-screen notice ─────────────────────────────────────────

		private function on_edit_screen( int $id, string $status = 'publish', bool $can_edit = true ): void {
			Functions\when( 'get_current_screen' )->justReturn(
				(object) array(
					'base'      => 'post',
					'post_type' => 'product',
				)
			);
			Functions\when( 'get_post' )->justReturn(
				(object) array(
					'ID'          => $id,
					'post_type'   => 'product',
					'post_status' => $status,
				)
			);
			Functions\when( 'current_user_can' )->justReturn( $can_edit );
		}

		private function notice(): string {
			ob_start();
			Lafka_Virtual_Menu_Items::render_notice();
			return (string) ob_get_clean();
		}

		public function test_notice_shows_on_a_flagged_published_product(): void {
			$this->seed_store();
			$this->on_edit_screen( 20 );

			$html = $this->notice();

			self::assertStringContainsString( 'notice-warning', $html );
			self::assertStringContainsString( 'Untick Virtual so customers can choose pickup or delivery', $html );
			self::assertStringContainsString( 'action=' . Lafka_Virtual_Menu_Items::DISMISS_ACTION, $html );
			self::assertStringContainsString( 'post=20', $html );
			self::assertStringContainsString( '_wpnonce=nonce-' . Lafka_Virtual_Menu_Items::DISMISS_ACTION . '_20', $html );
		}

		public function test_notice_stays_hidden_when_it_does_not_apply(): void {
			$this->seed_store();

			$this->on_edit_screen( 99 ); // not virtual.
			self::assertSame( '', $this->notice() );

			$this->on_edit_screen( 11, 'draft' ); // not published.
			self::assertSame( '', $this->notice() );

			$this->on_edit_screen( 11, 'publish', false ); // cannot edit.
			self::assertSame( '', $this->notice() );

			$this->on_edit_screen( 11 );
			$this->modes = array(); // store offers neither pickup nor delivery.
			self::assertSame( '', $this->notice() );

			$this->modes = array( 'pickup' );
			Functions\when( 'get_current_screen' )->justReturn(
				(object) array(
					'base'      => 'edit',
					'post_type' => 'product',
				)
			);
			self::assertSame( '', $this->notice() ); // product list, not the edit screen.
		}

		public function test_dismissing_marks_the_product_and_drops_it_from_site_health(): void {
			$this->seed_store();
			Lafka_Virtual_Menu_Items::flagged_items(); // warm the cache.
			$_GET['post'] = '11';
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\expect( 'check_admin_referer' )->once()->with( Lafka_Virtual_Menu_Items::DISMISS_ACTION . '_11' )->andReturn( 1 );
			Functions\when( 'wp_safe_redirect' )->alias(
				static function ( $url ) {
					throw new \RuntimeException( 'redirect:' . $url );
				}
			);

			try {
				Lafka_Virtual_Menu_Items::handle_dismiss();
				self::fail( 'Expected a redirect.' );
			} catch ( \RuntimeException $e ) {
				self::assertSame( 'redirect:https://example.test/wp-admin/post.php?post=11&action=edit', $e->getMessage() );
			}

			self::assertSame( '1', $this->meta[11][ Lafka_Virtual_Menu_Items::META_OK ] );
			self::assertSame( array( 20 => array( 21, 22 ) ), Lafka_Virtual_Menu_Items::flagged_items() );

			$this->on_edit_screen( 11 );
			self::assertSame( '', $this->notice() );
		}

		public function test_dismissing_without_permission_is_refused(): void {
			$_GET['post'] = '11';
			Functions\when( 'current_user_can' )->justReturn( false );
			Functions\when( 'wp_die' )->alias(
				static function () {
					throw new \RuntimeException( 'died' );
				}
			);

			try {
				Lafka_Virtual_Menu_Items::handle_dismiss();
				self::fail( 'Expected wp_die().' );
			} catch ( \RuntimeException $e ) {
				self::assertSame( 'died', $e->getMessage() );
			}
			self::assertSame( array(), $this->meta );
		}

		// ── Unvirtual (CLI backend) ───────────────────────────────────────

		public function test_plan_covers_every_flagged_item_or_just_the_requested_ids(): void {
			$this->seed_store();

			self::assertSame( array( 11, 21, 22 ), array_column( Lafka_Virtual_Menu_Items::plan( array() ), 'id' ) );
			// A product id pulls in its virtual variations; an explicit id skips
			// the exemptions (the operator asked for it); ids that are not
			// virtual are ignored.
			self::assertSame( array( 21, 22, 41 ), array_column( Lafka_Virtual_Menu_Items::plan( array( 20, 41, 99 ) ), 'id' ) );

			$row = Lafka_Virtual_Menu_Items::plan( array( 21 ) )[0];
			self::assertSame( 20, $row['item'] );
			self::assertSame( 'Item <21>', $row['name'] );
		}

		public function test_apply_unticks_virtual_and_saves_through_the_crud(): void {
			$this->virtual_ids = array( 11, 12 );
			$this->products    = array(
				11 => new VirtualProductDouble(),
				12 => new VirtualProductDouble(),
			);
			$this->products[12]->virtual = false; // Already fixed meanwhile.
			Lafka_Virtual_Menu_Items::flagged_items();

			$changed = Lafka_Virtual_Menu_Items::apply( Lafka_Virtual_Menu_Items::plan( array() ) );

			self::assertSame( 1, $changed );
			self::assertFalse( $this->products[11]->virtual );
			self::assertSame( 1, $this->products[11]->saves );
			self::assertSame( 0, $this->products[12]->saves );
			self::assertSame( array(), $this->transients );
		}

		/**
		 * Separate process: the command only loads when WP_CLI is true, which
		 * must not leak into other tests.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_cli_is_a_dry_run_unless_yes_is_given(): void {
			define( 'WP_CLI', true );
			$GLOBALS['lafka_cli_log'] = array();
			class_alias(
				get_class(
					new class() {
						public static function add_command( ...$args ): void {}
						public static function log( $msg ): void {
							$GLOBALS['lafka_cli_log'][] = $msg;
						}
						public static function success( $msg ): void {
							$GLOBALS['lafka_cli_log'][] = 'success: ' . $msg;
						}
						public static function warning( $msg ): void {
							$GLOBALS['lafka_cli_log'][] = 'warning: ' . $msg;
						}
					}
				),
				'WP_CLI'
			);
			Functions\when( 'WP_CLI\Utils\format_items' )->justReturn( null );

			$this->virtual_ids = array( 11 );
			$this->products    = array( 11 => new VirtualProductDouble() );

			require dirname( __DIR__, 2 ) . '/incl/cli/lafka-products-cli.php';
			$command = new \Lafka_Products_CLI_Command();

			$command->unvirtual( array(), array() );
			self::assertTrue( $this->products[11]->virtual, 'No --yes: nothing written.' );

			$command->unvirtual( array(), array( 'yes' => true, 'dry-run' => true ) );
			self::assertTrue( $this->products[11]->virtual, '--dry-run wins over --yes.' );

			$command->unvirtual( array(), array( 'yes' => true, 'ids' => '11' ) );
			self::assertFalse( $this->products[11]->virtual );
			self::assertSame( 1, $this->products[11]->saves );
			self::assertContains( 'success: Unticked Virtual on 1 item.', $GLOBALS['lafka_cli_log'] );
		}
	}
}
