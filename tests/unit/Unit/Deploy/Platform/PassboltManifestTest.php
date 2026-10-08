<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\PlatformRegistry;
use PHPUnit\Framework\TestCase;

/**
 * `passbolt install` runs baseline checks first, and one fails when
 * config/jwt/ is writable by the process: the install exited and the app
 * restart-looped on every start.
 */
class PassboltManifestTest extends TestCase
{
    private string $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = sys_get_temp_dir() . '/pa-passbolt-' . bin2hex(random_bytes(4));
        mkdir($this->app . '/config/jwt', 0775, true);
        mkdir($this->app . '/bin');
        // Stands in for `bin/cake passbolt create_jwt_keys`.
        file_put_contents($this->app . '/bin/cake', "#!/bin/sh\necho key > config/jwt/jwt.key\necho pem > config/jwt/jwt.pem\n");
        chmod($this->app . '/bin/cake', 0755);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->app) . ' && rm -rf ' . escapeshellarg($this->app));
        parent::tearDown();
    }

    public function test_the_jwt_directory_is_left_read_only_after_the_keys_exist(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root can write anything, so the mode cannot be observed');
        }

        $this->runJwtKeys();
        clearstatcache();

        $this->assertFileExists($this->app . '/config/jwt/jwt.key');
        $this->assertFalse(is_writable($this->app . '/config/jwt'));

        // An upgrade runs it again over the read-only directory.
        $this->runJwtKeys();
        clearstatcache();
        $this->assertFalse(is_writable($this->app . '/config/jwt'));
    }

    public function test_the_non_web_user_warning_is_turned_off(): void
    {
        $manifest = PlatformRegistry::find('passbolt');

        $this->assertNotNull($manifest);
        $this->assertSame('false', $manifest->env['PASSBOLT_SECURITY_DISPLAY_NON_WEBUSER_WARNING'] ?? null);
    }

    private function runJwtKeys(): void
    {
        $manifest = PlatformRegistry::find('passbolt');
        $this->assertNotNull($manifest);
        $command = current(array_filter($manifest->commands, static fn (PlatformCommand $c): bool => $c->id === 'jwt-keys'));
        $this->assertInstanceOf(PlatformCommand::class, $command);

        exec('sh -c ' . escapeshellarg(str_replace('/app', $this->app, $command->run)) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, implode("\n", $output));
    }
}
