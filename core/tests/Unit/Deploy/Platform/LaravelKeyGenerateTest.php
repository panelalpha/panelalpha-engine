<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use PHPUnit\Framework\TestCase;

/**
 * #178: the laravel manifest's install-stage key:generate, run as the
 * entrypoint runs it, with a stand-in `php` that records what it was asked.
 *
 * The engine puts APP_KEY into .env, which compose loads as env_file. A
 * key:generate after that rewrote the file under the running app, so the
 * first restart after the first deploy came back on another key.
 */
class LaravelKeyGenerateTest extends TestCase
{
    private string $bin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bin = sys_get_temp_dir() . '/pa-keygen-' . bin2hex(random_bytes(4));
        mkdir($this->bin);
        file_put_contents($this->bin . '/php', "#!/bin/sh\necho \"php \$*\" >> \"\$PA_CALLS\"\n");
        chmod($this->bin . '/php', 0755);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->bin . '/*') ?: []);
        rmdir($this->bin);
        parent::tearDown();
    }

    private function keyGenerate(?string $appKey): string
    {
        $command = null;
        foreach (PlatformRegistry::find('laravel')->stage(PlatformStage::INSTALL) as $c) {
            if ($c->id === 'key-generate') {
                $command = $c->run;
            }
        }
        $this->assertNotNull($command);

        $calls = $this->bin . '/calls';
        @unlink($calls);
        $env = ['PATH' => $this->bin . ':/usr/bin:/bin', 'PA_CALLS' => $calls];
        if ($appKey !== null) {
            $env['APP_KEY'] = $appKey;
        }
        $proc = proc_open(['/bin/sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc));

        return is_file($calls) ? (string) file_get_contents($calls) : '';
    }

    public function test_a_key_already_in_the_environment_is_not_regenerated(): void
    {
        $this->assertSame('', $this->keyGenerate('base64:' . base64_encode(random_bytes(32))));
    }

    public function test_no_key_still_gets_one(): void
    {
        $this->assertStringContainsString('artisan key:generate --force', $this->keyGenerate(null));
    }

    /** Plainpad's nested template says APP_KEY={KEY}; not a key, so it is still generated. */
    public function test_a_blank_or_placeholder_key_still_gets_one(): void
    {
        $this->assertStringContainsString('artisan key:generate', $this->keyGenerate(''));
        $this->assertStringContainsString('artisan key:generate', $this->keyGenerate('{KEY}'));
    }
}
