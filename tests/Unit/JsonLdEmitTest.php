<?php
/**
 * Lafka_JSON_LD::emit(): the @graph <script> block.
 *
 *   - script-context escaping: a `</script>` in any value (e.g. a product name)
 *     can never close the tag (C-6 stored-XSS defense), while URLs stay
 *     unescaped and every value round-trips through JSON;
 *   - skipped on admin / feed / 404 and when an SEO plugin owns schema
 *     (unless lafka_schema_force_emit);
 *   - the Restaurant node (and WebSite.publisher's link to it) only appear
 *     once the NAP basics are configured.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class JsonLdEmitTest extends TestCase {

	private const NAP = array(
		'lafka_business_name'       => 'Acme Test Cafe',
		'lafka_business_street'     => '123 Test Street',
		'lafka_business_city'       => 'Testville',
		'lafka_business_postal'     => 'T1S 1S1',
		'lafka_business_phone_e164' => '+15551234567',
	);

	/** @var array<string, mixed> The lafka_business_* option store (GX3: the single NAP store). */
	private array $options = array();

	/** @var array<string, mixed> Filter name => forced return value. */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array();
		$this->filters    = array();

		foreach ( array( 'is_admin', 'is_feed', 'is_404', 'is_product', 'is_shop', 'is_product_category', 'is_page', 'lafka_seo_plugin_active' ) as $false ) {
			Functions\when( $false )->justReturn( false );
		}
		// Front page: no breadcrumb / menu nodes, keeping the graph to WebSite (+ Restaurant).
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $default = null ) => $default );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value ) => array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value
		);
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, $flags = 0, $depth = 512 ) => json_encode( $data, $flags, $depth )
		);

		require_once dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function emit(): string {
		ob_start();
		\Lafka_JSON_LD::emit();
		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, mixed>|null The decoded payload, or null when nothing was emitted.
	 */
	private function emitted_payload(): ?array {
		$out = $this->emit();
		if ( '' === $out ) {
			return null;
		}
		$this->assertSame( 1, preg_match( '#^\s*<script type="application/ld\+json">(.*)</script>\s*$#s', $out, $m ) );
		return json_decode( $m[1], true, 512, JSON_THROW_ON_ERROR );
	}

	public function test_script_closing_tag_in_a_value_cannot_break_out_of_the_block(): void {
		$hostile = array(
			'@type' => 'Product',
			'name'  => '</script><script>alert(1)</script> Fish & Chips \'n\' "more"',
			'url'   => 'https://example.test/product/fish/',
		);
		$this->filters['lafka_json_ld_graph'] = array( $hostile );

		$out = $this->emit();

		$this->assertSame( 1, substr_count( strtolower( $out ), '</script' ), 'Only the block\'s own closing tag may appear.' );
		$this->assertSame( 1, substr_count( strtolower( $out ), '<script' ), 'No second <script> may be opened.' );
		$this->assertStringContainsString( '"https://example.test/product/fish/"', $out, 'URLs render without \/ escaping.' );
		$this->assertSame( array( $hostile ), $this->emitted_payload()['@graph'] );
	}

	public function test_emits_nothing_on_admin_feed_or_404(): void {
		foreach ( array( 'is_admin', 'is_feed', 'is_404' ) as $context ) {
			Functions\when( $context )->justReturn( true );
			$this->assertSame( '', $this->emit(), "No JSON-LD when {$context}()." );
			Functions\when( $context )->justReturn( false );
		}
	}

	public function test_yields_to_an_active_seo_plugin_unless_forced(): void {
		Functions\when( 'lafka_seo_plugin_active' )->justReturn( true );
		$this->assertSame( '', $this->emit() );

		$this->filters['lafka_schema_force_emit'] = true;
		$this->assertNotNull( $this->emitted_payload() );
	}

	public function test_unconfigured_install_emits_only_the_website_node_without_publisher(): void {
		$payload = $this->emitted_payload();

		$this->assertSame( 'https://schema.org', $payload['@context'] );
		$this->assertSame( array( 'WebSite' ), array_column( $payload['@graph'], '@type' ) );
		$this->assertArrayNotHasKey( 'publisher', $payload['@graph'][0], 'publisher must not point at an absent #restaurant node.' );
	}

	public function test_configured_nap_adds_the_restaurant_node_and_links_it_as_publisher(): void {
		$this->options = self::NAP;

		$graph = $this->emitted_payload()['@graph'];

		$this->assertCount( 2, $graph );
		$this->assertSame( 'WebSite', $graph[0]['@type'] );
		$this->assertSame( 'https://example.test/#restaurant', $graph[1]['@id'] );
		$this->assertSame( array( '@id' => 'https://example.test/#restaurant' ), $graph[0]['publisher'] );
	}
}
