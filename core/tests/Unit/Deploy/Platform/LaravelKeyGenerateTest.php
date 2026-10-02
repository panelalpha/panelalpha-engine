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

    private string $stderr = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bin = sys_get_temp_dir() . '/pa-keygen-' . bin2hex(random_bytes(4));
        mkdir($this->bin);
        // Like artisan: writes the key into .env only when PA_WRITES says the line was there.
        file_put_contents(
            $this->bin . '/php',
            "#!/bin/sh\necho \"php \$*\" >> \"\$PA_CALLS\"\n"
            . "[ -n \"\$PA_WRITES\" ] && echo 'APP_KEY=base64:Zm9v' > \"\$PA_WRITES\"\nexit 0\n"
        );
        chmod($this->bin . '/php', 0755);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->bin . '/{,.}*[!.]', GLOB_BRACE) ?: []);
        rmdir($this->bin);
        parent::tearDown();
    }

    private function keyGenerate(?string $appKey, ?string $writes = '.env', int $exit = 0): string
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
        $env = ['PATH' => $this->bin . ':/usr/bin:/bin', 'PA_CALLS' => $calls, 'PA_WRITES' => (string) $writes];
        if ($appKey !== null) {
            $env['APP_KEY'] = $appKey;
        }
        $proc = proc_open(['/bin/sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->bin, $env);
        stream_get_contents($pipes[1]);
        $this->stderr = (string) stream_get_contents($pipes[2]);
        $this->assertSame($exit, proc_close($proc));

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

    /** LinkAce: no APP_KEY line, so key:generate wrote nothing and exited 0; migrate must not follow. */
    public function test_a_key_generate_that_wrote_no_key_stops_the_install(): void
    {
        $this->assertStringContainsString('artisan key:generate', $this->keyGenerate(null, null, 1));
        $this->assertStringContainsString('stopping before migrate', $this->stderr);
    }

    /** Laravel writes .env.<APP_ENV> instead when the project has one. */
    public function test_a_key_written_into_the_environment_file_counts(): void
    {
        $this->assertStringContainsString('artisan key:generate', $this->keyGenerate(null, '.env.production'));
    }
}
