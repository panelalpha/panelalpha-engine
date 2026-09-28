<?php

namespace Tests\Unit\Deploy;

use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * What the port probe reports when the landing page is one redirect away.
 *
 * The probe fetched `/` and stopped. Firefly III answers `/` with a 302 to
 * `/login` and `/login` with a 500, so the probe saw a 302, called it healthy,
 * and `app_health_check` returned twelve passing checks on a site that served
 * an error page to every visitor. Four applications in the supported-apps
 * series passed every check while serving nothing usable; this is the half of
 * that a status code can catch.
 *
 * Runs the generated script against a real server rather than asserting on its
 * text: the script is shell, and what matters is what it does.
 */
class HealthProbeRedirectTest extends TestCase
{
    private const HOST = '127.0.0.1';

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

    /**
     * Serves $router on a free port and returns the port.
     */
    private function serve(string $router): int
    {
        $dir = sys_get_temp_dir() . '/pa-probe-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);

        $port = random_int(21000, 21999);
        // `__PORT__` is how a router names the port it is actually listening
        // on: an absolute redirect has to point back at it for the probe to
        // recognise the hop as local.
        file_put_contents($dir . '/router.php', str_replace('__PORT__', (string) $port, $router));

        $process = proc_open(
            sprintf('exec php -S %s:%d %s/router.php', self::HOST, $port, $dir),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->markTestSkipped('could not start a PHP built-in server');
        }
        $this->servers[] = [$process, $dir];

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen(self::HOST, $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return $port;
            }
            usleep(100_000);
        }

        $this->markTestSkipped('the built-in server did not come up');
    }

    /** The status the probe finally reports for that port. */
    private function probe(int $port, ?string $domain = null): string
    {
        $script = tempnam(sys_get_temp_dir(), 'pa-probe') . '.sh';
        file_put_contents($script, AppHealth::probeScript([$port], 5, 1, 0, $domain));

        exec('sh ' . escapeshellarg($script) . ' 2>/dev/null', $lines);
        unlink($script);

        $fields = explode("\t", $lines[0] ?? '');
        $result = $fields[2] ?? '';

        return strtok($result, ' ') ?: '000';
    }

    /** Firefly III's exact shape. */
    public function test_it_reports_the_status_of_the_page_the_redirect_leads_to(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/') { header('Location: /login', true, 302); exit; }
            http_response_code(500);
            echo 'Vite manifest not found';
            PHP);

        $this->assertSame('500', $this->probe($port), 'the 500 behind the redirect must be what is reported');
    }

    /** WordPress's shape: the redirect leads somewhere that works. */
    public function test_a_redirect_to_a_working_page_reads_as_working(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/') { header('Location: /install.php', true, 302); exit; }
            echo '<html><body>Installation</body></html>';
            PHP);

        $this->assertSame('200', $this->probe($port));
    }

    /**
     * An application that canonicalises to its own public address must not
     * send this probe out of the container and onto the internet. That case is
     * understood one layer up, where a local redirect against a remote 200
     * reads as healthy.
     */
    public function test_an_absolute_redirect_off_the_host_is_not_followed(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php header('Location: https://example.com/elsewhere', true, 302); exit;
            PHP);

        $this->assertSame('302', $this->probe($port));
    }

    /**
     * Firefly III's exact shape, and the one the relative-only version of this
     * probe still missed: it builds the Location from the request host and
     * answers `https://127.0.0.1:8000/login`. Absolute, but the same port --
     * so it is followed, and the 500 behind it is what gets reported.
     *
     * The scheme it names is deliberately not adopted: the port dialled here
     * is the container's own server, which speaks plain http.
     */
    public function test_an_absolute_redirect_back_to_the_same_port_is_followed(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/') {
                header('Location: https://127.0.0.1:__PORT__/login', true, 302);
                exit;
            }
            http_response_code(500);
            echo 'Vite manifest not found';
            PHP);

        $this->assertSame('500', $this->probe($port), 'the 500 behind an absolute loopback redirect');
    }

    /** …and the scheme it names is not adopted, because this port speaks http. */
    public function test_an_absolute_https_loopback_redirect_does_not_break_a_plain_http_app(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/') {
                header('Location: http://127.0.0.1:__PORT__/login', true, 302);
                exit;
            }
            echo '<html><title>Login to Firefly III</title></html>';
            PHP);

        $this->assertSame('200', $this->probe($port), 'a healthy page reached over http is a 200');
    }

    /** A loop has to end, and end quickly. */
    public function test_a_redirect_loop_terminates(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php header('Location: /loop', true, 302); exit;
            PHP);

        $started = microtime(true);
        $this->assertSame('302', $this->probe($port));
        $this->assertLessThan(20.0, microtime(true) - $started, 'the probe must bound its hops');
    }

    /**
     * Django's ALLOWED_HOSTS, Laravel's TrustHosts, Phorge's site URIs: the app
     * refuses any Host it was not configured for. Probed as `127.0.0.1` it
     * answered 400/500 while its domain served fine (#165, #190).
     */
    public function test_it_asks_with_the_project_domain_as_host(): void
    {
        $router = <<<'PHP'
            <?php
            if (($_SERVER['HTTP_HOST'] ?? '') !== 'app.example.test') {
                http_response_code(400);
                echo 'DisallowedHost';
                exit;
            }
            echo '<html><title>Phorge</title></html>';
            PHP;
        $port = $this->serve($router);

        $this->assertSame('200', $this->probe($port, 'app.example.test'));
        $this->assertSame('400', $this->probe($port), 'without a domain the probe still asks as 127.0.0.1');
    }

    /**
     * An app that builds its redirect from the request host now names the
     * domain. That is still this container, so it is followed -- over
     * 127.0.0.1 -- and the 500 behind it is what gets reported.
     */
    public function test_an_absolute_redirect_to_the_project_domain_is_followed_locally(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php
            if (($_SERVER['HTTP_HOST'] ?? '') !== 'app.example.test') { http_response_code(400); exit; }
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/') {
                header('Location: https://' . $_SERVER['HTTP_HOST'] . '/login', true, 302);
                exit;
            }
            http_response_code(500);
            echo 'Vite manifest not found';
            PHP);

        $this->assertSame('500', $this->probe($port, 'App.Example.test'));
    }

    /** A redirect to some other public name still stays unfollowed. */
    public function test_an_absolute_redirect_to_another_name_is_not_followed_with_a_domain(): void
    {
        $port = $this->serve(<<<'PHP'
            <?php header('Location: https://example.com/elsewhere', true, 302); exit;
            PHP);

        $this->assertSame('302', $this->probe($port, 'app.example.test'));
    }
}
