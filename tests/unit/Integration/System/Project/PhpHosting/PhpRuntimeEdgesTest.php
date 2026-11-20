<?php

namespace Tests\Integration\System\Project\PhpHosting;

use App\Models\Domain;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\AbstractApplication;
use App\System\Project\PhpHosting\PhpRuntime;
use App\System\Services\Php as PhpService;
use App\System\Services\Webserver;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/**
 * The PHP runtime of an account at its edges: FPM ports, WP-CLI's memory ceiling, an account with no PHP at all, and
 * the one-docroot application that reaches WP-CLI through it.
 */
class PhpRuntimeEdgesTest extends TestCase
{
    /** @var list<array<int, string>> */
    public array $processes = [];

    public ?string $hostDefaultPhp = '8.3';

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'nginx');
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        Mockery::close();
        parent::tearDown();
    }

    /** @return iterable<string, array{0: ?string, 1: int}> */
    public static function ports(): iterable
    {
        yield 'a version' => ['8.1', 9081];
        yield 'no version' => [null, 9083];
        yield 'not major.minor' => ['8', 9083];
        yield 'an empty one' => ['', 9083];
    }

    #[DataProvider('ports')]
    public function test_each_php_version_listens_on_its_own_port(?string $version, int $port): void
    {
        $this->assertSame($port, $this->runtime($this->user())->getPhpPort($version));
    }

    /** @return iterable<string, array{0: int, 1: string}> */
    public static function memoryLimits(): iterable
    {
        yield 'an unlimited container' => [0, '-1'];
        yield 'a small container keeps its whole limit' => [100, '100M'];
        yield 'a medium container gets the 128M floor' => [150, '128M'];
        yield 'a large container gets three quarters' => [1024, '768M'];
    }

    #[DataProvider('memoryLimits')]
    public function test_wp_cli_runs_below_the_containers_memory_limit(int $containerMb, string $limit): void
    {
        $user = $this->user();
        $user->shouldReceive('getMainDomain')->andReturn($this->domain());
        $user->shouldReceive('effectiveMemoryLimit')->andReturn($containerMb);

        $this->runtime($user)->runWpCli(['core', 'version']);

        $this->assertSame('memory_limit='.$limit, LocalRoot::values($this->processes[0])[6]);
    }

    public function test_an_account_without_any_php_version_cannot_run_wp_cli(): void
    {
        $this->hostDefaultPhp = null;
        $user = $this->user();
        $user->shouldReceive('getMainDomain')->andReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No PHP version is available for account 'alice'.");

        $this->runtime($user)->phpBinaryPath();
    }

    public function test_without_a_main_domain_the_hosts_default_php_runs(): void
    {
        $user = $this->user();
        $user->shouldReceive('getMainDomain')->andReturn(null);

        $this->assertSame('/usr/bin/php8.3', $this->runtime($user)->phpBinaryPath());
    }

    public function test_the_custom_ini_of_a_version_lives_in_the_project_dir(): void
    {
        $runtime = $this->runtime($this->user());

        $this->assertSame('/srv/users/alice/php/8.2/custom.ini', $runtime->customIniFilePath('8.2'));
    }

    public function test_an_application_is_its_docroot_under_the_accounts_home(): void
    {
        $domain = $this->domain();
        $user = $this->user();
        $user->shouldReceive('getMainDomain')->andReturn($domain);
        $user->shouldReceive('effectiveMemoryLimit')->andReturn(512);
        $application = new AbstractApplication($this->phpHosting($user), '/shop.example.test/public_html', [$domain]);

        $this->assertSame('php:alice:/shop.example.test/public_html', $application->id());
        $this->assertSame('/srv/home/alice/shop.example.test/public_html', $application->rootPath());
        $this->assertSame('/shop.example.test/public_html', $application->documentRoot());
        $this->assertSame([$domain], $application->domains());
        $this->assertInstanceOf(PhpRuntime::class, $application->php());
        $this->assertSame(['stdout' => 'wp-out', 'stderr' => '', 'exit_code' => 0], $application->runWpCli(['plugin', 'list']));
        $this->assertSame(['plugin', 'list'], array_slice(LocalRoot::values($this->processes[0]), -2));
    }

    private function runtime(ModelsUser $user): PhpRuntime
    {
        return $this->phpHosting($user)->phpRuntime();
    }

    private function phpHosting(ModelsUser $user): PhpHosting
    {
        $runtime = (new ProjectAggregate($this->system(), $user))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $runtime);

        return $runtime;
    }

    private function user(): ModelsUser
    {
        $model = Mockery::mock(ModelsUser::class)->makePartial();
        $model->username = 'alice';
        $model->setDetails(['template' => 'default', 'UID' => 1001, 'GID' => 1001]);

        return $model;
    }

    private function domain(): Domain
    {
        $domain = new Domain();
        $domain->domain = 'shop.example.test';
        $domain->type = 'main';
        $domain->setDetails(['document_root' => '/shop.example.test/public_html', 'php_version' => '8.2']);

        return $domain;
    }

    private function system(): System
    {
        $test = $this;

        return new class ($test) extends System {
            public function __construct(private readonly PhpRuntimeEdgesTest $test) {}

            public function projectDirPath(string $username): string
            {
                return '/srv/users/'.$username;
            }

            public function projectHomeDirPath(string $username): string
            {
                return '/srv/home/'.$username;
            }

            public function php(): PhpService
            {
                $php = Mockery::mock(PhpService::class);
                $php->shouldReceive('defaultPhpVersion')->andReturn($this->test->hostDefaultPhp);

                return $php;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (is_array($cmd)) {
                    $this->test->processes[] = $cmd;
                }

                return new class () extends Process {
                    public function __construct()
                    {
                        parent::__construct(['true']);
                    }

                    public function getOutput(): string
                    {
                        return 'wp-out';
                    }

                    public function getErrorOutput(): string
                    {
                        return '';
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }
                };
            }
        };
    }
}
