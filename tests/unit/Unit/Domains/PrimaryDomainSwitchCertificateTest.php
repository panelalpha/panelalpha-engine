<?php

namespace Tests\Unit\Domains;

use App\Integrations\Statistics\Statistics;
use App\Lib\Domains\MainDomainRename;
use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\KeptCertificate;
use App\Models\Domain as DomainModel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Services\Webserver;
use App\System\Services\Webserver\WebserverInterface;
use ArrayObject;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;
use Tests\Unit\Integrations\Statistics\FakeStatistics;
use Tests\Unit\Ssl\TestCertificates;

/**
 * A primary-domain switch moves the main domain away and adds the old one as an addon again, as the panel does it with a
 * rename. A certificate installed on that domain survives both steps, and a failed re-add; a name added back after the
 * switch, or one with no certificate kept for it, gets the engine's own.
 */
class PrimaryDomainSwitchCertificateTest extends TestCase
{
    use InMemoryDatabase;

    private const ROOT = 'old.example.test';

    private string $root;

    private ArrayObject $calls;

    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->app->instance(Statistics::class, new FakeStatistics);

        $this->root = sys_get_temp_dir().'/pa-primary-cert-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/home/1000/'.self::ROOT.'/public_html', 0777, true);
        mkdir($this->root.'/home/1000/new.example.test/public_html', 0777, true);
        $this->calls = new ArrayObject();

        putenv('PA_ROOT_LOCAL_ROOTS='.json_encode(['engine' => $this->root, 'homes' => $this->root.'/home']));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_a_primary_domain_switch_keeps_the_installed_certificate(): void
    {
        $user = $this->project();
        $custom = $this->installCustomCertificate();

        MainDomainRename::apply($user, 'new.example.test');
        $user->save();

        $this->reAddAsAddon($user, self::ROOT);

        $this->assertSame(
            'Custom Test CA',
            $this->issuerOf(self::ROOT),
            'the domain switched away and back keeps the certificate installed on it'
        );
        $this->assertSame(trim($custom['key']), trim((string) file_get_contents($this->certFile(self::ROOT, 'key'))));
    }

    public function test_a_rename_keeps_the_certificate_material_of_the_name_it_leaves(): void
    {
        $user = $this->project();
        $custom = $this->installCustomCertificate();

        MainDomainRename::apply($user, 'new.example.test');

        foreach (['crt', 'key', 'ca', 'pem'] as $extension) {
            $this->assertFileExists($this->certFile(self::ROOT, $extension), "the old name keeps its {$extension}");
        }
        $this->assertSame(trim($custom['cert']), trim((string) file_get_contents($this->certFile(self::ROOT, 'crt'))));
        $this->assertSame('PanelAlpha', $this->issuerOf('new.example.test'), 'the new name gets a certificate of its own');
    }

    public function test_a_rebuilt_domain_keeps_the_installed_certificate(): void
    {
        $user = $this->project();
        $custom = $this->installCustomCertificate();
        $model = $user->getMainDomain();
        $model->addAlias('www.'.self::ROOT);
        $model->save();

        app(System::class)->project($user)->domain($model)->rebuild();

        $this->assertSame(
            trim($custom['cert']),
            trim((string) file_get_contents($this->certFile(self::ROOT, 'crt'))),
            'a rebuild for a www. alias keeps the certificate the domain has'
        );
    }

    public function test_a_failed_re_add_during_a_switch_keeps_the_installed_certificate(): void
    {
        $user = $this->project();
        $custom = $this->installCustomCertificate();

        MainDomainRename::apply($user, 'new.example.test');
        $user->save();
        $this->calls->append('fail-config '.self::ROOT);

        try {
            $this->reAddAsAddon($user, self::ROOT);
            $this->fail('The re-add was expected to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('config failed', $e->getMessage());
        }

        foreach (['crt', 'key'] as $extension) {
            $this->assertFileExists($this->certFile(self::ROOT, $extension), "the failed re-add keeps the {$extension}");
        }
        $this->assertSame(trim($custom['key']), trim((string) file_get_contents($this->certFile(self::ROOT, 'key'))));
    }

    public function test_a_name_added_back_after_the_switch_gets_a_certificate_of_its_own(): void
    {
        $user = $this->project();
        $this->installCustomCertificate();

        MainDomainRename::apply($user, 'new.example.test');
        $user->save();
        $this->travel(KeptCertificate::SECONDS + 1)->seconds();

        $this->reAddAsAddon($user, self::ROOT);

        $this->assertSame('PanelAlpha', $this->issuerOf(self::ROOT), 'the old custom certificate is not served again');
    }

    public function test_material_left_under_a_name_no_rename_kept_is_replaced(): void
    {
        $user = $this->project();
        $this->installCustomCertificate();

        $project = app(System::class)->project($user);
        $project->domain($this->domainModel($user, self::ROOT, 'addon'))->create();

        $this->assertSame('PanelAlpha', $this->issuerOf(self::ROOT));
        $this->assertNotSame([], $this->opensslCommands(), 'the engine signed a certificate of its own');
    }

    public function test_a_domain_with_no_certificate_gets_the_engines_own(): void
    {
        $user = $this->project();

        $project = app(System::class)->project($user);
        $project->domain($this->domainModel($user, self::ROOT, 'addon'))->create();

        $this->assertFileExists($this->certFile(self::ROOT, 'crt'));
        $this->assertSame('PanelAlpha', $this->issuerOf(self::ROOT));
        $this->assertNotSame([], $this->opensslCommands(), 'the engine signed the certificate itself');
    }

    private function project(): User
    {
        $user = $this->makeUser('alice', ['template' => 'default', 'UID' => 1000, 'GID' => 1000], self::ROOT);
        $this->makeMainDomain($user, self::ROOT);
        $this->bindSystem();

        return $user;
    }

    /** The third step of the panel's switch: the old main domain added as an addon again. */
    private function reAddAsAddon(User $user, string $domain): void
    {
        $model = $this->domainModel($user, $domain, 'addon');
        $model->save();

        app(System::class)->project($user)->domain($model)->create();
    }

    private function domainModel(User $user, string $domain, string $type): DomainModel
    {
        $model = DomainModel::make([
            'user_id' => $user->id,
            'domain' => $domain,
            'type' => $type,
            'details' => ['document_root' => "/{$domain}/public_html"],
        ]);

        return $model;
    }

    /** @return array{cert: string, key: string} */
    private function installCustomCertificate(): array
    {
        $pair = TestCertificates::signedPairBy([self::ROOT], TestCertificates::authority('Custom Test CA'));

        if (! is_dir(dirname($this->certFile(self::ROOT, 'crt')))) {
            mkdir(dirname($this->certFile(self::ROOT, 'crt')), 0777, true);
        }
        foreach (['crt', 'ca', 'pem'] as $extension) {
            file_put_contents($this->certFile(self::ROOT, $extension), trim($pair['cert']).PHP_EOL);
        }
        file_put_contents($this->certFile(self::ROOT, 'key'), trim($pair['key']).PHP_EOL);

        return $pair;
    }

    private function issuerOf(string $domain): ?string
    {
        $facts = CertificateFacts::fromPem((string) file_get_contents($this->certFile($domain, 'crt')));

        return $facts['issuer_name'] ?? null;
    }

    /** @return list<list<string>> */
    private function opensslCommands(): array
    {
        return array_values(array_filter(
            $this->commands,
            static fn (array $argv): bool => ($argv[0] ?? '') === 'openssl'
        ));
    }

    private function certFile(string $domain, string $extension): string
    {
        return $this->root.'/users/alice/ssl-certs/'.$domain.'.'.$extension;
    }

    private function bindSystem(): void
    {
        $test = $this;
        $calls = $this->calls;
        $host = $this->createStub(WebserverInterface::class);

        $system = new class($test, $calls, $host) extends System
        {
            public function __construct(
                private readonly PrimaryDomainSwitchCertificateTest $test,
                private readonly ArrayObject $calls,
                private readonly WebserverInterface $host,
            ) {}

            public function engineDirPath(): string
            {
                return $this->test->rootForTest();
            }

            public function homesDirPath(): string
            {
                return $this->test->rootForTest().'/home';
            }

            public function projectDirPath(string $username): string
            {
                return $this->test->rootForTest().'/users/'.$username;
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->test->rootForTest().'/home/1000';
            }

            public function webserver(): Webserver
            {
                $host = $this->host;

                return new class($this, $host) extends Webserver
                {
                    public function __construct(System $system, private readonly WebserverInterface $host)
                    {
                        parent::__construct($system);
                    }

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this->host;
                    }

                    public function getCurrentWebserver(): string
                    {
                        return 'nginx';
                    }
                };
            }

            public function project(User $model): Project
            {
                $calls = $this->calls;

                return new class($this, $model, $calls) extends Project
                {
                    public function __construct(System $system, User $user, private readonly ArrayObject $calls)
                    {
                        parent::__construct($system, $user);
                    }

                    public function createDomainConfig(DomainModel $domain): void
                    {
                        if (in_array('fail-config '.$domain->domain, $this->calls->getArrayCopy(), true)) {
                            throw new \RuntimeException('config failed');
                        }
                        $this->calls->append('create-config '.$domain->domain);
                    }

                    public function rebuildDomain(DomainModel $domain): void
                    {
                        $this->calls->append('rebuild '.$domain->domain);
                    }

                    public function deleteDomainConfig(string $domainName): void
                    {
                        $this->calls->append('delete-config '.$domainName);
                    }

                    public function reloadWebserver(): void
                    {
                        $this->calls->append('reload');
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (is_array($cmd) && ($cmd[0] ?? '') === 'test' && isset($cmd[2])) {
                    $exists = match ($cmd[1] ?? '') {
                        '-e' => file_exists($cmd[2]),
                        '-f' => is_file($cmd[2]),
                        '-d' => is_dir($cmd[2]),
                        default => false,
                    };

                    return $exists ? FakeProcess::ok() : FakeProcess::failed();
                }

                return FakeProcess::forCommand($cmd);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->test->runPlanned($cmd);
            }
        };

        $this->app->instance(System::class, $system);
    }

    public function rootForTest(): string
    {
        return $this->root;
    }

    /** Runs the command the root broker would have run, as the test user: the files land where a real host puts them. */
    public function runPlanned(string|array $cmd): string
    {
        $argv = is_array($cmd) ? LocalRoot::plain($cmd) : [];
        $this->commands[] = $argv === [] ? (is_array($cmd) ? $cmd : [$cmd]) : $argv;
        if ($argv === [] || ($argv[0] ?? '') === 'true') {
            return '';
        }

        $process = new Process($argv);
        $process->run();

        if (! $process->isSuccessful() && ! in_array(basename($argv[0]), ['chown', 'chgrp'], true)) {
            throw new \RuntimeException('planned command failed: '.$process->getErrorOutput());
        }

        return $process->getOutput();
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
