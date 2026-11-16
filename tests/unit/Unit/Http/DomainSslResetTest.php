<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Domain as DomainModel;
use App\Models\User;
use App\Lib\Ssl\CertificateFacts;
use App\System;
use App\System\Project;
use App\System\Services\Webserver;
use App\System\Services\Webserver\WebserverInterface;
use ArrayObject;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;
use Tests\Unit\Ssl\TestCertificates;

/**
 * `DELETE .../installed-ssl-cert`: the way back from a certificate somebody installed to the one this engine gives a
 * domain it has never certified, so nothing of the old one is served or renewed.
 */
class DomainSslResetTest extends TestCase
{
    use InMemoryDatabase;

    private const DOMAIN = 'shop.example.test';

    private const URL = '/api/projects/alice/domains/'.self::DOMAIN.'/installed-ssl-cert';

    private string $root;

    private ArrayObject $calls;

    private WebserverInterface $host;

    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);

        $this->root = sys_get_temp_dir().'/pa-ssl-reset-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/engine', 0777, true);
        $this->calls = new ArrayObject();
        $this->host = $this->createStub(WebserverInterface::class);
        $this->bindSystem();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_a_custom_certificate_is_replaced_by_the_one_a_new_domain_gets(): void
    {
        $user = $this->project();
        $custom = $this->installCustomCertificate();
        $this->assertSame(
            'Custom Test CA',
            CertificateFacts::fromPem((string) file_get_contents($this->certFile('crt')))['issuer_name'] ?? null,
            'the domain starts on the certificate somebody installed'
        );
        $challenge = $this->acmeToken(self::DOMAIN);

        $this->expectHostReload();

        $this->deleteJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.common_name', self::DOMAIN)
            ->assertJsonPath('data.issuer_name', 'PanelAlpha')
            ->assertJsonPath('data.issuer', 'PanelAlpha')
            ->assertJsonPath('data.status', 'self_signed')
            ->assertJsonPath('data.self_signed', true)
            ->assertJsonPath('data.covers_domain', true);

        $cert = (string) file_get_contents($this->certFile('crt'));
        $this->assertNotSame(trim($custom['cert']), trim($cert), 'the installed certificate is gone');
        $this->assertNotSame(trim($custom['key']), trim((string) file_get_contents($this->certFile('key'))));
        $this->assertStringNotContainsString(trim($custom['cert']), (string) file_get_contents($this->certFile('ca')));
        $this->assertStringNotContainsString(trim($custom['cert']), (string) file_get_contents($this->certFile('pem')));

        $this->assertFileDoesNotExist($challenge, 'the ACME challenge state of the old certificate is forgotten');
        $this->assertContains('project-reload', $this->calls->getArrayCopy());
        $this->assertNotEmpty(
            array_filter($this->commands, static fn (array $argv): bool => ($argv[0] ?? '') === 'openssl'),
            'the engine signed a certificate of its own'
        );
        $this->assertSame('self_signed', $user->fresh()?->getDetails()['ssl']['status'] ?? null, 'details.ssl follows');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function domainTypes(): array
    {
        return [
            'main domain' => ['main'],
            'addon domain' => ['addon'],
            'subdomain' => ['sub'],
        ];
    }

    #[DataProvider('domainTypes')]
    public function test_a_main_an_addon_and_a_subdomain_all_take_the_way_back(string $type): void
    {
        $this->project($type);
        $custom = $this->installCustomCertificate();
        $this->expectHostReload();

        $this->deleteJson(self::URL)->assertOk()->assertJsonPath('data.status', 'self_signed');

        $this->assertNotSame(trim($custom['cert']), trim((string) file_get_contents($this->certFile('crt'))));
    }

    public function test_a_second_call_answers_the_certificate_the_domain_carries(): void
    {
        $this->project();
        $this->installCustomCertificate();

        $this->deleteJson(self::URL)->assertOk()->assertJsonPath('data.status', 'self_signed');
        $first = (string) file_get_contents($this->certFile('crt'));
        $this->commands = [];
        $this->expectHostReload(0);

        $this->deleteJson(self::URL)->assertOk()->assertJsonPath('data.status', 'self_signed');

        $this->assertSame($first, (string) file_get_contents($this->certFile('crt')), 'the engine\'s own certificate stays');
        $this->assertSame([], $this->opensslCommands(), 'nothing is signed again');
    }

    public function test_a_certificate_issued_through_the_engine_is_not_issued_again(): void
    {
        $user = $this->project();
        $issued = TestCertificates::signedPairBy([self::DOMAIN], TestCertificates::authority('Issuing Test CA'));
        app(System::class)->project($user)->domain($user->domains()->firstOrFail())
            ->putIssuedCertificate($issued['cert'], $issued['key']);
        $this->commands = [];
        $this->expectHostReload(0);

        $this->deleteJson(self::URL)->assertOk()->assertJsonPath('data.issuer_name', 'Issuing Test CA');

        $this->assertSame(trim($issued['cert']), trim((string) file_get_contents($this->certFile('crt'))));
        $this->assertSame([], $this->calls->getArrayCopy(), 'no reload, no issuance');
    }

    public function test_a_certificate_installed_over_an_issued_one_is_replaced(): void
    {
        $user = $this->project();
        $issued = TestCertificates::signedPairBy([self::DOMAIN], TestCertificates::authority('Issuing Test CA'));
        $connection = app(System::class)->project($user)->domain($user->domains()->firstOrFail());
        $connection->putIssuedCertificate($issued['cert'], $issued['key']);
        $custom = TestCertificates::signedPairBy([self::DOMAIN], TestCertificates::authority('Custom Test CA'));
        $connection->installCertificate($custom['cert'], $custom['key']);
        $this->expectHostReload();

        $this->deleteJson(self::URL)->assertOk()->assertJsonPath('data.status', 'self_signed');

        $this->assertNotSame(trim($custom['cert']), trim((string) file_get_contents($this->certFile('crt'))));
    }

    public function test_a_domain_with_ssl_turned_off_is_refused(): void
    {
        $this->project(sslDisabled: true);
        $custom = $this->installCustomCertificate();
        $this->expectHostReload(0);

        $this->deleteJson(self::URL)
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'domain')
            ->assertJsonPath('problems.0.code', 'ssl_disabled');

        $this->assertSame(trim($custom['cert']), trim((string) file_get_contents($this->certFile('crt'))));
        $this->assertSame([], $this->calls->getArrayCopy());
    }

    public function test_an_unknown_domain_or_project_is_not_found(): void
    {
        $this->project();

        $this->deleteJson('/api/projects/alice/domains/missing.example.test/installed-ssl-cert')->assertNotFound();
        $this->deleteJson('/api/projects/nobody/domains/'.self::DOMAIN.'/installed-ssl-cert')->assertNotFound();
    }

    public function test_the_command_takes_the_same_way_back(): void
    {
        $this->project();
        $custom = $this->installCustomCertificate();

        $this->assertSame(0, Artisan::call('ssl:project-cert:remove', ['domain' => self::DOMAIN]));
        $this->assertStringContainsString(self::DOMAIN.' is on a certificate the engine made', Artisan::output());
        $this->assertNotSame(trim($custom['cert']), trim((string) file_get_contents($this->certFile('crt'))));
    }

    public function test_the_command_refuses_a_domain_with_ssl_turned_off(): void
    {
        $this->project(sslDisabled: true);

        $this->assertSame(1, Artisan::call('ssl:project-cert:remove', ['domain' => self::DOMAIN]));
        $this->assertStringContainsString('SSL turned off', Artisan::output());
    }

    /** @return list<list<string>> */
    private function opensslCommands(): array
    {
        return array_values(array_filter($this->commands, static fn (array $argv): bool => ($argv[0] ?? '') === 'openssl'));
    }

    /** The host webserver is only reloaded for a certificate that changed. */
    private function expectHostReload(int $times = 1): void
    {
        $this->host = $this->createMock(WebserverInterface::class);
        $this->host->expects($this->exactly($times))->method('reload');
        $this->bindSystem();
    }

    private function project(string $type = 'main', bool $sslDisabled = false): User
    {
        $user = $this->makeUser('alice', ['template' => 'dind'], self::DOMAIN);

        $details = ['document_root' => '/'.self::DOMAIN.'/public_html'];
        if ($sslDisabled) {
            $details['ssl_disabled'] = true;
        }
        if ($type === 'sub') {
            $details['parent_domain'] = 'example.test';
        }

        $domain = DomainModel::make([
            'user_id' => $user->id,
            'domain' => self::DOMAIN,
            'type' => $type,
            'details' => $details,
        ]);
        $domain->save();

        return $user;
    }

    /**
     * The four files `PUT .../install-ssl-cert` leaves behind, from an authority of the test's own.
     *
     * @return array{cert: string, key: string}
     */
    private function installCustomCertificate(): array
    {
        $pair = TestCertificates::signedPairBy([self::DOMAIN], TestCertificates::authority('Custom Test CA'));

        if (! is_dir(dirname($this->certFile('crt')))) {
            mkdir(dirname($this->certFile('crt')), 0777, true);
        }
        file_put_contents($this->certFile('crt'), trim($pair['cert']).PHP_EOL);
        file_put_contents($this->certFile('key'), trim($pair['key']).PHP_EOL);
        file_put_contents($this->certFile('ca'), trim($pair['cert']).PHP_EOL);
        file_put_contents($this->certFile('pem'), trim($pair['cert']).PHP_EOL);

        return $pair;
    }

    /** A challenge token a failed ACME issuance would leave, in the directory the vhost serves. */
    private function acmeToken(string $domain): string
    {
        $dir = $this->root.'/engine/data/webserver/acme-challenges/'.$domain;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/leftover-token', 'token.thumbprint');

        return $dir;
    }

    private function certFile(string $extension): string
    {
        return $this->root.'/users/alice/ssl-certs/'.self::DOMAIN.'.'.$extension;
    }

    private function bindSystem(): void
    {
        $test = $this;
        $calls = $this->calls;
        $host = $this->host;

        $system = new class($test, $calls, $host) extends System
        {
            public function __construct(
                private readonly DomainSslResetTest $test,
                private readonly ArrayObject $calls,
                private readonly WebserverInterface $host,
            ) {}

            public function engineDirPath(): string
            {
                return $this->test->rootForTest().'/engine';
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
                return $this->test->rootForTest().'/home/'.$username;
            }

            public function fileExists(string $path): bool
            {
                return is_file($path);
            }

            public function directoryExists(string $path): bool
            {
                return is_dir($path);
            }

            public function fileGetContents(string $path): string
            {
                return (string) file_get_contents($path);
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

                    public function reloadWebserver(): void
                    {
                        $this->calls->append('project-reload');
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

    /**
     * Runs the command the root broker would have run for the request, as the test user: the certificate files land
     * where the vhost loads them, without root and without a host.
     */
    public function runPlanned(string|array $cmd): string
    {
        $argv = is_array($cmd) ? LocalRoot::plain($cmd) : [];
        $this->commands[] = $argv === [] ? (is_array($cmd) ? $cmd : [$cmd]) : $argv;
        if ($argv === []) {
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
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
