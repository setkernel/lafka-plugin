<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The plugin loads only the 'lafka-plugin' text domain. Any gettext call under
 * another domain (the theme's 'lafka', legacy widget domains, the implicit
 * 'default') falls back to English whenever the plugin runs on its own, and a
 * variable msgid (esc_attr_e( $var )) translates a runtime value against
 * 'default'. Token-walks every plugin PHP file (vendor / node_modules / tests
 * and dot-directories pruned) once per process.
 */
final class GettextDomainConsistencyTest extends TestCase {

	private const EXPECTED_DOMAIN = 'lafka-plugin';

	/**
	 * Directory names anywhere in the path that are excluded from the scan.
	 *
	 * @var array<int, string>
	 */
	private const SKIP_DIRS = array( 'vendor', 'node_modules', 'tests' );

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
	 * Every PHP file in the plugin. Excluded directories are pruned rather than
	 * walked, so the thousands of vendor / node_modules files are never visited.
	 *
	 * @return array<int, \SplFileInfo>
	 */
	private static function php_files(): array {
		$skip     = array_flip( self::SKIP_DIRS );
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( self::plugin_root(), \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $file ) use ( $skip ): bool {
					if ( $file->isDir() ) {
						return ! isset( $skip[ $file->getFilename() ] ) && ! str_starts_with( $file->getFilename(), '.' );
					}
					return 'php' === $file->getExtension();
				}
			)
		);

		return iterator_to_array( $iterator, false );
	}

	/**
	 * Token-walk every gettext call in the plugin and classify offenders.
	 *
	 * Memoized: both tests consume it, and the whole plugin is tokenized once.
	 *
	 * @return array{wrong_domain: array<int, string>, non_literal_text: array<int, string>}
	 */
	private static function collect_offenders(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$functions        = array_flip( self::GETTEXT_FUNCTIONS );
		$wrong_domain     = array();
		$non_literal_text = array();

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

				$domain = trim( $last_top_arg[1], "'\"" );
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
}
