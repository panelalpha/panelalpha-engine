<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\SilentPortCheck;
use PHPUnit\Framework\TestCase;

/**
 * The check the deploy-time probe adds when every port stayed silent and no
 * container is restarting.
 */
class SilentPortCheckTest extends TestCase
{
    private function check(array $results, string $ps, string $procNet): ?array
    {
        $system = new class ($ps, $procNet) extends System {
            public function __construct(private string $ps, private string $procNet)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;

                return str_contains($line, '/proc/') ? $this->procNet : $this->ps;
            }
        };

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('acme');
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('userAppComposeCommand')->willReturnCallback(static fn (array $rest): array => array_merge(['docker', 'compose'], $rest));

        return (new SilentPortCheck($dind))->check($results);
    }

    public function test_a_silent_port_is_explained_by_what_the_container_listens_on(): void
    {
        $check = $this->check(
            [['port' => 4000, 'status' => AppHealth::STATUS_FAIL]],
            '{"Name":"project-app-1","Service":"app","State":"running","Publishers":[{"TargetPort":4000,"PublishedPort":4000}]}',
            "  sl  local_address rem_address   st\n   0: 0100007F:0FA0 00000000:0000 0A 00000000:00000000 00:00000000 00000000  1000 0 1 1\n"
        );

        $this->assertSame(SilentPortCheck::ID, $check['id']);
        $this->assertSame('error', $check['severity']);
        $this->assertStringContainsString('app listens on 4000 on 127.0.0.1 only', $check['detail']);
    }

    /** The deploy waits on these, and only these. */
    public function test_a_container_bound_to_nothing_yet_is_named_as_starting(): void
    {
        $ps = '{"Name":"project-app-1","Service":"app","State":"running","Publishers":[{"TargetPort":8080,"PublishedPort":8080}]}';
        $results = [['port' => 8080, 'status' => AppHealth::STATUS_FAIL]];

        // Only Docker's embedded DNS on loopback: nothing of the app's own.
        $starting = $this->check($results, $ps, "  sl  local_address rem_address   st\n   0: 0B00007F:A1B2 00000000:0000 0A 00000000:00000000 00:00000000 00000000  0 0 1 1\n");
        $this->assertStringContainsString('listens on no TCP port yet', $starting['detail']);
        $this->assertSame(['app'], SilentPortCheck::starting($starting));

        $wrongPort = $this->check($results, $ps, "  sl  local_address rem_address   st\n   0: 00000000:0BB8 00000000:0000 0A 00000000:00000000 00:00000000 00000000  1000 0 1 1\n");
        $this->assertSame([], SilentPortCheck::starting($wrongPort), 'listening elsewhere is not still starting');
        $this->assertSame([], SilentPortCheck::starting(null));
    }

    public function test_an_answering_port_needs_no_explanation(): void
    {
        $this->assertNull($this->check(
            [['port' => 4000, 'status' => AppHealth::STATUS_FAIL], ['port' => 8080, 'status' => AppHealth::STATUS_OK]],
            '',
            ''
        ));
    }

    public function test_a_port_that_answered_a_server_error_is_not_silent(): void
    {
        // foodsoft, Memtly: HTTP 500 on the port, and the check told them it
        // "may expect HTTPS".
        $this->assertNull($this->check(
            [['port' => 3000, 'status' => AppHealth::STATUS_FAIL, 'http_code' => 500]],
            '{"Name":"project-app-1","Service":"app","State":"running","Publishers":[{"TargetPort":3000,"PublishedPort":3000}]}',
            "  sl  local_address rem_address   st\n   0: 00000000:0BB8 00000000:0000 0A 00000000:00000000 00:00000000 00000000  1000 0 1 1\n"
        ));
    }

    public function test_a_silent_port_is_still_explained_beside_one_that_answered_500(): void
    {
        $check = $this->check(
            [
                ['port' => 3000, 'status' => AppHealth::STATUS_FAIL, 'http_code' => 500],
                ['port' => 4000, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null],
            ],
            '{"Name":"project-app-1","Service":"app","State":"running","Publishers":[{"TargetPort":3000,"PublishedPort":3000},{"TargetPort":4000,"PublishedPort":4000}]}',
            "  sl  local_address rem_address   st\n   0: 00000000:0BB8 00000000:0000 0A 00000000:00000000 00:00000000 00000000  1000 0 1 1\n"
        );

        $this->assertSame(SilentPortCheck::ID, $check['id']);
        $this->assertStringContainsString('app listens on 3000, not on 4000', $check['detail']);
        $this->assertStringNotContainsString('may expect HTTPS', $check['detail']);
    }

    public function test_no_container_publishing_the_port_means_no_verdict(): void
    {
        $this->assertNull($this->check([['port' => 4000, 'status' => AppHealth::STATUS_FAIL]], '', ''));
    }
}
