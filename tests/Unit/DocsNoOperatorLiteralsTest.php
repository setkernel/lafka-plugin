<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * De-operator-ify guard for NX1-08g.
 *
 * docs/*.md ship inside a public, sellable plugin, so they must read as a
 * generic operator playbook — never as the launch operator's site. This test
 * scans every top-level markdown file under docs/ and fails if any
 * operator-specific literal (brand name, vanity domain, city/region, signature
 * menu item) appears. Genericised docs use neutral placeholders instead
 * ("Your Restaurant", example.com, "your city").
 *
 * Aggregator channel identifiers (ubereats / skipthedishes / doordash) are
 * deliberately NOT listed here: those are the tracking layer's channel enum,
 * a code contract documented verbatim in TRACKING.md, not operator brand data.
 */
final class DocsNoOperatorLiteralsTest extends TestCase {

	/**
	 * Operator-specific literals that must never appear in shipped docs.
	 * Each entry is a case-insensitive PCRE fragment (no delimiters).
	 *
	 * @var array<int, string>
	 */
	private const OPERATOR_LITERALS = array(
		'Peppery',
		'pepperypizzapoutine',
		'poutine',
		'Sackville',
		'Halifax',
		'\bHRM\b',
		'Garlic Fingers',
		'Meat Lovers',
	);

	private static function docs_dir(): string {
		return dirname( __DIR__, 2 ) . '/docs';
	}

	/**
	 * One case per top-level markdown file under docs/.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function docs_provider(): array {
		$files = glob( self::docs_dir() . '/*.md' );
		$cases = array();
		foreach ( (array) $files as $file ) {
			$cases[ basename( (string) $file ) ] = array( (string) $file );
		}
		return $cases;
	}

	#[DataProvider( 'docs_provider' )]
	public function test_doc_has_no_operator_literals( string $file ): void {
		$contents = (string) file_get_contents( $file );
		$hits     = array();

		foreach ( self::OPERATOR_LITERALS as $literal ) {
			if ( 1 === preg_match( '/' . $literal . '/i', $contents ) ) {
				$hits[] = $literal;
			}
		}

		$this->assertSame(
			array(),
			$hits,
			sprintf(
				'%s contains operator-specific literal(s) [%s]; docs must read as a '
					. 'generic operator playbook (use placeholders like "Your Restaurant", '
					. 'example.com, "your city").',
				basename( $file ),
				implode( ', ', $hits )
			)
		);
	}
}
