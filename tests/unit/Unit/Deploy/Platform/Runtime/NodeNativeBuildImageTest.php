<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A Node project whose install compiles a dependency gets the full Node image
 * rather than the slim one.
 *
 * The slim images carry no Python, no make and no C++ compiler, so a
 * dependency that runs `node-gyp rebuild` fails outright — the failure the
 * engine's own explainer names as `native-build-toolchain-missing`, seen on
 * code-server, cytube, habitica, kresus, lowdefy and termix. Fixing it by
 * installing the toolchain inside every build is not available: the host
 * build runs as the account's unprivileged uid, and `apt-get install` as a
 * non-root user stops on the dpkg lock. The image is the only place the
 * toolchain can come from.
 *
 * The other half of the test is that this does not cost the projects that do
 * not need it. `node-gyp-build`, `node-pre-gyp` and `node-addon-api` appear in
 * almost every mainstream Node application (n8n, directus, nocodb, outline,
 * budibase, medusa all carry several) and all of those ship prebuilt binaries
 * for linux-x64, so reading them would move a ~1.2GB image load onto projects
 * that install on the slim image exactly as before.
 */
class NodeNativeBuildImageTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/node-native-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The swap itself
    // -------------------------------------------------------------------------

    public function test_a_native_project_keeps_its_major_and_drops_slim(): void
    {
        $this->write('package.json', json_encode([
            'engines' => ['node' => '>=22'],
            'dependencies' => ['better-sqlite3' => '^13.0.3'],
        ]));
        $this->write('package-lock.json', $this->npmLock(['better-sqlite3'], true));

        // The engines field still decides the major; only the variant changes.
        $this->assertSame('node:22-bookworm', Images::nodeImage($this->tmpDir));
    }

    public function test_a_native_project_that_declares_nothing_gets_the_default_major_full(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['bcrypt' => '^5.1.1'],
        ]));

        $this->assertSame(
            'node:' . NodeRuntime::defaultMajor() . '-bookworm',
            Images::nodeImage($this->tmpDir)
        );
    }

    public function test_a_plain_project_is_untouched(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        $this->write('package-lock.json', $this->npmLock(['express'], false));

        $this->assertSame(Images::NODE_IMAGE, Images::nodeImage($this->tmpDir));
    }

    // -------------------------------------------------------------------------
    // git at build time
    //
    // The same swap, for a different missing binary. The generated Dockerfile
    // path runs `RUN {{ git_install }}` for every project; the host compile
    // path has no such line, and runs the slim image with no way to add one.
    // -------------------------------------------------------------------------

    /**
     * github.com/jeremyckahn/chitchatter: `build` is
     * `npm run build:app && npm run build:sdk`, `build:app` is `vite build`,
     * and `vite-plugin-pwa` stamps a revision into the service worker with
     * `git rev-parse`.
     *
     * The install and the transform both succeeded, and then:
     *
     *     vite v6.4.2 building for production...
     *     ✓ 1120 modules transformed.
     *     /bin/sh: 1: git: not found
     */
    public function test_a_pwa_build_gets_the_git_bearing_image(): void
    {
        $this->write('package.json', json_encode([
            'engines' => ['node' => '>=24'],
            'devDependencies' => ['vite-plugin-pwa' => '^0.21.2'],
            'scripts' => ['build' => 'vite build'],
        ]));

        $this->assertSame('node:24-bookworm', Images::nodeImage($this->tmpDir));
    }

    /** Every name on the list, not just the one repo. */
    public function test_each_git_at_build_dependency_gets_the_git_bearing_image(): void
    {
        foreach ([
            'vite-plugin-pwa',
            'next-sitemap',
            '@content-collections/core',
            'git-revision-webpack-plugin',
            'rollup-plugin-git-version',
        ] as $dep) {
            $this->write('package.json', json_encode(['devDependencies' => [$dep => '^1']]));

            $this->assertSame(
                'node:' . NodeRuntime::defaultMajor() . '-bookworm',
                Images::nodeImage($this->tmpDir),
                $dep
            );
        }
    }

    /** A build script that calls git itself needs no list at all. */
    public function test_a_build_script_calling_git_gets_the_git_bearing_image(): void
    {
        foreach ([
            'git rev-parse HEAD > version.txt && vite build',
            'npm run build && git describe --tags',
            'cross-env X=1 git log -1 --format=%h && next build',
        ] as $build) {
            $this->write('package.json', json_encode([
                'dependencies' => ['express' => '4.21.0'],
                'scripts' => ['build' => $build],
            ]));

            $this->assertSame(
                'node:' . NodeRuntime::defaultMajor() . '-bookworm',
                Images::nodeImage($this->tmpDir),
                $build
            );
        }
    }

    /**
     * github.com/fluidd-core/fluidd: the git call is in a file the project
     * ships, not a dependency and not a script.
     *
     *     // vite.config.inject-version.ts
     *     const vitePluginInjectVersion = (): Plugin => ({
     *       config: () => {
     *         const git_hash = child_process
     *           .execSync('git rev-parse --short HEAD')
     *
     * `Error: Command failed: git rev-parse --short HEAD` — after a complete
     * install and a complete build. The filename is indistinguishable from an
     * ordinary Vite config by name, so detection reads the contents.
     */
    public function test_git_inside_a_build_config_is_found(): void
    {
        $this->write('package.json', json_encode([
            'scripts' => ['build' => 'vite build'],
            'devDependencies' => ['vite' => '^6'],
        ]));
        $this->write('vite.config.inject-version.ts', <<<'TS'
import child_process from 'node:child_process';

const vitePluginInjectVersion = (): Plugin => ({
  name: 'version',
  config: () => {
    const git_hash = child_process
      .execSync('git rev-parse --short HEAD')
      .toString();
    return { define: { 'import.meta.env.HASH': JSON.stringify(git_hash) } };
  },
});
TS);

        $this->assertSame(
            'node:' . NodeRuntime::defaultMajor() . '-bookworm',
            Images::nodeImage($this->tmpDir)
        );
    }

    /** Each config stem, and each way of spelling the call. */
    public function test_each_build_config_stem_and_call_shape_is_found(): void
    {
        $bodies = [
            "execSync('git rev-parse --short HEAD')",
            'execSync("git log -1 --format=%h")',
            'run(`git describe --tags`)',
            'execSync("git show HEAD --stat")',
        ];

        foreach (['vite.config', 'webpack.config', 'next.config', 'rollup.config'] as $stem) {
            foreach ($bodies as $body) {
                $this->write('package.json', json_encode(['scripts' => ['build' => 'x']]));
                $this->write($stem . '.ts', "import cp from 'child_process';\nconst h = cp.{$body};\n");

                $this->assertSame(
                    'node:' . NodeRuntime::defaultMajor() . '-bookworm',
                    Images::nodeImage($this->tmpDir),
                    $stem . ' -> ' . $body
                );
            }
        }
    }

    /** A config that does not call git leaves the slim image alone. */
    public function test_a_build_config_without_git_stays_on_the_slim_image(): void
    {
        $this->write('package.json', json_encode([
            'scripts' => ['build' => 'vite build'],
        ]));
        $this->write('vite.config.ts', <<<'TS'
export default defineConfig({
  plugins: [vue()],
  build: { outDir: 'dist' },
  resolve: { alias: { '@': './src' } },
});
TS);

        $this->assertSame(Images::NODE_IMAGE, Images::nodeImage($this->tmpDir));
    }

    /** A project that only names git somewhere unrelated is left alone. */
    public function test_a_git_word_that_is_not_the_binary_does_not_fire(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['express' => '4.21.0'],
            'scripts' => [
                'build' => 'vite build',
                'prepare' => 'husky install',
                'postinstall' => 'node scripts/digit-check.js',
            ],
        ]));

        $this->assertSame(Images::NODE_IMAGE, Images::nodeImage($this->tmpDir));
    }

    /** A transitive name in the lock is not a declaration, here as elsewhere. */
    public function test_a_git_package_only_in_the_lock_does_not_fire(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        $this->write('package-lock.json', $this->npmLock(['vite-plugin-pwa'], false));

        $this->assertSame(Images::NODE_IMAGE, Images::nodeImage($this->tmpDir));
    }

    /**
     * chitchatter's real signal: it is *not* a native project, so the native
     * rule never fired for it and the git rule is the only thing that catches
     * it. Both are asserted together so a future change cannot quietly make
     * one stand in for the other.
     */
    public function test_the_git_rule_is_not_the_native_rule(): void
    {
        $this->write('package.json', json_encode([
            'devDependencies' => ['vite-plugin-pwa' => '^0.21.2'],
            'scripts' => ['build' => 'vite build'],
        ]));

        $context = ProjectContext::at($this->tmpDir);

        $this->assertFalse(NodeRuntime::hasNativeDependency($context));
        $this->assertTrue(NodeRuntime::needsGitBinary($context));
    }

    /**
     * The tag the swap produces has to keep whatever the catalogue resolved.
     * A host whose `node` entry names a different registry keeps that name --
     * the engine is guessing about the *variant*, not about the image.
     */
    public function test_the_swap_derives_from_the_resolved_tag(): void
    {
        $this->assertSame('node:20-bookworm', NodeRuntime::toolchainImage(NodeRuntime::imageTag('20')));
        $this->assertSame('node:24-bookworm', NodeRuntime::toolchainImage(NodeRuntime::imageTag('24')));
    }

    /**
     * A resolved tag this does not recognise is returned as it is. The slim
     * suffix is the only thing that says "official Node image, slim variant",
     * and stripping it off anything else would invent a tag nobody published.
     */
    public function test_a_tag_that_is_not_the_slim_variant_is_returned_unchanged(): void
    {
        foreach ([
            'panelalpha/node:20-bookworm-slim-pa12345678',
            'node:20-bookworm',
            'node:20-alpine',
            'node:20',
        ] as $tag) {
            $this->assertSame($tag, NodeRuntime::toolchainImage($tag), $tag);
        }
    }

    /**
     * The gate the generated-Dockerfile path uses, where the tag comes from
     * the detection decision rather than from {@see NodeRuntime::imageFor()}.
     * The decision resolves the Node major and knows nothing about the
     * project, so the swap has to be applied to what it produced — otherwise
     * a repo whose build lives in a Dockerfile would keep building on slim
     * while the same project on the host path got the full image.
     */
    public function test_the_gate_swaps_the_decisions_tag(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['bcrypt' => '^5.1.1']]));

        $this->assertSame(
            'node:22-bookworm',
            Images::nodeBuildImage('node:22-bookworm-slim', $this->tmpDir)
        );
    }

    public function test_the_gate_leaves_a_plain_project_and_a_foreign_image_alone(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));

        $this->assertSame(
            'node:22-bookworm-slim',
            Images::nodeBuildImage('node:22-bookworm-slim', $this->tmpDir)
        );

        // Not an official Node tag: a recipe that pins its own image, or a
        // command runtime's. Nothing here is ours to change.
        foreach (['oven/bun:1', 'python:3.12-slim', 'ghcr.io/acme/node:20'] as $tag) {
            $this->assertSame($tag, Images::nodeBuildImage($tag, $this->tmpDir), $tag);
        }
    }

    /**
     * The generated Dockerfile and a host compile must name the same variant.
     * This is the property that made the gate a shared method rather than a
     * second copy of the rule.
     */
    public function test_the_dockerfile_and_the_host_path_agree(): void
    {
        $this->write('package.json', json_encode([
            'engines' => ['node' => '22'],
            'dependencies' => ['better-sqlite3' => '^12.0.0'],
        ]));
        $this->write('package-lock.json', $this->npmLock(['better-sqlite3'], true));

        $this->assertSame(
            Images::nodeImage($this->tmpDir),
            Images::nodeBuildImage('node:22-bookworm-slim', $this->tmpDir)
        );
    }

    // -------------------------------------------------------------------------
    // Detection: what fires
    // -------------------------------------------------------------------------

    public function test_binding_gyp_is_a_native_project(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['nan' => '^2.25.0']]));
        // A node-gyp addon carries this; nothing else does.
        $this->write('binding.gyp', '{"targets": []}');

        $this->assertTrue(NodeRuntime::hasNativeDependency(ProjectContext::at($this->tmpDir)));
        $this->assertSame(
            'node:' . NodeRuntime::defaultMajor() . '-bookworm',
            Images::nodeImage($this->tmpDir)
        );
    }

    public function test_a_declared_node_gyp_dependency_is_a_native_project(): void
    {
        // lowdefy's shape: gyp named as a dependency, which nothing but a
        // build that runs gyp has a reason to do.
        $this->write('package.json', json_encode([
            'devDependencies' => ['node-gyp' => '13.0.0'],
        ]));

        $this->assertTrue(NodeRuntime::hasNativeDependency(ProjectContext::at($this->tmpDir)));
    }

    public function test_node_gyp_named_only_in_a_script_is_a_native_project(): void
    {
        // code-server's shape: the project's own install shells out to gyp.
        $this->write('package.json', json_encode([
            'scripts' => ['native' => './ci/dev/test-native.sh && node-gyp rebuild'],
        ]));

        $this->assertTrue(NodeRuntime::hasNativeDependency(ProjectContext::at($this->tmpDir)));
    }

    /**
     * A native package the project asks for itself, in any of the four
     * dependency maps. cytube names `bcrypt` and `cytubefilters` in
     * `dependencies`; kresus names `better-sqlite3` in `devDependencies`.
     */
    public function test_a_native_dependency_declared_by_the_project_is_a_native_project(): void
    {
        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
            $dir = $this->tmpDir . '/' . $section;
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/package.json', json_encode([$section => ['bcrypt' => '^5.1.1']]));

            $this->assertTrue(
                NodeRuntime::hasNativeDependency(ProjectContext::at($dir)),
                $section . ' was not read'
            );
        }
    }

    /**
     * The lockfile path on its own, with nothing declared in package.json:
     * npm records `hasInstallScript: true` for the package, and that is the
     * whole signal. This is code-server's real shape — `argon2` arrives
     * through a workspace's manifest, not the root's.
     *
     * Both npm formats, because those are the two that carry the flag. A
     * pnpm or Yarn lockfile records no install script at all (checked against
     * lowdefy's `pnpm-lock.yaml` and kresus's `yarn.lock`, neither of which
     * has one), so for those the manifest is the only evidence there is —
     * which is why a project on one of them that inherits its native
     * dependency transitively is the false negative this knowingly accepts.
     */
    public function test_a_native_dependency_with_an_install_script_in_the_lock(): void
    {
        foreach (['package-lock.json', 'npm-shrinkwrap.json'] as $lockfile) {
            $dir = $this->tmpDir . '/' . md5($lockfile);
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
            file_put_contents($dir . '/' . $lockfile, $this->npmLock(['argon2'], true));

            $this->assertTrue(
                NodeRuntime::hasNativeDependency(ProjectContext::at($dir)),
                $lockfile . ' was not read'
            );
            $this->assertSame(
                'node:' . NodeRuntime::defaultMajor() . '-bookworm',
                Images::nodeImage($dir),
                $lockfile . ' did not reach the image'
            );
        }
    }

    /**
     * wemux's shape: node-pty 1.x has no linux-x64 prebuild, so a project
     * declaring it compiles on every install.
     */
    public function test_node_pty_is_a_native_dependency(): void
    {
        $this->write('package.json', json_encode([
            'engines' => ['node' => '>=22'],
            'dependencies' => ['node-pty' => '^1.1.0'],
            'pnpm' => ['onlyBuiltDependencies' => ['esbuild', 'node-pty']],
        ]));
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $this->assertSame('node:22-bookworm', Images::nodeImage($this->tmpDir));
    }

    /**
     * A pnpm lock records no install script, so a native package arriving
     * transitively is only visible in the project's own build allowlist.
     */
    public function test_a_pnpm_build_allowlist_naming_a_native_package_is_a_native_project(): void
    {
        $cases = [
            'pkg-only' => [['pnpm' => ['onlyBuiltDependencies' => ['better-sqlite3']]], null],
            'pkg-allow' => [['pnpm' => ['allowBuilds' => ['bcrypt' => true]]], null],
            'ws-only' => [[], "packages:\n  - apps/*\nonlyBuiltDependencies:\n  - esbuild\n  - node-pty\n"],
            'ws-allow' => [[], "allowBuilds:\n  argon2: true\n"],
        ];
        foreach ($cases as $name => [$extra, $workspace]) {
            $dir = $this->tmpDir . '/' . $name;
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/package.json', json_encode(['dependencies' => ['express' => '4.21.0']] + $extra));
            if ($workspace !== null) {
                file_put_contents($dir . '/pnpm-workspace.yaml', $workspace);
            }

            $this->assertTrue(NodeRuntime::hasNativeDependency(ProjectContext::at($dir)), $name);
        }
    }

    /**
     * Allowing only prebuilt packages, or refusing a native one, is no
     * evidence of a compile.
     */
    public function test_a_pnpm_allowlist_without_a_native_package_stays_slim(): void
    {
        $cases = [
            'prebuilt' => [['pnpm' => ['onlyBuiltDependencies' => ['esbuild', '@swc/core', 'sharp']]], null],
            'refused' => [['pnpm' => ['allowBuilds' => ['better-sqlite3' => false]]], null],
            'broken-yaml' => [[], "onlyBuiltDependencies: [node-pty\n  : :"],
        ];
        foreach ($cases as $name => [$extra, $workspace]) {
            $dir = $this->tmpDir . '/' . $name;
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/package.json', json_encode(['dependencies' => ['express' => '4.21.0']] + $extra));
            if ($workspace !== null) {
                file_put_contents($dir . '/pnpm-workspace.yaml', $workspace);
            }

            $this->assertFalse(NodeRuntime::hasNativeDependency(ProjectContext::at($dir)), $name);
        }
    }

    /**
     * A package the list does not know, with an install script, does not
     * count. The list is the limit of what can be recognised, and guessing
     * beyond it is how every project ends up on the big image.
     */
    public function test_an_unknown_package_with_an_install_script_stays_slim(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        $this->write('package-lock.json', $this->npmLock(['some-obscure-tool'], true));

        $this->assertFalse(NodeRuntime::hasNativeDependency(ProjectContext::at($this->tmpDir)));
    }

    // -------------------------------------------------------------------------
    // Detection: what must not fire
    // -------------------------------------------------------------------------

    /**
     * The signal that is deliberately absent. Every one of these was present
     * in the locks of mainstream applications that install on the slim image.
     */
    public function test_the_weak_signals_do_not_fire_on_their_own(): void
    {
        foreach (['node-gyp-build', 'node-pre-gyp', 'node-addon-api', 'nan', 'prebuild-install'] as $package) {
            $dir = $this->tmpDir . '/' . md5($package);
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
            file_put_contents($dir . '/package-lock.json', $this->npmLock([$package], false));

            $this->assertFalse(
                NodeRuntime::hasNativeDependency(ProjectContext::at($dir)),
                $package . ' alone should not claim a toolchain'
            );
        }
    }

    /**
     * A project that merely *resolves* the names gets nothing.
     *
     * This is the test that keeps the mainstream applications on the slim
     * image: n8n, directus, medusa, nocodb, budibase and outline all carry
     * several of these names and an install script for none of them, because
     * a transitive dependency is enough to put the name in the lockfile.
     * Reading that as "this compiles" would move a ~1.2GB image load onto
     * every one of them.
     */
    public function test_names_resolved_into_the_lock_without_an_install_script_do_not_fire(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        // Present in the tree, no `hasInstallScript` anywhere.
        $this->write('package-lock.json', $this->npmLock(
            ['node-gyp', 'better-sqlite3', 'canvas', 'oracledb', 'kerberos'],
            false
        ));

        $this->assertFalse(NodeRuntime::hasNativeDependency(ProjectContext::at($this->tmpDir)));
        $this->assertSame(Images::NODE_IMAGE, Images::nodeImage($this->tmpDir));
    }

    /**
     * Substring guards. A package called `nano` must not fire, and `nanoid` or
     * `oracledb-client` must not match a name they merely contain — the same
     * rule the Python and Ruby package detectors enforce.
     */
    public function test_names_do_not_match_inside_longer_words(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['nano' => '10.0.0', 'sharper' => '1.0.0']]));
        $this->write('package-lock.json', $this->npmLock(['nanoid', 'node-gyp-build-optional', 'oracledb-client'], false));

        $this->assertFalse(NodeRuntime::hasNativeDependency(ProjectContext::at($this->tmpDir)));
    }

    public function test_a_missing_lockfile_is_not_a_native_project(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));

        $this->assertFalse(NodeRuntime::hasNativeDependency(ProjectContext::at($this->tmpDir)));
    }

    public function test_an_unreadable_lockfile_is_not_a_native_project(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        $this->write('package-lock.json', '');

        $this->assertFalse(NodeRuntime::hasNativeDependency(ProjectContext::at($this->tmpDir)));
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * A lockfile that lists $names in the tree, and records an install script
     * only when $withInstallScript is set — which is exactly the distinction
     * npm makes.
     *
     * @param  list<string>  $names
     */
    private function npmLock(array $names, bool $withInstallScript): string
    {
        $packages = [];
        foreach ($names as $name) {
            $packages['node_modules/' . $name] = $withInstallScript
                ? ['version' => '1.0.0', 'hasInstallScript' => true]
                : ['version' => '1.0.0'];
        }

        return json_encode(['lockfileVersion' => 3, 'packages' => $packages]) ?: '{}';
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->tmpDir . '/' . $relative;
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
