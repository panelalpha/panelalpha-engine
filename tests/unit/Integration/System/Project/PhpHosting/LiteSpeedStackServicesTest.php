<?php

namespace Tests\Integration\System\Project\PhpHosting;

use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\LiteSpeedStack;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A LiteSpeed account runs one lsphp per PHP version its domains use, restarted whole for any change.
 */
class LiteSpeedStackServicesTest extends TestCase
{
    /** @var list<string|array<int, string>> */
    public array $processes = [];

    /** @var list<string> */
    public array $copied = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_stack_renders_from_the_litespeed_templates_with_no_init_scripts(): void
    {
        [$stack, $project] = $this->stack([]);

        $this->assertSame('Dockerfile-ls', $stack->dockerfileTemplateName());
        $this->assertSame('docker-compose.yml-ls', $stack->composeTemplateName());
        $this->assertSame([], $stack->entrypointInitScripts($project));
    }

    public function test_one_lsphp_runs_per_php_version_the_domains_use(): void
    {
        [$stack, $project] = $this->stack([
            $this->domain('one.example.test', '8.3'),
            $this->domain('two.example.test', '8.3'),
            $this->domain('static.example.test', null),
            $this->domain('legacy.example.test', '7.4'),
        ], "PHP_LSAPI_CHILDREN=12\nPHP_LSAPI_MAX_REQUESTS=700");

        $services = $stack->services($project);

        $this->assertSame(['lsphp83', 'lsphp74'], array_map(static fn ($service): string => $service->name, $services));
        $this->assertSame(
            'exec runuser -u alice -- env LSPHP_ENABLE_USER_INI=on PHP_LSAPI_CHILDREN=12 PHP_LSAPI_MAX_REQUESTS=700 '
                .'/usr/local/lsws/lsphp83/bin/lsphp -b *:9083',
            $services[0]->command,
        );
        $this->assertStringEndsWith('/usr/local/lsws/lsphp74/bin/lsphp -b *:9074', $services[1]->command);
    }

    public function test_an_account_without_php_domains_runs_no_lsphp(): void
    {
        [$stack, $project] = $this->stack([$this->domain('static.example.test', null)]);

        $this->assertSame([], $stack->services($project));
    }

    public function test_a_reload_restarts_the_versions_lsphp_in_the_php_container(): void
    {
        [$stack, $project] = $this->stack([]);

        $stack->restartPhpHandler($project, '8.3');
        $stack->reloadPhpHandler($project, '8.2');

        $this->assertSame([
            Privileged::accountContainer('alice')->exec(['bash', '/entrypoint-runner.sh', 'restart', 'lsphp83']),
            Privileged::accountContainer('alice')->exec(['bash', '/entrypoint-runner.sh', 'restart', 'lsphp82']),
        ], $this->processes);
    }

    public function test_the_settings_are_the_accounts_redis_settings(): void
    {
        [$stack, $project] = $this->stack([]);

        $stack->applySettings($project);
        $stack->waitForAllRunning($project, 1, 0);

        $this->assertSame(["maxmemory 32mb\n"], $this->copied);
    }

    /**
     * @param  list<DomainModel>  $domains
     * @return array{0: LiteSpeedStack, 1: PhpHosting}
     */
    private function stack(array $domains, ?string $lsphpSettings = null): array
    {
        $model = Mockery::mock(ModelsUser::class)->makePartial();
        $model->username = 'alice';
        $model->setDetails(array_filter(['template' => 'default', 'lsphp_settings' => $lsphpSettings]));
        $model->shouldReceive('getDomains')->andReturn($domains);
        $model->shouldReceive('getRedisConfig')->andReturn(['maxmemory' => '32mb']);
        $system = $this->system();

        return [new LiteSpeedStack($system, $model), new PhpHosting(new ProjectAggregate($system, $model))];
    }

    private function domain(string $name, ?string $phpVersion): DomainModel
    {
        $domain = new DomainModel();
        $domain->domain = $name;
        $domain->setDetails(array_filter(['document_root' => "/{$name}/public_html", 'php_version' => $phpVersion]));

        return $domain;
    }

    private function system(): System
    {
        $test = $this;

        return new class ($test) extends System {
            public function __construct(private readonly LiteSpeedStackServicesTest $test) {}

            public function projectDirPath(string $username): string
            {
                return sys_get_temp_dir().'/pa-ls-stack-absent/'.$username;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (is_array($cmd) && LocalRoot::action($cmd) === 'files.copy') {
                    $this->test->copied[] = (string) file_get_contents(LocalRoot::values($cmd)[0]);

                    return FakeProcess::ok();
                }
                if (is_array($cmd) && LocalRoot::action($cmd) === 'account-container.exec') {
                    $this->test->processes[] = $cmd;
                }

                return FakeProcess::forCommand($cmd);
            }
        };
    }
}
