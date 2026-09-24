<?php
/**
 * Minimal WP_Error for tests that construct or inspect one without WordPress.
 *
 * @package Lafka\Plugin\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public string $code;
		public string $message;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
