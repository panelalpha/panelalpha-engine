<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\DetectAppPort;
use PHPUnit\Framework\TestCase;

/**
 * The run file holds env_vars and database passwords, so it is 0600 and the
 * account's (engine#173). The core runs as www-data and cannot open it; port
 * detection must still read it, or every app falls back to 8080.
 */
class ComposeYamlPrivateFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-private-compose-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        ComposeYaml::readPrivilegedWith(null);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @chmod($file, 0600);
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_the_run_file_is_owner_only(): void
    {
        $this->assertSame('600', EngineArtifacts::RUN_COMPOSE_MODE);
    }

    public function test_a_file_the_core_cannot_open_is_read_through_the_privileged_reader(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root opens a 0000 file, so the plain read never fails');
        }
        $path = $this->unreadableRunFile("services:\n  app:\n    image: acme/app\n    ports:\n      - '3000:3000'\n");
        $asked = [];
        ComposeYaml::readPrivilegedWith(function (string $p) use (&$asked): ?string {
            $asked[] = $p;
            chmod($p, 0600);
            $raw = file_get_contents($p);
            chmod($p, 0000);

            return $raw;
        });

        $this->assertSame([3000], DetectAppPort::detectAllPorts($path)['all']);
        $this->assertSame(3000, DetectAppPort::detectPrimaryPort($path));
        $this->assertSame([$path], array_values(array_unique($asked)));
    }

    public function test_a_readable_file_never_goes_through_the_privileged_reader(): void
    {
        $path = $this->dir . '/' . EngineArtifacts::RUN_COMPOSE;
        file_put_contents($path, "services:\n  app:\n    image: acme/app\n");
        ComposeYaml::readPrivilegedWith(function (): ?string {
            $this->fail('a readable file was read through sudo');
        });

        $this->assertSame(['app' => ['image' => 'acme/app']], ComposeYaml::parseFile($path)['services']);
    }

    public function test_a_missing_file_is_null_without_asking(): void
    {
        ComposeYaml::readPrivilegedWith(function (): ?string {
            $this->fail('a missing file was read through sudo');
        });

        $this->assertNull(ComposeYaml::read($this->dir . '/absent.yml'));
    }

    private function unreadableRunFile(string $yaml): string
    {
        $path = $this->dir . '/' . EngineArtifacts::RUN_COMPOSE;
        file_put_contents($path, $yaml);
        chmod($path, 0000);

        return $path;
    }
}
