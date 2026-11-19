<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\AppLauncher;
use PHPUnit\Framework\TestCase;

/**
 * A project that publishes the image it builds names it next to `build:` with
 * `pull_policy: missing`, as the engine's own stack does. `up --build` built it
 * anyway, on every deploy, and tagged the local build with the registry's name;
 * the deploy now pulls those services first and starts them without a build when
 * the registry has the tag. Which services count is publishedBuilds().
 */
class AppLauncherPublishedBuildsTest extends TestCase
{
    public function test_a_built_service_that_names_its_published_image_is_pulled_first(): void
    {
        $compose = ['services' => [
            'app' => ['build' => ['context' => '.'], 'image' => 'ghcr.io/panelalpha/panel:v2.0.0', 'pull_policy' => 'missing'],
            'db' => ['image' => 'mariadb:11'],
        ]];

        $this->assertSame(['app'], AppLauncher::publishedBuilds($compose));
    }

    public function test_a_built_service_without_the_pull_policy_is_built_as_before(): void
    {
        $compose = ['services' => [
            'app' => ['build' => ['context' => '.'], 'image' => 'ghcr.io/panelalpha/panel:v2.0.0'],
        ]];

        $this->assertSame([], AppLauncher::publishedBuilds($compose));
    }

    public function test_a_built_service_without_an_image_is_built_as_before(): void
    {
        $compose = ['services' => [
            'app' => ['build' => ['context' => '.'], 'pull_policy' => 'missing'],
        ]];

        $this->assertSame([], AppLauncher::publishedBuilds($compose));
    }

    /** One `up --no-build` starts every service, so a second service that only builds keeps the build. */
    public function test_one_service_that_only_builds_keeps_the_build_for_all(): void
    {
        $compose = ['services' => [
            'app' => ['build' => ['context' => '.'], 'image' => 'ghcr.io/panelalpha/panel:v2.0.0', 'pull_policy' => 'missing'],
            'worker' => ['build' => ['context' => './worker']],
        ]];

        $this->assertSame([], AppLauncher::publishedBuilds($compose));
    }

    public function test_a_project_that_builds_nothing_has_nothing_to_pull_first(): void
    {
        $this->assertSame([], AppLauncher::publishedBuilds(['services' => ['web' => ['image' => 'nginx:1.27-alpine']]]));
        $this->assertSame([], AppLauncher::publishedBuilds([]));
    }
}
