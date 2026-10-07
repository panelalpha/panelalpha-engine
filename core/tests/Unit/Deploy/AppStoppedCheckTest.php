<?php

namespace Tests\Unit\Deploy;

use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * `docker stop` on the app container left the sweep with no failed
 * check at all, so a site that answered nothing counted as serving. Rows are
 * `docker compose ps --format json` as Docker prints them.
 */
class AppStoppedCheckTest extends TestCase
{
    public function test_a_stopped_app_container_is_its_own_verdict(): void
    {
        $check = AppHealth::stoppedFrom(
            '{"Name":"project-app-1","Service":"app","State":"exited","Status":"Exited (0) 3 hours ago","ExitCode":0}'
        );

        $this->assertSame(AppHealth::CHECK_APP_STOPPED, $check['id']);
        $this->assertSame('error', $check['severity']);
        $this->assertStringContainsString('app (Exited (0) 3 hours ago)', $check['detail']);
        $this->assertStringContainsString('container_project_action up', $check['fix']);
    }

    public function test_created_and_paused_are_stopped_too(): void
    {
        $this->assertNotNull(AppHealth::stoppedFrom('{"Name":"project-app-1","Service":"app","State":"created","Status":"Created","ExitCode":0}'));
        $this->assertNotNull(AppHealth::stoppedFrom('{"Name":"project-app-1","Service":"app","State":"paused","Status":"Up 2 hours (Paused)","ExitCode":0}'));
    }

    public function test_a_finished_one_shot_is_not_a_stopped_app(): void
    {
        $rows = '{"Name":"project-migrate-1","Service":"migrate","State":"exited","Status":"Exited (0) 1 hour ago","ExitCode":0}' . "\n"
            . '{"Name":"project-app-1","Service":"app","State":"running","Status":"Up 1 hour","ExitCode":0}';

        $this->assertNull(AppHealth::stoppedFrom($rows, ['migrate']));
    }

    public function test_a_stop_signal_is_stopped_only_when_docker_never_restarted_it(): void
    {
        $row = '{"Name":"project-app-1","Service":"app","State":"exited","Status":"Exited (143) 5 minutes ago","ExitCode":143}';

        // `docker stop`: SIGTERM, no restart behind it.
        $this->assertNull(AppHealth::restartLoopCounting($row, ['project-app-1' => 0]));
        $this->assertNotNull(AppHealth::stoppedFrom($row, [], ['project-app-1' => 0]));

        // Wekan's crash loop parks in exited (137) between restarts: still a loop.
        $looping = '{"Name":"wekan-app","Service":"wekan","State":"exited","Status":"Exited (137) 2 seconds ago","ExitCode":137}';
        $this->assertNotNull(AppHealth::restartLoopCounting($looping, ['wekan-app' => 6]));
        $this->assertNotNull(AppHealth::restartLoopFrom($looping), 'no count to go on: a crash, as before');
        $this->assertNull(AppHealth::stoppedFrom($looping, [], ['wekan-app' => 6]));
    }

    public function test_a_crash_is_not_reported_as_stopped(): void
    {
        $this->assertNull(AppHealth::stoppedFrom('{"Name":"project-app-1","Service":"app","State":"exited","Status":"Exited (1) 2 minutes ago","ExitCode":1}'));
        $this->assertNull(AppHealth::stoppedFrom('{"Name":"project-app-1","Service":"app","State":"restarting","Status":"Restarting (1) 4 seconds ago","ExitCode":1}'));
        $this->assertNull(AppHealth::stoppedFrom('{"Name":"project-app-1","Service":"app","State":"running","Status":"Up 3 hours","ExitCode":0}'));
        $this->assertNull(AppHealth::stoppedFrom(''));
    }
}
