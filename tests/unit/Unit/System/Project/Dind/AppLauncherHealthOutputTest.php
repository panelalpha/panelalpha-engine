<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppLauncher;
use PHPUnit\Framework\TestCase;

/**
 * Which health verdicts paste the container's output into the deploy log. A
 * restart loop always did; a 5xx did not, so chatbot-ui's missing Supabase
 * keys and mafl's `Config not found` were only visible with `docker logs`.
 */
class AppLauncherHealthOutputTest extends TestCase
{
    public function test_a_5xx_verdict_pastes_the_container_output(): void
    {
        $this->assertTrue($this->sawFailure([AppHealth::DETAIL_PORTS => [['port' => 3000, 'status' => 'fail', 'http_code' => 500]]]));
    }

    public function test_a_restart_loop_still_does(): void
    {
        $this->assertTrue($this->sawFailure([AppHealth::DETAIL_CHECKS => [['id' => AppHealth::CHECK_RESTART_LOOPING]]]));
    }

    public function test_a_healthy_app_does_not(): void
    {
        $this->assertFalse($this->sawFailure([AppHealth::DETAIL_PORTS => [['port' => 3000, 'status' => 'ok', 'http_code' => 200]]]));
    }

    /** @param array<string, mixed> $details */
    private function sawFailure(array $details): bool
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn($details);
        $project = $this->createStub(Dind::class);
        $project->method('userModel')->willReturn($user);

        return (new \ReflectionMethod(AppLauncher::class, 'healthSawAFailingContainer'))->invoke(new AppLauncher($project));
    }
}
