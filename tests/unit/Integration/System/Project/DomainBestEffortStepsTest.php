<?php

namespace Tests\Integration\System\Project;

use App\Integrations\Statistics\Statistics;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\Domain as DomainModel;
use App\Models\ProxyRule;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Services\Webserver;
use App\System\Services\Webserver\WebserverInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Integration\Integrations\Statistics\FakeStatistics;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A domain's best-effort steps when what they clean up or configure refuses, its route to a container app, and
 * the certificate files it finds under another spelling of its name.
 */
class DomainBestEffortStepsTest extends TestCase
{
    use InMemoryDatabase;

    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->tmpRoot !== '') {
            $this->removeTree($this->tmpRoot);
        }
        parent::tearDown();
    }

    public function test_a_delete_goes_on_when_its_tunnel_rules_and_statistics_cannot_be_cleaned_up(): void
    {
        Log::spy();
        $statistics = new FakeStatistics();
        $statistics->failures['app.example.test'] = new \RuntimeException('awstats is locked');
        $this->app->instance(Statistics::class, $statistics);
        [$project, $domainModel, $this->tmpRoot, $driver] = $this->dindFixture();
        $driver->expects($this->once())->method('deleteDomainConfig')->with('app.example.test');
        Schema::drop('tunnels');
        Schema::drop('proxy_rules');

        $project->domain($domainModel)->delete();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_starts_with($m, 'Cloudflare teardown during domain delete failed for app.example.test: '));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_starts_with($m, 'ProxyRule cleanup during domain delete failed for app.example.test: '));
        Log::shouldHaveReceived('warning')->with('Statistics cleanup during domain delete failed for app.example.test: awstats is locked');
    }

    public function test_a_create_goes_on_when_statistics_cannot_be_configured(): void
    {
        Log::spy();
        $statistics = $this->createStub(Statistics::class);
        $statistics->method('configureDomain')->willThrowException(new \RuntimeException('awstats config is read-only'));
        $this->app->instance(Statistics::class, $statistics);
        [$project, $domainModel, $this->tmpRoot, $driver] = $this->dindFixture();
        $driver->expects($this->once())->method('addDomain')->with($domainModel);

        $project->domain($domainModel)->create();

        Log::shouldHaveReceived('warning')->with('Statistics configure failed for app.example.test: awstats config is read-only');
    }

    public function test_a_new_domain_of_a_container_app_is_routed_to_the_apps_port(): void
    {
        $this->app->instance(Statistics::class, new FakeStatistics());
        [$project, $domainModel, $this->tmpRoot, $driver] = $this->dindFixture();
        $driver->expects($this->once())->method('addDomain')->with($domainModel);
        $this->makeUser('alice');
        ProxyRule::ensureGeneratedHttpPair('alice', 'alice.example.test', 3000, 'https');

        $project->domain($domainModel)->create();

        $rules = ProxyRule::forUser('alice')->where('server_name', 'app.example.test')->orderBy('listen_port')->get();
        $this->assertSame([80, 443], $rules->pluck('listen_port')->map(fn ($p): int => (int) $p)->all());
        $this->assertSame([3000, 3000], $rules->pluck('upstream_port')->map(fn ($p): int => (int) $p)->all());
        $this->assertSame(['https', 'https'], $rules->pluck('upstream_protocol')->all());
    }

    public function test_publishing_a_certificate_rebuilds_and_reloads_the_host_webserver(): void
    {
        [$project, $domainModel, $this->tmpRoot, $driver] = $this->dindFixture();
        $driver->expects($this->once())->method('rebuildDomainConfig')->with($domainModel);
        $driver->expects($this->once())->method('reload');

        $project->domain($domainModel)->publishCertificateToHostWebserver();
    }

    public function test_certificate_files_under_another_casing_are_renamed_to_what_the_vhost_loads(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/pa-domain-case-' . bin2hex(random_bytes(4));
        $certDir = $this->tmpRoot . '/users/alice/ssl-certs';
        mkdir($certDir, 0777, true);
        file_put_contents($certDir . '/ssl.example.test.crt', 'crt');
        file_put_contents($certDir . '/SSL.Example.test.pem', 'pem');
        file_put_contents($certDir . '/SSL.Example.test.key', 'key');

        $this->assertTrue($this->hostDomain('ssl.example.test')->ensureServableCertificate());

        $this->assertSame('pem', file_get_contents($certDir . '/ssl.example.test.pem'));
        $this->assertSame('key', file_get_contents($certDir . '/ssl.example.test.key'));
        $this->assertSame('crt', file_get_contents($certDir . '/ssl.example.test.crt'));
        $this->assertFileDoesNotExist($certDir . '/SSL.Example.test.pem');
    }

    public function test_a_name_without_a_certificate_carries_none_of_the_engines(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/pa-domain-none-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/users/alice/ssl-certs', 0777, true);

        $this->assertFalse($this->hostDomain('none.example.test')->carriesEngineCertificate());
    }

    public function test_a_certificate_that_does_not_parse_reads_as_unknown(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/pa-domain-garbage-' . bin2hex(random_bytes(4));
        $certDir = $this->tmpRoot . '/users/alice/ssl-certs';
        mkdir($certDir, 0777, true);
        file_put_contents($certDir . '/bad.example.test.crt', "not a certificate\n");
        file_put_contents($certDir . '/bad.example.test.ca', '');

        $info = $this->hostDomain('bad.example.test')->getSslCertificateInfo();

        $this->assertSame("not a certificate\n", $info['certificate']);
        $this->assertSame('Unknown', $info['issuer_name']);
        $this->assertSame('', $info['common_name']);
        $this->assertSame([], $info['domains']);
    }

    private function hostDomain(string $name): \App\System\Project\Domain
    {
        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => $name]);

        return (new Project($this->systemWithDirectHostOps($this->tmpRoot), $user))->domain($domainModel);
    }

    /**
     * @return array{0: Project, 1: DomainModel, 2: string, 3: WebserverInterface}
     */
    private function dindFixture(): array
    {
        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $user->method('getUid')->willReturn(1000);
        $user->method('getGid')->willReturn(1000);
        $user->method('linuxIds')->willReturn([1000, 1000]);
        $user->method('hasGitProject')->willReturn(false);
        $user->method('getTemplate')->willReturn('dind');

        $domainModel = new DomainModel();
        $domainModel->domain = 'app.example.test';
        $domainModel->details = [
            'ssl_disabled' => true,
            'document_root' => '/public_html',
            'aliases' => ['www.app.example.test'],
        ];

        $driver = $this->createMock(WebserverInterface::class);
        $driver->method('addDomain');
        $driver->method('reload');
        $driver->method('deleteDomainLogsDir');

        $tmpRoot = sys_get_temp_dir() . '/pa-domain-stats-' . bin2hex(random_bytes(4));
        $home = $tmpRoot . '/home/alice';
        mkdir($home . '/public_html', 0777, true);

        $system = new class ($tmpRoot, $home, $driver) extends System {
            public function __construct(
                private string $engineRoot,
                private string $aliceHome,
                private WebserverInterface $driver,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return dirname($this->aliceHome);
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->aliceHome;
            }

            public function webserver(): Webserver
            {
                $driver = $this->driver;

                return new class ($driver) extends Webserver {
                    public function __construct(private WebserverInterface $driver)
                    {
                    }

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this->driver;
                    }

                    public function getCurrentWebserver(): string
                    {
                        return 'nginx-proxy';
                    }
                };
            }

            /** No process starts here: a streamed command fails as it would on a host that cannot run it. */
            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return FakeProcess::failed('no process is started in this test');
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private object $outer)
                    {
                    }

                    public function isDir(string $target): bool
                    {
                        return is_dir($target);
                    }

                    public function directoryExists(string $path): bool
                    {
                        return is_dir($path);
                    }

                    public function makeDirFromTemplate(
                        string $dir,
                        string $templateDir,
                        array $templateVars,
                        ?string $chown = null,
                        ?string $chmod = null,
                        array $exclude = [],
                    ): void {
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                    }
                };
            }
        };

        return [new Project($system, $user), $domainModel, $tmpRoot, $driver];
    }

    private function systemWithDirectHostOps(string $engineRoot): System
    {
        return new class ($engineRoot) extends System {
            public function __construct(private string $engineRoot)
            {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function webserver(): Webserver
            {
                return new class ($this) extends Webserver {
                    public function getCurrentWebserver(): string
                    {
                        return 'nginx-proxy';
                    }
                };
            }

            /** No process starts here: a streamed command fails as it would on a host that cannot run it. */
            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return FakeProcess::failed('no process is started in this test');
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->applyHostCommand($cmd);

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                $argv = LocalRoot::plain(is_array($cmd) ? $cmd : (preg_split('/\s+/', $cmd) ?: []));
                $exit = 1;
                $output = '';

                if (($argv[0] ?? '') === 'test' && ($argv[1] ?? '') === '-e') {
                    $exit = file_exists((string) ($argv[2] ?? '')) ? 0 : 1;
                } elseif (($argv[0] ?? '') === 'find') {
                    $dir = (string) ($argv[1] ?? '');
                    $name = (string) ($argv[5] ?? '');
                    $found = [];
                    if (is_dir($dir)) {
                        foreach (scandir($dir) ?: [] as $file) {
                            if (strcasecmp($file, $name) === 0) {
                                $found[] = $dir . '/' . $file;
                            }
                        }
                    }
                    $output = implode("\n", $found);
                    $exit = 0;
                } else {
                    try {
                        $this->applyHostCommand($cmd);
                        $exit = 0;
                    } catch (\Throwable) {
                        $exit = 1;
                    }
                }

                $process = new class ($output, $exit) extends \Symfony\Component\Process\Process {
                    public function __construct(
                        private string $stubOutput,
                        private int $stubExit,
                    ) {
                        parent::__construct(['true']);
                    }

                    public function getOutput(): string
                    {
                        return $this->stubOutput;
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->stubExit;
                    }
                };

                return $process;
            }

            private function applyHostCommand(string|array $cmd): void
            {
                $argv = LocalRoot::plain(is_array($cmd) ? $cmd : (preg_split('/\s+/', trim($cmd)) ?: []));
                $argv = array_values(array_filter($argv, static fn (string $word): bool => $word !== '--'));
                $op = $argv[0] ?? '';
                if ($op === 'mkdir' && ($argv[1] ?? '') === '-p') {
                    if (!is_dir((string) $argv[2]) && !mkdir((string) $argv[2], 0777, true) && !is_dir((string) $argv[2])) {
                        throw new \RuntimeException('mkdir failed: ' . $argv[2]);
                    }

                    return;
                }
                if ($op === 'cp') {
                    $dest = (string) $argv[count($argv) - 1];
                    $dir = dirname($dest);
                    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                        throw new \RuntimeException('mkdir failed: ' . $dir);
                    }
                    if (!copy((string) $argv[1], $dest)) {
                        throw new \RuntimeException('copy failed: ' . $argv[1] . ' -> ' . $dest);
                    }

                    return;
                }
                if ($op === 'chmod' || $op === 'chown') {
                    return;
                }
                if ($op === 'mv') {
                    rename((string) $argv[1], (string) $argv[2]);

                    return;
                }
                if ($op === 'rm') {
                    $path = (string) $argv[count($argv) - 1];
                    if (is_file($path)) {
                        unlink($path);
                    }

                    return;
                }
                if ($op === 'openssl') {
                    $keyOut = $argv[array_search('-keyout', $argv, true) + 1];
                    $certOut = $argv[array_search('-out', $argv, true) + 1];
                    file_put_contents($keyOut, "-----BEGIN KEY-----\n");
                    file_put_contents($certOut, "-----BEGIN CERT-----\n");
                }
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
