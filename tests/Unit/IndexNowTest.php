<?php
/**
 * GX3 IndexNow (incl/seo/lafka-indexnow.php):
 *
 *   - default OFF; pings only when enabled AND production AND public;
 *   - key generated once (32 hex) and served only as itself at /{key}.txt;
 *   - URLs queue (deduped, own host only) and ONE debounced Action
 *     Scheduler job flushes them in a single batched POST;
 *   - publish/update of products/pages (not noindexed), price/stock
 *     changes (variation → parent) and category edits enqueue;
 *   - transient failures keep the URLs for the next flush.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class IndexNowTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	private string $env = 'production';

	/** @var list<array> */
	private array $scheduled = array();

	/** @var list<array> */
	private array $posts = array();

	/** @var array<string, mixed> */
	private array $meta = array();

	private $response = array( 'response' => array( 'code' => 200 ) );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options   = array( 'lafka_seo_indexnow_enabled' => 'yes' );
		$this->env       = 'production';
		$this->scheduled = array();
		$this->posts     = array();
		$this->meta      = array();
		$this->response  = array( 'response' => array( 'code' => 200 ) );

		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_environment_type' )->alias( fn() => $this->env );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.test' . $p );
		Functions\when( 'trailingslashit' )->alias( static fn( $u ) => rtrim( (string) $u, '/' ) . '/' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( (string) $u, $c ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'as_has_scheduled_action' )->alias( fn( $hook ) => ! empty( $this->scheduled ) );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $time, $hook, $args, $group ) {
				$this->scheduled[] = array( $hook, $group );
				return 1;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = array( $url, json_decode( $args['body'], true ) );
				return $this->response;
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof WP_Error_Stub_IndexNow );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] ?? 0 );
		Functions\when( 'get_permalink' )->alias( static fn( $p ) => 'https://example.test/item/' . ( is_object( $p ) ? $p->ID : $p ) . '/' );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-settings.php';
		require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-indexnow.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post( int $id, string $type = 'product' ): object {
		return (object) array(
			'ID'        => $id,
			'post_type' => $type,
		);
	}

	public function test_off_by_default_and_never_off_production(): void {
		$this->options = array();
		self::assertFalse( lafka_indexnow_can_ping() );

		$this->options = array( 'lafka_seo_indexnow_enabled' => 'yes' );
		self::assertTrue( lafka_indexnow_can_ping() );

		foreach ( array( 'staging', 'development', 'local' ) as $env ) {
			$this->env = $env;
			self::assertFalse( lafka_indexnow_can_ping(), $env );
			self::assertFalse( lafka_indexnow_queue_url( 'https://example.test/x/' ) );
		}

		$this->env                    = 'production';
		$this->options['blog_public'] = '0';
		self::assertFalse( lafka_indexnow_can_ping(), 'discouraged from search engines' );
	}

	public function test_key_is_generated_once(): void {
		$key = lafka_indexnow_key();
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		self::assertSame( $key, lafka_indexnow_key() );
		self::assertSame( 'https://example.test/' . $key . '.txt', lafka_indexnow_key_url() );
	}

	public function test_urls_are_queued_deduped_and_debounced_into_one_job(): void {
		lafka_indexnow_queue_url( 'https://example.test/a/' );
		lafka_indexnow_queue_url( 'https://example.test/a/' );
		lafka_indexnow_queue_url( 'https://example.test/b/' );
		lafka_indexnow_queue_url( 'https://elsewhere.test/c/' );

		self::assertSame( array( 'https://example.test/a/', 'https://example.test/b/' ), $this->options[ LAFKA_INDEXNOW_QUEUE ] );
		self::assertSame( array( array( 'lafka_indexnow_flush', 'lafka' ) ), $this->scheduled );
	}

	public function test_flush_sends_one_batched_request_and_clears_the_queue(): void {
		lafka_indexnow_queue_url( 'https://example.test/a/' );
		lafka_indexnow_queue_url( 'https://example.test/b/' );

		self::assertSame( array( 'sent' => 2, 'code' => 200 ), lafka_indexnow_flush() );
		self::assertCount( 1, $this->posts );
		[ $endpoint, $body ] = $this->posts[0];
		self::assertSame( 'https://api.indexnow.org/indexnow', $endpoint );
		self::assertSame( 'example.test', $body['host'] );
		self::assertSame( lafka_indexnow_key(), $body['key'] );
		self::assertSame( lafka_indexnow_key_url(), $body['keyLocation'] );
		self::assertSame( array( 'https://example.test/a/', 'https://example.test/b/' ), $body['urlList'] );
		self::assertSame( array(), $this->options[ LAFKA_INDEXNOW_QUEUE ] );
		self::assertSame( 2, $this->options['lafka_seo_indexnow_last']['sent'] );
	}

	public function test_transient_failures_keep_the_urls(): void {
		lafka_indexnow_queue_url( 'https://example.test/a/' );
		$this->response = array( 'response' => array( 'code' => 503 ) );
		lafka_indexnow_flush();
		self::assertSame( array( 'https://example.test/a/' ), $this->options[ LAFKA_INDEXNOW_QUEUE ] );

		$this->response = array( 'response' => array( 'code' => 422 ) );
		lafka_indexnow_flush();
		self::assertSame( array(), $this->options[ LAFKA_INDEXNOW_QUEUE ], 'a rejected batch is not retried forever' );
	}

	public function test_flush_does_nothing_off_production(): void {
		$this->options[ LAFKA_INDEXNOW_QUEUE ] = array( 'https://example.test/a/' );
		$this->env                             = 'staging';
		lafka_indexnow_flush();
		self::assertSame( array(), $this->posts );
	}

	public function test_publish_and_update_of_menu_content_enqueue(): void {
		lafka_indexnow_on_transition( 'publish', 'draft', $this->post( 10 ) );
		lafka_indexnow_on_transition( 'publish', 'publish', $this->post( 11, 'page' ) );
		lafka_indexnow_on_transition( 'draft', 'draft', $this->post( 12 ) );
		lafka_indexnow_on_transition( 'publish', 'draft', $this->post( 13, 'nav_menu_item' ) );
		$this->meta[14]['_lafka_seo_noindex'] = '1';
		lafka_indexnow_on_transition( 'publish', 'draft', $this->post( 14, 'page' ) );

		self::assertSame( array( 'https://example.test/item/10/', 'https://example.test/item/11/' ), $this->options[ LAFKA_INDEXNOW_QUEUE ] );
	}

	public function test_price_and_stock_changes_enqueue_the_parent_product(): void {
		Functions\when( 'wp_get_post_parent_id' )->alias( static fn( $id ) => 21 === $id ? 20 : 0 );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		$variation = new class() {
			public function get_id() {
				return 21;
			}
		};

		lafka_indexnow_on_props_updated( $variation, array( 'description' ) );
		self::assertArrayNotHasKey( LAFKA_INDEXNOW_QUEUE, $this->options );

		lafka_indexnow_on_props_updated( $variation, array( 'sale_price' ) );
		lafka_indexnow_on_product_change( 30 );
		self::assertSame( array( 'https://example.test/item/20/', 'https://example.test/item/30/' ), $this->options[ LAFKA_INDEXNOW_QUEUE ] );
	}

	public function test_key_file_serves_only_the_real_key(): void {
		$key    = lafka_indexnow_key();
		$status = null;
		Functions\when( 'status_header' )->alias(
			static function ( $code ) use ( &$status ) {
				$status = $code;
			}
		);
		Functions\when( 'get_query_var' )->justReturn( str_repeat( 'a', 32 ) === $key ? str_repeat( 'b', 32 ) : str_repeat( 'a', 32 ) );
		lafka_indexnow_serve_key();
		self::assertSame( 404, $status );

		$status                                     = null;
		$this->options['lafka_seo_indexnow_enabled'] = 'no';
		Functions\when( 'get_query_var' )->justReturn( $key );
		lafka_indexnow_serve_key();
		self::assertSame( 404, $status, 'no key file while IndexNow is off' );
	}

	public function test_listed_on_the_modules_page_with_the_same_option(): void {
		require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
		require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-module-registry.php';
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );
		\Lafka_Module_Registry::reset();

		lafka_indexnow_register_module();
		$module = \Lafka_Module_Registry::get( 'indexnow' );

		self::assertNotNull( $module );
		self::assertSame( 'seo', $module->get_category() );
		self::assertFalse( $module->default_enabled() );
		self::assertTrue( $module->is_enabled() );
		$module->set_enabled( false );
		self::assertSame( 'no', $this->options['lafka_seo_indexnow_enabled'] );
		\Lafka_Module_Registry::reset();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile
final class WP_Error_Stub_IndexNow {
}
