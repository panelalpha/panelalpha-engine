<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\ListeningSockets;
use App\Lib\Deploy\Port\SilentPort;
use PHPUnit\Framework\TestCase;

/**
 * A container that is up and does not answer on the port it publishes, and
 * what it listens on instead (engine#90).
 */
class SilentPortTest extends TestCase
{
    /** `docker compose ps --format json`, one object per line. */
    private const PS = <<<'JSON'
        {"Name":"project-app-1","Service":"app","State":"running","Publishers":[{"URL":"0.0.0.0","TargetPort":4000,"PublishedPort":4000,"Protocol":"tcp"}]}
        {"Name":"project-db-1","Service":"db","State":"running","Publishers":[]}
        {"Name":"project-web-1","Service":"web","State":"exited","Publishers":[{"URL":"0.0.0.0","TargetPort":80,"PublishedPort":8080,"Protocol":"tcp"}]}
        JSON;

    /** /proc/<pid>/net/tcp header plus one LISTEN (0A) line. */
    private static function procNet(string $hexAddr, int $port): string
    {
        return "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"
            . sprintf("   0: %s:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000  1000        0 12345 1 0000000000000000 100 0 0 10 0\n", $hexAddr, $port);
    }

    public function test_the_running_container_publishing_the_silent_port_is_found(): void
    {
        $this->assertSame(
            [['name' => 'project-app-1', 'service' => 'app', 'published' => 4000, 'target' => 4000]],
            SilentPort::publishers(self::PS, [4000, 8080])
        );
    }

    public function test_the_array_form_of_ps_is_read_too(): void
    {
        $ps = '[{"Name":"p-app-1","Service":"app","State":"running","Publishers":[{"TargetPort":3000,"PublishedPort":8080}]}]';

        $this->assertSame([['name' => 'p-app-1', 'service' => 'app', 'published' => 8080, 'target' => 3000]], SilentPort::publishers($ps, [8080]));
    }

    /** teslamate's shape: Phoenix bound to loopback inside the container. */
    public function test_a_port_bound_to_loopback_only_is_named(): void
    {
        $sockets = ListeningSockets::fromProcNet(self::procNet('0100007F', 4000));

        $this->assertSame(
            'app listens on 4000 on 127.0.0.1 only, inside its container, so connections from outside it are refused. It has to listen on 0.0.0.0.',
            SilentPort::diagnose('app', 4000, 4000, $sockets)
        );
    }

    public function test_a_different_port_is_named(): void
    {
        $sockets = ListeningSockets::fromProcNet(self::procNet('00000000', 3000));

        $this->assertSame('app listens on 3000, not on 8000 (published as 8080).', SilentPort::diagnose('app', 8080, 8000, $sockets));
    }

    /** ofbiz's 8443 is TLS; the probe asked in plain HTTP. */
    public function test_a_port_that_is_bound_but_did_not_answer_http_says_so(): void
    {
        $sockets = ListeningSockets::fromProcNet(self::procNet('00000000', 8443));

        $this->assertStringContainsString('may expect HTTPS', SilentPort::diagnose('ofbiz', 8443, 8443, $sockets));
    }

    public function test_nothing_listening_yet_is_said_plainly(): void
    {
        $this->assertStringContainsString('listens on no TCP port yet', SilentPort::diagnose('app', 8080, 8080, []));
    }
}
