<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\AppLauncher;
use PHPUnit\Framework\TestCase;

/** What the Procfile release builds before it runs, and how `compose ps` rows are read. */
class AppLauncherReleaseServicesTest extends TestCase
{
    public function test_a_depends_on_that_is_neither_a_list_nor_a_map_starts_nothing_more(): void
    {
        $compose = ['services' => [
            'release' => ['build' => ['context' => '.'], 'depends_on' => 'db'],
            'db' => ['build' => ['context' => './db']],
        ]];

        $this->assertSame(['release'], $this->servicesToBuild($compose, 'release'));
    }

    public function test_a_service_reached_twice_is_built_once_and_an_unknown_one_not_at_all(): void
    {
        $compose = ['services' => [
            'release' => ['build' => '.', 'depends_on' => ['assets', 'ghost', 'db']],
            'assets' => ['build' => './assets', 'depends_on' => ['db' => ['condition' => 'service_started']]],
            'db' => ['build' => './db', 'depends_on' => ['assets']],
        ]];

        $this->assertSame(['release', 'assets', 'db'], $this->servicesToBuild($compose, 'release'));
        $this->assertSame([], $this->servicesToBuild(['services' => 'none'], 'release'));
    }

    public function test_still_running_reads_either_output_shape(): void
    {
        $array = '[{"Service":"ready","State":"running"},{"Service":"ready","State":"running"},{"Service":"app","State":"exited"}]';
        $lines = "{\"Service\":\"ready\",\"State\":\"Running\"}\nnot json\n{\"Service\":\"app\",\"State\":\"running\"}";

        $this->assertSame(['ready'], AppLauncher::stillRunning($array, ['ready', 'app']));
        $this->assertSame(['ready'], AppLauncher::stillRunning($lines, ['ready']));
        $this->assertSame([], AppLauncher::stillRunning('', ['ready']));
    }

    /**
     * @param array<mixed> $compose
     * @return list<string>
     */
    private function servicesToBuild(array $compose, string $service): array
    {
        return (new \ReflectionMethod(AppLauncher::class, 'servicesToBuild'))->invoke(null, $compose, $service);
    }
}
