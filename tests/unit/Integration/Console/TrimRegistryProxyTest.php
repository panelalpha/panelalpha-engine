<?php

namespace Tests\Integration\Console;

use App\Console\Commands\System\TrimRegistryProxy;
use Tests\Support\LocalRoot;
use Tests\TestCase;

class TrimRegistryProxyTest extends TestCase
{
    /**
     * The store is a named volume, so a restart alone keeps it. The
     * wipe must remove the storage tree and scheduler state, not the mountpoint.
     */
    public function test_it_wipes_the_store_inside_the_container(): void
    {
        $wipe = TrimRegistryProxy::wipeArgv();

        $this->assertTrue(LocalRoot::isRequest($wipe));
        $this->assertSame('docker.wipeRegistryStorage', LocalRoot::action($wipe));
        $this->assertSame(['panelalpha-registry-proxy'], LocalRoot::values($wipe));
        $this->assertNotContains('/var/lib/registry', $wipe);
    }

    public function test_it_restarts_the_container_after_the_wipe(): void
    {
        $clear = TrimRegistryProxy::clearArgv();

        $this->assertTrue(LocalRoot::isRequest($clear));
        $this->assertSame('docker.restart', LocalRoot::action($clear));
        $this->assertSame(['panelalpha-registry-proxy'], LocalRoot::values($clear));
    }

    /** Array form end to end, so no part of this is reparsed by a shell. */
    public function test_it_passes_no_shell_string(): void
    {
        foreach (array_merge(TrimRegistryProxy::wipeArgv(), TrimRegistryProxy::clearArgv()) as $arg) {
            $this->assertStringNotContainsString(' ', $arg);
        }
    }
}
