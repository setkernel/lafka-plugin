<?php
/**
 * The [lafka_contact_form] partial, rendered as the AJAX re-render path
 * includes it (no shortcode-provided variables, e.g. no
 * $lafka_shortcode_params_for_tpl — an undefined-variable warning there fails
 * the test under failOnWarning):
 *
 *   - every visible field has a programmatic label (C-11, WCAG 4.1.2 / 1.3.1);
 *   - posted values and translations are escaped on output. Translations are
 *     hostile here: the esc_*() variants escape them, plain __()/_e() do not,
 *     so an `echo __()` regression leaks the payload.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ContactFormPartialTest extends TestCase {

	private const PAYLOAD = '"><script>x()</script>';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST = array();

		$escape = static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES );
		Functions\when( '__' )->justReturn( self::PAYLOAD );
		Functions\when( '_e' )->alias(
			static function () {
				echo self::PAYLOAD; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- models an unescaped translation.
			}
		);
		Functions\when( 'esc_html__' )->justReturn( $escape( self::PAYLOAD ) );
		Functions\when( 'esc_attr__' )->justReturn( $escape( self::PAYLOAD ) );
		Functions\when( 'esc_html_e' )->alias(
			static function () use ( $escape ) {
				echo $escape( self::PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
			}
		);
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_textarea' )->alias( $escape );
		Functions\when( 'esc_url' )->alias( $escape );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		// Sanitizers pass hostile input through, so only output escaping protects.
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( 'admin@example.test' );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_rand' )->justReturn( 3 );
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render(): string {
		// Every optional field on, as a shortcode with fields="..." would.
		$lafka_contact_form_fields = 'name,email,phone,address,subject'; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- read by the partial.
		ob_start();
		include dirname( __DIR__, 2 ) . '/shortcodes/partials/contact-form.php';
		return (string) ob_get_clean();
	}

	public function test_every_visible_field_has_a_programmatic_label(): void {
		$dom = new \DOMDocument();
		$dom->loadHTML( '<!DOCTYPE html><html><body>' . $this->render() . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $dom );

		$labelled_ids = array();
		foreach ( $xpath->query( '//label[@for]' ) as $label ) {
			$labelled_ids[ $label->getAttribute( 'for' ) ] = true;
		}

		$fields    = $xpath->query( '//input[not(@type="hidden") and not(@type="submit")] | //textarea | //select' );
		$unlabeled = array();
		foreach ( $fields as $field ) {
			$id = $field->getAttribute( 'id' );
			if ( ! isset( $labelled_ids[ $id ] ) && ! $field->hasAttribute( 'aria-label' ) && ! $field->hasAttribute( 'aria-labelledby' ) ) {
				$unlabeled[] = $field->getAttribute( 'name' );
			}
		}

		$this->assertSame( 7, $fields->length, 'name, email, phone, address, subject, message and captcha render.' );
		$this->assertSame( array(), $unlabeled );
	}

	public function test_posted_values_and_translations_are_escaped(): void {
		foreach ( array( 'lafka_name', 'lafka_email', 'lafka_phone', 'lafka_address', 'lafka_subject', 'lafka_enquiry' ) as $field ) {
			$_POST[ $field ] = self::PAYLOAD;
		}

		$html = $this->render();

		$this->assertStringContainsString( 'value="' . htmlspecialchars( self::PAYLOAD, ENT_QUOTES ) . '" name="lafka_name"', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}
}
