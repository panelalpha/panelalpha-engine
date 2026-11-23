<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use Tests\Support\TestNetwork;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The whole decision a JS recipe ends with, pinned per project shape.
 */
class NodeRuntimeFinalizeProjectTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-characterise-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @param array<string, string> $files */
    private function tree(array $files): string
    {
        foreach ($files as $relative => $contents) {
            $path = $this->dir.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $contents);
        }

        return $this->dir;
    }

    public static function projects(): array
    {
        return [
            'node app with scripts' => [['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'default_start' => 'node index.js', 'default_build' => ''], ['package.json' => '{"scripts":{"build":"tsc","start":"node dist\\/main.js","setup":"node setup.js"}}', 'package-lock.json' => '{}'], ['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'output_directory' => 'dist', 'package_manager' => 'npm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'build_command' => 'npm run build', 'start_command' => 'npm start', 'setup_command' => 'npm run setup', 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '3000', 'HUSKY' => '0', 'CI' => '1']]],
            'nginx app stripping a typecheck' => [['strategy' => 'vite', 'label' => 'Vite', 'runtime' => 'nginx', 'port_hint' => '8080', 'output_directory' => 'build', 'default_build' => 'npx vite build', 'env' => ['VITE_X' => '1', 'PORT' => '9999']], ['package.json' => '{"scripts":{"build":"vue-tsc && vite build"}}', 'pnpm-lock.yaml' => "lockfileVersion: '9.0'\n"], ['strategy' => 'vite', 'label' => 'Vite', 'runtime' => 'nginx', 'port_hint' => 8080, 'output_directory' => 'build', 'package_manager' => 'pnpm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 sh -c \'command -v corepack >/dev/null 2>&1 || npm install -g corepack\' && corepack enable && corepack prepare pnpm@10 --activate && pnpm install --frozen-lockfile --dangerously-allow-all-builds', 'build_command' => 'export PATH=/app/node_modules/.bin:$PATH && vite build', 'start_command' => '', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '9999', 'HUSKY' => '0', 'CI' => '1', 'VITE_X' => '1']]],
            'nginx workspace app' => [['strategy' => 'nextjs-export', 'label' => 'Next', 'runtime' => 'nginx', 'port_hint' => 80, 'default_build' => 'npx next build', 'workspace_relative' => 'apps/web', 'workspace_slug' => 'web', 'workspace_package' => '@acme/web'], ['package.json' => '{"workspaces":["apps\\/*"],"scripts":{"build":"turbo build"}}', 'yarn.lock' => "# yarn lockfile v1\n"], ['strategy' => 'nextjs-export', 'label' => 'Next', 'runtime' => 'nginx', 'port_hint' => 80, 'output_directory' => 'dist', 'package_manager' => 'yarn', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 sh -c \'command -v corepack >/dev/null 2>&1 || npm install -g corepack\' && corepack enable && yarn install --frozen-lockfile --ignore-engines', 'build_command' => 'yarn exec turbo build --filter=@acme/web...', 'start_command' => '', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '80', 'HUSKY' => '0', 'CI' => '1']]],
            'defaults only' => [['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 5000, 'default_start' => 'node index.js', 'default_build' => 'npm run compile', 'env' => []], ['package.json' => '{"name":"svc","main":"lib\\/server.js"}'], ['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 5000, 'output_directory' => 'dist', 'package_manager' => 'npm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm install --no-audit --no-fund', 'build_command' => 'npm run compile', 'start_command' => 'node lib/server.js', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '5000', 'HUSKY' => '0', 'CI' => '1']]],
            'typescript server run through its tsx' => [['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'default_start' => 'node index.js', 'default_build' => ''], ['package.json' => '{"type":"module","scripts":{"build":"vite build"},"dependencies":{"express":"^4.22.1","tsx":"^4.21.0"}}', 'package-lock.json' => '{}', 'bun.lockb' => 'x', 'server/index.ts' => 'import express from \'express\';'], ['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'output_directory' => 'dist', 'package_manager' => 'npm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'build_command' => 'npm run build', 'start_command' => 'npx tsx server/index.ts', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '3000', 'HUSKY' => '0', 'CI' => '1']]],
            'typescript server without tsx' => [['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'default_start' => 'node index.js', 'default_build' => ''], ['package.json' => '{"type":"module","scripts":{"build":"vite build"},"dependencies":{"express":"^4.22.1"}}', 'package-lock.json' => '{}', 'server/index.ts' => ''], ['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'output_directory' => 'dist', 'package_manager' => 'npm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'build_command' => 'npm run build', 'start_command' => 'node server/index.ts', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '3000', 'HUSKY' => '0', 'CI' => '1']]],
            'javascript server below server/' => [['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'default_start' => 'node index.js', 'default_build' => ''], ['package.json' => '{"type":"module","scripts":{"build":"vite build"},"dependencies":{"express":"^4.22.1","tsx":"^4.21.0"}}', 'package-lock.json' => '{}', 'server/index.js' => ''], ['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'output_directory' => 'dist', 'package_manager' => 'npm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'build_command' => 'npm run build', 'start_command' => 'node server/index.js', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '3000', 'HUSKY' => '0', 'CI' => '1']]],
            'root index.js beats a server directory' => [['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'default_start' => 'node index.js', 'default_build' => ''], ['package.json' => '{"type":"module","scripts":{"build":"vite build"},"dependencies":{"express":"^4.22.1","tsx":"^4.21.0"}}', 'package-lock.json' => '{}', 'index.js' => '', 'server/index.ts' => ''], ['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'output_directory' => 'dist', 'package_manager' => 'npm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'build_command' => 'npm run build', 'start_command' => 'node index.js', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '3000', 'HUSKY' => '0', 'CI' => '1']]],
            'api server beside a vite app it never serves' => [['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'default_start' => 'node index.js', 'default_build' => ''], ['package.json' => '{"type":"module","scripts":{"build":"vite build"},"dependencies":{"express":"^4.22.1"}}', 'package-lock.json' => '{}', 'index.html' => '<div id=root>', 'vite.config.ts' => 'export default { server: { proxy: { "/auth": "http://localhost:3001", \'/socket.io\': { target: "ws://localhost:3001" } } } }', 'server/index.js' => 'import api from \'./routes.js\'; app.use(\'/api\', api)', 'server/routes.js' => 'router.get(\'/health\')'], ['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'output_directory' => 'dist', 'package_manager' => 'npm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'build_command' => 'npm run build', 'start_command' => 'node panelalpha.spa-api.mjs', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '3000', 'HUSKY' => '0', 'CI' => '1'], 'spa_api' => ['server' => 'node server/index.js', 'api_prefixes' => ['/api', '/auth', '/socket.io']]]],
            'a server file below the entry serves the vite build' => [['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'default_start' => 'node index.js', 'default_build' => ''], ['package.json' => '{"type":"module","scripts":{"build":"vite build"},"dependencies":{"express":"^4.22.1"}}', 'package-lock.json' => '{}', 'index.html' => '', 'vite.config.ts' => '', 'server/index.js' => 'import \'./app.js\'', 'server/app.js' => 'app.use(express.static(dist))'], ['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'output_directory' => 'dist', 'package_manager' => 'npm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'build_command' => 'npm run build', 'start_command' => 'node server/index.js', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '3000', 'HUSKY' => '0', 'CI' => '1']]],
            'a root server without a vite app' => [['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'default_start' => 'node index.js', 'default_build' => ''], ['package.json' => '{"type":"module","scripts":{"build":"vite build"},"dependencies":{"express":"^4.22.1"}}', 'package-lock.json' => '{}', 'server.js' => 'app.listen(3000)'], ['strategy' => 'express', 'label' => 'Express', 'runtime' => 'node', 'port_hint' => 3000, 'output_directory' => 'dist', 'package_manager' => 'npm', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'build_command' => 'npm run build', 'start_command' => 'node server.js', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '3000', 'HUSKY' => '0', 'CI' => '1']]],
            'bun static site' => [['strategy' => 'astro', 'label' => 'Astro', 'runtime' => 'static', 'port_hint' => 4321, 'default_build' => 'npx astro build'], ['package.json' => '{"scripts":{"build-only":"astro build"}}', 'bun.lock' => '{}'], ['strategy' => 'astro', 'label' => 'Astro', 'runtime' => 'static', 'port_hint' => 4321, 'output_directory' => 'dist', 'package_manager' => 'bun', 'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 bun install', 'build_command' => 'npx astro build', 'start_command' => '', 'setup_command' => null, 'env' => ['HOST' => TestNetwork::ANY, 'HOSTNAME' => TestNetwork::ANY, 'PORT' => '4321', 'HUSKY' => '0', 'CI' => '1']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $partial
     * @param  array<string, string>  $files
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('projects')]
    public function test_the_finalized_project_is_pinned(array $partial, array $files, array $expected): void
    {
        $dir = $this->tree($files);
        $listed = [];
        foreach (array_keys($files) as $file) {
            $listed[strtolower($file)] = true;
        }

        $this->assertSame($expected, NodeRuntime::finalizeProject($partial, $dir, $listed));
    }
}
