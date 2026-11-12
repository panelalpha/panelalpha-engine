<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Models\Domain;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\WordPress\MustUsePlugin;
use App\System\Services\Webserver;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Pins the whole WP-CLI command line of a PHP-hosting account, and the refusal of a run with no exit code.
 */
class PhpRuntimeWpCliCharacterisationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setWebserver('openlitespeed');
    }

    protected function tearDown(): void
    {
        $this->setWebserver(null);
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_command_runs_the_matched_domains_php_as_the_account(): void
    {
        $host = new WpCliHost(FakeProcess::of(3, 'out', 'err'));
        $project = $this->project($host);

        $result = $project->phpRuntime()->runWpCli(['--path=/home/alice/shop.test/public_html', 'option', 'get', 'home']);

        $this->assertSame(['stdout' => 'out', 'stderr' => 'err', 'exit_code' => 3], $result);
        $this->assertCount(1, $host->commands);
        $this->assertTrue(LocalRoot::isRequest($host->commands[0]));
        $this->assertSame('account-container.exec', LocalRoot::action($host->commands[0]));
        $this->assertSame('1001:1002', LocalRoot::values($host->commands[0])[1]);
        $this->assertSame([
            '/usr/local/lsws/lsphp74/bin/php', '-d', 'memory_limit=768M', '/opt/wp-cli.phar',
            '--exec='.MustUsePlugin::MEND_ON_LOAD,
            '--path=/home/alice/shop.test/public_html', 'option', 'get', 'home',
        ], LocalRoot::inAccount($host->commands[0]));
    }

    public function test_a_run_without_an_exit_code_is_refused(): void
    {
        $host = new WpCliHost(new class (['true']) extends Process {
            public function getExitCode(): ?int
            {
                return null;
            }
        });

        $this->expectExceptionMessage('Could not get exit code after running WP-CLI command: plugin list');

        $this->project($host)->phpRuntime()->runWpCli(['plugin', 'list']);
    }

    private function project(WpCliHost $host): PhpHosting
    {
        $shop = new Domain();
        $shop->domain = 'shop.test';
        $shop->setDetails(['php_version' => '7.4']);

        $model = Mockery::mock(ModelsUser::class)->makePartial();
        $model->username = 'alice';
        $model->setDetails(['UID' => 1001, 'GID' => 1002, 'memory_limit' => 1024]);
        $model->shouldReceive('findDomainByDocumentRoot')->with('/home/alice/shop.test/public_html')->andReturn($shop);
        $model->shouldReceive('findDomainByDocumentRoot')->andReturn(null);
        $model->shouldReceive('getMainDomain')->andReturn($shop);

        return new PhpHosting(new ProjectAggregate($host, $model));
    }

    private function setWebserver(?string $slug): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, $slug);
    }
}

final class WpCliHost extends System
{
    /** @var list<string|list<string>> */
    public array $commands = [];

    public function __construct(private Process $answer) {}

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $this->commands[] = $cmd;

        return $this->answer;
    }
}
