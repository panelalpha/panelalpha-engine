<?php

namespace Tests\Integration\System\Project\Dind;

use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppHealthFixtures;
use Tests\Support\TestNetwork;

class AppHealthTest extends TestCase
{
    use AppHealthFixtures;

    public function test_the_edge_probe_reports_where_a_redirect_points(): void
    {
        $dir = sys_get_temp_dir() . '/pa-edge-' . bin2hex(random_bytes(4));
        mkdir($dir);
        // A fake curl answering the way OpenCloud's vhost does over plain http.
        file_put_contents($dir . '/curl', "#!/bin/sh\nprintf '308 https://cloud.panelalpha.online/'\n");
        chmod($dir . '/curl', 0o755);
        file_put_contents($dir . '/edge.sh', AppHealth::edgeProbeScript('cloud.panelalpha.online', TestNetwork::public(10)));
        $raw = (string) shell_exec('PATH=' . escapeshellarg($dir . ':' . getenv('PATH')) . ' bash ' . escapeshellarg($dir . '/edge.sh'));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);

        $edge = AppHealth::parseFingerprint($raw);
        $this->assertSame(308, $edge['code']);
        $this->assertSame('', $edge['hash2']);
        $this->assertSame('https://cloud.panelalpha.online/', $edge['location']);
    }

    /** Immich Kiosk stamps the current second into its page. */
    public function test_the_app_probe_sees_a_per_second_timestamp_as_varying(): void
    {
        $dir = sys_get_temp_dir() . '/pa-app-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/curl', <<<'SH'
            #!/bin/sh
            while [ $# -gt 0 ]; do [ "$1" = "-o" ] && out=$2; shift; done
            date +%s >"$out"
            printf 200
            SH);
        chmod($dir . '/curl', 0o755);
        file_put_contents($dir . '/app.sh', AppHealth::appProbeScript('http', 3000, 2));
        $raw = (string) shell_exec('PATH=' . escapeshellarg($dir . ':' . getenv('PATH')) . ' bash ' . escapeshellarg($dir . '/app.sh'));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);

        $app = AppHealth::parseFingerprint($raw);
        $this->assertSame(200, $app['code']);
        $this->assertNotSame($app['hash'], $app['hash2'], 'the two fetches must not share a second');
    }

    /** @return array{0: string, 1: string} the fingerprint line, and every curl call */
    private static function runEdgeProbe(bool $https, string $httpsAnswer): array
    {
        $dir = sys_get_temp_dir() . '/pa-edge-' . bin2hex(random_bytes(4));
        mkdir($dir);
        // https answers $httpsAnswer, plain http answers 500.
        file_put_contents($dir . '/curl', <<<SH
            #!/bin/sh
            echo "\$*" >> {$dir}/calls
            case "\$*" in *https://*) printf '{$httpsAnswer} ' ;; *) printf '500 ' ;; esac
            SH);
        chmod($dir . '/curl', 0o755);
        file_put_contents($dir . '/edge.sh', AppHealth::edgeProbeScript('shop.example.com', TestNetwork::public(10), 4, $https));
        $raw = (string) shell_exec('PATH=' . escapeshellarg($dir . ':' . getenv('PATH')) . ' bash ' . escapeshellarg($dir . '/edge.sh'));
        $calls = (string) @file_get_contents($dir . '/calls');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);

        return [$raw, $calls];
    }

    public function test_the_edge_is_probed_over_https_when_the_domain_has_tls(): void
    {
        [$raw, $calls] = self::runEdgeProbe(true, '200');

        $edge = AppHealth::parseFingerprint($raw);
        $this->assertSame(200, $edge['code']);
        $this->assertSame('https', $edge['scheme']);
        // SNI and Host both carry the name; the address is still the vhost's.
        $this->assertStringContainsString('--resolve shop.example.com:443:' . TestNetwork::public(10) . ' https://shop.example.com/', $calls);
        $this->assertStringNotContainsString('http://' . TestNetwork::public(10) . '/', $calls);

        $verdict = AppHealth::compareFingerprints('shop.example.com', $edge, self::fingerprint(200, $edge['hash']));
        $this->assertSame('Reachable: https://shop.example.com/ answered 200 through the webserver', AppHealth::describeReach($verdict));
    }

    public function test_the_edge_falls_back_to_http_when_https_answers_nothing(): void
    {
        [$raw] = self::runEdgeProbe(true, '000');

        $edge = AppHealth::parseFingerprint($raw);
        $this->assertSame(500, $edge['code']);
        $this->assertSame('http', $edge['scheme']);
    }

    public function test_the_edge_of_a_domain_without_tls_is_probed_over_http(): void
    {
        [$raw, $calls] = self::runEdgeProbe(false, '200');

        $this->assertSame('http', AppHealth::parseFingerprint($raw)['scheme']);
        $this->assertStringNotContainsString('https://', $calls);
        $verdict = AppHealth::compareFingerprints('shop.example.com', AppHealth::parseFingerprint($raw), self::fingerprint(200, 'x'));
        $this->assertStringStartsWith('Not reachable: http://shop.example.com/ answered 500', AppHealth::describeReach($verdict));
    }
}
