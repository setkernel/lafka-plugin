<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for audit f096, widened for NX1-07p.
 *
 * The plugin loads its catalog under the 'lafka-plugin' text domain
 * (load_plugin_textdomain in lafka-plugin.php). Audit f096 fixed ~16 gettext
 * calls under incl/ that passed the THEME's 'lafka' domain instead, so their
 * msgids never landed in the plugin POT and fell back to English whenever the
 * plugin ran without the Lafka theme.
 *
 * The original guard only walked incl/, which meant stray domains in the
 * root plugin file, the classic widgets under widgets/, and the shortcode
 * partials under shortcodes/ ('lafka', 'lafka-foodmenu', 'lafka-widgets-thumb',
 * 'lafka-stretched-header', one-off widget domains) were tolerated. NX1-07p
 * widens the scan to the ENTIRE plugin (excluding vendor / node_modules /
 * tests) and adds two further guards:
 *
 *   1. No gettext call may pass a literal text domain other than 'lafka-plugin'
 *      (widened scope).
 *   2. No gettext ECHO/RETURN call may pass a non-literal (variable) msgid:
 *      esc_attr_e( $var ) / esc_html_e( $var ) etc. silently translate a
 *      runtime value against the implicit 'default' domain — an invisible
 *      stray domain that never loads the plugin catalog. Such calls must be
 *      plain escape-and-echo (echo esc_attr( $var )) instead.
 *   3. None of the named legacy stray domains may appear as a gettext domain.
 *
 * Only the argument lists of the gettext functions below are inspected, so
 * wp_cache_* group names and other non-gettext uses of the bare 'lafka'
 * literal (e.g. lafka-bestseller.php / lafka-asset-pruning.php cache groups)
 * are ignored. Calls whose domain is a variable / computed expression are
 * skipped for the domain check because they cannot be asserted statically.
 */
final class GettextDomainConsistencyTest extends TestCase {

	private const EXPECTED_DOMAIN = 'lafka-plugin';

	/**
	 * Directory names anywhere in the path that are excluded from the scan.
	 *
	 * @var array<int, string>
	 */
	private const SKIP_DIRS = array( 'vendor', 'node_modules', 'tests', '.git' );

	/**
	 * Legacy stray text domains that must never resurface as a gettext domain.
	 * These once shipped in widgets / shortcodes and never loaded a catalog.
	 *
	 * @var array<int, string>
	 */
	private const KNOWN_STRAY_DOMAINS = array(
		'lafka',
		'lafka-foodmenu',
		'lafka-widgets-thumb',
		'lafka-stretched-header',
		'lafka-widgets',
		'default',
	);

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
	 * Every PHP file in the plugin except the excluded tooling directories.
	 *
	 * @return array<int, \SplFileInfo>
	 */
	private static function php_files(): array {
		$root  = self::plugin_root();
		$files = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
			$segments = explode( DIRECTORY_SEPARATOR, $relative );
			if ( array_intersect( $segments, self::SKIP_DIRS ) ) {
				continue;
			}

			$files[] = $file;
		}

		return $files;
	}

	/**
	 * Token-walk every gettext call in the plugin and classify offenders.
	 *
	 * The result is memoized because several test methods (and one data
	 * provider) consume it — re-tokenizing the whole plugin per call would be
	 * needlessly slow and memory-heavy.
	 *
	 * @return array{wrong_domain: array<int, string>, non_literal_text: array<int, string>, domains: array<int, array{0: string, 1: string, 2: int, 3: string}>}
	 */
	private static function collect_offenders(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$functions        = array_flip( self::GETTEXT_FUNCTIONS );
		$wrong_domain     = array();
		$non_literal_text = array();
		$domains          = array();

		foreach ( self::php_files() as $file ) {
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

				// First significant token of argument 1 is the msgid.
				$p = $j + 1;
				while ( $p < $count && is_array( $tokens[ $p ] )
					&& in_array( $tokens[ $p ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					++$p;
				}
				$first_arg = $tokens[ $p ] ?? null;
				if ( ! is_array( $first_arg ) || T_CONSTANT_ENCAPSED_STRING !== $first_arg[0] ) {
					$non_literal_text[] = sprintf(
						'%s:%d %s() msgid is not a string literal — use plain escape-and-echo instead',
						$file->getFilename(),
						$token[2],
						$token[1]
					);
					continue;
				}

				// Walk the argument list; the text domain is the last top-level
				// argument and only matters when it is a string literal.
				$depth        = 0;
				$last_top_arg = null;
				for ( $q = $j; $q < $count; $q++ ) {
					$inner = $tokens[ $q ];
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

				$domain      = trim( $last_top_arg[1], "'\"" );
				$domains[]   = array( $file->getFilename(), $token[1], $token[2], $domain );
				if ( self::EXPECTED_DOMAIN !== $domain ) {
					$wrong_domain[] = sprintf(
						'%s:%d %s() uses text domain "%s"',
						$file->getFilename(),
						$token[2],
						$token[1],
						$domain
					);
				}
			}
		}

		sort( $wrong_domain );
		sort( $non_literal_text );
		$cache = array(
			'wrong_domain'     => $wrong_domain,
			'non_literal_text' => $non_literal_text,
			'domains'          => $domains,
		);
		return $cache;
	}

	public function test_all_plugin_gettext_calls_use_plugin_domain(): void {
		$offenders = self::collect_offenders()['wrong_domain'];
		$this->assertSame(
			array(),
			$offenders,
			"Every plugin gettext string must use the 'lafka-plugin' text domain "
				. "(the domain the plugin loads); no other domain — the theme's "
				. "'lafka', legacy widget domains, or the implicit 'default' — is "
				. "loaded when the plugin runs on its own.\n"
				. implode( "\n", $offenders )
		);
	}

	public function test_no_gettext_call_uses_non_literal_text(): void {
		$offenders = self::collect_offenders()['non_literal_text'];
		$this->assertSame(
			array(),
			$offenders,
			"A gettext call whose msgid is a runtime variable (e.g. esc_attr_e( \$value )) "
				. "translates that value against the implicit 'default' domain, which never "
				. "loads the plugin catalog. Use plain escape-and-echo (echo esc_attr( \$value )) "
				. "for dynamic output instead.\n"
				. implode( "\n", $offenders )
		);
	}

	#[DataProvider( 'known_stray_domain_provider' )]
	public function test_known_stray_domain_is_never_a_gettext_domain( string $stray ): void {
		$hits = array();
		foreach ( self::collect_offenders()['domains'] as $call ) {
			list( $filename, $function, $line, $domain ) = $call;
			if ( $stray === $domain ) {
				$hits[] = sprintf( '%s:%d %s()', $filename, $line, $function );
			}
		}

		$this->assertSame(
			array(),
			$hits,
			sprintf(
				'The legacy stray text domain "%s" must never appear as a gettext '
					. "domain — it loads no catalog. Normalize to 'lafka-plugin'.\n%s",
				$stray,
				implode( "\n", $hits )
			)
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function known_stray_domain_provider(): array {
		$cases = array();
		foreach ( self::KNOWN_STRAY_DOMAINS as $domain ) {
			$cases[ $domain ] = array( $domain );
		}
		return $cases;
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
