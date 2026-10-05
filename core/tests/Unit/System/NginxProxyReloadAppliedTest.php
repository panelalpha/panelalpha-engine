<?php

namespace Tests\Unit\System;

use App\System;
use App\System as EngineSystem;
use App\System\Services\Webserver;
use App\System\Services\Webserver\NginxProxy;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A fresh sites-http boots on the shipped wildcard config. Once the engine
 * renders `listen <ip>:80`, a reload cannot rebind (EADDRINUSE against its own
 * wildcard socket) and nginx keeps the old config, so no site added after it
 * is ever served. The reload has to notice and restart the container itself:
 * the restart it used to queue through `at` never ran on a host without `at`.
 */
class NginxProxyReloadAppliedTest extends TestCase
{
    private const WILDCARD = 'wildcard';
    private const BOUND = 'bound';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/nginx-proxy-reload-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/webserver-config/nginx-proxy', 0777, true);
        file_put_contents(
            $this->dir . '/webserver-config/nginx-proxy/nginx.conf',
            "http {\n    server {\n        listen 10.10.0.44:80 default_server;\n    }\n"
            . "    server {\n        listen 10.10.0.44:443 ssl default_server;\n    }\n}\n"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/webserver-config/nginx-proxy/nginx.conf');
        @rmdir($this->dir . '/webserver-config/nginx-proxy');
        @rmdir($this->dir . '/webserver-config');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_reload_left_on_wildcard_sockets_restarts_the_container_directly(): void
    {
        Log::spy();
        $host = $this->host(self::WILDCARD, self::BOUND);

        $this->proxy($host)->reload();

        $this->assertSame(1, $host->restarts, 'the container has to be restarted once');
        $this->assertSame([], $host->queued, 'nothing may depend on `at` running a queued job');
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_reload_that_applied_does_not_restart(): void
    {
        $host = $this->host(self::BOUND, self::BOUND);

        $this->proxy($host)->reload();

        $this->assertSame(0, $host->restarts);
        $this->assertSame([], $host->queued);
    }

    public function test_a_refused_restart_is_logged_and_the_reload_does_not_throw(): void
    {
        Log::spy();
        $host = $this->host(self::WILDCARD, self::WILDCARD, refuse: true);

        $this->proxy($host)->reload();

        $this->assertSame(0, $host->restarts);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_restart_that_still_does_not_bind_is_logged(): void
    {
        Log::spy();
        $host = $this->host(self::WILDCARD, self::WILDCARD);

        $this->proxy($host)->reload();

        $this->assertSame(1, $host->restarts);
        Log::shouldHaveReceived('error')->once();
    }

    private function proxy(EngineSystem $host): NginxProxy
    {
        return new class ($host) extends NginxProxy {
            protected function pauseBeforeRecheck(): void
            {
            }
        };
    }

    /**
     * A host whose sites-http listens per $before until it is restarted, then per $after.
     */
    private function host(string $before, string $after, bool $refuse = false): EngineSystem
    {
        return new class ($this->dir, $before, $after, $refuse) extends System {
            public int $restarts = 0;
            /** @var list<bool> */
            public array $queued = [];

            public function __construct(
                private string $dir,
                private string $before,
                private string $after,
                private bool $refuse
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function composeFilePath(): string
            {
                return $this->dir . '/docker-compose.yml';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if (is_array($cmd) && array_slice($cmd, 0, 2) === ['sudo', 'cp']) {
                    copy($cmd[2], $cmd[3]);
                    return '';
                }
                if (is_string($cmd) && str_contains($cmd, '/proc/net/tcp')) {
                    return self::procNetTcp($this->restarts > 0 ? $this->after : $this->before);
                }

                return '';
            }

            public function webserver(): Webserver
            {
                return new class ($this) extends Webserver {
                    public function __construct(private EngineSystem $host)
                    {
                        parent::__construct($host);
                    }

                    public function restartWebserverContainerFromHost(): void
                    {
                        if ($this->host->refuse()) {
                            throw new \Exception('Refusing to restart sites-http');
                        }
                        $this->host->restarts++;
                    }

                    public function scheduleWebserverReloadInBackground(bool $rebindIpListeners = false): void
                    {
                        $this->host->queued[] = $rebindIpListeners;
                    }
                };
            }

            public function refuse(): bool
            {
                return $this->refuse;
            }

            private static function procNetTcp(string $state): string
            {
                // 10.10.0.44 little-endian is 2C000A0A; ports 80 and 443 are 0050 and 01BB.
                $addr = $state === 'bound' ? '2C000A0A' : '00000000';
                $line = '%4d: %s:%s 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1 1 0 100 0 0 10 0';

                return "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"
                    . sprintf($line, 0, $addr, '0050') . "\n"
                    . sprintf($line, 1, $addr, '01BB') . "\n"
                    . sprintf($line, 2, '0100007F', '1F90') . "\n";
            }
        };
    }
}
