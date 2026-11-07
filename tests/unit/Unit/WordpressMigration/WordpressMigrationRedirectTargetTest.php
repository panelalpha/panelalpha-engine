<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Where a source redirect leads: every Location form the guard turns into an absolute URL.
 */
class WordpressMigrationRedirectTargetTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function redirects(): array
    {
        return [
            'absolute, trimmed' => ['https://example.com/a/b', "  https://other.test/x \n", 'https://other.test/x'],
            'absolute, any scheme case' => ['https://example.com/a/b', 'HTTP://Other.test', 'HTTP://Other.test'],
            'absolute, another scheme' => ['https://example.com/a/b', 'ftp://files.test/x', 'ftp://files.test/x'],
            'protocol relative' => ['https://example.com:8443/a/b.php', '//cdn.test/x', 'https://cdn.test/x'],
            'root relative keeps the port' => ['https://example.com:8443/a/b.php', '/root', 'https://example.com:8443/root'],
            'root relative without a port' => ['http://example.com/a/b.php', '/root?x=1', 'http://example.com/root?x=1'],
            'path relative' => ['https://example.com/blog/wp-login.php', 'wp-admin/', 'https://example.com/blog/wp-admin/'],
            'path relative on a directory' => ['https://example.com/blog/', 'x', 'https://example.com/blog/x'],
            'path relative without a path' => ['https://example.com', 'wp-admin/', 'https://example.com/wp-admin/'],
            'path relative at the root' => ['https://example.com/noslash', 'x', 'https://example.com/x'],
            'query only' => ['https://example.com/a?q=1', '?page=2', 'https://example.com/?page=2'],
            'no scheme separator is relative' => ['https://example.com/a/b', 'mailto:x', 'https://example.com/a/mailto:x'],
            'ipv6 host keeps its brackets' => ['http://[2001:db8::1]:8080/wp/login.php', 'admin/', 'http://[2001:db8::1]:8080/wp/admin/'],
            'ipv6 host, root relative' => ['http://[2001:db8::1]/wp/', '/x', 'http://[2001:db8::1]/x'],
        ];
    }

    #[DataProvider('redirects')]
    public function test_a_location_becomes_an_absolute_url(string $current, string $location, string $expected): void
    {
        $this->assertSame($expected, SourceFetchGuard::resolveRedirect($current, $location));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusals(): array
    {
        return [
            'empty location' => ['https://example.com/a', '', 'Source WordPress redirect is missing a target'],
            'blank location' => ['https://example.com/a', " \t ", 'Source WordPress redirect is missing a target'],
            'current without a scheme' => ['example.com/a', 'x', 'Source WordPress URL is invalid'],
            'current without a host' => ['file:///a/b', 'x', 'Source WordPress URL is invalid'],
            'current that does not parse' => ['http:///a', 'x', 'Source WordPress URL is invalid'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_redirect_that_cannot_be_resolved_is_refused(string $current, string $location, string $message): void
    {
        try {
            SourceFetchGuard::resolveRedirect($current, $location);
            $this->fail('The redirect must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_an_absolute_location_is_returned_even_when_the_current_url_is_invalid(): void
    {
        $this->assertSame('https://other.test/x', SourceFetchGuard::resolveRedirect('not a url', 'https://other.test/x'));
    }
}
