<?php

namespace Tests\Support;

use App\System;
use App\System\Services\Webserver;

/**
 * Binds a System whose webserver only counts rebuilds and reloads, so a
 * proxy-rule change in a test never touches the webserver of this machine.
 * The host's listening sockets are whatever {@see hostListens()} says.
 */
trait RecordsWebserverApply
{
    /** @var \ArrayObject<string, int> */
    protected \ArrayObject $webserverCalls;

    /** @var \ArrayObject<string, string> `ss -Hln` output by -t / -u */
    protected \ArrayObject $hostSockets;

    protected function recordWebserverApply(): void
    {
        $calls = $this->webserverCalls = new \ArrayObject(['rebuild' => 0, 'reload' => 0]);
        $sockets = $this->hostSockets = new \ArrayObject(['-t' => '', '-u' => '']);

        $this->app->instance(System::class, new class ($calls, $sockets) extends System {
            /**
             * @param \ArrayObject<string, int> $calls
             * @param \ArrayObject<string, string> $sockets
             */
            public function __construct(private \ArrayObject $calls, private \ArrayObject $sockets)
            {
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                $cmd = (array) $cmd;
                if (($cmd[0] ?? null) !== 'ss') {
                    throw new \RuntimeException('unexpected host command: ' . implode(' ', $cmd));
                }

                return $this->sockets[(string) end($cmd)] ?? '';
            }

            public function webserver(): Webserver
            {
                return new class ($this, $this->calls) extends Webserver {
                    /** @param \ArrayObject<string, int> $calls */
                    public function __construct(System $system, private \ArrayObject $calls)
                    {
                        parent::__construct($system);
                    }

                    public function rebuildConfig(?array $domains = null): void
                    {
                        $this->calls['rebuild']++;
                    }

                    public function scheduleWebserverReloadInBackground(bool $rebindIpListeners = false): void
                    {
                        $this->calls['reload']++;
                    }
                };
            }
        });
    }

    /** Lines as `ss -Hln` prints them, e.g. `LISTEN 0 128 0.0.0.0:22 0.0.0.0:*`. */
    protected function hostListens(string $tcp, string $udp = ''): void
    {
        $this->hostSockets['-t'] = $tcp;
        $this->hostSockets['-u'] = $udp;
    }

    protected function assertWebserverApplied(int $times): void
    {
        $this->assertSame(['rebuild' => $times, 'reload' => $times], $this->webserverCalls->getArrayCopy());
    }
}
