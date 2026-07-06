<?php
/**
 * Minimal WP_Post stub for unit tests.
 *
 * Brain Monkey can't redefine instanceof targets after the fact, so any module
 * that does `$x instanceof WP_Post` needs the symbol declared in the global
 * namespace before its source file is loaded. We define a permissive stub
 * that only exposes the fields the modules under test actually touch
 * (ID, post_name, post_content).
 *
 * @package Lafka\Plugin\Tests\Unit\Stubs
 */

if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_Post { // phpcs:ignore
		public int $ID = 0;
		public string $post_name = '';
		public string $post_content = '';
		public string $post_type = '';
		public function __construct( $row = null ) {
			if ( is_object( $row ) ) {
				foreach ( (array) $row as $k => $v ) {
					if ( property_exists( $this, $k ) ) {
						$this->$k = $v;
					}
				}
			}
		}
	}
}
