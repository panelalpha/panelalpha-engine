<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WordpressMigrationFetchGuardTest extends TestCase
{
    public function test_https_and_private_lan_hosts_are_allowed(): void
    {
        $resolve = static fn (string $host): array => match ($host) {
            'example.com' => ['93.184.216.34'],
            default => [$host],
        };

        SourceFetchGuard::assertAllowed('https://example.com/blog', $resolve);
        SourceFetchGuard::assertAllowed('http://10.1.2.3/wp', $resolve);
        SourceFetchGuard::assertAllowed('http://192.168.0.5/', $resolve);
        SourceFetchGuard::assertAllowed('https://172.16.5.5/blog', $resolve);

        $this->addToAssertionCount(4);
    }

    public function test_non_http_schemes_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http or https');

        SourceFetchGuard::assertAllowed('file:///etc/passwd', static fn (): array => []);
    }

    #[DataProvider('blockedTargets')]
    public function test_loopback_and_link_local_are_rejected(string $url): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('loopback or link-local');

        SourceFetchGuard::assertAllowed($url, static function (string $host): array {
            return match ($host) {
                'localhost' => ['127.0.0.1'],
                'metadata.example' => ['169.254.169.254'],
                'mixed.example' => ['93.184.216.34', '127.0.0.1'],
                default => [$host],
            };
        });
    }

    /**
     * @return list<array{string}>
     */
    public static function blockedTargets(): array
    {
        return [
            ['http://127.0.0.1/'],
            ['http://169.254.169.254/latest/meta-data'],
            ['http://[::1]/'],
            ['http://[fe80::1]/'],
            ['http://[::ffff:127.0.0.1]/'],
            ['http://[::ffff:169.254.169.254]/'],
            ['http://0.0.0.0/'],
            ['https://localhost/wp-admin'],
            ['https://metadata.example/'],
            ['https://mixed.example/'],
        ];
    }

    public function test_redirect_to_loopback_stays_absolute_for_the_next_check(): void
    {
        $next = SourceFetchGuard::resolveRedirect(
            'https://example.com/wp-login.php',
            'http://169.254.169.254/latest/meta-data',
        );

        $this->assertSame('http://169.254.169.254/latest/meta-data', $next);

        $this->expectException(RuntimeException::class);
        SourceFetchGuard::assertAllowed($next, static fn (): array => ['169.254.169.254']);
    }

    public function test_relative_redirect_stays_on_the_same_origin(): void
    {
        $this->assertSame(
            'https://example.com/wp-admin/',
            SourceFetchGuard::resolveRedirect('https://example.com/wp-login.php', '/wp-admin/'),
        );
        $this->assertSame(
            'https://example.com/blog/wp-admin/',
            SourceFetchGuard::resolveRedirect('https://example.com/blog/wp-login.php', 'wp-admin/'),
        );
    }
}
