<?php
/**
 * Test-only stub for WooCommerce Blocks' IntegrationInterface.
 *
 * Lafka_Blocks_Integration only defines itself when this interface exists (it is
 * shipped by WooCommerce Blocks, not by the plugin). Unit tests do not boot
 * WooCommerce, so this minimal interface lets the integration class load and its
 * registration/enqueue contract be asserted in isolation.
 *
 * @package Lafka\Plugin\Tests
 */

// phpcs:disable
namespace Automattic\WooCommerce\Blocks\Integrations;

if ( ! interface_exists( __NAMESPACE__ . '\\IntegrationInterface' ) ) {
	interface IntegrationInterface {
		public function get_name();
		public function initialize();
		public function get_script_handles();
		public function get_editor_script_handles();
		public function get_script_data();
	}
}
