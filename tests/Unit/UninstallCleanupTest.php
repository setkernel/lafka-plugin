<?php
/**
 * UninstallCleanupTest — locks the NX1-06 uninstall contract.
 *
 *   - uninstall.php is a thin WP_UNINSTALL_PLUGIN-guarded bootstrap that
 *     delegates to the testable Lafka_Uninstall class.
 *   - the "Remove all data on uninstall" toggle defaults OFF.
 *   - toggle OFF runs only the minimal pass (revert attributes + DROP the two
 *     conversion tables); it never deletes options, posts or terms.
 *   - toggle ON runs the full inventory-driven cleanup: prefixed option LIKE
 *     deletes, force-deletes each Lafka CPT's posts, deletes each Lafka
 *     taxonomy's terms, and removes the documented product/user meta keys.
 *   - the option-prefix inventory covers every known lafka* option name.
 *   - order + order-item meta is documented as intentionally retained.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.36.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Modules_Page;
use Lafka_Uninstall;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/tools/class-lafka-uninstall.php';

/**
 * In-memory $wpdb double that records the queries + deletes uninstall issues.
 */
class FakeUninstallWpdb {

	public string $prefix   = 'wp_';
	public string $options  = 'wp_options';
	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $usermeta = 'wp_usermeta';

	/** @var array<int,string> */
	public array $queries = array();
	/** @var array<string,array<int,int>> post_type => post IDs. */
	public array $post_ids = array();
	/** @var array<int,array{table:string,where:array}> */
	public array $deletes = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$replacement = ( is_int( $a ) || is_float( $a ) ) ? (string) $a : "'" . (string) $a . "'";
			$sql         = preg_replace( '/%[dsf]/', $replacement, (string) $sql, 1 );
		}
		return $sql;
	}

	public function query( $sql ) {
		$this->queries[] = (string) $sql;
		return 0;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_col( $sql ) {
		foreach ( $this->post_ids as $type => $ids ) {
			if ( false !== strpos( (string) $sql, "'" . $type . "'" ) ) {
				return $ids;
			}
		}
		return array();
	}

	public function delete( $table, $where, $formats = null ) {
		$this->deletes[] = array(
			'table' => $table,
			'where' => $where,
		);
		return 1;
	}
}

final class UninstallCleanupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ─── Bootstrap shape ──────────────────────────────────────────────────────

	public function test_uninstall_php_is_thin_bootstrap(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $src );
		$this->assertStringContainsString( 'incl/tools/class-lafka-uninstall.php', $src );
		$this->assertStringContainsString( 'Lafka_Uninstall::run', $src );
	}

	// ─── Toggle default + read ────────────────────────────────────────────────

	public function test_data_toggle_defaults_off(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return $default; // nothing stored → default '0'
			}
		);
		$this->assertFalse( Lafka_Uninstall::should_delete_all_data() );
	}

	public function test_data_toggle_on_when_option_is_one(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return Lafka_Uninstall::DATA_TOGGLE_OPTION === $key ? '1' : $default;
			}
		);
		$this->assertTrue( Lafka_Uninstall::should_delete_all_data() );
	}

	// ─── Option-prefix inventory completeness ─────────────────────────────────

	public function test_option_prefix_list_covers_every_known_option(): void {
		$known = array(
			'lafka',
			'lafka_abandoned_cart_db_version',
			'lafka_contact_phone',
			'lafka_dietary_tags_seeded_version',
			'lafka_first_order_discount_percent',
			'lafka_free_delivery_threshold',
			'lafka_homepage_hero_attachment_id',
			'lafka_kds_options',
			'lafka_kds_token_activity',
			'lafka_order_hours_options',
			'lafka_push_activity_log',
			'lafka_push_db_version',
			'lafka_share_on_posts',
			'lafka_share_on_products',
			'lafka_shipping_areas_advanced',
			'lafka_shipping_areas_branches',
			'lafka_shipping_areas_datetime',
			'lafka_shipping_areas_general',
			'lafka_slow_day_days',
			'lafka_slow_day_discount_percent',
			'lafka_business_name',
			'lafka_business_geo_lat',
			'lafka_last_processed_order_ids',
			'lafka_restaurant_info',
			'lafka_restaurant_hero_title',
			'lafka_delete_data_on_uninstall',
		);
		foreach ( $known as $name ) {
			$this->assertTrue(
				Lafka_Uninstall::option_matches( $name ),
				"Option {$name} must be covered by the uninstall inventory."
			);
		}
	}

	public function test_option_matches_rejects_foreign_and_empty_names(): void {
		$this->assertFalse( Lafka_Uninstall::option_matches( '' ) );
		$this->assertFalse( Lafka_Uninstall::option_matches( 'woocommerce_db_version' ) );
		$this->assertFalse( Lafka_Uninstall::option_matches( 'blogname' ) );
		$this->assertFalse( Lafka_Uninstall::option_matches( 'lafkax_notours' ) );
	}

	// ─── Inventory lists ──────────────────────────────────────────────────────

	public function test_inventory_lists_are_exact(): void {
		$this->assertSame(
			array( 'lafka_abandoned_carts', 'lafka_push_subscriptions' ),
			Lafka_Uninstall::tables()
		);
		$this->assertSame(
			array( 'lafka-foodmenu', 'lafka_shipping_areas', 'lafka_glb_addon' ),
			Lafka_Uninstall::post_types()
		);
		$this->assertContains( 'lafka_branch_location', Lafka_Uninstall::taxonomies() );
		$this->assertContains( 'lafka_foodmenu_category', Lafka_Uninstall::taxonomies() );
	}

	public function test_order_meta_is_documented_as_retained(): void {
		$retained = Lafka_Uninstall::retained_meta_keys();
		$this->assertContains( '_lafka_kds_', $retained );
		$this->assertContains( '_lafka_addon_', $retained );

		// The delete lists must never touch order/order-item meta.
		$this->assertNotContains( '_lafka_kds_', Lafka_Uninstall::deleted_post_meta_keys() );
		$this->assertNotContains( '_lafka_addon_', Lafka_Uninstall::deleted_post_meta_keys() );
	}

	// ─── Toggle OFF: minimal pass only ────────────────────────────────────────

	public function test_run_toggle_off_does_minimal_only(): void {
		$wpdb            = new FakeUninstallWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return $default; // toggle off
			}
		);
		$deleted_posts = array();
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_delete_post' )->alias(
			static function ( $id, $force = false ) use ( &$deleted_posts ) {
				$deleted_posts[] = array( $id, $force );
				return true;
			}
		);

		Lafka_Uninstall::run();

		$joined = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString( 'woocommerce_attribute_taxonomies', $joined );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_lafka_abandoned_carts', $joined );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_lafka_push_subscriptions', $joined );
		$this->assertStringNotContainsString( 'DELETE FROM', $joined, 'Toggle OFF must not delete option/meta rows.' );
		$this->assertSame( array(), $deleted_posts, 'Toggle OFF must not delete any posts.' );
	}

	// ─── Toggle ON: full cleanup ──────────────────────────────────────────────

	public function test_run_toggle_on_deletes_options_via_prefix_like(): void {
		$wpdb            = new FakeUninstallWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return Lafka_Uninstall::DATA_TOGGLE_OPTION === $key ? '1' : $default;
			}
		);
		$deleted_options = array();
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted_options ) {
				$deleted_options[] = $name;
				return true;
			}
		);
		Functions\when( 'wp_delete_post' )->justReturn( true );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'wp_delete_term' )->justReturn( true );

		Lafka_Uninstall::run();

		$joined = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString(
			"DELETE FROM wp_options WHERE option_name LIKE 'lafka\\_business\\_%'",
			$joined
		);
		$this->assertStringContainsString(
			"DELETE FROM wp_options WHERE option_name LIKE 'lafka\\_kds\\_%'",
			$joined
		);
		// Transients (option rows with the WP-internal prefix; underscores are
		// esc_like-escaped in the emitted LIKE, so match the un-escaped token).
		$this->assertStringContainsString( 'transient', $joined );
		// Exact-match options deleted via delete_option().
		$this->assertContains( 'lafka', $deleted_options );
		$this->assertContains( 'lafka_delete_data_on_uninstall', $deleted_options );
	}

	public function test_full_cleanup_force_deletes_every_cpt_post(): void {
		$wpdb            = new FakeUninstallWpdb();
		$wpdb->post_ids  = array(
			'lafka-foodmenu'       => array( 10, 11 ),
			'lafka_shipping_areas' => array( 20 ),
			'lafka_glb_addon'      => array( 30, 31 ),
		);
		$GLOBALS['wpdb'] = $wpdb;

		$deleted = array();
		Functions\when( 'wp_delete_post' )->alias(
			static function ( $id, $force = false ) use ( &$deleted ) {
				$deleted[] = array( 'id' => (int) $id, 'force' => $force );
				return true;
			}
		);

		Lafka_Uninstall::delete_cpt_posts();

		$ids = array_map(
			static function ( $d ) {
				return $d['id'];
			},
			$deleted
		);
		$this->assertEqualsCanonicalizing( array( 10, 11, 20, 30, 31 ), $ids );
		foreach ( $deleted as $d ) {
			$this->assertTrue( $d['force'], 'CPT posts must be force-deleted so meta cascades.' );
		}
	}

	public function test_full_cleanup_deletes_terms_for_each_taxonomy(): void {
		$wpdb            = new FakeUninstallWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_terms' )->alias(
			static function ( $args ) {
				return 'lafka_branch_location' === $args['taxonomy'] ? array( 5, 6 ) : array( 7 );
			}
		);
		$deleted = array();
		Functions\when( 'wp_delete_term' )->alias(
			static function ( $term_id, $taxonomy ) use ( &$deleted ) {
				$deleted[] = array( (int) $term_id, $taxonomy );
				return true;
			}
		);

		Lafka_Uninstall::delete_terms();

		$this->assertContains( array( 5, 'lafka_branch_location' ), $deleted );
		$this->assertContains( array( 6, 'lafka_branch_location' ), $deleted );
		$this->assertContains( array( 7, 'lafka_foodmenu_category' ), $deleted );
	}

	public function test_delete_meta_removes_documented_keys(): void {
		$wpdb            = new FakeUninstallWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		Lafka_Uninstall::delete_meta();

		$joined = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString(
			"DELETE FROM wp_postmeta WHERE meta_key = '_lafka_variable_in_catalog'",
			$joined
		);
		$this->assertStringContainsString(
			"DELETE FROM wp_usermeta WHERE meta_key = '_lafka_review_email_optout'",
			$joined
		);
		// Order-item meta must never appear in a DELETE.
		$this->assertStringNotContainsString( '_lafka_kds_', $joined );
		$this->assertStringNotContainsString( '_lafka_addon_', $joined );
	}

	// ─── Cross-file constant parity ───────────────────────────────────────────

	public function test_modules_page_toggle_option_matches_uninstall_constant(): void {
		require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
		require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-module-registry.php';
		require_once dirname( __DIR__, 2 ) . '/incl/admin/class-lafka-modules-page.php';

		$this->assertSame(
			Lafka_Uninstall::DATA_TOGGLE_OPTION,
			Lafka_Modules_Page::DATA_REMOVAL_OPTION,
			'The Modules-page checkbox and uninstall.php must read/write the same option.'
		);
	}
}
