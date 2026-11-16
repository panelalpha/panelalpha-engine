<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Dockerfile\GitHistoryUse;
use PHPUnit\Framework\TestCase;

/**
 * Following package.json scripts into the files they run: order, chains, paths that leave the context, the budget.
 */
class GitHistoryUseScriptsTest extends TestCase
{
    /** @var list<string> */
    private array $reads = [];

    /**
     * @param array<string, string> $files
     * @return callable(string): ?string
     */
    private function reader(array $files): callable
    {
        return function (string $path) use ($files): ?string {
            $this->reads[] = $path;

            return $files[$path] ?? null;
        };
    }

    /** @param array<string, string> $scripts */
    private function package(array $scripts): string
    {
        return (string) json_encode(['scripts' => $scripts]);
    }

    /**
     * @param array<string, string> $scripts
     * @param array<string, string> $files
     */
    private function reason(array $scripts, array $files, ?string $dockerfile = "FROM node:22\nRUN npm ci\n"): ?string
    {
        $this->reads = [];

        return GitHistoryUse::reason($dockerfile, null, ['package.json' => $this->package($scripts)], $this->reader($files));
    }

    public function test_a_missing_script_file_is_skipped_and_the_next_one_read(): void
    {
        $reason = $this->reason(
            ['postinstall' => 'node scripts/missing.js && node ./scripts/stamp.js'],
            [
                'scripts/stamp.js' => "const lib = require('./lib/version.js');\n",
                'scripts/lib/version.js' => "execSync('git rev-parse HEAD')\n",
            ]
        );

        $this->assertSame('scripts/lib/version.js, run by a package.json script, reads the version from git', $reason);
        $this->assertSame(
            ['scripts/missing.js', 'scripts/stamp.js', 'scripts/lib/version.js'],
            array_values(array_filter($this->reads, static fn (string $p): bool => str_starts_with($p, 'scripts/')))
        );
    }

    public function test_imports_that_leave_the_context_or_enter_node_modules_are_not_read(): void
    {
        $reason = $this->reason(
            ['prepare' => 'tsx tools/build.ts'],
            [
                'tools/build.ts' => "import a from '../../outside.js';\nimport b from '../node_modules/x/y.js';\nimport c from './c.mts';\n",
                'tools/c.mts' => "import d from '../tools/./d.js';\n",
                'tools/d.js' => "spawn('git', ['describe', '--tags'])\n",
            ]
        );

        $this->assertSame('tools/d.js, run by a package.json script, reads the version from git', $reason);
        $this->assertNotContains('node_modules/x/y.js', $this->reads);
    }

    public function test_script_chains_are_followed_through_pre_and_post_hooks(): void
    {
        $this->assertSame(
            'package.json script "poststamp" reads the version from git',
            $this->reason(
                [
                    'build' => 'npm run compile && yarn stamp',
                    'compile' => 'tsc',
                    'stamp' => 'echo stamping',
                    'poststamp' => 'git log -1 > VERSION',
                ],
                []
            )
        );
    }

    public function test_a_script_the_dockerfile_runs_is_checked_before_the_files_scripts_run(): void
    {
        $this->assertSame(
            'package.json script "release" reads the version from git',
            $this->reason(
                ['build' => 'node build.js', 'release' => 'git describe'],
                ['build.js' => "execSync('git rev-parse HEAD')"],
                "FROM node:22\nRUN pnpm run release\n"
            )
        );
    }

    public function test_a_bundler_config_is_checked_before_any_script(): void
    {
        $this->assertSame(
            'vite.config.ts reads the version from git',
            $this->reason(
                ['build' => 'git describe'],
                ['vite.config.ts' => "const v = execSync('git describe --tags')", 'next.config.js' => 'git log']
            )
        );
    }

    public function test_the_file_budget_stops_the_walk(): void
    {
        $files = [];
        $commands = [];
        for ($i = 1; $i <= 21; $i++) {
            $files["f{$i}.js"] = 'console.log(1)';
            $commands[] = "node f{$i}.js";
        }
        $files['f21.js'] = "execSync('git rev-parse HEAD')";

        $this->assertNull($this->reason(['build' => implode(' && ', $commands)], $files));

        $files['f20.js'] = "execSync('git rev-parse HEAD')";

        $this->assertSame('f20.js, run by a package.json script, reads the version from git', $this->reason(['build' => implode(' && ', $commands)], $files));
    }

    public function test_without_a_reader_or_a_package_json_nothing_is_followed(): void
    {
        $this->assertNull(GitHistoryUse::reason(null, null, ['package.json' => $this->package(['build' => 'git describe'])]));
        $this->assertNull(GitHistoryUse::reason(null, null, [], $this->reader(['vite.config.ts' => 'git describe'])));
        $this->assertNull(GitHistoryUse::reason(null, null, ['package.json' => 'not json'], $this->reader([])));
    }
}
