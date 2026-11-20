<?php

namespace Tests\Integration\System\Project\PhpHosting;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmApacheStack;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * The Apache inside an nginx-proxy account's php container: its vhosts, modules, reloads and the wait for it to run.
 */
class FpmApacheStackApacheTest extends TestCase
{
    use InMemoryDatabase;

    private string $tmpRoot;

    /** @var list<string|array<int, string>> */
    public array $execs = [];

    /** @var list<string|array<int, string>> */
    public array $processes = [];

    public bool $apacheRunning = true;

    public bool $statusThrows = false;

    public bool $firewallFails = false;

    /** @var list<DomainModel> */
    private array $domains = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->tmpRoot = sys_get_temp_dir().'/pa-fpm-apache-'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot.'/templates', 0777, true);
        file_put_contents(
            $this->tmpRoot.'/templates/virtualHost-apache-user.blade.php',
            "{{ \$domain }} {{ \$relative_document_root }} {{ \$ssl_cert_file ?? 'no-ssl' }} {{ \$ssl_cert_key_file ?? '' }} {{ \$ssl_cert_ca_file ?? '' }}\n",
        );
        $this->setCurrentWebserver('nginx-proxy');
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        $this->removeTree($this->tmpRoot);
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_stack_renders_from_the_fpm_apache_templates(): void
    {
        $stack = $this->project()->fpmApache();

        $this->assertInstanceOf(FpmApacheStack::class, $stack);
        $this->assertSame('Dockerfile-fpm-apache', $stack->dockerfileTemplateName());
        $this->assertSame('docker-compose.yml-fpm-apache', $stack->composeTemplateName());
        $this->assertSame(['10-remoteip.sh' => 'a2enmod remoteip 2>/dev/null || true'], $stack->entrypointInitScripts($this->project()));
    }

    public function test_apache_runs_in_the_foreground_after_the_php_services(): void
    {
        $this->domains = [$this->domain('app.example.test', '8.3')];
        $project = $this->project();

        $services = $project->fpmApache()?->services($project) ?? [];

        $last = end($services);
        $this->assertNotFalse($last);
        $this->assertSame('apache2', $last->name);
        $this->assertStringEndsWith('exec apache2 -DFOREGROUND', $last->command);
        $this->assertSame(['20-apache.sh' => 'apache2ctl start'], $last->daemonStart);
        $this->assertGreaterThan(1, count($services));
    }

    public function test_waiting_returns_as_soon_as_apache_answers(): void
    {
        $this->project()->awaitReady(3, 0);

        $this->assertSame([$this->apacheStatus()], $this->execs);
    }

    public function test_waiting_gives_up_after_its_tries(): void
    {
        $this->apacheRunning = false;

        try {
            $this->project()->awaitReady(2, 0);
            $this->fail('the wait for a stopped Apache must give up');
        } catch (\Exception $e) {
            $this->assertSame('Waited too long for all services to start', $e->getMessage());
        }

        $this->assertSame([$this->apacheStatus(), $this->apacheStatus()], $this->execs);
    }

    public function test_a_negative_interval_is_refused(): void
    {
        $this->expectExceptionMessage('Invalid interval');

        $this->project()->awaitReady(1, -1);
    }

    public function test_a_status_that_fails_reads_as_apache_not_running(): void
    {
        $this->statusThrows = true;
        $project = $this->project();

        $this->assertFalse($project->fpmApache()?->isApacheRunning($project));
    }

    public function test_a_stopped_apache_is_not_reloaded(): void
    {
        $this->apacheRunning = false;

        $this->project()->reloadWebserver();

        $this->assertSame([$this->apacheStatus()], $this->execs);
    }

    public function test_reloading_apache_and_toggling_a_module_run_in_the_php_container(): void
    {
        $project = $this->project();

        $project->reloadApache();
        $project->enableApacheMod('rewrite');
        $project->disableApacheMod('headers');

        $this->assertSame([
            Privileged::accountContainer('alice')->exec(['service', 'apache2', 'reload']),
            Privileged::accountContainer('alice')->exec(['a2enmod', 'rewrite']),
            Privileged::accountContainer('alice')->exec(['a2dismod', 'headers']),
        ], $this->execs);
    }

    public function test_an_ssl_domain_gets_its_certificate_files_in_the_vhost(): void
    {
        $project = $this->project();

        $project->createDomainConfig($this->domain('shop.example.test', null, ssl: true));

        $this->assertSame(
            'shop.example.test /shop.example.test/public_html /etc/apache2/ssl-certs/shop.example.test.crt '
                ."/etc/apache2/ssl-certs/shop.example.test.key /etc/apache2/ssl-certs/shop.example.test.ca\n",
            file_get_contents($this->tmpRoot.'/users/alice/apache-sites/shop.example.test.conf'),
        );
    }

    public function test_every_domain_of_the_account_is_rebuilt(): void
    {
        $this->domains = [$this->domain('one.example.test'), $this->domain('two.example.test')];

        $this->project()->rebuildDomains();

        $this->assertSame(
            "one.example.test /one.example.test/public_html no-ssl  \n",
            file_get_contents($this->tmpRoot.'/users/alice/apache-sites/one.example.test.conf'),
        );
        $this->assertFileExists($this->tmpRoot.'/users/alice/apache-sites/two.example.test.conf');
        $reload = Privileged::accountContainer('alice')->exec(['service', 'apache2', 'reload']);
        $this->assertSame(2, count(array_keys($this->execs, $reload, true)));
    }

    public function test_the_domains_listed_are_the_conf_files_of_apache_sites(): void
    {
        $dir = $this->tmpRoot.'/users/alice/apache-sites';
        mkdir($dir.'/nested.conf.d', 0777, true);
        touch($dir.'/one.example.test.conf');
        touch($dir.'/two.example.test.conf');
        touch($dir.'/notes.txt');
        $project = $this->project();

        $listed = $project->fpmApache()?->listDomains($project) ?? [];
        sort($listed);

        $this->assertSame(['one.example.test', 'two.example.test'], $listed);
    }

    public function test_the_vhosts_are_removed_by_root_one_by_one_or_all_at_once(): void
    {
        $project = $this->project();
        $dir = $this->tmpRoot.'/users/alice/apache-sites';

        $project->deleteDomainConfig('one.example.test');
        $project->fpmApache()?->deleteDomainsConfigs($project, ['two.example.test', 'three.example.test']);
        $project->deleteAllDomainsConfigs();

        $this->assertSame([
            Privileged::files()->removeIfExists($dir.'/one.example.test.conf'),
            Privileged::files()->removeIfExists($dir.'/two.example.test.conf'),
            Privileged::files()->removeIfExists($dir.'/three.example.test.conf'),
        ], $this->execs);
        $this->assertSame([Privileged::fileLines()->emptyDirectory($dir)], $this->processes);
    }

    public function test_a_handler_restart_goes_through_the_fpm_runner(): void
    {
        $project = $this->project();

        $project->fpmApache()?->restartPhpHandler($project, '8.3');

        $this->assertCount(1, $this->processes);
        $this->assertIsArray($this->processes[0]);
        $this->assertSame('account-container.exec', LocalRoot::action($this->processes[0]));
        $this->assertStringContainsString('php-fpm8.3', (string) end($this->processes[0]));
    }

    public function test_the_entrypoint_init_scripts_run_in_the_php_container(): void
    {
        $this->project()->runEntrypointInitScripts();

        $this->assertSame([Privileged::accountContainer('alice')->exec([
            'bash', '-c', 'for f in /entrypoint-init.d/*.sh; do [ -f "$f" ] && bash "$f"; done; true',
        ])], $this->processes);
        $this->assertSame('php', $this->project()->defaultServiceName());
    }

    public function test_a_missing_compose_file_is_built_rather_than_pulled(): void
    {
        $project = $this->project();

        $project->buildIfMissing();

        $this->assertSame([Privileged::compose($project->composeFilePath())->build()], $this->execs);
    }

    public function test_a_firewall_that_cannot_be_applied_only_warns(): void
    {
        Log::spy();
        $this->firewallFails = true;
        $project = $this->project();

        $project->start();

        $this->assertContains(Privileged::compose($project->composeFilePath())->up(), $this->execs);
        Log::shouldHaveReceived('warning')->twice()->with('Tenant network firewall not applied for alice: no pash-tenants chain');
    }

    public function test_without_apache_a_domain_rebuild_does_nothing(): void
    {
        $this->setCurrentWebserver('nginx');
        $project = $this->project();

        $project->rebuildDomain($this->domain('one.example.test', '8.3'));

        $this->assertNull($project->fpmApache());
        $this->assertSame([], $this->execs);
        $this->assertSame([], $this->processes);
        $this->assertFileDoesNotExist($this->tmpRoot.'/users/alice/apache-sites/one.example.test.conf');
    }

    /** @return list<string> */
    private function apacheStatus(): array
    {
        return Privileged::accountContainer('alice')->exec(['service', 'apache2', 'status']);
    }

    private function project(): PhpHosting
    {
        $runtime = (new ProjectAggregate($this->system(), $this->userModel()))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $runtime);

        return $runtime;
    }

    private function userModel(): ModelsUser
    {
        $model = Mockery::mock(ModelsUser::class)->makePartial();
        $model->username = 'alice';
        $model->setDetails(['template' => 'default', 'UID' => 1000, 'GID' => 1000]);
        $model->shouldReceive('getDomains')->andReturn($this->domains);

        return $model;
    }

    private function domain(string $name, ?string $phpVersion = null, bool $ssl = false): DomainModel
    {
        $domain = new DomainModel();
        $domain->domain = $name;
        $domain->type = 'addon';
        $domain->setDetails(array_filter([
            'ssl_disabled' => ! $ssl,
            'document_root' => "/{$name}/public_html",
            'php_version' => $phpVersion,
        ], static fn (mixed $value): bool => $value !== null && $value !== false));

        return $domain;
    }

    private function system(): System
    {
        $test = $this;

        return new class ($test, $this->tmpRoot) extends System {
            public function __construct(
                private readonly FpmApacheStackApacheTest $test,
                private readonly string $root,
            ) {}

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function templatesDirPath(): string
            {
                return $this->root.'/templates';
            }

            public function homesDirPath(): string
            {
                return $this->root.'/home';
            }

            public function projectDirPath(string $username): string
            {
                return $this->root.'/users/'.$username;
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->root.'/home/'.$username;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if (is_array($cmd) && LocalRoot::action($cmd) === 'files.copy') {
                    [$source, $target] = LocalRoot::values($cmd);
                    @mkdir(dirname($target), 0777, true);
                    copy($source, $target);

                    return '';
                }
                if ($cmd === TenantNetwork::firewallArgv()) {
                    if ($this->test->firewallFails) {
                        throw new \RuntimeException("no pash-tenants chain\n");
                    }

                    return '';
                }
                if (is_array($cmd) && LocalRoot::action($cmd) !== 'account-container.exec' && ! in_array(LocalRoot::action($cmd), ['files.removeIfExists', 'compose.build', 'compose.up'], true)) {
                    return '';
                }
                $this->test->execs[] = $cmd;
                if ($cmd === Privileged::accountContainer('alice')->exec(['service', 'apache2', 'status'])) {
                    if ($this->test->statusThrows) {
                        throw new \RuntimeException('service: apache2: unrecognized service');
                    }

                    return $this->test->apacheRunning ? 'apache2 is running' : 'apache2 is not running';
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (is_array($cmd) && LocalRoot::action($cmd) !== 'account-container.exec') {
                    return FakeProcess::forCommand($cmd);
                }
                $this->test->processes[] = $cmd;

                return FakeProcess::ok();
            }
        };
    }

    private function setCurrentWebserver(?string $webserver): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, $webserver);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            $full = "{$path}/{$item}";
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
