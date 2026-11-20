<?php

namespace Tests\Integration\System\Project\Dind\Strategy;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\System\Project\Dind\Strategy\UserComposeStrategy;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * {@see UserComposeStrategy::apply()} with and without a compose file, the files it refuses, the
 * placeholders it fills and the override it layers when git cannot say what the repository shipped.
 */
class UserComposeApplyBranchesTest extends TestCase
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

    public function test_apply_without_a_compose_file_serves_the_placeholder(): void
    {
        $this->strategy(null)->apply(['compose_path' => null], null, $this->dir, '1001:1001', 'https://example.test/acme/app');

        $this->assertContains('warn: No compose file to run, so the placeholder page is served', $this->logLines());
        $this->assertNotEmpty($this->eventsStartingWith("compose {$this->dir} "));
        $this->assertSame([], $this->eventsStartingWith("write {$this->dir}/docker-compose.panelalpha.yml"));
    }

    public function test_apply_runs_the_compose_file_the_decision_names(): void
    {
        $this->putFile('deploy/compose.prod.yml', "services:\n  app:\n    image: nginx:1.27\n    ports:\n      - \"8080:80\"\n");

        $this->strategy(null)->apply(['compose_path' => $this->dir.'/deploy/compose.prod.yml'], null, $this->dir, '1001:1001', 'src');

        $run = $this->runFile();
        $this->assertSame('nginx:1.27', $run['services']['app']['image']);
        $this->assertSame('unless-stopped', $run['services']['app']['restart']);
        $this->assertContains('info: Hardened compose for hosting (resource limits, restart policy, isolation)', $this->logLines());
    }

    public function test_apply_falls_back_to_the_existing_compose_file_and_skips_prepare_once_env_exists(): void
    {
        $this->putFile('docker-compose.yml', "services:\n  web:\n    image: httpd:2.4\n    ports:\n      - \"8081:80\"\n");
        $this->putFile('.env', "A=1\n");

        $this->strategy($this->dir.'/docker-compose.yml')->apply(['compose_path' => ''], null, $this->dir, '1001:1001', 'src');

        $this->assertSame('httpd:2.4', $this->runFile()['services']['web']['image']);
        $this->assertNotContains('info: Running prepare commands', $this->logLines());
    }

    public function test_a_compose_file_that_is_not_yaml_is_refused(): void
    {
        $this->putFile('docker-compose.yml', "just some text\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The compose file in this project could not be read as YAML.');

        $this->strategy($this->dir.'/docker-compose.yml')->refreshRunFile($this->dir, '1001:1001');
    }

    public function test_an_override_that_is_not_yaml_is_refused(): void
    {
        $this->putFile('docker-compose.yml', "services:\n  app:\n    image: nginx\n");
        $this->putFile('docker-compose.override.yml', "just some text\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The compose override in this project (docker-compose.override.yml) could not be read as YAML.');

        $this->strategy($this->dir.'/docker-compose.yml')->refreshRunFile($this->dir, '1001:1001');
    }

    public function test_a_build_whose_dockerfile_exists_is_left_as_written(): void
    {
        $this->putFile('Dockerfile', "FROM nginx\n");
        $this->putFile('docker-compose.yml', "services:\n  app:\n    build: .\n    ports:\n      - \"8080:80\"\n");

        $this->strategy($this->dir.'/docker-compose.yml')->refreshRunFile($this->dir, '1001:1001');

        $this->assertSame('.', $this->runFile()['services']['app']['build']);
        $this->assertSame([], array_filter($this->logLines(), static fn (string $line): bool => str_contains($line, 'is missing; using Dockerfile')));
    }

    public function test_a_host_network_service_naming_its_port_is_not_given_a_guessed_one(): void
    {
        $this->putFile('docker-compose.yml', "services:\n  app:\n    image: acme/app\n    network_mode: host\n    environment:\n      PORT: \"3000\"\n");

        $this->strategy($this->dir.'/docker-compose.yml')->refreshRunFile($this->dir, '1001:1001');

        $this->assertSame([], array_filter($this->logLines(), static fn (string $line): bool => str_contains($line, 'which is a guess')));
        $this->assertSame([], array_filter($this->logLines(), static fn (string $line): bool => str_contains($line, 'No service publishes a port;')));
    }

    public function test_blank_and_published_secrets_and_the_app_address_are_filled_and_reported(): void
    {
        $this->putFile('docker-compose.yml', <<<'YAML'
            services:
              docmost:
                image: docmost/docmost:latest
                environment:
                  APP_URL: 'http://localhost:3000'
                  APP_SECRET: 'REPLACE_WITH_LONG_SECRET'
                ports:
                  - "3000:3000"
              planka:
                image: ghcr.io/plankanban/planka
                environment:
                  SECRET_KEY: notsecretkey
            YAML);

        $this->strategy($this->dir.'/docker-compose.yml')->refreshRunFile($this->dir, '1001:1001');

        $lines = $this->logLines();
        $this->assertContains('info: Replaced the published placeholder in SECRET_KEY with a generated secret', $lines);
        $this->assertContains('info: This project ships its compose file with the passwords left blank. Generated them for this account: APP_SECRET', $lines);
        $this->assertContains('info: Pointed the application address at its public URL: APP_URL', $lines);
        $environment = $this->runFile()['services']['docmost']['environment'];
        $this->assertSame('https://acme.example.test', $environment['APP_URL']);
        $this->assertNotSame('REPLACE_WITH_LONG_SECRET', $environment['APP_SECRET']);
        $this->assertNotSame('notsecretkey', $this->runFile()['services']['planka']['environment']['SECRET_KEY']);
    }

    public function test_an_override_beside_a_replacing_app_config_compose_is_layered_without_git_history(): void
    {
        $this->putFile(EngineArtifacts::APP_CONFIG_COMPOSE, "services:\n  app:\n    image: nginx\n");
        $this->putFile('docker-compose.override.yml', "services:\n  app:\n    environment:\n      MODE: recipe\n");

        $this->strategy($this->dir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE)->refreshRunFile($this->dir, '1001:1001');

        $copy = $this->eventsStartingWith("write {$this->dir}/".EngineArtifacts::RUN_CLIENT_OVERRIDE);
        $this->assertCount(1, $copy);
        $this->assertStringContainsString('MODE: recipe', $copy[0]);
    }

    public function test_engine_files_are_kept_out_of_nothing_when_there_is_no_run_file(): void
    {
        $this->strategy(null)->keepEngineFilesOutOfContext($this->dir, '1001:1001');

        $this->assertSame([], $this->events);
    }

    private function strategy(?string $composeSource): UserComposeStrategy
    {
        return new UserComposeStrategy($this->harnessDind(
            ['UID' => 1001, 'GID' => 1001, 'memory_limit' => 1024],
            null,
            null,
            null,
            null,
            'https://acme.example.test',
            $composeSource
        ));
    }

    /** @return list<string> */
    private function eventsStartingWith(string $prefix): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (string $event): bool => str_starts_with((string) preg_replace('/^\[\d+ log lines\] /', '', $event), $prefix)
        ));
    }

    /** @return array<string, mixed> */
    private function runFile(): array
    {
        $writes = $this->eventsStartingWith("write {$this->dir}/docker-compose.panelalpha.yml ");
        $this->assertNotSame([], $writes, 'the run file was written');
        $event = (string) end($writes);

        return Yaml::parse(substr($event, strpos($event, "\n") + 1));
    }

    /** @return list<string> */
    private function logLines(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['level'].': '.$entry['msg'],
            $this->logger?->entries() ?? []
        );
    }
}
