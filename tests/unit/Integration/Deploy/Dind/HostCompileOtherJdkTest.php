<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Tigase takes its Java release from a parent pom in its own repository, so
 * detection picked JDK 21 and javac refused `release 25`. The compile
 * runs again on the catalogued JDK and the app is then run on it. The same
 * goes for a build that refuses 21 for an older JDK: an enforcer's range
 * (Ambari's [17,18)) or a Lombok built before 21 (hmdm-server's 1.18.20 on
 * a pom declaring 1.8).
 */
class HostCompileOtherJdkTest extends TestCase
{
    private const FAILURE = '[ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin:3.15.0:compile '
        .'(default-compile) on project tigase-server: Fatal error compiling: error: release version 25 not supported -> [Help 1]';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-jdk-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function decision(string $toolchain = '21-maven', string $image = 'maven:3.9-eclipse-temurin-21', string $declared = ''): array
    {
        return [
            'strategy' => 'java',
            'image' => $image,
            'requirements' => [new Requirement('java', $toolchain, $declared, 'pom.xml')],
        ];
    }

    public function test_a_refused_release_picks_the_catalogued_jdk(): void
    {
        $this->assertSame('maven:3.9-eclipse-temurin-25', HostCompile::retryJdkImage(self::decision(), self::FAILURE));
    }

    public function test_nothing_newer_or_nothing_refused_means_no_retry(): void
    {
        // Already on the newest JDK the catalogue has.
        $this->assertNull(HostCompile::retryJdkImage(
            self::decision('25-maven', 'maven:3.9-eclipse-temurin-25'),
            str_replace('25', '26', self::FAILURE)
        ));
        $this->assertNull(HostCompile::retryJdkImage(self::decision(), '[ERROR] COMPILATION ERROR : cannot find symbol'));
        $this->assertNull(HostCompile::retryJdkImage(['strategy' => 'go'] + self::decision(), self::FAILURE));
    }

    public function test_javacs_other_wording_of_a_refused_release_picks_it_too(): void
    {
        $this->assertSame('maven:3.9-eclipse-temurin-25', HostCompile::retryJdkImage(
            self::decision(),
            '[ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin:3.13.0:compile on project microcks-model: '
            .'Fatal error compiling: error: invalid target release: 25 -> [Help 1]'
        ));
    }

    public function test_an_enforcer_range_picks_the_jdk_inside_it(): void
    {
        $this->assertSame('maven:3.9-eclipse-temurin-17', HostCompile::retryJdkImage(
            self::decision(),
            '[ERROR] Rule 1: org.apache.maven.enforcer.rules.version.RequireJavaVersion failed with message: '
            .'[ERROR] Detected JDK /opt/java/openjdk is version 21.0.12-1 which is not in the allowed range [17,18).'
        ));
        $this->assertSame('maven:3.9-eclipse-temurin-25', HostCompile::retryJdkImage(
            self::decision(),
            'Detected JDK /opt/java/openjdk is version 21.0.13 which is not in the allowed range [25,).'
        ));
    }

    public function test_an_enforcer_in_the_projects_own_words_falls_back_to_the_declared_release(): void
    {
        $this->assertSame('maven:3.9-eclipse-temurin-17', HostCompile::retryJdkImage(
            self::decision('21-maven', 'maven:3.9-eclipse-temurin-21', '17'),
            'Rule 0: org.apache.maven.enforcer.rules.version.RequireJavaVersion failed with message: DataBuff needs JDK 17'
        ));
        $this->assertNull(HostCompile::retryJdkImage(
            self::decision(),
            'Rule 0: org.apache.maven.enforcer.rules.version.RequireJavaVersion failed with message: JDK version can\'t be empty.'
        ));
    }

    public function test_an_old_lombok_on_a_java_8_pom_compiles_on_jdk_8(): void
    {
        $lombok = '[ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin:3.8.1:compile (default-compile) '
            .'on project common: Fatal error compiling: java.lang.NoSuchFieldError: Class com.sun.tools.javac.tree.JCTree$JCImport '
            ."does not have member field 'com.sun.tools.javac.tree.JCTree qualid' -> [Help 1]";

        $this->assertSame('maven:3.9-eclipse-temurin-8', HostCompile::retryJdkImage(
            self::decision('21-maven', 'maven:3.9-eclipse-temurin-21', '8'),
            $lombok
        ));
        $this->assertSame('maven:3.9-eclipse-temurin-17', HostCompile::retryJdkImage(self::decision(), $lombok), 'no declared release: the newest older JDK');
    }

    /** javac 21 dropped release 7: the oldest catalogued JDK that still compiles it. */
    public function test_a_release_the_jdk_no_longer_supports_picks_an_older_jdk(): void
    {
        $this->assertSame('maven:3.9-eclipse-temurin-8', HostCompile::retryJdkImage(
            self::decision(),
            '[ERROR] COMPILATION ERROR : [ERROR] Source option 7 is no longer supported. Use 8 or later.'
        ));
        $this->assertSame('maven:3.9-eclipse-temurin-8', HostCompile::retryJdkImage(
            self::decision(),
            '[ERROR] Target option 7 is no longer supported. Use 8 or later.'
        ));
    }

    /** A recipe that named its own image chose it; the engine does not swap it. */
    public function test_a_recipes_own_image_is_kept(): void
    {
        $this->assertNull(HostCompile::retryJdkImage(self::decision('21-maven', 'ghcr.io/acme/jdk:21'), self::FAILURE));
    }

    public function test_the_compile_reruns_on_the_newer_jdk_and_the_app_runs_on_it(): void
    {
        $compose = $this->dir.'/docker-compose.panelalpha.yml';
        file_put_contents($compose, "services:\n  app:\n    image: 'maven:3.9-eclipse-temurin-21'\n"
            ."    working_dir: /app\n  db:\n    image: 'postgres:17'\n");

        $system = new class extends System
        {
            /** @var list<string> */
            public array $images = [];

            public function __construct() {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $request = is_array($cmd) ? array_values($cmd) : [$cmd];
                $argv = LocalRoot::plain($request);
                if (($argv[0] ?? null) === 'cp') {
                    copy($argv[2], $argv[3]);
                } elseif (($argv[0] ?? null) === 'mv') {
                    rename($argv[count($argv) - 2], $argv[count($argv) - 1]);
                } elseif (LocalRoot::build($request) !== []) {
                    $this->images[] = LocalRoot::build($request)['image'];
                }
                $process = new Process(['true']);
                $process->run();

                return $process;
            }
        };

        $model = new User;
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder);
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('userAppComposeFilePath')->willReturn($compose);
        $dind->method('composeWriter')->willReturnCallback(fn (): ComposeWriter => new ComposeWriter($dind));

        $compile = new HostCompile($dind);
        (new \ReflectionMethod($compile, 'compileOnOtherJdk'))->invoke(
            $compile,
            self::decision(),
            '/home/acme/project',
            'maven:3.9-eclipse-temurin-25',
            '',
            'mvn -B -DskipTests package',
            [],
            ''
        );

        $this->assertSame(['maven:3.9-eclipse-temurin-25'], $system->images);
        $written = (string) file_get_contents($compose);
        $this->assertStringContainsString("image: 'maven:3.9-eclipse-temurin-25'", $written);
        $this->assertStringContainsString("image: 'postgres:17'", $written);
        $this->assertStringNotContainsString('temurin-21', $written);
    }
}
