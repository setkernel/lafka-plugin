<?php
/**
 * Minimal stand-in for WP_Error so test code can return an instance whose
 * `is_wp_error()` Brain Monkey stub responds true without booting WordPress.
 *
 * @package Lafka\Plugin\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error_Stub' ) ) {
	class WP_Error_Stub {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}
