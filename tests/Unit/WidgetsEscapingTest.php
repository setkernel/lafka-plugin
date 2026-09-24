<?php
/**
 * Classic widgets escape hostile post data and operator input on output, and
 * run every saved text field through a sanitizer.
 *
 * Regressions this guards (v9.7.21 hardening): Popular Posts echoed titles raw
 * and used the_permalink() (raw echo) in href; Latest Menu Entries built its
 * href as esc_url( the_permalink() ), which printed the raw permalink; the About
 * widget's admin form printed page titles raw; update() used strip_tags().
 *
 * esc_* stubs escape for real (htmlspecialchars) and the_permalink() echoes the
 * raw URL as WordPress does, so an unescaped path leaks the payload.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/wp-widget-stub.php';

final class WidgetsEscapingTest extends TestCase {

	private const URL_PAYLOAD   = 'https://example.test/"><script>url()</script>';
	private const TITLE_PAYLOAD = '<script>title()</script>';

	private const WIDGET_ARGS = array(
		'before_widget' => '',
		'after_widget'  => '',
		'before_title'  => '',
		'after_title'   => '',
		'widget_id'     => 'w-1',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$escape = static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_url' )->alias( $escape );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->echoArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => 'text(' . $s . ')' );
		Functions\when( 'sanitize_email' )->alias( static fn( $s ) => 'email(' . $s . ')' );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = array() ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'is_email' )->justReturn( false );
		// Post-data sources return hostile values.
		Functions\when( 'get_permalink' )->justReturn( self::URL_PAYLOAD );
		Functions\when( 'the_permalink' )->alias(
			static function () {
				echo self::URL_PAYLOAD; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- models WP's raw echo.
			}
		);
		Functions\when( 'get_the_title' )->justReturn( self::TITLE_PAYLOAD );
		// Inputs of lafka_get_restaurant_info(), reached for the tel: link.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = '' ) => $default );
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( $url, '/' ) . '/' );
		// Cache + query plumbing.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_reset_postdata' )->justReturn( null );

		foreach ( array( 'LafkaAboutWidget', 'LafkaContactsWidget', 'LafkaPaymentOptionsWidget', 'LafkaPopularPostsWidget', 'LafkaLatestMenuEntriesWidget' ) as $widget ) {
			require_once dirname( __DIR__, 2 ) . '/widgets/' . $widget . '.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function assert_no_payload( string $html ): void {
		$this->assertStringNotContainsString( '<script>', $html, 'Hostile data reached the page unescaped.' );
	}

	public function test_popular_posts_escapes_titles_and_links(): void {
		Functions\when( 'wp_cache_get' )->alias( static fn( $key ) => 'lafka_popular_widget_ver' === $key ? 0 : array( 7 ) );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'get_post' )->alias( static fn( $id ) => (object) array( 'ID' => $id ) );
		Functions\when( 'get_queried_object_id' )->justReturn( 0 );
		Functions\when( 'has_post_thumbnail' )->justReturn( false );
		Functions\when( 'get_the_date' )->justReturn( '' );

		ob_start();
		( new \LafkaPopularPostsWidget() )->widget( self::WIDGET_ARGS, array( 'number' => 1 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( htmlspecialchars( self::TITLE_PAYLOAD, ENT_QUOTES ), $html, 'The post is rendered.' );
		$this->assert_no_payload( $html );
	}

	public function test_latest_menu_entries_escapes_links(): void {
		if ( ! class_exists( 'WP_Query' ) ) {
			// One-post query: have_posts() is true until the_post() runs once.
			class_alias(
				get_class(
					new class() {
						private bool $done = false;

						public function __construct( $args = array() ) {}

						public function have_posts(): bool {
							return ! $this->done;
						}

						public function the_post(): void {
							$this->done = true;
						}
					}
				),
				'WP_Query'
			);
		}
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'has_post_thumbnail' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 7 );
		Functions\when( 'the_post_thumbnail' )->justReturn( null );

		ob_start();
		( new \LafkaLatestMenuEntriesWidget() )->widget( self::WIDGET_ARGS, array( 'number' => 1 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="' . htmlspecialchars( self::URL_PAYLOAD, ENT_QUOTES ) . '"', $html );
		$this->assert_no_payload( $html );
	}

	public function test_about_widget_form_escapes_page_titles(): void {
		Functions\when( 'get_pages' )->justReturn( array( (object) array( 'ID' => 3, 'post_title' => self::TITLE_PAYLOAD ) ) );

		ob_start();
		( new \LafkaAboutWidget() )->form( array() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<option value="3">' . htmlspecialchars( self::TITLE_PAYLOAD, ENT_QUOTES ) . '</option>', $html );
		$this->assert_no_payload( $html );
	}

	public function test_contacts_widget_escapes_operator_values(): void {
		$fields = array_fill_keys( array( 'worktime', 'address', 'phone', 'fax', 'email' ), self::TITLE_PAYLOAD );

		ob_start();
		( new \LafkaContactsWidget() )->widget( self::WIDGET_ARGS, $fields );
		$html = (string) ob_get_clean();

		$this->assertSame( 5, substr_count( $html, htmlspecialchars( self::TITLE_PAYLOAD, ENT_QUOTES ) ) );
		$this->assert_no_payload( $html );
	}

	/**
	 * A freshly added widget has an empty instance: rendering and saving it
	 * must not raise undefined-index warnings (failOnWarning is on).
	 */
	public function test_widgets_tolerate_an_empty_instance(): void {
		ob_start();
		( new \LafkaAboutWidget() )->widget( self::WIDGET_ARGS, array() );
		( new \LafkaPaymentOptionsWidget() )->widget( self::WIDGET_ARGS, array() );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'r_more', $html, 'No page chosen: no "Read more" link.' );

		$saved = ( new \LafkaPopularPostsWidget() )->update( array(), array() );
		$this->assertSame(
			array(
				'title'  => '',
				'number' => 5,
			),
			$saved
		);
	}

	public function test_contacts_widget_reads_the_restaurant_info_once_per_render(): void {
		require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
		// Each lafka_get_restaurant_info() call ends in the lafka_restaurant_info filter.
		$calls = 0;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( &$calls ) {
				if ( 'lafka_restaurant_info' === $tag ) {
					++$calls;
					$value['phone_display'] = '555 0100';
				}
				return $value;
			}
		);

		ob_start();
		( new \LafkaContactsWidget() )->widget( self::WIDGET_ARGS, array() );
		ob_end_clean();

		$this->assertSame( 1, $calls );
	}

	/**
	 * @param array<string, string> $expected Saved value per field.
	 */
	#[DataProvider( 'update_provider' )]
	public function test_update_sanitizes_every_text_field( string $widget, array $expected ): void {
		$new_instance = array_fill_keys( array_keys( $expected ), '<b>v</b>' ) + array(
			'number' => '1',
			'seal'   => '',
		);

		$saved = ( new $widget() )->update( $new_instance, array() );

		$this->assertSame( $expected, array_intersect_key( $saved, $expected ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function update_provider(): array {
		$text = 'text(<b>v</b>)';
		return array(
			'about'       => array( 'LafkaAboutWidget', array( 'title' => $text ) ),
			'contacts'    => array(
				'LafkaContactsWidget',
				array(
					'title'    => $text,
					'worktime' => $text,
					'address'  => $text,
					'phone'    => $text,
					'fax'      => $text,
					'email'    => 'email(<b>v</b>)',
				),
			),
			'payment'     => array( 'LafkaPaymentOptionsWidget', array( 'title' => $text ) ),
			'popular'     => array( 'LafkaPopularPostsWidget', array( 'title' => $text ) ),
			'latest menu' => array( 'LafkaLatestMenuEntriesWidget', array( 'title' => $text ) ),
		);
	}
}
