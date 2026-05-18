<?php
/**
 * Minimal stand-in for WC_Email so the KDS email subclasses can be loaded
 * and instantiated in unit tests without a full WooCommerce bootstrap.
 *
 * Only the surface the KDS email classes actually touch in their constructor
 * + identity tests is implemented. Behavioural tests (trigger flow, content
 * rendering, dedupe) need a richer stub or an integration test against WC.
 *
 * IMPORTANT: do not define WP functions here (`__`, `esc_html`, etc.).
 * Brain\Monkey + Patchwork need to be able to redefine those per-test, and a
 * hard `function` declaration breaks every other test in the suite. Tests
 * that include this stub must bring up Brain\Monkey themselves (see
 * Brain\Monkey\setUp() / Functions::when() in their own setUp method).
 *
 * @package Lafka_Kitchen_Display
 */

declare(strict_types=1);

if ( ! class_exists( 'WC_Email' ) ) {
	class WC_Email { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public string $id              = '';
		public bool $customer_email    = false;
		public string $title           = '';
		public string $description     = '';
		public string $template_html   = '';
		public string $template_plain  = '';
		public string $template_base   = '';
		public array $placeholders     = array();
		public $object                 = null;
		public string $recipient       = '';

		public function __construct() {}

		public function setup_locale(): void {}
		public function restore_locale(): void {}

		public function is_enabled(): bool {
			return true;
		}

		public function get_recipient(): string {
			return $this->recipient;
		}

		public function get_subject(): string {
			return '';
		}

		public function get_heading(): string {
			return '';
		}

		public function get_content(): string {
			return '';
		}

		public function get_headers(): string {
			return '';
		}

		public function get_attachments(): array {
			return array();
		}

		public function get_additional_content(): string {
			return method_exists( $this, 'get_default_additional_content' ) ? (string) $this->get_default_additional_content() : '';
		}

		public function get_blogname(): string {
			return 'Test Site';
		}

		public function send( $to, $subject, $message, $headers = '', $attachments = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return true;
		}
	}
}
