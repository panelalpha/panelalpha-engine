<?php

namespace Tests\Integration\Proxy;

use App\Lib\Helpers\UpstreamSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpstreamSpecErrorsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array{0: string, 1: int}}>
     */
    public static function accepted(): array
    {
        return [
            'port with surrounding spaces' => ['  8080  ', 'app', ['app', 8080]],
            'default host is trimmed' => ['443', '  app  ', ['app', 443]],
            'lowest port' => ['1', 'app', ['app', 1]],
            'highest port' => ['65535', 'app', ['app', 65535]],
            'leading zeros' => ['0080', 'app', ['app', 80]],
            'host and port ignore the default host' => ['web:3000', '', ['web', 3000]],
            'host and highest port' => ['web:65535', 'app', ['web', 65535]],
            'dotted host' => ['db.internal:5432', 'app', ['db.internal', 5432]],
        ];
    }

    /**
     * @param  array{0: string, 1: int}  $expected
     */
    #[DataProvider('accepted')]
    public function test_accepted_values(string $proxyTo, string $defaultHost, array $expected): void
    {
        $this->assertSame($expected, UpstreamSpec::parse($proxyTo, $defaultHost));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refused(): array
    {
        return [
            'empty' => ['', 'app', '--proxy-to is required (port or host:port).'],
            'blank' => [" \t ", 'app', '--proxy-to is required (port or host:port).'],
            'port zero' => ['0', 'app', 'Upstream port must be 1-65535.'],
            'port above range' => ['65536', 'app', 'Upstream port must be 1-65535.'],
            'port checked before the default host' => ['0', '', 'Upstream port must be 1-65535.'],
            'blank default host' => ['8080', '   ', 'Default upstream host is required for port-only --proxy-to.'],
            'host with port zero' => ['web:0', 'app', 'Upstream port must be 1-65535.'],
            'host with port above range' => ['web:70000', 'app', 'Upstream port must be 1-65535.'],
            'no port after colon' => ['web:', 'app', "Invalid --proxy-to 'web:'. Expected port (e.g. 8080) or host:port (e.g. myproject:8080)."],
            'two colons' => ['a:b:80', 'app', "Invalid --proxy-to 'a:b:80'. Expected port (e.g. 8080) or host:port (e.g. myproject:8080)."],
            'space in host' => ['my host:80', 'app', "Invalid --proxy-to 'my host:80'. Expected port (e.g. 8080) or host:port (e.g. myproject:8080)."],
            'negative port' => ['-1', 'app', "Invalid --proxy-to '-1'. Expected port (e.g. 8080) or host:port (e.g. myproject:8080)."],
        ];
    }

    #[DataProvider('refused')]
    public function test_refused_values(string $proxyTo, string $defaultHost, string $message): void
    {
        try {
            UpstreamSpec::parse($proxyTo, $defaultHost);
            $this->fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(\InvalidArgumentException::class, $e::class);
            $this->assertSame($message, $e->getMessage());
        }
    }
}
