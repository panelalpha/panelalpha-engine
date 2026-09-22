<?php

namespace Tests\Unit\Console;

use App\Console\Commands\System\TrimRegistryProxy;
use Tests\TestCase;

class TrimRegistryProxyTest extends TestCase
{
    /**
     * The store is a tmpfs, so a restart is what empties it. Anything that
     * reaches into the filesystem instead is a regression: it would need a
     * shell, and it would have to stop short of the mountpoint itself.
     */
    public function test_it_clears_by_restarting_the_container(): void
    {
        $this->assertSame(
            ['sudo', 'docker', 'restart', 'panelalpha-registry-proxy'],
            TrimRegistryProxy::clearArgv()
        );
    }

    /** Array form end to end, so no part of this is reparsed by a shell. */
    public function test_it_passes_no_shell_string(): void
    {
        foreach (TrimRegistryProxy::clearArgv() as $arg) {
            $this->assertStringNotContainsString(' ', $arg);
        }
    }
}
