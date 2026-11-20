<?php

namespace Tests\Integration\System\Project\PhpHosting;

use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Services\Webserver;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Re-rendering a domain's vhost changes nothing PHP reads, so the handler's workers are replaced gracefully instead of
 * a restart that cuts the requests in flight and waits for a master to come back.
 */
class FpmApacheDomainRebuildTest extends TestCase
{
    use InMemoryDatabase;

    private string $tmpRoot;

    /** @var list<array<int, string>> */
    private array $execs = [];

    /** @var list<array<int, string>> */
    private array $processes = [];

    private bool $handlerSwapFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->tmpRoot = sys_get_temp_dir().'/pa-fpm-domain-rebuild-'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot.'/templates', 0777, true);
        file_put_contents($this->tmpRoot.'/templates/virtualHost-apache-user.blade.php', "# {{ \$domain }}\n");
        $this->setCurrentWebserver('nginx-proxy');
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_rebuilding_a_domain_replaces_the_handlers_workers_and_restarts_nothing(): void
    {
        $project = $this->project();

        $project->rebuildDomain($this->domain());

        $handler = array_values(array_filter(
            $this->processes,
            static fn (array $cmd): bool => LocalRoot::action($cmd) === 'account-container.exec'
        ));

        $this->assertSame(
            [Privileged::accountContainer('alice')->exec(['bash', '-c', FpmStack::reloadScript('8.3')])],
            $handler,
            'the handler of the domain\'s PHP version has its workers replaced, not its master restarted'
        );
        $this->assertStringNotContainsString('s6-svc', $this->journalAsText());
    }

    public function test_a_handler_swap_that_fails_still_reloads_the_webserver_with_the_new_vhost(): void
    {
        $this->handlerSwapFails = true;

        $this->project()->rebuildDomain($this->domain());

        $this->assertContains(
            Privileged::accountContainer('alice')->exec(['service', 'apache2', 'reload']),
            $this->execs,
            'the vhost reaches Apache although the workers could not be swapped'
        );
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
        $model->setDetails([
            'template' => 'default',
            'UID' => 1000,
            'GID' => 1000,
        ]);

        return $model;
    }

    private function domain(): DomainModel
    {
        $domain = new DomainModel();
        $domain->domain = 'app.example.test';
        $domain->type = 'addon';
        $domain->setDetails([
            'ssl_disabled' => true,
            'document_root' => '/app.example.test/public_html',
            'php_version' => '8.3',
        ]);

        return $domain;
    }

    private function system(): System
    {
        $test = $this;

        return new class($test, $this->tmpRoot) extends System
        {
            public function __construct(
                private readonly FpmApacheDomainRebuildTest $test,
                private readonly string $root,
            ) {}

            public function engineDirPath(): string
            {
                return $this->root;
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
                if (is_array($cmd)) {
                    $this->test->recordExec($cmd);
                }

                return $cmd === Privileged::accountContainer('alice')->exec(['service', 'apache2', 'status'])
                    ? 'apache2 is running'
                    : '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (is_array($cmd) && ($cmd[0] ?? '') !== 'test') {
                    $this->test->recordProcess($cmd);
                }

                return $this->test->answer($cmd);
            }
        };
    }

    /** @param  array<int, string>  $cmd */
    public function recordExec(array $cmd): void
    {
        $this->execs[] = $cmd;
    }

    /** @param  array<int, string>  $cmd */
    public function recordProcess(array $cmd): void
    {
        $this->processes[] = $cmd;
    }

    /** @param string|array<int, string> $cmd */
    public function answer(string|array $cmd): Process
    {
        if ($this->handlerSwapFails && is_array($cmd) && LocalRoot::action($cmd) === 'account-container.exec') {
            return FakeProcess::failed('php-fpm8.3 could not signal its workers');
        }

        return FakeProcess::forCommand($cmd);
    }

    public function journalAsText(): string
    {
        return implode("\n", array_map(static fn (array $cmd): string => implode(' ', $cmd), [...$this->execs, ...$this->processes]));
    }

    private function setCurrentWebserver(?string $webserver): void
    {
        $property = (new \ReflectionClass(Webserver::class))->getProperty('currentWebserver');
        $property->setAccessible(true);
        $property->setValue(null, $webserver);
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
