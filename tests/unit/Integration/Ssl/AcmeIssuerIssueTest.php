<?php

namespace Tests\Integration\Ssl;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\HttpAcmeChallengeStore;
use App\Lib\Ssl\AcmeIssuer;
use App\Lib\Ssl\IssuableDomain;
use App\Models\Domain;
use App\System;
use App\System\Filesystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** A whole HTTP-01 issuance against a throwaway ACME authority served by `php -S` on loopback. */
class AcmeIssuerIssueTest extends TestCase
{
    private const NAME = 'shop.acme.com';

    private const CHALLENGES = '/engine/data/webserver/acme-challenges/'.self::NAME;

    private const ROUTER = <<<'PHP'
        <?php
        $base = 'http://'.$_SERVER['HTTP_HOST'];
        $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $validated = __DIR__.'/validated';
        file_put_contents(__DIR__.'/requests', $_SERVER['REQUEST_METHOD'].' '.$path."\n", FILE_APPEND);
        header('Replay-Nonce: n'.bin2hex(random_bytes(6)));
        $json = static function (array $body, int $code = 200, ?string $location = null): void {
            http_response_code($code);
            header('Content-Type: application/json');
            if ($location !== null) {
                header('Location: '.$location);
            }
            echo json_encode($body, JSON_UNESCAPED_SLASHES);
        };
        $order = ['status' => 'ready', 'authorizations' => [$base.'/authz/1'], 'finalize' => $base.'/finalize'];
        match ($path) {
            '/directory' => $json(['newNonce' => $base.'/nonce', 'newAccount' => $base.'/account', 'newOrder' => $base.'/order']),
            '/nonce' => http_response_code(200),
            '/account' => $json(['status' => 'valid'], 201, $base.'/account/1'),
            '/order' => $json(['status' => 'pending'] + $order, 201, $base.'/order/1'),
            '/authz/1' => $json([
                'identifier' => ['type' => 'dns', 'value' => trim((string) file_get_contents(__DIR__.'/name'))],
                'status' => file_exists($validated) ? 'valid' : 'pending',
                'challenges' => [['type' => 'http-01', 'url' => $base.'/challenge/1', 'token' => 'tok_1']],
            ]),
            '/challenge/1' => (static function () use ($json, $validated): void {
                touch($validated);
                $json(['status' => 'processing']);
            })(),
            '/order/1' => $json($order),
            '/finalize' => $json(['status' => 'valid', 'certificate' => $base.'/certificate']),
            '/certificate' => (static function (): void {
                header('Content-Type: application/pem-certificate-chain');
                echo file_get_contents(__DIR__.'/chain.pem');
            })(),
            default => http_response_code(404),
        };
        PHP;

    /** @var resource|null */
    private $server = null;

    private string $dir = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function test_the_certificate_is_installed_with_its_chain_and_the_challenge_cleaned_up(): void
    {
        $root = TestCertificates::authority('Fake ACME Root');
        $intermediate = TestCertificates::intermediate($root, 'Fake ACME Intermediate');
        $leaf = TestCertificates::signedBy([self::NAME], $intermediate);
        $directory = $this->authority($leaf."\n".$intermediate['cert']);
        $host = self::host();
        Log::spy();

        $installed = [];
        $domain = $this->domain($installed);
        $issuer = new AcmeIssuer($directory, 'certs@example.com', new HttpAcmeChallengeStore($host), $host);

        $issuer->issue($domain);

        $this->assertSame(trim($leaf), $installed['cert']);
        $this->assertSame(trim($intermediate['cert']), $installed['ca']);
        $this->assertNotFalse(openssl_pkey_get_private($installed['key']));
        $this->assertSame('prime256v1', openssl_pkey_get_details(openssl_pkey_get_private($installed['key']))['ec']['curve_name']);
        $this->assertSame(3, $installed['published']);
        $this->assertMatchesRegularExpression('/^tok_1\.[A-Za-z0-9_-]{43}$/', $host->written[self::CHALLENGES.'/tok_1']);
        $this->assertSame(['/engine/crt/acme-account.key'], array_keys($host->files));
        $this->assertContains('POST /challenge/1', $this->requests());
        Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_starts_with($message, '[acme] '));
    }

    public function test_an_authority_that_returns_no_certificate_installs_nothing(): void
    {
        $directory = $this->authority('');
        $host = self::host();
        $installed = [];
        $domain = $this->domain($installed);
        $issuer = new AcmeIssuer($directory, null, new HttpAcmeChallengeStore($host), $host);

        try {
            $issuer->issue($domain);
            $this->fail('an empty chain must not be installed');
        } catch (RuntimeException $e) {
            $this->assertSame('The authority returned no certificate for '.self::NAME, $e->getMessage());
        }

        $this->assertArrayNotHasKey('cert', $installed);
        $this->assertSame(2, $installed['published']);
        $this->assertArrayNotHasKey(self::CHALLENGES.'/tok_1', $host->files);
    }

    /**
     * @param  array<string, mixed>  $installed
     */
    private function domain(array &$installed): IssuableDomain
    {
        $installed['published'] = 0;
        $domain = $this->createMock(IssuableDomain::class);
        $domain->method('model')->willReturn(new Domain(['domain' => self::NAME]));
        $domain->method('putIssuedCertificate')->willReturnCallback(
            function (string $cert, string $key, string $ca = '') use (&$installed): void {
                $installed += ['cert' => $cert, 'key' => $key, 'ca' => $ca];
            }
        );
        $domain->method('publishCertificateToHostWebserver')->willReturnCallback(
            function () use (&$installed): void {
                $installed['published']++;
            }
        );
        $domain->expects($this->never())->method('generateSelfSignedCertificate');

        return $domain;
    }

    /** Starts the fake authority serving $chain and answers its directory URL. */
    private function authority(string $chain): string
    {
        $this->dir = sys_get_temp_dir().'/pa-acme-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/router.php', self::ROUTER);
        file_put_contents($this->dir.'/name', self::NAME);
        file_put_contents($this->dir.'/chain.pem', $chain);

        $port = $this->freePort();
        $this->server = proc_open(
            [PHP_BINARY, '-S', TestNetwork::LOOPBACK.":{$port}", $this->dir.'/router.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->waitForListener($port);

        return 'http://'.TestNetwork::LOOPBACK.":{$port}/directory";
    }

    /** @return list<string> */
    private function requests(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->dir.'/requests'))));
    }

    /** A host whose files live in memory: the challenge store and the account key both write there. */
    private static function host(): System
    {
        return new class extends System
        {
            /** @var array<string, string> */
            public array $files = [];

            /** @var array<string, string> */
            public array $written = [];

            public function engineDirPath(): string
            {
                return '/engine';
            }

            public function directoryExists(string $path): bool
            {
                foreach (array_keys($this->files) as $file) {
                    if (str_starts_with($file, $path.'/')) {
                        return true;
                    }
                }

                return false;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (is_array($cmd) && LocalRoot::action($cmd) === 'files.listAllNames') {
                    $dir = LocalRoot::values($cmd)[0];
                    $names = array_map('basename', array_filter(array_keys($this->files), fn (string $file): bool => dirname($file) === $dir));

                    return FakeProcess::ok(implode("\n", $names));
                }

                return FakeProcess::ok();
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                foreach (is_array($cmd) ? LocalRoot::values($cmd) : [] as $path) {
                    foreach (array_keys($this->files) as $file) {
                        if ($file === $path || str_starts_with($file, $path.'/')) {
                            unset($this->files[$file]);
                        }
                    }
                }

                return '';
            }

            public function filesystem(): Filesystem
            {
                $host = $this;

                return new class($host) extends Filesystem
                {
                    public function __construct(private System $host)
                    {
                        parent::__construct($host);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->host->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->host->files[$path];
                    }

                    public function makeDirWithParents(string $target, ?string $chown = null): void {}

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->host->files[$path] = $contents;
                        $this->host->written[$path] = $contents;
                    }
                };
            }
        };
    }

    private function waitForListener(int $port): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen(TestNetwork::LOOPBACK, $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }

        $this->fail("php -S did not start listening on {$port}");
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://'.TestNetwork::LOOPBACK.':0');
        $this->assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
