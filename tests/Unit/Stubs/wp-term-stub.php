<?php
/**
 * Minimal WP_Term stub for unit tests.
 *
 * Modules check `$x instanceof WP_Term`, so the symbol must exist before the
 * module under test loads. Exposes only the public fields the modules read.
 *
 * @package Lafka\Plugin\Tests\Unit\Stubs
 */

if ( ! class_exists( 'WP_Term' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_Term { // phpcs:ignore
		public int $term_id = 0;
		public string $name = '';
		public string $slug = '';
		public string $taxonomy = '';
		public string $description = '';
		public int $parent = 0;
		public int $count = 0;
		/** @param array<string,mixed> $props */
		public function __construct( array $props = array() ) {
			foreach ( $props as $k => $v ) {
				if ( property_exists( $this, $k ) ) {
					$this->$k = $v;
				}
			}
		}
	}
}
