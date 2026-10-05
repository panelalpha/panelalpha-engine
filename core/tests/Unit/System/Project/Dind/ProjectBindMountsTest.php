<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\ProjectBindMounts;
use PHPUnit\Framework\TestCase;

/**
 * engine#33: a rebuild took the app down before cloning, and kept it down
 * through the whole build. Only a container that reads ~/project through a
 * bind mount has to stop first.
 */
class ProjectBindMountsTest extends TestCase
{
    public function test_a_bind_under_the_project_is_named(): void
    {
        $inspect = "app\t/home/acme/project/docker\t/var/run/docker.sock\n"
            . "web\t/home/acme/project/dist/\n"
            . "db\n";

        $this->assertSame(
            ['app mounts ./docker', 'web mounts ./dist'],
            ProjectBindMounts::under($inspect, '/home/acme/project')
        );
    }

    public function test_the_project_root_itself_counts(): void
    {
        $this->assertSame(['app mounts .'], ProjectBindMounts::under("app\t/home/acme/project", '/home/acme/project/'));
    }

    public function test_an_image_built_app_keeps_serving(): void
    {
        // Express: everything COPY'd into the image, a named volume, no binds.
        $this->assertSame([], ProjectBindMounts::under("app\n", '/home/acme/project'));
        $this->assertSame([], ProjectBindMounts::under('', '/home/acme/project'));
    }

    public function test_a_sibling_directory_is_not_the_project(): void
    {
        $this->assertSame([], ProjectBindMounts::under("app\t/home/acme/project-next/docker\t/home/acme/.panelalpha", '/home/acme/project'));
    }

    public function test_the_containers_holding_the_binds_are_named_too(): void
    {
        $inspect = "c0ffee\tapp\t/home/acme/project\n"
            . "beef\tdb\t/var/lib/lxcfs/proc/meminfo\n";

        $this->assertSame(
            ['mounts' => ['app mounts .'], 'containers' => ['c0ffee'], 'running' => ['c0ffee', 'beef']],
            ProjectBindMounts::parse($inspect, '/home/acme/project')
        );
        $this->assertSame(['mounts' => [], 'containers' => [], 'running' => []], ProjectBindMounts::parse('', '/home/acme/project'));
    }
}
