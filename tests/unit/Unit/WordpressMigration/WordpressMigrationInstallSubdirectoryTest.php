<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The install subdirectory a source URL names, whatever page of the install the user pasted.
 */
class WordpressMigrationInstallSubdirectoryTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function urls(): array
    {
        return [
            'no path' => ['https://example.com', ''],
            'root' => ['https://example.com/', ''],
            'subdirectory' => ['https://example.com/blog', 'blog'],
            'subdirectory with a slash' => ['https://example.com/blog/', 'blog'],
            'nested subdirectory' => ['https://example.com/a/b/', 'a/b'],
            'login page' => ['https://example.com/blog/wp-login.php', 'blog'],
            'login page at the root' => ['https://example.com/wp-login.php', ''],
            'index page' => ['https://example.com/blog/index.php', 'blog'],
            'index page with a slash' => ['https://example.com/blog/index.php/', 'blog'],
            'admin' => ['https://example.com/blog/wp-admin', 'blog'],
            'admin with a slash' => ['https://example.com/wp-admin/', ''],
            'admin index page' => ['https://example.com/a/b/wp-admin/index.php', 'a/b'],
            'name ending like the login page' => ['https://example.com/mywp-login.php', ''],
            'name ending like admin' => ['https://example.com/xwp-admin', 'xwp-admin'],
            'query and fragment' => ['https://example.com/blog/?p=1#top', 'blog'],
            'relative login page' => ['wp-login.php', ''],
            'path only' => ['/shop/wp-admin', 'shop'],
            'many slashes' => ['https://example.com///', ''],
        ];
    }

    #[DataProvider('urls')]
    public function test_the_install_subdirectory_of_a_url(string $url, string $expected): void
    {
        $this->assertSame($expected, SourceUrl::installSubdirectory($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escapes(): array
    {
        return [
            'empty segment' => ['https://example.com/a//b'],
            'parent segment' => ['https://example.com/a/../b'],
            'current segment' => ['https://example.com/./x'],
            'parent only' => ['https://example.com/..'],
        ];
    }

    #[DataProvider('escapes')]
    public function test_a_path_leaving_the_document_root_is_refused(string $url): void
    {
        try {
            SourceUrl::installSubdirectory($url);
            $this->fail('The path must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame('Source WordPress URL path must stay under the document root.', $e->getMessage());
        }
    }

    public function test_the_destination_site_url_carries_the_subdirectory(): void
    {
        $this->assertSame('https://dest.example.test/a/b', SourceUrl::destinationSiteUrl('dest.example.test', 'https://example.com/a/b/wp-admin/'));
        $this->assertSame('https://dest.example.test', SourceUrl::destinationSiteUrl('dest.example.test', 'https://example.com/wp-login.php'));
    }
}
