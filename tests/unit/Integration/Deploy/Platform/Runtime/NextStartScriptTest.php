<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\DetectProjectStrategy;
use PHPUnit\Framework\TestCase;

/**
 * timlrx/tailwind-nextjs-starter-blog's `start` is `next dev`: the deploy built the app and then served it from the dev
 * server, which compiled each page on its first request. The recipe's `next start` serves what was built.
 */
class NextStartScriptTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-next-start-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir.'/next.config.js', 'module.exports = {};');
        file_put_contents($this->dir.'/package-lock.json', '{"lockfileVersion":3}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function startFor(array $scripts, array $dependencies = ['next' => '15.0.0']): string
    {
        file_put_contents($this->dir.'/package.json', (string) json_encode(['scripts' => $scripts, 'dependencies' => $dependencies]));

        return (string) DetectProjectStrategy::detect($this->dir)['start_command'];
    }

    public function test_a_dev_server_start_is_replaced_by_next_start(): void
    {
        $this->assertSame('npx next start -H 0.0.0.0 -p 3000', $this->startFor(['build' => 'next build', 'start' => 'next dev']));
    }

    public function test_a_dev_server_reached_through_a_script_is_replaced_too(): void
    {
        $this->assertSame(
            'npx next start -H 0.0.0.0 -p 3000',
            $this->startFor(['build' => 'next build', 'dev' => 'cross-env INIT_CWD=$PWD next dev', 'start' => 'npm run dev'])
        );
    }

    public function test_a_production_start_is_kept(): void
    {
        $this->assertSame('npm start', $this->startFor(['build' => 'next build', 'start' => 'next start -p $PORT']));
        $this->assertSame('npm start', $this->startFor(['build' => 'next build', 'start' => 'npx serve out']));
    }
}
