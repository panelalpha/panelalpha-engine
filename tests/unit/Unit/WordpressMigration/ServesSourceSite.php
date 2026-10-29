<?php

namespace Tests\Unit\WordpressMigration;

use App\Lib\Http\OutboundUrl;
use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\EngineAddresses;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPolicy;

/**
 * A PHP built-in server standing in for a source site at `wp.example`, on
 * this host's own (private) address. The fetch guard refuses private
 * addresses, so `wp.example` is put on the operator's allow list for it.
 */
trait ServesSourceSite
{
    public const SOURCE_HOST = 'wp.example';

    /** @var array{0: resource, 1: string}|null */
    private ?array $server = null;

    protected function stopSourceSite(): void
    {
        OutboundUrl::setPolicyForTests(null, null);
        if ($this->server === null) {
            return;
        }

        [$process, $dir] = $this->server;
        proc_terminate($process);
        proc_close($process);
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
        $this->server = null;
    }

    /**
     * A client reaching `wp.example` at the server running $router, and the server's base URL.
     *
     * @return array{0: CurlSourceHttpClient, 1: string}
     */
    private function clientOnSourceSite(string $router): array
    {
        $address = gethostbyname(gethostname());
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || str_starts_with($address, '127.')) {
            $this->markTestSkipped('needs a non-loopback address of this host for the built-in server');
        }

        OutboundUrl::setPolicyForTests(false, [self::SOURCE_HOST]);
        $url = 'http://'.self::SOURCE_HOST.':'.$this->serve($address, $router);
        $client = new CurlSourceHttpClient(null, static fn (): array => [$address], new SourceIpPolicy(new class extends EngineAddresses
        {
            public function ranges(): array
            {
                return [];
            }
        }));

        return [$client, $url];
    }

    private function serve(string $address, string $router): int
    {
        $dir = sys_get_temp_dir().'/pa-source-site-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        file_put_contents($dir.'/router.php', $router);

        $port = random_int(23000, 23999);
        $process = proc_open(
            sprintf('exec php -S %s:%d %s/router.php', $address, $port, $dir),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (! is_resource($process)) {
            $this->markTestSkipped('could not start a PHP built-in server');
        }
        $this->server = [$process, $dir];

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen($address, $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return $port;
            }
            usleep(100_000);
        }

        $this->markTestSkipped('the built-in server did not come up');
    }
}
