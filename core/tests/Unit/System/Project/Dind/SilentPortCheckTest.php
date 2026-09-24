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
 * container is restarting (engine#90).
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

    public function test_an_answering_port_needs_no_explanation(): void
    {
        $this->assertNull($this->check(
            [['port' => 4000, 'status' => AppHealth::STATUS_FAIL], ['port' => 8080, 'status' => AppHealth::STATUS_OK]],
            '',
            ''
        ));
    }

    public function test_no_container_publishing_the_port_means_no_verdict(): void
    {
        $this->assertNull($this->check([['port' => 4000, 'status' => AppHealth::STATUS_FAIL]], '', ''));
    }
}
