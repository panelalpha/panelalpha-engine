<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposePsRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** The rows are compose 5.6.1's on a test host, from the list and inspect its daemon answered for the same stack. */
final class ComposePsRowTest extends TestCase
{
    private const CREATED = 1794325201;

    private const LXCFS = ['cpuinfo', 'diskstats', 'loadavg', 'meminfo', 'stat'];

    public function test_a_running_healthy_container_is_the_row_compose_prints(): void
    {
        $row = ComposePsRow::of(
            $this->listed('08b3082237e3aff8dc324ef30d3ee1bb8b72f1da2e2ea6a88dabe3c913b1ddbd', 'app', '/usr/local/bin/entrypoint.sh', 'running', 'Up 3 hours (healthy)', [self::volume()]),
            ['State' => ['Status' => 'running', 'ExitCode' => 0, 'Health' => ['Status' => 'healthy']], 'NetworkSettings' => ['Ports' => [
                '8000/tcp' => [['HostIp' => TestNetwork::ANY, 'HostPort' => '8000'], ['HostIp' => '::', 'HostPort' => '8000']],
            ]]],
            self::CREATED + 3 * 3600 + 600,
        );

        $this->assertSame([
            'Command' => '"/usr/local/bin/entr…"',
            'CreatedAt' => '2026-11-10 15:40:01 +0000 UTC',
            'Engine' => '',
            'ExitCode' => 0,
            'Health' => 'healthy',
            'ID' => '08b3082237e3',
            'Image' => '2fauth/2fauth:8.0.2',
            'Labels' => 'com.docker.compose.oneoff=False,com.docker.compose.project=project,com.docker.compose.service=app',
            'LocalVolumes' => '1',
            'Mounts' => 'project_twofau…,/var/lib/lxcfs…,/var/lib/lxcfs…,/var/lib/lxcfs…,/var/lib/lxcfs…,/var/lib/lxcfs…',
            'Name' => 'project-app-1',
            'Names' => 'project-app-1',
            'Networks' => 'project_default',
            'Ports' => TestNetwork::ANY.':8000->8000/tcp, [::]:8000->8000/tcp',
            'Project' => 'project',
            'Publishers' => [
                ['URL' => TestNetwork::ANY, 'TargetPort' => 8000, 'PublishedPort' => 8000, 'Protocol' => 'tcp'],
                ['URL' => '::', 'TargetPort' => 8000, 'PublishedPort' => 8000, 'Protocol' => 'tcp'],
            ],
            'RunningFor' => '3 hours ago',
            'Service' => 'app',
            'Size' => '0B',
            'State' => 'running',
            'Status' => 'Up 3 hours (healthy)',
        ], $row);
    }

    public function test_an_exited_one_shot_has_its_exit_code_no_health_and_no_ports(): void
    {
        $row = ComposePsRow::of(
            $this->listed('68643791a6b6253a1a2baf93d5ddd8f2d14dda8780cd8d2f52f63a7202bab408', 'init', '/bin/sh /panelalpha/init.sh', 'exited', 'Exited (3) 2 hours ago', [
                self::volume(), ['Source' => '/home/pdk2fa/project/panelalpha'],
            ]),
            ['State' => ['Status' => 'exited', 'ExitCode' => 3, 'Health' => ['Status' => 'unhealthy']], 'NetworkSettings' => ['Ports' => []]],
            self::CREATED + 3 * 3600,
        );

        $this->assertSame('"/bin/sh /panelalpha…"', $row['Command']);
        $this->assertSame([3, '', '', [], 'exited'], [$row['ExitCode'], $row['Health'], $row['Ports'], $row['Publishers'], $row['State']]);
        $this->assertSame('project_twofau…,/home/pdk2fa/p…,/var/lib/lxcfs…,/var/lib/lxcfs…,/var/lib/lxcfs…,/var/lib/lxcfs…,/var/lib/lxcfs…', $row['Mounts']);
        $this->assertSame('1', $row['LocalVolumes']);
    }

    public function test_a_short_command_is_whole_and_quoted_as_go_quotes_it(): void
    {
        $this->assertSame('"/bin/sh -c \'exit 0\'"', $this->command("/bin/sh -c 'exit 0'"));
        $this->assertSame('"sh -c \"echo \\\\\\"x\\\\\\"\""', $this->command('sh -c "echo \"x\""'));
        $this->assertSame('"sh -c \'a\nb\tc\x1b\'"', $this->command("sh -c 'a\nb\tc\x1b'"));
        $this->assertSame('""', $this->command(''));
    }

    public function test_a_command_is_cut_by_its_width(): void
    {
        $this->assertSame('"'.str_repeat('a', 20).'"', $this->command(str_repeat('a', 20)));
        $this->assertSame('"'.str_repeat('a', 19).'…"', $this->command(str_repeat('a', 21)));
        $this->assertSame('"echo 日本語日本語日…"', $this->command('echo 日本語日本語日本語'));
        $this->assertSame('"'.str_repeat('a', 19).'…"', $this->command(str_repeat('a', 20)."\n"));
    }

    /** @param  list<array<string, string>|null>  $bindings */
    #[DataProvider('ports')]
    public function test_the_ports_column_is_the_cli_s(array $bindings, string $ports): void
    {
        $row = ComposePsRow::of($this->listed(str_repeat('a', 64), 'web', 'nginx', 'running', 'Up 2 minutes', []), [
            'State' => ['Status' => 'running'], 'NetworkSettings' => ['Ports' => $bindings],
        ], self::CREATED + 120);

        $this->assertSame($ports, $row['Ports']);
    }

    /** @return iterable<string, array{array<string, list<array<string, string>>|null>, string}> */
    public static function ports(): iterable
    {
        $bound = static fn (string $ip, int $port): array => ['HostIp' => $ip, 'HostPort' => (string) $port];

        yield 'nothing' => [[], ''];
        yield 'exposed only' => [['9229/tcp' => null], '9229/tcp'];
        yield 'one port on both families' => [['80/tcp' => [$bound(TestNetwork::ANY, 80), $bound('::', 80)]], TestNetwork::ANY.':80->80/tcp, [::]:80->80/tcp'];
        yield 'a published port of another number comes last' => [
            ['80/tcp' => [$bound(TestNetwork::ANY, 8080)], '9000/tcp' => [$bound(TestNetwork::LOOPBACK, 9000)]],
            TestNetwork::LOOPBACK.':9000->9000/tcp, '.TestNetwork::ANY.':8080->80/tcp',
        ];
        yield 'consecutive ports as a range' => [
            ['3000/tcp' => [$bound(TestNetwork::ANY, 3000)], '3001/tcp' => [$bound(TestNetwork::ANY, 3001)], '3002/tcp' => [$bound(TestNetwork::ANY, 3002)]],
            TestNetwork::ANY.':3000-3002->3000-3002/tcp',
        ];
        yield 'a gap ends a range' => [
            ['80/tcp' => [$bound(TestNetwork::ANY, 80), $bound('::', 80)], '443/tcp' => [$bound(TestNetwork::ANY, 443), $bound('::', 443)]],
            TestNetwork::ANY.':80->80/tcp, [::]:80->80/tcp, '.TestNetwork::ANY.':443->443/tcp, [::]:443->443/tcp',
        ];
        yield 'each protocol its own' => [
            ['53/udp' => [$bound(TestNetwork::ANY, 53)], '53/tcp' => [$bound(TestNetwork::ANY, 53)], '54/tcp' => null],
            TestNetwork::ANY.':53->53/tcp, '.TestNetwork::ANY.':53->53/udp, 54/tcp',
        ];
    }

    #[DataProvider('ages')]
    public function test_running_for_is_the_cli_s_human_duration(int $seconds, string $ago): void
    {
        $row = ComposePsRow::of($this->listed(str_repeat('a', 64), 'web', 'nginx', 'running', 'Up', []), [], self::CREATED + $seconds);

        $this->assertSame($ago, $row['RunningFor']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function ages(): iterable
    {
        yield 'created after now' => [-5, 'Less than a second ago'];
        yield 'now' => [0, 'Less than a second ago'];
        yield 'a second' => [1, '1 second ago'];
        yield 'seconds' => [59, '59 seconds ago'];
        yield 'a minute' => [119, 'About a minute ago'];
        yield 'minutes' => [3599, '59 minutes ago'];
        yield 'an hour, rounded' => [5399, 'About an hour ago'];
        yield 'hours, rounded' => [5400, '2 hours ago'];
        yield 'two days' => [48 * 3600 - 1800, '2 days ago'];
        yield 'weeks' => [15 * 86400, '2 weeks ago'];
        yield 'months' => [90 * 86400, '3 months ago'];
        yield 'years' => [3 * 365 * 86400, '3 years ago'];
    }

    public function test_a_container_without_labels_names_or_networks_prints_empty_fields(): void
    {
        $row = ComposePsRow::of(['Id' => str_repeat('b', 64), 'Created' => self::CREATED], [], self::CREATED);

        $this->assertSame(['', '', '', '', '', '0', '', ''], [
            $row['Labels'], $row['Name'], $row['Names'], $row['Networks'], $row['Mounts'], $row['LocalVolumes'], $row['Project'], $row['Service'],
        ]);
        $this->assertSame('"', $row['Command'][0]);
    }

    public function test_every_name_is_listed_without_its_slash(): void
    {
        $row = ComposePsRow::of(['Id' => str_repeat('b', 64), 'Names' => ['/shop-db-1', '/shop-web-1/db']], [], self::CREATED);

        $this->assertSame(['shop-db-1', 'shop-db-1,shop-web-1/db'], [$row['Name'], $row['Names']]);
    }

    private function command(string $command): string
    {
        return ComposePsRow::of(['Id' => str_repeat('c', 64), 'Command' => $command], [], self::CREATED)['Command'];
    }

    /** @return array<string, string> */
    private static function volume(): array
    {
        return ['Name' => 'project_twofauth_data', 'Source' => '/home/pdk2fa/docker/volumes/project_twofauth_data/_data', 'Driver' => 'local'];
    }

    /**
     * @param  list<array<string, string>>  $mounts
     * @return array<string, mixed>
     */
    private function listed(string $id, string $service, string $command, string $state, string $status, array $mounts): array
    {
        foreach (self::LXCFS as $file) {
            $mounts[] = ['Source' => '/var/lib/lxcfs/proc/'.$file];
        }

        return [
            'Id' => $id,
            'Names' => ['/project-'.$service.'-1'],
            'Image' => '2fauth/2fauth:8.0.2',
            'ImageID' => 'sha256:'.str_repeat('3f', 32),
            'Command' => $command,
            'Created' => self::CREATED,
            'State' => $state,
            'Status' => $status,
            'Labels' => ['com.docker.compose.oneoff' => 'False', 'com.docker.compose.project' => 'project', 'com.docker.compose.service' => $service],
            'Mounts' => $mounts,
            'NetworkSettings' => ['Networks' => ['project_default' => []]],
        ];
    }
}
