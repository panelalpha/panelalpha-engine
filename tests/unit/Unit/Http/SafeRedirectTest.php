<?php

namespace Tests\Unit\Http;

use App\Lib\Helpers\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SSO redirect target comes out of the account's own overrides/app.sh, so
 * it is customer input reaching a Location header on the engine's origin.
 */
class SafeRedirectTest extends TestCase
{
    #[DataProvider('offSiteProvider')]
    public function test_an_off_site_target_is_reduced_to_its_path(string $redirect, string $expected): void
    {
        $this->assertSame($expected, SafeRedirect::toPath($redirect));
    }

    #[DataProvider('inAppProvider')]
    public function test_an_in_app_target_is_left_alone(string $redirect, string $expected): void
    {
        $this->assertSame($expected, SafeRedirect::toPath($redirect));
    }

    public function test_an_empty_target_falls_back_to_the_app_root(): void
    {
        $this->assertSame('/', SafeRedirect::toPath(''));
        $this->assertSame('/', SafeRedirect::toPath(null));
        $this->assertSame('/', SafeRedirect::toPath('   '));
    }

    public function test_a_header_splitting_target_is_refused(): void
    {
        $this->assertSame('/', SafeRedirect::toPath("https://evil.test\r\nSet-Cookie: a=1"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function offSiteProvider(): array
    {
        return [
            'absolute https' => ['https://evil.test/pwned', '/pwned'],
            'absolute with credentials' => ['http://user:pass@evil.test/x?y=1', '/x?y=1'],
            'protocol relative' => ['//evil.test/pwned', '/pwned'],
            'bare host' => ['https://evil.test', '/'],
            // A browser reads the backslash as a slash, so `/\host` is another
            // spelling of `//host` -- the case a "must start with /" check misses.
            'backslash protocol relative' => ['/\\evil.test/pwned', '/'],
            'double backslash' => ['\\\\evil.test/pwned', '/'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function inAppProvider(): array
    {
        return [
            'root' => ['/', '/'],
            'path' => ['/wp-admin/', '/wp-admin/'],
            'path with query and fragment' => ['/wp-admin/index.php?a=1#top', '/wp-admin/index.php?a=1#top'],
            'relative path gains a slash' => ['wp-admin/', '/wp-admin/'],
        ];
    }
}
