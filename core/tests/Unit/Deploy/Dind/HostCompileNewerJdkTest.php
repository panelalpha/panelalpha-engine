<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Tigase takes its Java release from a parent pom in its own repository, so
 * detection picked JDK 21 and javac refused `release 25` (#109). The compile
 * runs again on the catalogued JDK and the app is then run on it.
 */
class HostCompileNewerJdkTest extends TestCase
{
    private const FAILURE = "[ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin:3.15.0:compile "
        . '(default-compile) on project tigase-server: Fatal error compiling: error: release version 25 not supported -> [Help 1]';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-jdk-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function decision(string $toolchain = '21-maven', string $image = 'maven:3-eclipse-temurin-21'): array
    {
        return [
            'strategy' => 'java',
            'image' => $image,
            'requirements' => [new Requirement('java', $toolchain, '', 'pom.xml')],
        ];
    }

    public function test_a_refused_release_picks_the_catalogued_jdk(): void
    {
        $this->assertSame('maven:3-eclipse-temurin-25', HostCompile::newerJdkImage(self::decision(), self::FAILURE));
    }

    public function test_nothing_newer_or_nothing_refused_means_no_retry(): void
    {
        // Already on the newest JDK the catalogue has.
        $this->assertNull(HostCompile::newerJdkImage(
            self::decision('25-maven', 'maven:3-eclipse-temurin-25'),
            str_replace('25', '26', self::FAILURE)
        ));
        $this->assertNull(HostCompile::newerJdkImage(self::decision(), '[ERROR] COMPILATION ERROR : cannot find symbol'));
        $this->assertNull(HostCompile::newerJdkImage(['strategy' => 'go'] + self::decision(), self::FAILURE));
    }

    /** A recipe that named its own image chose it; the engine does not swap it. */
    public function test_a_recipes_own_image_is_kept(): void
    {
        $this->assertNull(HostCompile::newerJdkImage(self::decision('21-maven', 'ghcr.io/acme/jdk:21'), self::FAILURE));
    }

    public function test_the_compile_reruns_on_the_newer_jdk_and_the_app_runs_on_it(): void
    {
        $compose = $this->dir . '/docker-compose.panelalpha.yml';
        file_put_contents($compose, "services:\n  app:\n    image: 'maven:3-eclipse-temurin-21'\n"
            . "    working_dir: /app\n  db:\n    image: 'postgres:17'\n");

        $system = new class () extends System {
            /** @var list<string> */
            public array $images = [];

            public function __construct()
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = is_array($cmd) ? $cmd : [$cmd];
                if (($cmd[1] ?? null) === 'cp') {
                    copy($cmd[2], $cmd[3]);
                } elseif (($cmd[1] ?? null) === 'docker' && !in_array('exec', $cmd, true)) {
                    // The compile is a `docker run`; a `docker compose exec` into the
                    // account (the run file's rewrite asks it for its lxcfs files) is not.
                    $sh = array_search('sh', $cmd, true);
                    $this->images[] = (string) $cmd[$sh - 1];
                }
                $process = new Process(['true']);
                $process->run();

                return $process;
            }
        };

        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder());
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('userAppComposeFilePath')->willReturn($compose);
        $dind->method('composeWriter')->willReturnCallback(fn (): ComposeWriter => new ComposeWriter($dind));

        $compile = new HostCompile($dind);
        (new \ReflectionMethod($compile, 'compileOnNewerJdk'))->invoke(
            $compile,
            self::decision(),
            '/home/acme/project',
            'maven:3-eclipse-temurin-25',
            '',
            'mvn -B -DskipTests package',
            [],
            ''
        );

        $this->assertSame(['maven:3-eclipse-temurin-25'], $system->images);
        $written = (string) file_get_contents($compose);
        $this->assertStringContainsString("image: 'maven:3-eclipse-temurin-25'", $written);
        $this->assertStringContainsString("image: 'postgres:17'", $written);
        $this->assertStringNotContainsString('temurin-21', $written);
    }
}
