<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Dockerfile\GitHistoryUse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitHistoryUseTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function dockerfilesThatUseGit(): array
    {
        return [
            'git describe' => ["FROM golang:1.22\nRUN go build -ldflags \"-X main.v=$(git describe --tags)\" .\n"],
            'COPY .git' => ["FROM node:22\nCOPY .git .git\nRUN npm ci\n"],
            'bind mount of .git' => ["FROM rust:1\nRUN --mount=type=bind,source=.git,target=.git cargo build\n"],
            'git -C rev-parse' => ["FROM node:22\nRUN echo $(git -C /src rev-parse HEAD) > /v\n"],
            'submodules' => ["FROM node:22\nCOPY . .\nRUN git submodule update --init\n"],
        ];
    }

    #[DataProvider('dockerfilesThatUseGit')]
    public function test_a_dockerfile_that_uses_git_keeps_it(string $dockerfile): void
    {
        $this->assertSame('its Dockerfile uses git', GitHistoryUse::reason($dockerfile, null, []));
    }

    /** @return array<string, array{string}> */
    public static function dockerfilesThatDoNot(): array
    {
        return [
            // traefik/whoami: installs git, never reads history.
            'whoami' => ["FROM golang:1-alpine AS builder\nRUN apk --no-cache --no-progress add git ca-certificates tzdata make \\\n"
                . "    && update-ca-certificates\nCOPY . .\nRUN make build\n"],
            'installs git' => ["FROM debian:bookworm\nRUN apt-get update && apt-get install -y git curl\n"],
            'clones something else' => ["FROM node:22\nRUN git clone https://github.com/x/y.git /opt/y\n"],
            '.gitignore and github.com' => ["FROM node:22\nCOPY .gitignore .\nRUN curl https://github.com/x/y\n"],
            'build arg' => ["FROM node:22\nARG GIT_SHA\nENV GIT_SHA=\$GIT_SHA\n"],
            'a comment' => ["FROM node:22\n# run git describe here one day\nCOPY . .\n"],
            '.github dir' => ["FROM node:22\nCOPY .github .github\n"],
        ];
    }

    #[DataProvider('dockerfilesThatDoNot')]
    public function test_a_dockerfile_that_does_not_use_git_lets_it_go(string $dockerfile): void
    {
        $this->assertNull(GitHistoryUse::reason($dockerfile, null, []));
    }

    public function test_a_dockerignore_that_reincludes_git_is_the_projects_decision(): void
    {
        $this->assertSame(
            'its .dockerignore re-includes .git',
            GitHistoryUse::reason(null, "node_modules\n!.git\n", [])
        );
        $this->assertNull(GitHistoryUse::reason(null, "node_modules\n.git\n!.gitignore\n", []));
    }

    /** @return array<string, array{string, string}> */
    public static function manifestsThatReadGit(): array
    {
        return [
            'setuptools-scm' => ['pyproject.toml', "[build-system]\nrequires = [\"setuptools\", \"setuptools-scm\"]\n"],
            'hatch-vcs' => ['pyproject.toml', "[tool.hatch.version]\nsource = \"vcs\"\n[build-system]\nrequires = [\"hatchling\", \"hatch-vcs\"]\n"],
            'setup.py scm' => ['setup.py', "setup(use_scm_version=True)\n"],
            'vergen' => ['Cargo.toml', "[build-dependencies]\nvergen = { version = \"8\", features = [\"git\"] }\n"],
            'git-rev-sync' => ['package.json', "{\"dependencies\": {\"git-rev-sync\": \"^3.0.0\"}}\n"],
            'gemspec' => ['Gemfile', "source 'https://rubygems.org'\ngemspec\n"],
            'make describe' => ['Makefile', "VERSION := \$(shell git describe --tags)\n"],
            // go-vikunja/vikunja's magefile.go, trimmed.
            'mage git runner' => ['magefile.go', "func runGitCommandWithOutput(ctx context.Context, arg ...string) ([]byte, error) {\n"
                . "\tcmd := exec.CommandContext(ctx, \"git\", arg...)\n\treturn cmd.Output()\n}\n\n"
                . "func getRawVersionNumber(ctx context.Context) string {\n"
                . "\tversionBytes, err := runGitCommandWithOutput(ctx, \"describe\", \"--tags\", \"--always\", \"--abbrev=10\")\n"],
            'mage sh.Output' => ['magefile.go', "out, _ := sh.Output(\"git\", \"rev-parse\", \"--short\", \"HEAD\")\n"],
        ];
    }

    #[DataProvider('manifestsThatReadGit')]
    public function test_a_manifest_that_reads_the_version_from_git_keeps_it(string $file, string $contents): void
    {
        $this->assertSame($file . ' reads the version from git', GitHistoryUse::reason(null, null, [$file => $contents]));
    }

    public function test_ordinary_manifests_let_it_go(): void
    {
        $this->assertNull(GitHistoryUse::reason(null, null, [
            'pyproject.toml' => "[project]\nname = \"app\"\nversion = \"1.0\"\n",
            'Cargo.toml' => "[package]\nname = \"app\"\nbuilt-in = true\n[dependencies]\nserde = \"1\"\n",
            'package.json' => "{\"repository\": \"git+https://github.com/x/y.git\"}\n",
            'Gemfile' => "source 'https://rubygems.org'\ngem 'rails'\n",
            'Makefile' => "build:\n\tgo build ./...\n",
            'magefile.go' => "func Build() error {\n\treturn sh.Run(\"go\", \"build\", \"./...\")\n}\n",
        ]));
    }

    public function test_a_bundler_config_that_runs_git_keeps_it(): void
    {
        // Stremio/stremio-web's webpack.config.js:15.
        $files = ['webpack.config.js' => "const { execSync } = require('child_process');\n"
            . "const COMMIT_HASH = execSync('git rev-parse HEAD').toString().trim();\n"];

        $this->assertSame(
            'webpack.config.js reads the version from git',
            GitHistoryUse::reason("FROM node:20\nCOPY . .\nRUN pnpm build\n", null, ['package.json' => '{"scripts":{"build":"webpack"}}'], $this->reader($files))
        );
    }

    public function test_a_script_file_a_lifecycle_hook_runs_is_followed_into_its_imports(): void
    {
        // bitfocus/companion: postinstall -> run build:writefile -> tools/build_writefile.mts -> ./lib.mts.
        $package = json_encode(['scripts' => [
            'postinstall' => 'husky && run build:writefile && run build:whatsnew',
            'build:writefile' => 'tsx ./tools/build_writefile.mts',
            'build:whatsnew' => 'tsx ./tools/build_whatsnew.mts',
        ]]);
        $files = [
            'tools/build_writefile.mts' => "import { fs, path } from 'zx'\nimport { generateVersionString } from './lib.mts'\n",
            'tools/build_whatsnew.mts' => "import fs from 'fs'\n",
            'tools/lib.mts' => "const headHashRaw = await \$`git rev-parse --short=10 HEAD`\n",
        ];

        $this->assertSame(
            'tools/lib.mts, run by a package.json script, reads the version from git',
            GitHistoryUse::reason("FROM node:26\nCOPY . /app/\nRUN yarn\n", null, ['package.json' => $package], $this->reader($files))
        );
    }

    public function test_a_script_the_dockerfile_names_and_inline_git_are_seen(): void
    {
        $package = json_encode(['scripts' => [
            'dist' => 'node scripts/dist.js',
            'version:stamp' => 'echo $(git describe --tags) > VERSION',
        ]]);
        $read = $this->reader(['scripts/dist.js' => "require('child_process').spawnSync('git', ['describe', '--tags'])\n"]);

        $this->assertSame(
            'scripts/dist.js, run by a package.json script, reads the version from git',
            GitHistoryUse::reason("FROM node:22\nRUN npm run dist\n", null, ['package.json' => $package], $read)
        );
        $this->assertSame(
            'package.json script "version:stamp" reads the version from git',
            GitHistoryUse::reason("FROM node:22\nRUN yarn version:stamp\n", null, ['package.json' => $package], $read)
        );
    }

    public function test_js_projects_that_do_not_read_git_let_it_go(): void
    {
        $package = json_encode(['scripts' => [
            'postinstall' => 'node ./scripts/patch.js',
            'build' => 'vite build',
            'release' => 'node scripts/release.js',
        ]]);
        $files = [
            'vite.config.ts' => "export default { build: { outDir: 'dist' } }\n",
            'scripts/patch.js' => "require('../../outside.js')\n",
            // Not run by the build.
            'scripts/release.js' => "execSync('git tag v1')\n",
            'webpack.config.js' => "// see https://github.com/x/y.git\nmodule.exports = {}\n",
        ];

        $this->assertNull(GitHistoryUse::reason("FROM node:22\nRUN npm ci && npm run build\n", null, ['package.json' => $package], $this->reader($files)));
    }

    /**
     * @param array<string, string> $files
     * @return callable(string): ?string
     */
    private function reader(array $files): callable
    {
        return static fn (string $path): ?string => $files[$path] ?? null;
    }
}
