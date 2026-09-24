<?php
/**
 * UninstallCleanupTest — locks the NX1-06 uninstall contract.
 *
 *   - uninstall.php delegates to the testable Lafka_Uninstall class.
 *   - the "Remove all data on uninstall" toggle defaults OFF.
 *   - toggle OFF runs only the minimal pass (revert attributes + DROP the two
 *     conversion tables + their markers); it never deletes options, posts or terms.
 *   - toggle ON runs the full inventory-driven cleanup: prefixed option LIKE
 *     deletes, force-deletes each Lafka CPT's posts, deletes each Lafka
 *     taxonomy's terms, and removes the documented product/user meta keys.
 *   - the inventory covers every lafka* option, table, CPT and taxonomy the
 *     plugin source writes/registers (derived by scanning the source once).
 *   - order + order-item meta is documented as intentionally retained.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   10.0.0
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

	/** @var array<string,string>|null path => contents, scanned once per process. */
	private static ?array $plugin_sources = null;

	/**
	 * Every PHP source file the plugin ships (main file + incl/shortcodes/widgets).
	 *
	 * @return array<string,string>
	 */
	private static function plugin_sources(): array {
		if ( null !== self::$plugin_sources ) {
			return self::$plugin_sources;
		}
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/lafka-plugin.php' );
		foreach ( array( 'incl', 'shortcodes', 'widgets' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		self::$plugin_sources = array();
		foreach ( $files as $file ) {
			self::$plugin_sources[ $file ] = (string) file_get_contents( $file );
		}
		return self::$plugin_sources;
	}

	/**
	 * Every capture of $pattern across the plugin source.
	 *
	 * @return array<int,string>
	 */
	private static function scan( string $pattern ): array {
		$names = array();
		foreach ( self::plugin_sources() as $src ) {
			if ( preg_match_all( $pattern, $src, $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Every option name the plugin's code writes, read off the source: option
	 * API calls with a literal name, register_setting() option names, option
	 * name constants, WooCommerce settings-page field ids, Customizer
	 * option-type settings and option-name helper functions.
	 *
	 * @return array<int,string>
	 */
	private static function options_written_by_the_plugin(): array {
		$names = array();
		foreach ( array(
			"/(?:update_option|add_option)\(\s*'(lafka[a-z0-9_]*)'/",
			"/register_setting\(\s*'[^']*'\s*,\s*'(lafka[a-z0-9_]*)'/",
			"/const\s+\w*OPTION\w*\s*=\s*'(lafka[a-z0-9_]*)'/",
			"/add_setting\(\s*'(lafka[a-z0-9_]*)'\s*,\s*array\((?:(?!add_setting).){0,200}?'type'\s*=>\s*'option'/s",
			"/function\s+\w*option_(?:name|key)\w*\([^)]*\)[^{]*\{\s*return\s+'(lafka[a-z0-9_]*)'/",
		) as $pattern ) {
			$names = array_merge( $names, self::scan( $pattern ) );
		}
		// WooCommerce settings-page fields store under their id (the *_title /
		// *_end ids are section markers, not stored values).
		foreach ( self::plugin_sources() as $file => $src ) {
			if ( str_contains( $file, 'class-lafka-wc-settings-restaurant.php' ) && preg_match_all( "/'id'\s*=>\s*'(lafka[a-z0-9_]*)'/", $src, $m ) ) {
				foreach ( $m[1] as $id ) {
					if ( ! preg_match( '/_(title|end)$/', $id ) ) {
						$names[] = $id;
					}
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	public function test_every_option_the_plugin_writes_is_removed_by_full_cleanup(): void {
		$names = self::options_written_by_the_plugin();
		$this->assertGreaterThan( 30, count( $names ), 'The option scan found suspiciously few names.' );

		foreach ( $names as $name ) {
			$this->assertTrue( Lafka_Uninstall::option_matches( $name ), "Option {$name} is written by the plugin but survives a full uninstall." );
		}
	}

	public function test_full_cleanup_leaves_theme_owned_options_alone(): void {
		foreach ( array( 'lafka_dynamic_css_version', 'lafka_legacy_migration_version', 'lafka_search_cache_version', 'lafka_github_token', 'theme_mods_lafka' ) as $name ) {
			$this->assertFalse( Lafka_Uninstall::option_matches( $name ), "Theme-owned option {$name} must survive a plugin uninstall." );
		}
	}

	public function test_option_matches_rejects_foreign_and_empty_names(): void {
		$this->assertFalse( Lafka_Uninstall::option_matches( '' ) );
		$this->assertFalse( Lafka_Uninstall::option_matches( 'woocommerce_db_version' ) );
		$this->assertFalse( Lafka_Uninstall::option_matches( 'blogname' ) );
		$this->assertFalse( Lafka_Uninstall::option_matches( 'lafkax_notours' ) );
	}

	// ─── Table / CPT / taxonomy inventory completeness ────────────────────────

	public function test_every_table_cpt_and_taxonomy_the_plugin_registers_is_in_the_inventory(): void {
		$tables     = self::scan( "/function\s+\w*table_name\w*\(\)[^{]*\{[^}]*?'(lafka_[a-z0-9_]+)'/" );
		$post_types = self::scan( "/register_post_type\(\s*'(lafka[a-z0-9_-]*)'/" );
		$taxonomies = self::scan( "/register_taxonomy\(\s*'(lafka[a-z0-9_-]*)'/" );

		$this->assertNotEmpty( $tables, 'The table scan found nothing — pattern drift?' );
		$this->assertNotEmpty( $post_types, 'The post-type scan found nothing — pattern drift?' );
		$this->assertNotEmpty( $taxonomies, 'The taxonomy scan found nothing — pattern drift?' );
		$this->assertSame( array(), array_values( array_diff( $tables, Lafka_Uninstall::tables() ) ), 'Tables the plugin creates but uninstall never drops.' );
		$this->assertSame( array(), array_values( array_diff( $post_types, Lafka_Uninstall::post_types() ) ), 'CPTs whose posts survive a full uninstall.' );
		$this->assertSame( array(), array_values( array_diff( $taxonomies, Lafka_Uninstall::taxonomies() ) ), 'Taxonomies whose terms survive a full uninstall.' );
	}

	public function test_order_meta_is_documented_as_retained(): void {
		$retained = Lafka_Uninstall::retained_meta_keys();
		$this->assertContains( '_lafka_kds_', $retained );
		$this->assertContains( '_lafka_addon_keys', $retained );

		// The delete lists must never touch order/order-item meta.
		$this->assertNotContains( '_lafka_kds_', Lafka_Uninstall::deleted_post_meta_keys() );
		$this->assertNotContains( '_lafka_addon_keys', Lafka_Uninstall::deleted_post_meta_keys() );
	}

	// ─── Toggle OFF: minimal pass only ────────────────────────────────────────

	public function test_uninstall_php_with_toggle_off_does_the_minimal_pass_only(): void {
		$wpdb            = new FakeUninstallWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return $default; // toggle off
			}
		);
		Functions\when( 'plugin_dir_path' )->alias( static fn( $file ) => dirname( $file ) . '/' );
		$deleted_options = array();
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted_options ) {
				$deleted_options[] = $name;
				return true;
			}
		);
		Functions\expect( 'wp_delete_post' )->never();
		Functions\expect( 'wp_delete_term' )->never();

		// Nothing else in the plugin reads this WordPress-core constant.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'lafka-plugin/lafka-plugin.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$joined = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString( 'woocommerce_attribute_taxonomies', $joined );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_lafka_abandoned_carts', $joined );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_lafka_push_subscriptions', $joined );
		$this->assertStringNotContainsString( 'DELETE FROM', $joined, 'Toggle OFF must not delete option/meta rows.' );
		$this->assertSame(
			array( 'lafka_abandoned_cart_db_version', 'lafka_push_db_version', 'lafka_push_activity_log' ),
			$deleted_options,
			'Toggle OFF removes only the dropped tables\' markers.'
		);
	}

	public function test_revert_attribute_types_scopes_to_lafka_swatch_types_only(): void {
		$wpdb            = new FakeUninstallWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		Lafka_Uninstall::revert_attribute_types();

		$this->assertCount( 1, $wpdb->queries );
		$sql = $wpdb->queries[0];
		$this->assertStringContainsString( "SET attribute_type = 'select'", $sql );
		$this->assertStringContainsString( "IN ( 'color', 'image', 'label' )", $sql );
		$this->assertStringNotContainsString( '!=', $sql, 'A blanket != reset would clobber attribute types owned by other plugins.' );
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
		Functions\when( 'is_admin' )->justReturn( false ); // keep the page class inert on load
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
