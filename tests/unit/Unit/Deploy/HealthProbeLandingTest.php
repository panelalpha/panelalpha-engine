<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\ProbedResponse;
use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * The probe reports the path that answered, and follows a path-relative
 * Location to get there. Runs the generated script against a real
 * server, as HealthProbeRedirectTest does.
 */
class HealthProbeLandingTest extends TestCase
{
    /** @var list<array{0: resource, 1: string}> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as [$process, $dir]) {
            proc_terminate($process);
            proc_close($process);
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
        $this->servers = [];
        parent::tearDown();
    }

    private function serve(string $router, array $files = []): int
    {
        $dir = sys_get_temp_dir() . '/pa-landing-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/router.php', $router);
        foreach ($files as $name => $contents) {
            file_put_contents($dir . '/' . $name, $contents);
        }

        $port = random_int(22000, 22999);
        $process = proc_open(
            sprintf('exec php -S 127.0.0.1:%d %s/router.php', $port, $dir),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->markTestSkipped('could not start a PHP built-in server');
        }
        $this->servers[] = [$process, $dir];

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return $port;
            }
            usleep(100_000);
        }

        $this->markTestSkipped('the built-in server did not come up');
    }

    /** @return array<string, mixed> the parsed result for that port */
    private function probe(int $port, ?string $domain = null): array
    {
        $script = tempnam(sys_get_temp_dir(), 'pa-landing') . '.sh';
        file_put_contents($script, AppHealth::probeScript([$port], 5, 1, 0, $domain));
        exec('bash ' . escapeshellarg($script) . ' 2>/dev/null', $lines);
        unlink($script);

        return AppHealth::parseProbeOutput(implode("\n", $lines), [$port])[0];
    }

    /**
     * Dolibarr 24.0.0 with no conf.php, replayed: `/` answers
     * `Location: install/index.php` -- no leading slash -- and the page
     * behind it is the captured wizard.
     */
    public function test_dolibarrs_path_relative_redirect_lands_on_its_installer(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/') { header('Location: install/index.php', true, 302); exit; }
            if ($path === '/install/index.php') { readfile(__DIR__ . '/install.html'); exit; }
            http_response_code(404);
            PHP, ['install.html' => file_get_contents(__DIR__ . '/../../fixtures/installers/dolibarr-install.html')]);

        $result = $this->probe($port);

        $this->assertSame(200, $result['http_code']);
        $this->assertSame('/install/index.php', $result['path']);

        $verdict = CheckRunner::for(null)->run(
            new ProbedResponse($result['http_code'], $result['body'], '', (float) $result['time'], $result['path']),
            null
        );
        $this->assertSame('unclaimed_install', $verdict['serving']);
    }

    /** Resolved against the directory of the current path, query dropped. */
    public function test_a_path_relative_redirect_resolves_against_the_current_directory(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/') { header('Location: /app/start.php?x=1', true, 302); exit; }
            if ($path === '/app/start.php') { header('Location: setup/', true, 302); exit; }
            echo 'landed at ' . $path;
            PHP);

        $result = $this->probe($port);

        $this->assertSame(200, $result['http_code']);
        $this->assertSame('/app/setup/', $result['path']);
        $this->assertSame('landed at /app/setup/', $result['body']);
    }

    public function test_a_page_served_at_the_root_reports_the_root(): void
    {
        $port = $this->serve('<?php echo "home";');

        $this->assertSame('/', $this->probe($port)['path']);
    }

    /** An absolute Location off loopback is still not followed, relative handling or not. */
    public function test_an_absolute_redirect_off_the_host_still_stops_at_the_root(): void
    {
        $port = $this->serve("<?php header('Location: https://example.com/install/', true, 302);");

        $result = $this->probe($port);
        $this->assertSame(302, $result['http_code']);
        $this->assertSame('/', $result['path']);
    }

    /**
     * OpenClaw behind a trusted proxy: a request with no forwarded client is
     * refused with 403 "Proxy client attribution is required", while the vhost's
     * request (X-Forwarded-For + X-Real-IP, X-Forwarded-Proto) gets the page.
     */
    public function test_the_probe_sends_the_client_address_the_vhost_would(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php
            if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') { http_response_code(500); exit; }
            if (($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') === '' || ($_SERVER['HTTP_X_REAL_IP'] ?? '') === '') {
                http_response_code(403); echo 'proxy_attribution_required'; exit;
            }
            echo $_SERVER['HTTP_X_FORWARDED_FOR'];
            PHP);

        $result = $this->probe($port, 'openclaw.example.com');

        $this->assertSame(200, $result['http_code']);
        // An outside visitor, never loopback: apps treat a local client as trusted.
        $this->assertNotFalse(filter_var($result['body'], FILTER_VALIDATE_IP));
        $this->assertStringStartsNotWith('127.', $result['body']);
    }

    /** A probe line from before the path field still parses, as the root. */
    public function test_a_line_without_the_path_field_reads_as_the_root(): void
    {
        $line = "8000\thttp\t200 0.01\t\t" . base64_encode('hi');

        $this->assertSame('/', AppHealth::parseProbeOutput($line, [8000])[0]['path']);
    }
}
