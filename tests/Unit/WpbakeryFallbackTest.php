<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/compat/lafka-wpbakery-fallback.php';

/**
 * WPBakery graceful-fallback shim: strip orphaned [vc_*] wrappers, keep content.
 */
final class WpbakeryFallbackTest extends TestCase {

	public function test_strips_vc_wrappers_keeps_inner_content(): void {
		$in  = '[vc_row css=".vc_custom_1{padding:0}"][vc_column width="7/12"]<h4>Send us a Message</h4>[lafka_contact_form to="x@y.z"][/vc_column][/vc_row]';
		$out = lafka_wpbakery_strip_orphans( $in );
		self::assertStringNotContainsString( '[vc_', $out, 'all vc_ wrapper tags removed' );
		self::assertStringNotContainsString( '[/vc_', $out );
		self::assertStringContainsString( '<h4>Send us a Message</h4>', $out, 'inner HTML preserved' );
		self::assertStringContainsString( '[lafka_contact_form to="x@y.z"]', $out, 'nested first-party shortcode preserved' );
	}

	public function test_strips_woocommerce_shortcode_wrapper(): void {
		$out = lafka_wpbakery_strip_orphans( '[vc_row][vc_column][woocommerce_cart][/vc_column][/vc_row]' );
		self::assertSame( '[woocommerce_cart]', $out );
	}

	public function test_noop_when_no_vc_marker(): void {
		$plain = '<p>Just regular content with [lafka_map] in it.</p>';
		self::assertSame( $plain, lafka_wpbakery_strip_orphans( $plain ) );
	}

	public function test_returns_string_for_empty(): void {
		self::assertSame( '', lafka_wpbakery_strip_orphans( '' ) );
	}

	public function test_detection_helpers_exist(): void {
		self::assertTrue( function_exists( 'lafka_wpbakery_is_active' ) );
		self::assertTrue( function_exists( 'lafka_revslider_is_active' ) );
		// In the unit context neither plugin is loaded.
		self::assertFalse( lafka_wpbakery_is_active() );
		self::assertFalse( lafka_revslider_is_active() );
	}
}
