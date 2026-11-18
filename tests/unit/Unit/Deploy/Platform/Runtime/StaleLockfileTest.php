<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Platform\Runtime\StaleLockfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StaleLockfileTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function refusals(): array
    {
        return [
            'npm ci' => ['npm error `npm ci` can only install packages when your package.json and package-lock.json or npm-shrinkwrap.json are in sync. Please update your lock file with `npm install` before continuing.'],
            'pnpm outdated' => [' ERR_PNPM_OUTDATED_LOCKFILE  Cannot install with "frozen-lockfile" because pnpm-lock.yaml is not up to date with <ROOT>/package.json'],
            'pnpm config' => [' ERR_PNPM_LOCKFILE_CONFIG_MISMATCH  Cannot proceed with the frozen installation. The current "overrides" configuration doesn\'t match'],
            'yarn 1' => ['error Your lockfile needs to be updated, but yarn was run with `--frozen-lockfile`.'],
            'yarn berry' => ['➤ YN0028: │ The lockfile would have been modified by this install, which is explicitly forbidden.'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_lockfile_refusal_is_recognised(string $failure): void
    {
        $this->assertTrue(StaleLockfile::refusedBy($failure));
    }

    public function test_other_install_failures_are_not(): void
    {
        $this->assertFalse(StaleLockfile::refusedBy('npm error code ETARGET npm error notarget No matching version found for oracle-redis@^1.0.0.'));
        $this->assertFalse(StaleLockfile::refusedBy(' ERR_PNPM_IGNORED_BUILDS  Ignored build scripts: esbuild@0.27.4'));
    }

    public function test_npm_ci_becomes_npm_install_keeping_the_env_and_the_lockfile(): void
    {
        $install = JsPackageManager::installCommand('npm', ['package-lock.json' => true]);

        $unfrozen = (string) StaleLockfile::unfrozen($install);

        $this->assertStringStartsWith("HUSKY=0 LEFTHOOK=0 CI=1 sh -c 'for f in package-lock.json", $unfrozen);
        $this->assertStringContainsString('cp "$f" "/tmp/pa-lock.$f"; fi; done; npm install --no-audit --no-fund; s=$?; ', $unfrozen);
        $this->assertStringContainsString('cp "/tmp/pa-lock.$f" "$f"; fi; done; exit $s\'', $unfrozen);
        $this->assertStringNotContainsString('npm ci', $unfrozen);
    }

    public function test_pnpm_is_told_not_to_freeze_and_its_corepack_steps_stay(): void
    {
        $install = 'HUSKY=0 LEFTHOOK=0 CI=1 '.JsPackageManager::ENSURE_COREPACK
            .' && corepack enable && corepack prepare pnpm@10 --activate && pnpm install --frozen-lockfile --dangerously-allow-all-builds';

        $unfrozen = (string) StaleLockfile::unfrozen($install);

        $this->assertStringStartsWith('HUSKY=0 LEFTHOOK=0 CI=1 '.JsPackageManager::ENSURE_COREPACK.' && corepack enable && corepack prepare pnpm@10 --activate && sh -c ', $unfrozen);
        $this->assertStringContainsString('pnpm install --no-frozen-lockfile --dangerously-allow-all-builds; s=$?', $unfrozen);
        $this->assertSame('corepack enable && corepack prepare pnpm@10 --activate', self::provisioningAfterCorepack($unfrozen));
    }

    public function test_yarn_drops_its_freeze(): void
    {
        $this->assertStringContainsString(
            'yarn install --ignore-engines; s=$?',
            (string) StaleLockfile::unfrozen('corepack enable && yarn install --frozen-lockfile --ignore-engines')
        );
        $this->assertStringContainsString(
            'yarn install --no-immutable; s=$?',
            (string) StaleLockfile::unfrozen('corepack enable && yarn install --immutable')
        );
    }

    public function test_an_install_that_is_not_frozen_has_no_unfrozen_form(): void
    {
        $this->assertNull(StaleLockfile::unfrozen('HUSKY=0 LEFTHOOK=0 CI=1 npm install --no-audit --no-fund'));
        $this->assertNull(StaleLockfile::unfrozen('HUSKY=0 LEFTHOOK=0 CI=1 bun install'));
        $this->assertNull(StaleLockfile::unfrozen(''));
    }

    public function test_the_host_build_keeps_the_unfrozen_install_in_one_step(): void
    {
        $unfrozen = (string) StaleLockfile::unfrozen(JsPackageManager::installCommand('npm', ['package-lock.json' => true]));

        [$provisioning, $install] = HostNodeBuild::splitToolingPrefix($unfrozen);

        $this->assertSame('', $provisioning);
        $this->assertSame($unfrozen, $install);
        $this->assertStringContainsString('else '.$unfrozen.' && {', HostNodeBuild::innerScript($unfrozen, 'npm run build'));
    }

    private static function provisioningAfterCorepack(string $install): string
    {
        [$provisioning] = HostNodeBuild::splitToolingPrefix($install);

        return trim(substr($provisioning, strlen(JsPackageManager::ENSURE_COREPACK.' && ')));
    }
}
