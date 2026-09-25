<?php
/**
 * Minimal WC_Shipping_Rate for unit tests (the constructor + getters Lafka reads).
 *
 * @package Lafka_Plugin
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting

if ( ! class_exists( 'WC_Shipping_Rate' ) ) {
	class WC_Shipping_Rate {
		public function __construct( public string $id = '', public string $label = '', public $cost = 0, public array $taxes = array(), public string $method_id = '', public $instance_id = 0 ) {}
		public function get_id() {
			return $this->id;
		}
		public function get_method_id() {
			return $this->method_id;
		}
		public function get_label() {
			return $this->label;
		}
		public function get_cost() {
			return $this->cost;
		}
	}
}
