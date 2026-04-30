<?php
/**
 * Minimal stand-in for WP_Widget so widget classes can be loaded and
 * instantiated in unit tests without a full WordPress bootstrap.
 *
 * Only the surface widget tests touch is implemented. Tests that need
 * actual widget rendering (`widget()`, `form()`, `update()` end-to-end)
 * need a richer harness.
 *
 * @package Lafka\Plugin\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Widget' ) ) {
	class WP_Widget { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public string $id_base        = '';
		public string $name           = '';
		public string $option_name    = '';
		public ?string $alt_option_name = null;
		public string $id             = '';
		public int $number            = 0;

		public function __construct( $id_base = '', $name = '', $widget_options = array(), $control_options = array() ) {
			$this->id_base = (string) $id_base;
			$this->name    = (string) $name;
		}

		public function get_field_id( $field_name ): string {
			return 'widget-' . $this->id_base . '-' . $field_name;
		}

		public function get_field_name( $field_name ): string {
			return 'widget-' . $this->id_base . '[' . $field_name . ']';
		}
	}
}
