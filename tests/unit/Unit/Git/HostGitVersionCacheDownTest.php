<?php

namespace Tests\Unit\Git;

use App\Lib\Git\HostGitVersion;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/** A cache that cannot be used does not hide the version: git is asked directly. */
class HostGitVersionCacheDownTest extends TestCase
{
    public function test_a_failing_cache_falls_back_to_asking_git(): void
    {
        $runs = 0;
        $cache = new class(new ArrayStore) extends Repository
        {
            public function remember($key, $ttl, \Closure $callback): mixed
            {
                throw new \RuntimeException('cache store unreachable');
            }
        };
        $git = new HostGitVersion(function (array $argv) use (&$runs): string {
            $runs++;

            return "git version 2.43.0\n";
        }, $cache);

        $this->assertSame('2.43.0', $git->version());
        $this->assertTrue($git->pinsAddresses());
        $this->assertSame(2, $runs);
    }
}
