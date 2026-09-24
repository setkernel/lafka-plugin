<?php
/**
 * C-10: load_plugin_textdomain() must run before init:5 so CPT/taxonomy labels
 * (registered at init:5) are translated. It used to hook `init` (priority 10),
 * after label registration, so labels rendered untranslated on non-English
 * sites.
 *
 * Source-level guard: the hook lives in lafka-plugin.php, which cannot be
 * loaded in a unit test.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TextDomainPriorityTest extends TestCase {

	public function test_textdomain_loads_on_plugins_loaded_before_init(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );

		preg_match_all( "/add_action\(\s*'([a-z_]+)'\s*,\s*'lafka_load_plugin_text_domain'/", $src, $m );

		$this->assertSame( array( 'plugins_loaded' ), $m[1], 'The text domain must load on plugins_loaded (and only there) so it precedes init:5 label registration.' );
	}
}
