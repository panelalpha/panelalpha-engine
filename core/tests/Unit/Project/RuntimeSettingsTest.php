<?php

namespace Tests\Unit\Project;

use App\Lib\Project\RuntimeSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RuntimeSettingsTest extends TestCase
{
    /** @return array<string, array{mixed}> */
    public static function unset(): array
    {
        return ['null' => [null], 'empty' => [''], 'not a string' => [['pm' => 'static']], 'zero' => [0]];
    }

    #[DataProvider('unset')]
    public function test_nothing_usable_stored_gives_the_defaults(mixed $raw): void
    {
        $this->assertSame('dynamic', RuntimeSettings::phpFpmPool($raw)['pm']);
        $this->assertSame(['PHP_LSAPI_CHILDREN' => '35', 'PHP_LSAPI_MAX_REQUESTS' => '5000'], RuntimeSettings::lsphp($raw));
        $this->assertSame('128mb', RuntimeSettings::redis($raw)['maxmemory']);
    }

    public function test_php_fpm_takes_valid_values_keeps_unknown_keys_and_ignores_bad_ones(): void
    {
        $settings = RuntimeSettings::phpFpmPool("pm = static\npm.max_children = 20\npm.max_requests = lots\nrequest_terminate_timeout = 30s\nno separator");

        $this->assertSame('static', $settings['pm']);
        $this->assertSame('20', $settings['pm.max_children']);
        $this->assertSame('0', $settings['pm.max_requests'], 'not an int, default kept');
        $this->assertSame('30s', $settings['request_terminate_timeout'], 'unknown keys pass through');
        $this->assertCount(7, $settings);
    }

    public function test_php_fpm_refuses_an_unknown_process_manager(): void
    {
        $this->assertSame('dynamic', RuntimeSettings::phpFpmPool('pm = forking')['pm']);
    }

    public function test_php_fpm_needs_spaces_around_the_equals_sign(): void
    {
        $this->assertSame('dynamic', RuntimeSettings::phpFpmPool('pm=static')['pm']);
    }

    public function test_lsphp_reads_key_equals_value(): void
    {
        $this->assertSame(
            ['PHP_LSAPI_CHILDREN' => '10', 'PHP_LSAPI_MAX_REQUESTS' => '5000', 'EXTRA' => 'x'],
            RuntimeSettings::lsphp("PHP_LSAPI_CHILDREN=10\nPHP_LSAPI_MAX_REQUESTS=often\nEXTRA=x")
        );
    }

    public function test_redis_keeps_only_allowed_keys_with_valid_values(): void
    {
        $settings = RuntimeSettings::redis("maxmemory 256mb\nmaxmemory-policy volatile-ttl\nhz five\nactivedefrag yes\nlazyfree-lazy-expire maybe\nsave 900 1\nbind 0.0.0.0");

        $this->assertSame('256mb', $settings['maxmemory']);
        $this->assertSame('volatile-ttl', $settings['maxmemory-policy']);
        $this->assertSame('10', $settings['hz'], 'not an int, default kept');
        $this->assertSame('yes', $settings['activedefrag']);
        $this->assertSame('no', $settings['lazyfree-lazy-expire'], 'not yes/no, default kept');
        $this->assertSame('900 1', $settings['save'], 'the value is everything after the first space');
        $this->assertArrayNotHasKey('bind', $settings);
    }

    public function test_redis_refuses_an_unknown_eviction_policy(): void
    {
        $this->assertSame('allkeys-lru', RuntimeSettings::redis('maxmemory-policy evict-everything')['maxmemory-policy']);
    }
}
