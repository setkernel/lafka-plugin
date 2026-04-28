<?php
declare(strict_types=1);

namespace Lafka\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P6-PERF-6 regression lock: flatpickr l10n must be gated by get_locale(),
 * not enqueued in bulk over the entire l10n directory.
 */
final class FlatpickrL10nTest extends TestCase {

    public function test_flatpickr_l10n_enqueue_uses_get_locale(): void {
        $hits = $this->find_files_referencing( 'flatpickr-l10n' );
        $this->assertNotEmpty( $hits, 'No file references the flatpickr-l10n handle' );

        foreach ( $hits as $file ) {
            $contents = file_get_contents( $file );
            $this->assertStringContainsString(
                'get_locale()',
                $contents,
                "$file enqueues flatpickr-l10n but does not gate by get_locale()"
            );
        }
    }

    public function test_no_glob_enqueue_of_all_l10n_files(): void {
        $hits = $this->find_files_referencing( 'flatpickr/l10n' );
        foreach ( $hits as $file ) {
            $contents = file_get_contents( $file );
            // No naive foreach over glob() of the l10n dir
            $this->assertDoesNotMatchRegularExpression(
                "/foreach\s*\(\s*glob\([^)]*flatpickr\/l10n[^)]*\)/",
                $contents,
                "$file appears to glob+enqueue all flatpickr l10n files"
            );
        }
    }

    private function find_files_referencing( string $needle ): array {
        $hits = [];
        $rii  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ), \RecursiveDirectoryIterator::SKIP_DOTS )
        );
        foreach ( $rii as $f ) {
            if ( $f->isFile() && str_ends_with( $f->getFilename(), '.php' ) ) {
                $contents = file_get_contents( $f->getPathname() );
                if ( strpos( $contents, $needle ) !== false ) {
                    $hits[] = $f->getPathname();
                }
            }
        }
        return $hits;
    }
}
