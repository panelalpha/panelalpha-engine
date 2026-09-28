<?php

namespace Tests\Unit\Deploy;

use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * Which answers the port probe keeps waiting on (engine#280).
 *
 * An image that runs its own proxy in front of the app (Pingvin Share X:
 * Caddy) binds the port at once and answers 502 until the backend is up. The
 * probe used to retry only on 000, so it stopped at the first 502 and failed
 * the deploy seconds before the app came up.
 *
 * Runs the generated script against a fake `curl` that answers a scripted
 * sequence of codes, one per call.
 */
class HealthProbeRetryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-retry-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);

        file_put_contents($this->dir . '/curl', <<<'SH'
            #!/bin/sh
            n=$(cat "$FAKE_CURL_DIR/calls" 2>/dev/null || echo 0)
            n=$((n + 1))
            echo "$n" >"$FAKE_CURL_DIR/calls"
            code=$(sed -n "${n}p" "$FAKE_CURL_DIR/codes")
            [ -n "$code" ] || code=$(tail -n 1 "$FAKE_CURL_DIR/codes")
            printf '%s 0.002' "$code"
            [ "$code" = "000" ] && exit 7
            exit 0
            SH);
        chmod($this->dir . '/curl', 0o755);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param list<string> $codes what curl answers, call by call
     * @return array{0: string, 1: int} the reported code and how many times curl ran
     */
    private function probe(array $codes, int $attempts = 4): array
    {
        file_put_contents($this->dir . '/codes', implode("\n", $codes) . "\n");
        $script = $this->dir . '/probe.sh';
        file_put_contents($script, AppHealth::probeScript([8000], 1, $attempts, 0));

        $env = 'PATH=' . escapeshellarg($this->dir . ':' . getenv('PATH'))
            . ' FAKE_CURL_DIR=' . escapeshellarg($this->dir);
        exec($env . ' bash ' . escapeshellarg($script) . ' 2>/dev/null', $lines);

        $fields = explode("\t", $lines[0] ?? '');

        return [strtok($fields[2] ?? '', ' ') ?: '', (int) trim((string) @file_get_contents($this->dir . '/calls'))];
    }

    public function test_a_proxy_502_is_waited_out_until_the_backend_answers(): void
    {
        [$code, $calls] = $this->probe(['502', '503', '504', '200']);

        $this->assertSame('200', $code);
        $this->assertSame(4, $calls);
    }

    public function test_a_502_that_never_clears_is_still_reported_as_502(): void
    {
        [$code, $calls] = $this->probe(['502'], 3);

        $this->assertSame('502', $code, 'the last status is reported, not a made-up one');
        $this->assertSame(3, $calls, 'bounded by the attempt count');
    }

    public function test_an_application_500_is_not_retried(): void
    {
        [$code, $calls] = $this->probe(['500', '200']);

        $this->assertSame('500', $code);
        $this->assertSame(1, $calls);
    }

    public function test_a_healthy_app_is_probed_once(): void
    {
        [$code, $calls] = $this->probe(['200']);

        $this->assertSame('200', $code);
        $this->assertSame(1, $calls);
    }
}
