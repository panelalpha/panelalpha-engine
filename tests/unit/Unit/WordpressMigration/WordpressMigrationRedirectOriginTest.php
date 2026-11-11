<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which redirect hops the source client may take: a 307 or 308 resends the token, the body and the
 * wp-admin password, so it stays on the site; no hop leaves https for plain http.
 */
class WordpressMigrationRedirectOriginTest extends TestCase
{
    /**
     * @return array<string, array{int, string, string, string}>
     */
    public static function refused(): array
    {
        $downgrade = 'The source WordPress site redirects from https to plain http at http://example.com; enter that URL to import over plain http';

        return [
            'https to http 307' => [307, 'https://example.com/wp-login.php', 'http://example.com/wp-login.php', $downgrade],
            'https to http 308' => [308, 'https://example.com/', 'http://example.com/', $downgrade],
            'https to http 301' => [301, 'https://example.com/', 'http://example.com/', $downgrade],
            'https to http 302 on another host' => [302, 'https://example.com/', 'http://other.example/', str_replace('http://example.com', 'http://other.example', $downgrade)],
            '307 to another host' => [307, 'https://example.com/x', 'https://other.example/x', self::elsewhere('https://other.example')],
            '308 to another host' => [308, 'https://example.com/x', 'https://other.example/x', self::elsewhere('https://other.example')],
            '307 to another subdomain' => [307, 'https://example.com/', 'https://shop.example.com/', self::elsewhere('https://shop.example.com')],
            '308 to another port' => [308, 'https://example.com/x', 'https://example.com:8443/x', self::elsewhere('https://example.com:8443')],
            '307 from http to https on another host' => [307, 'http://example.com/', 'https://other.example/', self::elsewhere('https://other.example')],
            '308 from http on 8080 to https on 8443' => [308, 'http://example.com:8080/', 'https://example.com:8443/', self::elsewhere('https://example.com:8443')],
            '307 from the default http port to https on 8443' => [307, 'http://example.com/', 'https://example.com:8443/', self::elsewhere('https://example.com:8443')],
            '300 to another host, which resends the request too' => [300, 'https://example.com/x', 'https://other.example/x', self::elsewhere('https://other.example')],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_hop_that_would_carry_the_request_off_the_site_is_refused(int $status, string $from, string $to, string $message): void
    {
        try {
            SourceFetchGuard::assertRedirectAllowed($status, $from, $to);
            $this->fail("The {$status} hop to {$to} must be refused");
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::UNREACHABLE, $e->errorType());
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function allowed(): array
    {
        return [
            '308 on the same origin' => [308, 'https://example.com/a', 'https://example.com/b'],
            '307 to the www twin' => [307, 'https://example.com/a', 'https://www.example.com/a'],
            '308 from the www twin' => [308, 'https://www.example.com:8443/', 'https://example.com:8443/'],
            '307 with the default port written out' => [307, 'https://example.com/', 'https://example.com:443/'],
            '307 to the host in capitals' => [307, 'https://example.com/', 'HTTPS://EXAMPLE.com/'],
            '301 to another host' => [301, 'https://example.com/', 'https://other.example/'],
            '302 from http to https' => [302, 'http://example.com/', 'https://example.com/'],
            '303 to another port' => [303, 'http://example.com/', 'http://example.com:8080/'],
            '308 from http to https on the same host' => [308, 'http://example.com/x', 'https://example.com/x'],
            '307 from http to https on the www twin' => [307, 'http://example.com/', 'https://www.example.com/'],
            '308 from http to https on the same explicit port' => [308, 'http://example.com:8080/', 'https://example.com:8080/'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_a_hop_that_stays_on_the_site_or_drops_the_request_is_allowed(int $status, string $from, string $to): void
    {
        SourceFetchGuard::assertRedirectAllowed($status, $from, $to);

        $this->addToAssertionCount(1);
    }

    private static function elsewhere(string $origin): string
    {
        return "The source WordPress site redirects to {$origin}; enter that URL as the source URL";
    }
}
