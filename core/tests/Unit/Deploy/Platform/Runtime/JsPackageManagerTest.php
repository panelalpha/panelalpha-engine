<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JsPackageManager.
 *
 * The class has no Laravel dependencies, so this test extends the plain
 * PHPUnit TestCase — no app boot required.
 */
class JsPackageManagerTest extends TestCase
{
    public function test_detect_package_manager_prefers_lockfiles_over_package_manager_field(): void
    {
        $this->assertSame('bun', JsPackageManager::detectPackageManager(['bun.lock' => true]));
        $this->assertSame('bun', JsPackageManager::detectPackageManager(['bun.lockb' => true]));
        $this->assertSame('pnpm', JsPackageManager::detectPackageManager(['pnpm-lock.yaml' => true]));
        $this->assertSame('yarn', JsPackageManager::detectPackageManager(['yarn.lock' => true]));
        $this->assertSame('npm', JsPackageManager::detectPackageManager(['package-lock.json' => true]));
        $this->assertSame('npm', JsPackageManager::detectPackageManager(['npm-shrinkwrap.json' => true]));

        // No lockfile at all: packageManager field decides.
        $this->assertSame('pnpm', JsPackageManager::detectPackageManager([], ['packageManager' => 'pnpm@9.1.0']));
        $this->assertSame('yarn', JsPackageManager::detectPackageManager([], ['packageManager' => 'yarn@4.0.0']));
        $this->assertSame('bun', JsPackageManager::detectPackageManager([], ['packageManager' => 'bun@1.1.0']));

        // Neither lockfile nor field: npm.
        $this->assertSame('npm', JsPackageManager::detectPackageManager([]));

        // A lockfile present outranks a conflicting packageManager field.
        $this->assertSame(
            'yarn',
            JsPackageManager::detectPackageManager(['yarn.lock' => true], ['packageManager' => 'pnpm@9.0.0'])
        );
    }

    public function test_script_command_per_package_manager(): void
    {
        $this->assertSame('npm run build', JsPackageManager::scriptCommand('npm', 'build'));
        $this->assertSame('npm start', JsPackageManager::scriptCommand('npm', 'start'));
        $this->assertSame('yarn build', JsPackageManager::scriptCommand('yarn', 'build'));
        $this->assertSame('yarn start', JsPackageManager::scriptCommand('yarn', 'start'));
        $this->assertSame('pnpm run build', JsPackageManager::scriptCommand('pnpm', 'build'));
        $this->assertSame('pnpm start', JsPackageManager::scriptCommand('pnpm', 'start'));
        $this->assertSame('bun run build', JsPackageManager::scriptCommand('bun', 'build'));
        $this->assertSame('bun run start', JsPackageManager::scriptCommand('bun', 'start'));
    }

    public function test_lockfile_name_per_package_manager(): void
    {
        $this->assertSame('bun.lock', JsPackageManager::lockfileName('bun', ['bun.lock' => true]));
        $this->assertSame('bun.lockb', JsPackageManager::lockfileName('bun', ['bun.lockb' => true]));
        $this->assertNull(JsPackageManager::lockfileName('bun', []));
        $this->assertSame('pnpm-lock.yaml', JsPackageManager::lockfileName('pnpm', ['pnpm-lock.yaml' => true]));
        $this->assertNull(JsPackageManager::lockfileName('pnpm', []));
        $this->assertSame('yarn.lock', JsPackageManager::lockfileName('yarn', ['yarn.lock' => true]));
        $this->assertSame('package-lock.json', JsPackageManager::lockfileName('npm', ['package-lock.json' => true]));
        $this->assertSame('npm-shrinkwrap.json', JsPackageManager::lockfileName('npm', ['npm-shrinkwrap.json' => true]));
        $this->assertNull(JsPackageManager::lockfileName('npm', []));
    }

    public function test_install_command_npm_uses_ci_with_lockfile_and_install_without(): void
    {
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund',
            JsPackageManager::installCommand('npm', ['package-lock.json' => true])
        );
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 npm install --no-audit --no-fund',
            JsPackageManager::installCommand('npm', [])
        );
    }

    public function test_install_command_yarn_frozen_lockfile_when_present(): void
    {
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK . ' && corepack enable && yarn install --frozen-lockfile --ignore-engines',
            JsPackageManager::installCommand('yarn', ['yarn.lock' => true])
        );
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK . ' && corepack enable && yarn install --ignore-engines',
            JsPackageManager::installCommand('yarn', [])
        );
    }

    /**
     * Yarn 1 refuses to install when any package in the tree declares an
     * `engines.node` the running Node does not satisfy -- including a
     * transitive one, which the project does not control. npm ignores the
     * field, so the same repository installs under npm and dies under Yarn 1.
     *
     * mybucks-online/app is the real case: no engines of its own, a
     * `@mybucks.online/core` dependency wanting `>=24`, and Node 20 on the
     * host -- `Found incompatible module` for an app that builds fine.
     */
    public function test_install_command_yarn_classic_ignores_engines(): void
    {
        $this->assertStringContainsString(
            '--ignore-engines',
            JsPackageManager::installCommand(
                'yarn',
                ['yarn.lock' => true],
                ['packageManager' => 'yarn@1.22.22']
            )
        );
    }

    /**
     * Berry does not enforce `engines` at all, and `--ignore-engines` is not
     * one of its options -- passing it would fail a project that installs fine.
     */
    public function test_install_command_yarn_berry_never_gets_ignore_engines(): void
    {
        $this->assertStringNotContainsString(
            '--ignore-engines',
            JsPackageManager::installCommand(
                'yarn',
                ['yarn.lock' => true],
                ['packageManager' => 'yarn@4.18.0']
            )
        );
        $this->assertStringNotContainsString(
            '--ignore-engines',
            JsPackageManager::installCommand('yarn', ['yarn.lock' => true, '.yarnrc.yml' => true])
        );
    }

    /**
     * Yarn Berry removed `--frozen-lockfile` in favour of `--immutable` and
     * exits non-zero (YN0050) on the old spelling, so a Berry project needs
     * the newer flag. The authoritative signal is `packageManager`, which
     * corepack itself honours.
     */
    public function test_install_command_yarn_berry_uses_immutable(): void
    {
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK . ' && corepack enable && yarn install --immutable',
            JsPackageManager::installCommand(
                'yarn',
                ['yarn.lock' => true],
                ['packageManager' => 'yarn@4.18.0']
            )
        );
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK . ' && corepack enable && yarn install --immutable',
            JsPackageManager::installCommand(
                'yarn',
                ['yarn.lock' => true],
                ['packageManager' => 'yarn@3.2.1']
            )
        );
    }

    /**
     * Corepack reads `packageManager` and refuses to run any other version, so
     * it outranks the other two signals even when they point at Berry.
     */
    public function test_install_command_yarn_classic_package_manager_field_wins(): void
    {
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK . ' && corepack enable && yarn install --frozen-lockfile --ignore-engines',
            JsPackageManager::installCommand('yarn', ['yarn.lock' => true], [
                'packageManager' => 'yarn@1.22.22',
            ])
        );
    }

    /** `.yarnrc.yml` is read only by Yarn 2+, so its presence is Berry. */
    public function test_install_command_yarn_berry_detected_from_yarnrc_yml(): void
    {
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK . ' && corepack enable && yarn install --immutable',
            JsPackageManager::installCommand('yarn', ['yarn.lock' => true, '.yarnrc.yml' => true])
        );
    }

    /**
     * With no packageManager field and no `.yarnrc.yml`, the lockfile's own
     * header decides: Berry opens with `__metadata:`, Classic with
     * `# yarn lockfile v1`.
     */
    public function test_install_command_yarn_reads_lockfile_header_for_the_major(): void
    {
        $berry = $this->tempProject([
            'yarn.lock' => "# This file is generated by running \"yarn install\" inside your project.\n\n__metadata:\n  version: 8\n",
        ]);
        $classic = $this->tempProject(["# THIS IS AN AUTOGENERATED FILE. DO NOT EDIT THIS FILE DIRECTLY.\n# yarn lockfile v1\n\n"]);

        try {
            $this->assertSame(
                'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK . ' && corepack enable && yarn install --immutable',
                JsPackageManager::installCommand('yarn', ['yarn.lock' => true], [], $berry)
            );
            $this->assertSame(
                'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK . ' && corepack enable && yarn install --frozen-lockfile --ignore-engines',
                JsPackageManager::installCommand('yarn', ['yarn.lock' => true], [], $classic)
            );
        } finally {
            @unlink($berry . '/yarn.lock');
            @rmdir($berry);
            @unlink($classic . '/yarn.lock');
            @rmdir($classic);
        }
    }

    /**
     * A repo that says nothing about its Yarn major keeps the pre-existing
     * behaviour: `--frozen-lockfile`, which is what Yarn 1 needs and what a
     * project with no Berry signal most likely is.
     */
    public function test_install_command_yarn_falls_back_to_frozen_without_a_version_signal(): void
    {
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK . ' && corepack enable && yarn install --frozen-lockfile --ignore-engines',
            JsPackageManager::installCommand('yarn', ['yarn.lock' => true], ['name' => 'app'])
        );
    }

    /** npm, pnpm and bun are untouched by the Yarn major. */
    public function test_install_command_other_package_managers_unchanged_by_yarn_flag(): void
    {
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund',
            JsPackageManager::installCommand('npm', ['package-lock.json' => true], ['packageManager' => 'yarn@4.18.0'])
        );
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 npm install --no-audit --no-fund',
            JsPackageManager::installCommand('npm', [])
        );
        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 bun install',
            JsPackageManager::installCommand('bun', ['bun.lock' => true], ['packageManager' => 'yarn@4.18.0'])
        );

        $pnpm = JsPackageManager::installCommand('pnpm', ['pnpm-lock.yaml' => true], ['packageManager' => 'pnpm@10.9.0']);
        $this->assertStringContainsString('pnpm install --frozen-lockfile', $pnpm);
        $this->assertStringNotContainsString('--immutable', $pnpm);
    }

    public function test_install_command_bun_never_uses_frozen_lockfile(): void
    {
        // Lockfiles committed under an older Bun disagree with oven/bun:1, so
        // bun install must never pass --frozen-lockfile regardless of lockfile.
        $withLock = JsPackageManager::installCommand('bun', ['bun.lock' => true]);
        $withoutLock = JsPackageManager::installCommand('bun', []);

        $this->assertStringNotContainsString('frozen-lockfile', $withLock);
        $this->assertSame($withLock, $withoutLock);
        $this->assertStringContainsString('bun install', $withLock);
    }

    public function test_install_command_pnpm_pins_via_corepack_and_allows_builds_on_recent_versions(): void
    {
        $command = JsPackageManager::installCommand('pnpm', ['pnpm-lock.yaml' => true], [
            'packageManager' => 'pnpm@10.9.0',
        ]);

        $this->assertStringContainsString('corepack prepare pnpm@10.9.0 --activate', $command);
        $this->assertStringContainsString('--frozen-lockfile', $command);
        $this->assertStringContainsString('--dangerously-allow-all-builds', $command);
    }

    public function test_install_command_pnpm_omits_dangerously_allow_all_builds_before_10_9(): void
    {
        $command = JsPackageManager::installCommand('pnpm', [], [
            'packageManager' => 'pnpm@10.8.0',
        ]);

        $this->assertStringNotContainsString('--dangerously-allow-all-builds', $command);
    }

    public function test_install_command_pnpm_defaults_to_pnpm_10_when_field_absent(): void
    {
        $command = JsPackageManager::installCommand('pnpm', []);

        $this->assertStringContainsString('corepack prepare pnpm@10 --activate', $command);
        // Bare "pnpm@10" (no minor) means latest 10.x, which is >= 10.9.
        $this->assertStringContainsString('--dangerously-allow-all-builds', $command);
    }

    public function test_with_ci_install_env_is_idempotent(): void
    {
        $once = JsPackageManager::withCiInstallEnv('npm install');
        $twice = JsPackageManager::withCiInstallEnv($once);

        $this->assertSame('HUSKY=0 LEFTHOOK=0 CI=1 npm install', $once);
        $this->assertSame($once, $twice);
        $this->assertSame('', JsPackageManager::withCiInstallEnv(''));
    }

    public function test_is_git_hook_installer_script_matches_known_tools_only(): void
    {
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('lefthook install'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('husky install'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('husky'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('simple-git-hooks'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('yorkie'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('pre-commit install'));
        $this->assertFalse(JsPackageManager::isGitHookInstallerScript('prisma generate'));
        $this->assertFalse(JsPackageManager::isGitHookInstallerScript(''));
    }

    public function test_strip_git_hook_installer_scripts_removes_only_matching_lifecycle_scripts(): void
    {
        $stripped = JsPackageManager::stripGitHookInstallerScripts([
            'scripts' => [
                'prepare' => 'husky install',
                'postinstall' => 'lefthook install',
                'preinstall' => 'echo hi',
                'build' => 'next build',
            ],
        ]);

        $this->assertArrayNotHasKey('prepare', $stripped['scripts']);
        $this->assertArrayNotHasKey('postinstall', $stripped['scripts']);
        $this->assertSame('echo hi', $stripped['scripts']['preinstall']);
        $this->assertSame('next build', $stripped['scripts']['build']);
    }

    /**
     * A lifecycle script that runs a repository file cannot run in the
     * dependencies layer, because only package.json and the lockfile are
     * copied before it -- the source arrives one layer later.
     *
     * MyTube: `"preinstall": "node ./backend/scripts/check-supported-node.cjs"`,
     * which failed the build with `Cannot find module` before `COPY . .`.
     */
    public function test_is_git_hook_installer_script_also_matches_a_local_script_reference(): void
    {
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('node ./backend/scripts/check-supported-node.cjs'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('ts-node server/app.ts'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('bash scripts/setup.sh'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('node scripts/a.cjs && node -e "x"'));
    }

    /**
     * An inline `node -e` has no file to miss, and a `node_modules/` path is
     * installed by the time the script runs — stripping either would break a
     * package that builds itself on install.
     */
    public function test_an_inline_or_installed_script_is_not_a_local_reference(): void
    {
        $this->assertFalse(JsPackageManager::isGitHookInstallerScript('node -e "console.log(1)"'));
        $this->assertFalse(JsPackageManager::isGitHookInstallerScript('node node_modules/.bin/foo.js'));
        $this->assertFalse(JsPackageManager::isGitHookInstallerScript('prisma generate'));
        $this->assertFalse(JsPackageManager::isGitHookInstallerScript('./backend/scripts/x.cjs'));
        $this->assertFalse(JsPackageManager::isGitHookInstallerScript(''));
    }

    public function test_strip_git_hook_installer_scripts_is_a_no_op_without_scripts(): void
    {
        $this->assertSame([], JsPackageManager::stripGitHookInstallerScripts([]));
    }

    public function test_dockerfile_strip_git_hook_scripts_command_uses_bun_or_node_runner(): void
    {
        // The runner is still the project's own, but the step is now bounded
        // and allowed to fail: `bun -e` hangs on this script, and an
        // unbounded RUN that spins pins a core on the host indefinitely.
        $this->assertStringContainsString(' bun -e ', JsPackageManager::dockerfileStripGitHookScriptsCommand('bun'));
        $this->assertStringContainsString(' node -e ', JsPackageManager::dockerfileStripGitHookScriptsCommand('npm'));
        $this->assertStringContainsString(' node -e ', JsPackageManager::dockerfileStripGitHookScriptsCommand('pnpm'));
        $this->assertStringContainsString('lefthook', JsPackageManager::dockerfileStripGitHookScriptsCommand('bun'));
        $this->assertStringStartsWith('timeout ', JsPackageManager::dockerfileStripGitHookScriptsCommand('bun'));
    }

    public function test_is_js_workspace_detects_array_string_and_pnpm_workspace_file(): void
    {
        $this->assertTrue(JsPackageManager::isJsWorkspace(['workspaces' => ['packages/*']]));
        $this->assertTrue(JsPackageManager::isJsWorkspace(['workspaces' => 'packages/*']));
        $this->assertFalse(JsPackageManager::isJsWorkspace(['workspaces' => []]));
        $this->assertFalse(JsPackageManager::isJsWorkspace(['workspaces' => '']));
        $this->assertFalse(JsPackageManager::isJsWorkspace([]));
        $this->assertTrue(JsPackageManager::isJsWorkspace([], ['pnpm-workspace.yaml' => true]));
    }

    public function test_has_script_requires_a_non_empty_string(): void
    {
        $this->assertTrue(JsPackageManager::hasScript(['build' => 'vite build'], 'build'));
        $this->assertFalse(JsPackageManager::hasScript(['build' => ''], 'build'));
        $this->assertFalse(JsPackageManager::hasScript(['build' => '   '], 'build'));
        $this->assertFalse(JsPackageManager::hasScript([], 'build'));
    }

    public function test_resolve_lifecycle_command_prefers_workspace_slug_script(): void
    {
        $command = JsPackageManager::resolveLifecycleCommand(
            'npm',
            ['build' => 'turbo build', 'build:storefront' => 'turbo build --filter=storefront'],
            'build',
            ['workspace_slug' => 'storefront', 'workspace_package' => 'storefront']
        );

        $this->assertSame('npm run build:storefront', $command);
    }

    public function test_resolve_lifecycle_command_injects_turbo_filter_when_bare_and_falls_back_to_default(): void
    {
        $filtered = JsPackageManager::resolveLifecycleCommand(
            'pnpm',
            ['build' => 'turbo build'],
            'build',
            ['workspace_package' => 'storefront']
        );
        $this->assertSame('pnpm exec turbo build --filter=storefront...', $filtered);

        $fallback = JsPackageManager::resolveLifecycleCommand(
            'npm',
            [],
            'build',
            ['default_build' => 'npx vite build']
        );
        $this->assertSame('npx vite build', $fallback);

        $noDefault = JsPackageManager::resolveLifecycleCommand('npm', [], 'build', []);
        $this->assertSame('', $noDefault);
    }

    public function test_resolve_lifecycle_command_leaves_already_filtered_turbo_script_untouched(): void
    {
        $command = JsPackageManager::resolveLifecycleCommand(
            'npm',
            ['build' => 'turbo build --filter=storefront...'],
            'build',
            ['workspace_package' => 'storefront']
        );

        $this->assertSame('npm run build', $command);
    }

    public function test_turbo_needs_package_filter_detects_bare_turbo_without_filter_or_scope(): void
    {
        $this->assertTrue(JsPackageManager::turboNeedsPackageFilter('turbo build'));
        $this->assertFalse(JsPackageManager::turboNeedsPackageFilter('turbo build --filter=web'));
        $this->assertFalse(JsPackageManager::turboNeedsPackageFilter('turbo build --scope=web'));
        $this->assertFalse(JsPackageManager::turboNeedsPackageFilter('vite build'));
    }

    public function test_turbo_filtered_command_preserves_dotenv_prefix_and_picks_runner_per_pm(): void
    {
        $this->assertSame(
            'npx turbo build --filter=web...',
            JsPackageManager::turboFilteredCommand('npm', 'build', 'web', 'turbo build')
        );
        $this->assertSame(
            'dotenv -c npx turbo build --filter=web...',
            JsPackageManager::turboFilteredCommand('npm', 'build', 'web', 'dotenv -c turbo build')
        );
        $this->assertSame(
            'bunx turbo run start --filter=web...',
            JsPackageManager::turboFilteredCommand('bun', 'start', 'web', 'turbo start')
        );
        $this->assertSame(
            'pnpm exec turbo build --filter=web...',
            JsPackageManager::turboFilteredCommand('pnpm', 'build', 'web', 'turbo build')
        );
        $this->assertSame(
            'yarn exec turbo build --filter=web...',
            JsPackageManager::turboFilteredCommand('yarn', 'build', 'web', 'turbo build')
        );
    }

    /**
     * Firefly III's shape: the root has no `build` at all, only
     * `resources/assets/v1` and `resources/assets/v2`, and only v2 builds.
     * Returning null there -- the old behaviour at the call site -- meant no
     * frontend was compiled, `public/build/manifest.json` was never written,
     * and every Blade `@vite` answered 500.
     */
    public function test_build_target_finds_the_workspace_that_declares_a_build(): void
    {
        $root = ['workspaces' => ['resources/assets/v1', 'resources/assets/v2']];
        $packages = [
            'resources/assets/v1' => ['scripts' => ['production' => 'mix --production']],
            'resources/assets/v2' => ['scripts' => ['build' => 'vite build --emptyOutDir']],
        ];

        $this->assertSame(
            ['workspace' => 'resources/assets/v2', 'script' => 'build'],
            JsPackageManager::buildTarget($root, static fn (string $path): ?array => $packages[$path] ?? null)
        );
    }

    /**
     * A root script is the repository's own answer and outranks anything a
     * workspace declares, whatever the order.
     */
    public function test_build_target_prefers_the_root_script(): void
    {
        $root = [
            'scripts' => ['build' => 'npm run build --workspaces'],
            'workspaces' => ['apps/web'],
        ];

        $this->assertSame(
            ['workspace' => '', 'script' => 'build'],
            JsPackageManager::buildTarget($root, static fn (string $path): ?array => ['scripts' => ['build' => 'vite build']])
        );
    }

    public function test_build_target_is_null_when_nobody_builds(): void
    {
        $root = ['workspaces' => ['apps/api']];

        $this->assertNull(
            JsPackageManager::buildTarget($root, static fn (string $path): ?array => ['scripts' => ['test' => 'jest']])
        );
        $this->assertNull(JsPackageManager::buildTarget([], static fn (string $path): ?array => null));
        // A root `build` that is blank or not a string is not a build, the
        // same rule hasScript() applies everywhere else.
        $this->assertNull(JsPackageManager::buildTarget(
            ['scripts' => ['build' => '']],
            static fn (string $path): ?array => null
        ));
    }

    /** npm's other shape: an object with a `packages` key. */
    public function test_build_target_reads_the_object_form_of_workspaces(): void
    {
        $root = ['workspaces' => ['packages' => ['web']]];

        $this->assertSame(
            ['workspace' => 'web', 'script' => 'build'],
            JsPackageManager::buildTarget($root, static fn (string $path): ?array => ['scripts' => ['build' => 'vite build']])
        );
    }

    /**
     * These paths become the directory a shell command runs in, so an escape
     * is dropped rather than followed -- and a glob names no directory any
     * reader can open, so it is left unresolved rather than guessed at.
     */
    public function test_workspaces_keeps_only_paths_inside_the_repository(): void
    {
        $this->assertSame(
            ['apps/web', 'glob/*'],
            JsPackageManager::workspaces(['workspaces' => [
                '/etc',
                '../../outside',
                'apps/../web/../../x',
                'apps/web/',
                'glob/*',
                42,
            ]])
        );
        $this->assertSame([], JsPackageManager::workspaces(['workspaces' => 'resources/assets/v2']));
        $this->assertSame([], JsPackageManager::workspaces([]));
    }

    public function test_in_workspace_escapes_the_path(): void
    {
        $this->assertSame(
            "cd 'resources/assets/v2' && npm run build",
            JsPackageManager::inWorkspace('npm run build', 'resources/assets/v2')
        );
    }

    /**
     * A throwaway project directory holding the given files, so the lockfile
     * header can be read the way a real caller's $projectDir allows.
     *
     * @param array<string, string> $contents relative path => contents
     */
    private function tempProject(array $contents): string
    {
        $dir = sys_get_temp_dir() . '/pa-js-pm-' . bin2hex(random_bytes(8));
        mkdir($dir);
        foreach ($contents as $name => $body) {
            file_put_contents($dir . '/' . $name, $body);
        }

        return $dir;
    }
}
