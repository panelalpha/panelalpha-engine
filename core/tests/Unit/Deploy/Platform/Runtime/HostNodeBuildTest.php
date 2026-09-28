<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Dockerfile\GitInstall;
use App\Lib\Deploy\Platform\Dockerfile\DockerIgnore;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use PHPUnit\Framework\TestCase;

class HostNodeBuildTest extends TestCase
{
    public function test_strips_leading_typecheck_from_static_build_scripts(): void
    {
        $this->assertSame(
            'astro build',
            NodeRuntime::stripTypecheckPrefix('astro check && astro build')
        );
        $this->assertSame(
            'astro build && node process-html.mjs',
            NodeRuntime::stripTypecheckPrefix('astro check && astro build && node process-html.mjs')
        );
        $this->assertSame(
            'vite build',
            NodeRuntime::stripTypecheckPrefix('vue-tsc -b && vite build')
        );
        $this->assertSame(
            'vite build',
            NodeRuntime::stripTypecheckPrefix('tsc && vite build')
        );
        $this->assertSame(
            'astro build',
            NodeRuntime::stripTypecheckPrefix('astro build')
        );
    }

    public function test_git_hook_prepare_scripts_are_detected_and_stripped(): void
    {
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('lefthook install'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('husky install'));
        $this->assertTrue(JsPackageManager::isGitHookInstallerScript('husky'));
        $this->assertFalse(JsPackageManager::isGitHookInstallerScript('prisma generate'));

        $stripped = JsPackageManager::stripGitHookInstallerScripts([
            'scripts' => [
                'prepare' => 'lefthook install',
                'build' => 'next build',
            ],
        ]);
        $this->assertArrayNotHasKey('prepare', $stripped['scripts']);
        $this->assertSame('next build', $stripped['scripts']['build']);
        $this->assertStringContainsString('lefthook', JsPackageManager::dockerfileStripGitHookScriptsCommand('bun'));
    }

    /**
     * The engine's own .dockerignore excludes `.git`, and it no longer edits a
     * project's.
     *
     * The inverse used to be true: `.git` was kept in the build context so
     * content pipelines could read history, and a project's own file was
     * rewritten to allow it. That made `COPY . .` hash differently on every
     * clone and no build could hit its cache. The recipes that read history
     * build on the host now, against the real checkout. {@see DockerIgnore}.
     */
    public function test_dockerignore_excludes_git_so_the_context_is_stable(): void
    {
        $contents = DockerIgnore::contents();

        $this->assertMatchesRegularExpression('/^\.git$/m', $contents);
        $this->assertMatchesRegularExpression('/^node_modules$/m', $contents);
        $this->assertFalse(
            method_exists(DockerIgnore::class, 'allowingGit'),
            'the engine no longer edits a project\'s own .dockerignore'
        );
        // git is still installed in the builder image: a build that shells out
        // to it fails on a missing binary differently from a missing history.
        $this->assertStringContainsString(
            'apk add --no-cache git',
            GitInstall::command('node:22-alpine')
        );
    }

    public function test_nginx_build_prefers_build_only_then_strips_check(): void
    {
        $this->assertSame(
            'pnpm run build-only',
            NodeRuntime::nginxAssetBuildCommand('pnpm', [
                'build' => 'run-p type-check "build-only {@}" --',
                'build-only' => 'vite build',
            ])
        );
        $this->assertSame(
            'export PATH=/app/node_modules/.bin:$PATH && astro build',
            NodeRuntime::nginxAssetBuildCommand('npm', [
                'build' => 'astro check && astro build',
            ])
        );
        // engine#151: every command after the stripped prefix needs the
        // project's binaries, not only the first (reveal.js).
        $this->assertSame(
            'export PATH=/app/node_modules/.bin:$PATH && vite build && vite build -c vite.config.styles.ts',
            NodeRuntime::nginxAssetBuildCommand('npm', [
                'build' => 'tsc && vite build && vite build -c vite.config.styles.ts',
            ])
        );
        $this->assertSame(
            'npm run build',
            NodeRuntime::nginxAssetBuildCommand('npm', [
                'build' => 'astro build',
            ])
        );
    }

    /**
     * Which interpreter and which install line — the container that runs them
     * is {@see \Tests\Unit\Deploy\Dind\DindHostBuilderTest}'s business.
     */
    public function test_compiler_and_runtime_images_follow_the_lockfile(): void
    {
        $this->assertTrue(ImageTransfer::isSafeImageRef(Images::NODE_IMAGE));
        $this->assertTrue(ImageTransfer::isSafeImageRef(HostNodeBuild::BUN_IMAGE));
        $this->assertTrue(HostNodeBuild::usesJsPackageManager('npm ci --no-audit --no-fund'));
        $this->assertTrue(HostNodeBuild::usesJsPackageManager('HUSKY=0 LEFTHOOK=0 CI=1 bun install'));
        $this->assertSame('HUSKY=0 LEFTHOOK=0 CI=1 bun install', HostNodeBuild::bunInstallCommand('npm ci --no-audit --no-fund'));
        $this->assertSame('HUSKY=0 LEFTHOOK=0 CI=1 bun install', HostNodeBuild::bunInstallCommand('bun install --frozen-lockfile'));
        // An npm project compiles under Node, which is what "follow the
        // lockfile" means. This used to assert bun and so encoded the bug:
        // compiling npm projects under bun rewrote their install and build
        // lines into bun's dialect and ran the git-hook strip step under
        // `bun -e`, where it hangs and pins a core.
        $this->assertSame(
            Images::NODE_IMAGE,
            HostNodeBuild::compilerImage('HUSKY=0 LEFTHOOK=0 CI=1 npm ci', Images::NODE_IMAGE, 'npm')
        );
        $this->assertSame(
            HostNodeBuild::BUN_IMAGE,
            HostNodeBuild::compilerImage('HUSKY=0 LEFTHOOK=0 CI=1 bun install', Images::NODE_IMAGE, 'bun')
        );
        $this->assertSame(
            HostNodeBuild::BUN_IMAGE,
            HostNodeBuild::runtimeImage('bun', Images::NODE_IMAGE)
        );
        $this->assertSame(
            Images::NODE_IMAGE,
            HostNodeBuild::runtimeImage('npm', Images::NODE_IMAGE)
        );
        $this->assertSame(
            'node:22-bookworm-slim',
            HostNodeBuild::runtimeImage('pnpm', 'node:22-bookworm-slim')
        );
    }

    public function test_build_command_switches_to_bun_in_the_bun_image(): void
    {
        $this->assertSame('bun run build', HostNodeBuild::bunBuildCommand('pnpm run build'));
        $this->assertSame('bun run build-only', HostNodeBuild::bunBuildCommand('pnpm run build-only'));
        $this->assertSame('bun run build', HostNodeBuild::bunBuildCommand('npm run build'));
        $this->assertSame('bun run build', HostNodeBuild::bunBuildCommand('yarn build'));
        $this->assertSame('bun run start', HostNodeBuild::bunBuildCommand('npm start'));
        $this->assertSame('bunx astro build', HostNodeBuild::bunBuildCommand('npx astro build'));
        $this->assertSame(
            'bun run build && bun run postbuild',
            HostNodeBuild::bunBuildCommand('npm run build && yarn postbuild')
        );
    }

    public function test_build_command_leaves_direct_binary_calls_alone(): void
    {
        $direct = 'PATH=/app/node_modules/.bin:$PATH astro build';
        $this->assertSame($direct, HostNodeBuild::bunBuildCommand($direct));
        $this->assertSame('vite build', HostNodeBuild::bunBuildCommand('vite build'));
        $this->assertSame('', HostNodeBuild::bunBuildCommand(''));
    }

    /** engine#159/#160: a pnpm build printed `cp: cannot stat '/app/package-lock.json'`. */
    public function test_only_an_existing_lockfile_is_stamped_into_node_modules(): void
    {
        $script = HostNodeBuild::innerScript('pnpm install --frozen-lockfile', 'pnpm run build');

        $this->assertStringNotContainsString('cp /app/package-lock.json', $script);
        $this->assertStringContainsString(
            'if [ -f "/app/$f" ]; then cp "/app/$f" /app/node_modules/.pa-lock; break; fi',
            $script
        );
    }

    public function test_package_manager_setup_runs_even_on_a_node_modules_cache_hit(): void
    {
        $install = 'corepack enable && corepack prepare pnpm@10 --activate && pnpm install --frozen-lockfile';

        $this->assertSame(
            ['corepack enable && corepack prepare pnpm@10 --activate', 'pnpm install --frozen-lockfile'],
            HostNodeBuild::splitToolingPrefix($install)
        );
        $this->assertSame(
            ['npm install -g bun', 'bun install'],
            HostNodeBuild::splitToolingPrefix('npm install -g bun && bun install')
        );
        $this->assertSame(
            ['', 'npm ci --no-audit --no-fund'],
            HostNodeBuild::splitToolingPrefix('npm ci --no-audit --no-fund')
        );

        // corepack has to sit before the cache check, or `pnpm run build` on a
        // second deploy runs without pnpm ever being put on PATH.
        $script = HostNodeBuild::innerScript($install, 'pnpm run build');
        $this->assertStringStartsWith('export PATH=/tmp/corepack-bin:/tmp/corepack-npm/bin:$PATH', $script);
        $this->assertStringContainsString(
            'corepack enable --install-directory /tmp/corepack-bin && corepack prepare pnpm@10 --activate',
            $script
        );
        $this->assertStringEndsWith('fi && pnpm run build', $script);
        $this->assertStringNotContainsString('hit"; else corepack', $script);
    }

    /**
     * The install commands above are hand-written, and every real one arrives
     * with `HUSKY=0 LEFTHOOK=0 CI=1` in front of it. Matching the raw string
     * meant no yarn or pnpm project's `corepack enable` was ever recognised as
     * provisioning, so it kept the shim directory it cannot write to and the
     * host build died with
     * `EACCES: permission denied, symlink … -> '/usr/local/bin/pnpm'` --
     * phpMyAdmin, on every deploy.
     */
    public function test_corepack_is_provisioning_even_behind_the_install_environment(): void
    {
        foreach (['yarn' => 'yarn.lock', 'pnpm' => 'pnpm-lock.yaml'] as $pm => $lockfile) {
            $install = JsPackageManager::installCommand($pm, [$lockfile => true]);
            [$prepare, $rest] = HostNodeBuild::splitToolingPrefix($install);

            $this->assertStringStartsWith(JsPackageManager::ENSURE_COREPACK . ' && corepack enable', $prepare, $pm);
            $this->assertStringStartsWith('HUSKY=0 LEFTHOOK=0 CI=1 ' . $pm . ' install', $rest, $pm);

            $script = HostNodeBuild::innerScript($install, JsPackageManager::scriptCommand($pm, 'build'));
            $this->assertStringContainsString(
                'corepack enable --install-directory /tmp/corepack-bin',
                $script,
                $pm
            );
            // The bare form is what needs root. It must not survive anywhere.
            $this->assertStringNotContainsString('corepack enable && ', $script, $pm);
            $this->assertStringNotContainsString('npm install -g corepack', $script, $pm);
            $this->assertStringContainsString('npm install -g --prefix /tmp/corepack-npm corepack', $script, $pm);
        }
    }

    /**
     * engine#157: Node 25+ images ship no corepack. The guard installs it,
     * and on the host it is found without writing npm's global prefix.
     */
    public function test_a_missing_corepack_is_installed_before_it_is_used(): void
    {
        $install = JsPackageManager::installCommand(
            'pnpm',
            ['pnpm-lock.yaml' => true],
            ['packageManager' => 'pnpm@11.25.0']
        );

        $this->assertStringStartsWith(
            "HUSKY=0 LEFTHOOK=0 CI=1 sh -c 'command -v corepack >/dev/null 2>&1 || npm install -g corepack'"
                . ' && corepack enable && corepack prepare pnpm@11.25.0 --activate',
            $install
        );
        $this->assertTrue(HostNodeBuild::usesJsPackageManager($install));

        [$prepare, $rest] = HostNodeBuild::splitToolingPrefix($install);
        $this->assertStringStartsWith("sh -c 'command -v corepack", $prepare);
        $this->assertStringStartsWith('HUSKY=0 LEFTHOOK=0 CI=1 pnpm install', $rest);
    }
}
