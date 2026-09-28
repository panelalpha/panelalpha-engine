<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Dockerfile\BuildRecipe;
use App\Lib\Deploy\Platform\Dockerfile\NodeInstallLayer;
use Tests\TestCase;

/**
 * The layer that turns a lockfile into node_modules.
 *
 * The ordering here is what makes a warm deploy fast: manifests are copied
 * ahead of the source, so BuildKit reuses the whole install whenever only
 * application code changed. Copying the source first would invalidate the
 * install on every commit, which is a two-minute npm ci on every push.
 *
 * Workspaces give that up on purpose - npm and bun need every
 * `apps/*​/package.json` before they can link anything.
 */
class NodeInstallLayerTest extends TestCase
{
    /**
     * @param array<string, true> $files
     */
    private function render(array $files, string $install = 'npm ci', string $manager = ''): string
    {
        $decision = $manager === '' ? [] : ['package_manager' => $manager];

        return (new NodeInstallLayer(new BuildRecipe($decision, $files), $install))->render();
    }

    public function test_the_manifests_are_copied_before_the_source(): void
    {
        $layer = $this->render(['package.json' => true, 'package-lock.json' => true]);

        $this->assertLessThan(
            strpos($layer, 'COPY . .'),
            strpos($layer, 'COPY package-lock.json ./'),
            'the lockfile must be copied before the source'
        );
    }

    public function test_the_install_runs_before_the_source_is_copied(): void
    {
        // The whole point: a code-only change leaves this layer cached.
        $layer = $this->render(['package.json' => true, 'package-lock.json' => true]);

        $this->assertLessThan(strpos($layer, 'COPY . .'), strpos($layer, 'RUN npm ci'));
    }

    public function test_each_package_managers_lockfile_is_the_one_copied(): void
    {
        foreach ([
            'pnpm' => 'pnpm-lock.yaml',
            'yarn' => 'yarn.lock',
            'bun' => 'bun.lock',
        ] as $manager => $lockfile) {
            $layer = $this->render(['package.json' => true, $lockfile => true], 'install', $manager);

            $this->assertStringContainsString("COPY {$lockfile} ./", $layer, $manager);
        }
    }

    public function test_extra_configuration_files_are_copied_when_present(): void
    {
        // An .npmrc naming a private registry has to be there before the
        // install runs, or the install fails resolving.
        $layer = $this->render([
            'package.json' => true,
            'package-lock.json' => true,
            '.npmrc' => true,
            'bunfig.toml' => true,
        ]);

        $this->assertStringContainsString('COPY .npmrc ./', $layer);
        $this->assertStringContainsString('COPY bunfig.toml ./', $layer);
    }

    public function test_a_configuration_file_the_project_lacks_is_not_copied(): void
    {
        // COPY of a missing path fails the build.
        $layer = $this->render(['package.json' => true, 'package-lock.json' => true]);

        $this->assertStringNotContainsString('.npmrc', $layer);
        $this->assertStringNotContainsString('pnpm-workspace.yaml', $layer);
    }

    /**
     * engine#152: zigbee2mqtt ships `.npmrc` and lists it in its own
     * `.dockerignore`, so BuildKit has no `/.npmrc` to COPY.
     */
    public function test_a_file_the_projects_dockerignore_drops_is_not_copied(): void
    {
        $dir = sys_get_temp_dir() . '/node-install-ignore-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/.dockerignore', ".eslintignore\n.git\n.npmrc\nnode_modules\n");

        try {
            $layer = (new NodeInstallLayer(
                new BuildRecipe(['package_manager' => 'pnpm'], ['package.json' => true, 'pnpm-lock.yaml' => true, '.npmrc' => true], $dir),
                'pnpm install --frozen-lockfile'
            ))->render();

            $this->assertStringNotContainsString('COPY .npmrc', $layer);
            $this->assertStringContainsString('COPY pnpm-lock.yaml ./', $layer);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function test_a_project_with_no_lockfile_copies_only_its_manifest(): void
    {
        $layer = $this->render(['package.json' => true]);

        $this->assertStringContainsString('COPY package.json ./', $layer);
        $this->assertStringContainsString('--mount=type=cache,target=/root/.npm', $layer);
        $this->assertStringContainsString('npm ci', $layer);
    }

    public function test_git_hook_scripts_are_stripped_before_installing(): void
    {
        // husky's prepare script runs during install and fails in a container
        // with no .git, taking the build with it.
        $layer = $this->render(['package.json' => true, 'package-lock.json' => true]);

        $this->assertLessThan(strpos($layer, 'npm ci'), strpos($layer, 'RUN '));
        $this->assertMatchesRegularExpression('/RUN .+\n.*RUN .*npm ci/', $layer);
    }

    public function test_a_workspace_copies_the_tree_first(): void
    {
        // npm cannot link workspaces it cannot see, so this one gives up the
        // cache deliberately rather than failing to install.
        $layer = $this->render([
            'package.json' => true,
            'package-lock.json' => true,
            'pnpm-workspace.yaml' => true,
        ]);

        $this->assertStringStartsWith('COPY . .', $layer);
        $this->assertStringNotContainsString('COPY package.json ./', $layer);
    }

    public function test_a_workspace_still_installs_after_stripping_hooks(): void
    {
        $layer = $this->render([
            'package.json' => true,
            'pnpm-workspace.yaml' => true,
        ], 'pnpm install --frozen-lockfile', 'pnpm');

        $this->assertStringContainsString('pnpm install --frozen-lockfile', $layer);
        $this->assertStringContainsString('target=/root/.local/share/pnpm/store', $layer);
    }
}
