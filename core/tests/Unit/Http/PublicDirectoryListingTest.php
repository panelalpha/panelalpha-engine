<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * public/test-report/.htaccess turned directory listings
 * on. Core serves public/ through nginx, which ignores .htaccess, so it was
 * inert -- until someone fronts core with Apache, or copies the file.
 */
class PublicDirectoryListingTest extends TestCase
{
    public function test_nothing_under_public_turns_directory_listings_on(): void
    {
        $public = dirname(__DIR__, 3) . '/public';
        $offenders = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getFilename() !== '.htaccess') {
                continue;
            }
            if (preg_match('/^\s*Options\b[^\n]*\+Indexes/mi', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = substr($file->getPathname(), strlen($public) + 1);
            }
        }

        $this->assertSame([], $offenders);
    }
}
