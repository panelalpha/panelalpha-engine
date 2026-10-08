<?php

namespace Tests\Unit\Deploy;

use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * An app a recipe declares HTTPS-only (`port_scheme: https`): nginx with
 * `listen 8443 ssl` answers plain http with a 400, which the probe took for
 * the app. Runs the generated script against a fake `curl` that answers 400
 * on http and 200 on https.
 */
class HealthProbeDeclaredSchemeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-scheme-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);

        file_put_contents($this->dir . '/curl', <<<'SH'
            #!/bin/sh
            for last; do :; done
            echo "$last" >>"$FAKE_CURL_DIR/urls"
            case "$last" in
                https://*) printf '200 0.002' ;;
                *) printf '400 0.002' ;;
            esac
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
     * @param list<int> $httpsPorts
     * @return array{0: string, 1: string, 2: list<string>} scheme, code, the URLs curl was asked for
     */
    private function probe(array $httpsPorts): array
    {
        $script = $this->dir . '/probe.sh';
        file_put_contents($script, AppHealth::probeScript([8443], 1, 1, 0, null, false, $httpsPorts));
        $env = 'PATH=' . escapeshellarg($this->dir . ':' . getenv('PATH')) . ' FAKE_CURL_DIR=' . escapeshellarg($this->dir);
        exec($env . ' bash ' . escapeshellarg($script) . ' 2>/dev/null', $lines);
        $fields = explode("\t", $lines[0] ?? '');
        $urls = file($this->dir . '/urls', FILE_IGNORE_NEW_LINES) ?: [];

        return [$fields[1] ?? '', strtok($fields[2] ?? '', ' ') ?: '', $urls];
    }

    public function test_a_declared_https_port_is_probed_over_https(): void
    {
        [$scheme, $code, $urls] = $this->probe([8443]);

        $this->assertSame(['https', '200'], [$scheme, $code]);
        $this->assertSame(['https://127.0.0.1:8443/'], $urls);
    }

    /** Undeclared, http still comes first: its answer is kept as before. */
    public function test_an_undeclared_port_is_probed_over_http_first(): void
    {
        [$scheme, $code, $urls] = $this->probe([]);

        $this->assertSame(['http', '400'], [$scheme, $code]);
        $this->assertSame(['http://127.0.0.1:8443/'], $urls);
    }
}
