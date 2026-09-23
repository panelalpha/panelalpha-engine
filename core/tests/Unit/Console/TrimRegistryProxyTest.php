<?php

namespace Tests\Unit\Console;

use App\Console\Commands\System\TrimRegistryProxy;
use Tests\TestCase;

class TrimRegistryProxyTest extends TestCase
{
    /**
     * The store is a named volume (#285), so a restart alone keeps it. The
     * wipe must remove the storage tree and scheduler state, not the mountpoint.
     */
    public function test_it_wipes_the_store_inside_the_container(): void
    {
        $this->assertSame(
            [
                'sudo', 'docker', 'exec', 'panelalpha-registry-proxy',
                'rm', '-rf', '/var/lib/registry/docker', '/var/lib/registry/scheduler-state.json',
            ],
            TrimRegistryProxy::wipeArgv()
        );
        $this->assertNotContains('/var/lib/registry', TrimRegistryProxy::wipeArgv());
    }

    public function test_it_restarts_the_container_after_the_wipe(): void
    {
        $this->assertSame(
            ['sudo', 'docker', 'restart', 'panelalpha-registry-proxy'],
            TrimRegistryProxy::clearArgv()
        );
    }

    /** Array form end to end, so no part of this is reparsed by a shell. */
    public function test_it_passes_no_shell_string(): void
    {
        foreach (array_merge(TrimRegistryProxy::wipeArgv(), TrimRegistryProxy::clearArgv()) as $arg) {
            $this->assertStringNotContainsString(' ', $arg);
        }
    }
}
