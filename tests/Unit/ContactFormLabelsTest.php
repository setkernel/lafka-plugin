<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * C-11 C-A11Y-Audit-2026-04-29: regression lock — every contact form input must
 * have a programmatic label association (WCAG 4.1.2 / 1.3.1).
 *
 * Verifies via source-grep that each named form field either:
 *   (a) is preceded by a <label for="<id>"> AND has a matching id="<id>", or
 *   (b) carries aria-label or aria-labelledby on the input/textarea element.
 */
final class ContactFormLabelsTest extends TestCase {

	private string $template;

	protected function setUp(): void {
		parent::setUp();
		$path = dirname( __DIR__, 2 ) . '/shortcodes/partials/contact-form.php';
		$this->assertFileExists( $path, 'contact-form.php partial not found' );
		$this->template = file_get_contents( $path );
	}

	// ------------------------------------------------------------------
	// Helper
	// ------------------------------------------------------------------

	/**
	 * Assert that the template contains a <label for="$id"> AND an element
	 * with id="$id", OR that the named input/textarea carries aria-label or
	 * aria-labelledby.
	 *
	 * @param string $fieldId  Expected id attribute value (e.g. 'lafka-contact-name').
	 * @param string $fieldName Expected name attribute value on the input/textarea.
	 */
	private function assertFieldIsLabelled( string $fieldId, string $fieldName ): void {
		$hasLabelFor = (bool) preg_match(
			'/<label[^>]+for="' . preg_quote( $fieldId, '/' ) . '"/',
			$this->template
		);
		$hasIdOnField = (bool) preg_match(
			'/<(?:input|textarea|select)[^>]+id="' . preg_quote( $fieldId, '/' ) . '"/',
			$this->template
		);
		$hasAriaLabel = (bool) preg_match(
			'/<(?:input|textarea|select)[^>]+name="' . preg_quote( $fieldName, '/' ) . '"[^>]+aria-label(?:ledby)?=/',
			$this->template
		);
		$hasAriaLabelAlt = (bool) preg_match(
			'/<(?:input|textarea|select)[^>]+aria-label(?:ledby)?=[^>]+name="' . preg_quote( $fieldName, '/' ) . '"/',
			$this->template
		);

		$labelPairOk    = $hasLabelFor && $hasIdOnField;
		$ariaOk         = $hasAriaLabel || $hasAriaLabelAlt;

		$this->assertTrue(
			$labelPairOk || $ariaOk,
			"Field '$fieldName' (id='$fieldId') must have either a matching <label for> + id pair " .
			'or an aria-label / aria-labelledby attribute (WCAG 4.1.2 / 1.3.1 — C-11)'
		);
	}

	// ------------------------------------------------------------------
	// Tests — one per form field
	// ------------------------------------------------------------------

	public function test_name_field_is_labelled(): void {
		$this->assertFieldIsLabelled( 'lafka-contact-name', 'lafka_name' );
	}

	public function test_email_field_is_labelled(): void {
		$this->assertFieldIsLabelled( 'lafka-contact-email', 'lafka_email' );
	}

	public function test_phone_field_is_labelled(): void {
		$this->assertFieldIsLabelled( 'lafka-contact-phone', 'lafka_phone' );
	}

	public function test_address_field_is_labelled(): void {
		$this->assertFieldIsLabelled( 'lafka-contact-address', 'lafka_address' );
	}

	public function test_subject_field_is_labelled(): void {
		$this->assertFieldIsLabelled( 'lafka-contact-subject', 'lafka_subject' );
	}

	public function test_message_textarea_is_labelled(): void {
		$this->assertFieldIsLabelled( 'lafka-contact-message', 'lafka_enquiry' );
	}

	public function test_captcha_input_is_labelled(): void {
		$this->assertFieldIsLabelled( 'lafka-contact-captcha', 'lafka_captcha_answer' );
	}

	/** Ensure the template actually uses screen-reader-text class on labels. */
	public function test_labels_use_screen_reader_text_class(): void {
		$this->assertMatchesRegularExpression(
			'/<label[^>]+class="screen-reader-text"/',
			$this->template,
			'Labels in contact-form.php must use the screen-reader-text class to stay visually hidden'
		);
	}

	/** Audit provenance comment must be present. */
	public function test_c11_audit_comment_present(): void {
		$this->assertStringContainsString(
			'C-A11Y-Audit-2026-04-29',
			$this->template,
			'contact-form.php must carry C-A11Y-Audit-2026-04-29 provenance comments'
		);
	}
}
