<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\ProjectBindMounts;
use PHPUnit\Framework\TestCase;

/**
 * A rebuild took the app down before cloning, and kept it down
 * through the whole build. Only a container that reads ~/project through a
 * bind mount has to stop first.
 */
class ProjectBindMountsTest extends TestCase
{
    public function test_a_bind_under_the_project_is_named(): void
    {
        $inspected = [
            self::container('a1', 'app', ['/home/acme/project/docker', '/var/run/docker.sock']),
            self::container('w1', 'web', ['/home/acme/project/dist/']),
            self::container('d1', 'db', []),
        ];

        $this->assertSame(
            ['app mounts ./docker', 'web mounts ./dist'],
            ProjectBindMounts::under($inspected, '/home/acme/project')
        );
    }

    public function test_the_project_root_itself_counts(): void
    {
        $this->assertSame(['app mounts .'], ProjectBindMounts::under([self::container('a1', 'app', ['/home/acme/project'])], '/home/acme/project/'));
    }

    public function test_an_image_built_app_keeps_serving(): void
    {
        // Express: everything COPY'd into the image, a named volume, no binds.
        $volume = self::container('a1', 'app', []);
        $volume['Mounts'] = [['Type' => 'volume', 'Name' => 'project_data', 'Source' => '/home/acme/project', 'Destination' => '/data']];
        $this->assertSame([], ProjectBindMounts::under([$volume], '/home/acme/project'));
        $this->assertSame([], ProjectBindMounts::under([], '/home/acme/project'));
    }

    public function test_a_sibling_directory_is_not_the_project(): void
    {
        $this->assertSame([], ProjectBindMounts::under([self::container('a1', 'app', ['/home/acme/project-next/docker', '/home/acme/.panelalpha'])], '/home/acme/project'));
    }

    public function test_a_container_without_a_service_is_named_as_one(): void
    {
        $this->assertSame(['a container mounts ./src'], ProjectBindMounts::under([self::container('a1', null, ['/home/acme/project/src'])], '/home/acme/project'));
    }

    public function test_the_containers_holding_the_binds_are_named_too(): void
    {
        $inspected = [
            self::container('c0ffee', 'app', ['/home/acme/project']),
            self::container('beef', 'db', ['/var/lib/lxcfs/proc/meminfo']),
        ];

        $this->assertSame(
            ['mounts' => ['app mounts .'], 'containers' => ['c0ffee'], 'running' => ['c0ffee', 'beef']],
            ProjectBindMounts::parse($inspected, '/home/acme/project')
        );
        $this->assertSame(['mounts' => [], 'containers' => [], 'running' => []], ProjectBindMounts::parse([], '/home/acme/project'));
    }

    /**
     * A container's inspect, as the account's daemon gives it.
     *
     * @param  list<string>  $binds  the sources of its bind mounts
     * @return array<string, mixed>
     */
    private static function container(string $id, ?string $service, array $binds): array
    {
        return [
            'Id' => $id,
            'Config' => ['Labels' => $service === null ? null : ['com.docker.compose.service' => $service]],
            'Mounts' => array_map(static fn (string $source): array => ['Type' => 'bind', 'Name' => '', 'Source' => $source, 'Destination' => '/mnt'], $binds),
        ];
    }
}
