<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use PHPUnit\Framework\TestCase;

/**
 * A pnpm install the host build did not provision through corepack leaves the run container nothing to prepare.
 */
class HostRunProjectPrefixTest extends TestCase
{
    public function test_a_pnpm_install_without_corepack_needs_no_prefix(): void
    {
        $this->assertSame('', HostRunProject::packageManagerPrefix([
            'strategy' => 'nextjs',
            'package_manager' => 'pnpm',
            'install_command' => 'pnpm install --frozen-lockfile',
        ]));
    }

    public function test_a_corepack_provisioned_pnpm_is_prepared_in_the_run_container(): void
    {
        $prefix = HostRunProject::packageManagerPrefix([
            'strategy' => 'nextjs',
            'package_manager' => 'pnpm',
            'install_command' => 'npm install -g corepack && corepack enable && corepack prepare pnpm@10 --activate && pnpm install',
        ]);

        $this->assertStringStartsWith('export COREPACK_HOME=/tmp/corepack', $prefix);
        $this->assertStringContainsString('corepack enable --install-directory /tmp/corepack-bin', $prefix);
        $this->assertStringEndsWith(' && ', $prefix);
    }
}
