<?php

namespace Tests\Integration\System\Project\Dind\Strategy;

use App\System\Project\Dind\DeployStrategy;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The entry points the deploy calls on {@see DeployStrategy} outside apply(): the build-context ignore file a
 * repository Dockerfile gets, the regenerated run file, and the one sidecar harvester per deploy.
 */
class DeployStrategyEntryPointsTest extends TestCase
{
    use StrategyHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    public function test_a_repository_dockerfile_gets_its_ignore_file_once_env_is_final(): void
    {
        $this->putFile('Dockerfile', "FROM nginx\nCOPY . /usr/share/nginx/html\n");

        $this->strategy(null)->keepEngineFilesOutOfBuildContext(['strategy' => 'dockerfile', 'dockerfile' => 'Dockerfile'], $this->dir, '1001:1001');

        $this->assertNotSame([], $this->eventsStartingWith("write {$this->dir}/Dockerfile.dockerignore "));
    }

    public function test_a_generated_strategy_has_no_repository_build_context_to_guard(): void
    {
        $this->putFile('Dockerfile', "FROM nginx\n");

        $this->strategy(null)->keepEngineFilesOutOfBuildContext(['strategy' => 'node'], $this->dir, '1001:1001');

        $this->assertSame([], $this->events);
    }

    public function test_the_run_file_is_regenerated_from_the_project_s_compose_file(): void
    {
        $this->putFile('docker-compose.yml', "services:\n  web:\n    image: nginx:1.27\n    ports:\n      - \"8080:80\"\n");

        $this->strategy($this->dir.'/docker-compose.yml')->refreshComposeRunFile($this->dir, '1001:1001');

        $written = $this->eventsStartingWith("write {$this->dir}/docker-compose.panelalpha.yml ");
        $this->assertCount(1, $written);
        $this->assertSame('nginx:1.27', Yaml::parse(substr($written[0], strpos($written[0], "\n") + 1))['services']['web']['image']);
    }

    public function test_one_sidecar_harvester_serves_the_whole_deploy(): void
    {
        $strategy = $this->strategy(null);

        $this->assertSame($strategy->sidecars(), $strategy->sidecars());
    }

    private function strategy(?string $composeSource): DeployStrategy
    {
        return new DeployStrategy($this->harnessDind(['UID' => 1001, 'GID' => 1001], null, null, null, null, 'https://acme.example.test', $composeSource));
    }

    /** @return list<string> */
    private function eventsStartingWith(string $prefix): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (string $event): bool => str_starts_with((string) preg_replace('/^\[\d+ log lines\] /', '', $event), $prefix)
        ));
    }
}
