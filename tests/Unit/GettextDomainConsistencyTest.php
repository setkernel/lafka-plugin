<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for audit f096.
 *
 * The plugin loads its catalog under the 'lafka-plugin' text domain
 * (load_plugin_textdomain in lafka-plugin.php), but ~16 gettext calls passed
 * the THEME's 'lafka' domain instead — dietary filter chips on the menu
 * archive, the storefront closed-store notice, and admin media-picker labels.
 * Those msgids never landed in the plugin POT, so translators never saw them
 * and they fell back to English whenever the plugin ran without the Lafka
 * theme (the 'lafka' domain is not loaded at all in that case).
 *
 * This test fails if ANY gettext call under incl/ uses a text-domain literal
 * other than 'lafka-plugin'. It only inspects the argument list of the gettext
 * functions below, so wp_cache_* group names and other non-gettext uses of the
 * bare 'lafka' literal are ignored. Calls whose domain is a variable /
 * computed expression are skipped because they cannot be asserted statically.
 */
final class GettextDomainConsistencyTest extends TestCase {

	private const EXPECTED_DOMAIN = 'lafka-plugin';

	/**
	 * Gettext functions whose final string-literal argument is the text domain.
	 *
	 * @var array<int, string>
	 */
	private const GETTEXT_FUNCTIONS = array(
		'__',
		'_e',
		'_x',
		'_ex',
		'_n',
		'_nx',
		'_n_noop',
		'_nx_noop',
		'esc_html__',
		'esc_html_e',
		'esc_html_x',
		'esc_attr__',
		'esc_attr_e',
		'esc_attr_x',
	);

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Walk every PHP file under incl/ and collect gettext calls whose literal
	 * text domain is not 'lafka-plugin'.
	 *
	 * @return array<int, string> Human-readable offender descriptions.
	 */
	private static function find_wrong_domain_calls(): array {
		$functions = array_flip( self::GETTEXT_FUNCTIONS );
		$root      = self::plugin_root() . '/incl';
		$offenders = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) );
			$count  = count( $tokens );

			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];
				if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) ) {
					continue;
				}

				// The next significant token must be the call's opening paren.
				$j = $i + 1;
				while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}
				if ( $j >= $count || '(' !== $tokens[ $j ] ) {
					continue;
				}

				// Skip method / static / declaration uses of the same name.
				$k = $i - 1;
				while ( $k >= 0 && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
					--$k;
				}
				if ( $k >= 0 && is_array( $tokens[ $k ] )
					&& in_array( $tokens[ $k ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
					continue;
				}

				// Walk the argument list; the text domain is the last top-level
				// argument and only matters when it is a string literal.
				$depth        = 0;
				$last_top_arg = null;
				for ( $p = $j; $p < $count; $p++ ) {
					$inner = $tokens[ $p ];
					if ( '(' === $inner ) {
						++$depth;
						continue;
					}
					if ( ')' === $inner ) {
						--$depth;
						if ( 0 === $depth ) {
							break;
						}
						continue;
					}
					if ( 1 === $depth ) {
						if ( is_array( $inner ) ) {
							if ( T_WHITESPACE === $inner[0] ) {
								continue;
							}
							$last_top_arg = $inner;
						} else {
							// A comma or other top-level punctuation resets the
							// "current argument" tracking.
							$last_top_arg = null;
						}
					}
				}

				if ( null === $last_top_arg || T_CONSTANT_ENCAPSED_STRING !== $last_top_arg[0] ) {
					continue; // Variable / computed domain — not statically checkable.
				}

				$domain = trim( $last_top_arg[1], "'\"" );
				if ( self::EXPECTED_DOMAIN !== $domain ) {
					$offenders[] = sprintf(
						'%s:%d %s() uses text domain "%s"',
						$file->getFilename(),
						$token[2],
						$token[1],
						$domain
					);
				}
			}
		}

		sort( $offenders );
		return $offenders;
	}

	public function test_all_plugin_gettext_calls_use_plugin_domain(): void {
		$offenders = self::find_wrong_domain_calls();
		$this->assertSame(
			array(),
			$offenders,
			"Every plugin gettext string must use the 'lafka-plugin' text domain "
				. "(the domain the plugin loads); the theme's 'lafka' domain is not "
				. "loaded when the plugin runs without the Lafka theme.\n"
				. implode( "\n", $offenders )
		);
	}

	/**
	 * The specific strings flagged by audit f096, with the source file each
	 * lives in. Each must now be wrapped with the plugin domain and must NOT
	 * use the theme's 'lafka' domain.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function audited_string_provider(): array {
		$dietary = 'incl/woocommerce/lafka-dietary-tags.php';
		$hours   = 'incl/order-hours/Lafka_Order_Hours.php';
		$meta    = 'incl/woocommerce-metaboxes.php';

		return array(
			'dietary: Popular'          => array( $dietary, 'Popular' ),
			'dietary: Vegetarian'       => array( $dietary, 'Vegetarian' ),
			'dietary: Vegan'            => array( $dietary, 'Vegan' ),
			'dietary: Spicy'            => array( $dietary, 'Spicy' ),
			'dietary: vegan desc'       => array( $dietary, 'No animal products of any kind.' ),
			'hours: Closed right now'   => array( $hours, 'Closed right now' ),
			'hours: Opens %s'           => array( $hours, 'Opens %s' ),
			'hours: closed notice'      => array( $hours, 'Sorry, the store is currently closed and is not accepting orders.' ),
			'meta: Choose an image'     => array( $meta, 'Choose an image' ),
			'meta: Use image'           => array( $meta, 'Use image' ),
		);
	}

	#[DataProvider( 'audited_string_provider' )]
	public function test_audited_string_uses_plugin_domain( string $relative_path, string $msgid ): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . $relative_path );
		$quoted = preg_quote( $msgid, '/' );

		$this->assertMatchesRegularExpression(
			"/(?:__|_e|_x|esc_html__|esc_attr__)\(\s*'" . $quoted . "'.*?'lafka-plugin'/s",
			$source,
			sprintf( '"%s" in %s must be wrapped with the lafka-plugin text domain.', $msgid, $relative_path )
		);

		$this->assertDoesNotMatchRegularExpression(
			"/'" . $quoted . "'\s*,(?:\s*'[^']*'\s*,)?\s*'lafka'\s*\)/",
			$source,
			sprintf( '"%s" in %s must not use the theme\'s lafka text domain.', $msgid, $relative_path )
		);
	}
}
