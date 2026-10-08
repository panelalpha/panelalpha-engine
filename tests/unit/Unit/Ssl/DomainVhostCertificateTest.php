<?php

namespace Tests\Unit\Ssl;

use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use App\System\Services\Webserver\NginxProxy;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A domain vhost never names a cert file that is not on disk: nginx fails its
 * config test on one, and then no reload on the host applies.
 */
class DomainVhostCertificateTest extends TestCase
{
    private const DOMAIN = 'glowbear2-0e4b.panelalpha.online';
    private const USERNAME = 'glowbear2';

    private string $projectDir;
    private string $activeProjectDir;

    public function activeProjectDirForTest(): string
    {
        return $this->activeProjectDir;
    }

    private ?System $engine = null;

    /** @var list<string|array<string>> */
    public array $commands = [];
    public bool $opensslFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/pa-vhost-ssl-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir, 0777, true);
        $this->activeProjectDir = $this->projectDir;
        $this->engine = null;
        $this->commands = [];
        $this->opensslFails = false;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->projectDir);
        parent::tearDown();
    }

    public function test_a_domain_with_no_cert_yet_is_self_signed_before_the_vhost_names_it(): void
    {
        $certDir = $this->certDir();
        $vars = $this->nginx()->sslTemplateVars($this->connection());
        $pem = str_replace('\\', '/', "{$certDir}/" . self::DOMAIN . '.pem');
        $key = str_replace('\\', '/', "{$certDir}/" . self::DOMAIN . '.key');

        $this->assertTrue($vars['ssl_enabled']);
        $this->assertSame($pem, str_replace('\\', '/', $vars['ssl_cert_pem_file']));
        $this->assertSame($key, str_replace('\\', '/', $vars['ssl_cert_key_file']));
        $this->assertFileExists($vars['ssl_cert_pem_file']);
        $this->assertFileExists($vars['ssl_cert_key_file']);
        $this->assertSame(1, $this->opensslRuns());
    }

    public function test_self_signing_is_idempotent(): void
    {
        $connection = $this->connection();

        $this->assertTrue($connection->ensureServableCertificate());
        $this->assertTrue($connection->ensureServableCertificate());
        $this->assertSame(1, $this->opensslRuns());
    }

    public function test_an_existing_cert_is_used_as_it_is(): void
    {
        $certDir = $this->certDir();
        $this->touchCert("{$certDir}/" . self::DOMAIN . '.pem');
        $this->touchCert("{$certDir}/" . self::DOMAIN . '.key');

        $vars = $this->nginx()->sslTemplateVars($this->connection());

        $this->assertSame([
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => str_replace('\\', '/', $vars['ssl_cert_pem_file']),
            'ssl_cert_key_file' => str_replace('\\', '/', $vars['ssl_cert_key_file']),
        ], [
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => "{$certDir}/" . self::DOMAIN . '.pem',
            'ssl_cert_key_file' => "{$certDir}/" . self::DOMAIN . '.key',
        ]);
        $this->assertSame([], $this->commands);
        $this->assertStringContainsString(
            'ssl_certificate ' . $vars['ssl_cert_pem_file'] . ';',
            $this->render($vars)
        );
    }

    public function test_a_crt_without_its_pem_does_not_count(): void
    {
        $certDir = $this->certDir();
        $this->touchCert("{$certDir}/" . self::DOMAIN . '.crt');
        $this->touchCert("{$certDir}/" . self::DOMAIN . '.key');

        $this->assertFalse($this->connection()->hasServableCertificate());
    }

    public function test_a_cert_that_cannot_be_made_leaves_no_tls_block(): void
    {
        $this->opensslFails = true;

        $vars = $this->nginx()->sslTemplateVars($this->connection());

        $this->assertSame([], $vars);
        $vhost = $this->render($vars);
        $this->assertStringNotContainsString('ssl_certificate', $vhost);
        $this->assertStringNotContainsString('443', $vhost);
    }

    public function test_a_deleted_project_gets_no_tls_block_and_no_new_cert(): void
    {
        $vars = $this->nginx()->sslTemplateVars($this->connection(projectDirExists: false));

        $this->assertSame([], $vars);
        $this->assertSame(0, $this->opensslRuns());
        $this->assertStringNotContainsString('ssl_certificate', $this->render($vars));
    }

    public function test_a_domain_with_ssl_off_never_touches_certs(): void
    {
        $vars = $this->nginx()->sslTemplateVars($this->connection(sslDisabled: true));

        $this->assertSame([], $vars);
        $this->assertSame([], $this->commands);
    }

    private function certDir(): string
    {
        return str_replace('\\', '/', $this->activeProjectDir) . '/ssl-certs';
    }

    public function touchCert(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        touch($path);
    }

    private function nginx(): NginxProxy
    {
        return new NginxProxy($this->system());
    }

    private function connection(bool $projectDirExists = true, bool $sslDisabled = false): ProjectDomain
    {
        $this->activeProjectDir = $projectDirExists
            ? $this->projectDir
            : $this->projectDir . '-missing';

        $user = new ModelsUser();
        $user->username = self::USERNAME;

        $model = new DomainModel();
        $model->forceFill(['domain' => self::DOMAIN, 'details' => ['ssl_disabled' => $sslDisabled]]);

        $project = new Project($this->system(), $user);

        return new ProjectDomain($project, $model);
    }

    /**
     * Just enough filesystem for `test -e`, openssl and cp.
     */
    private function system(): System
    {
        if ($this->engine !== null) {
            return $this->engine;
        }

        $test = $this;

        return $this->engine = new class ($test) extends System {
            public function __construct(private DomainVhostCertificateTest $test)
            {
            }

            public function projectDirPath(string $username): string
            {
                return $this->test->activeProjectDirForTest();
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $exists = is_array($cmd)
                    && ($cmd[0] ?? '') === 'test'
                    && ($cmd[1] ?? '') === '-e'
                    && isset($cmd[2])
                    && file_exists($cmd[2]);

                return $this->test->processWithTestExitCode($exists ? 0 : 1);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->commands[] = $cmd;
                if (is_array($cmd) && in_array('openssl', $cmd, true)) {
                    if ($this->test->opensslFails) {
                        throw new \RuntimeException('openssl: command not found');
                    }
                    $keyout = $cmd[array_search('-keyout', $cmd, true) + 1];
                    $out = $cmd[array_search('-out', $cmd, true) + 1];
                    $this->test->touchCert($keyout);
                    $this->test->touchCert($out);
                    if (str_ends_with($out, '.crt')) {
                        $this->test->touchCert(substr($out, 0, -4) . '.pem');
                        $this->test->touchCert(substr($out, 0, -4) . '.ca');
                    }
                } elseif (is_string($cmd) && str_starts_with($cmd, 'sudo cp ')) {
                    $parts = explode(' ', $cmd);
                    $source = $parts[2] ?? '';
                    $target = $parts[3] ?? '';
                    if ($source !== '' && $target !== '' && file_exists($source)) {
                        $this->test->touchCert($target);
                    }
                } elseif (is_string($cmd) && str_starts_with($cmd, 'sudo mkdir -p ')) {
                    $dir = substr($cmd, strlen('sudo mkdir -p '));
                    if (!is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }
                }

                return '';
            }
        };
    }

    private function opensslRuns(): int
    {
        return count(array_filter(
            $this->commands,
            static fn ($cmd): bool => is_array($cmd) && in_array('openssl', $cmd, true)
        ));
    }

    public function processWithTestExitCode(int $exitCode): Process
    {
        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn($exitCode);

        return $process;
    }

    /**
     * @param array<string, mixed> $sslVars
     */
    private function render(array $sslVars): string
    {
        // A checkout keeps templates/ beside core/; the installed engine mounts core alone.
        $path = dirname(__DIR__, 4) . '/templates/dind/virtualHost-nginx-proxy.blade.php';
        if (!is_file($path)) {
            $this->markTestSkipped("No vhost template at {$path}.");
        }
        $template = (string) file_get_contents($path);

        return Blade::render($template, [
            'user' => self::USERNAME,
            'domain' => self::DOMAIN,
            'aliases' => [],
            'ips_v4' => [],
            'ips_v6' => [],
            'relative_document_root' => '/' . self::DOMAIN . '/public_html',
            'proxy_http' => ['host' => self::USERNAME, 'port' => 8080, 'protocol' => 'http'],
            'proxy_https' => ['host' => self::USERNAME, 'port' => 8080, 'protocol' => 'http'],
            'proxy_extra' => [],
            ...$sslVars,
        ]);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
