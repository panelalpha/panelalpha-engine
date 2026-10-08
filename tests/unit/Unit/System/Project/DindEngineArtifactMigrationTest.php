<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\Source\EngineArtifactMigration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ADR-0001, ticket 04 (W9) — migrating an account deployed by the previous
 * engine, against a real git repository so the "differs from HEAD" and
 * "restore from HEAD" steps are exercised for real.
 */
class DindEngineArtifactMigrationTest extends TestCase
{
    private string $tmpRoot;

    private string $homeRoot;

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = str_replace('\\', '/', sys_get_temp_dir()).'/pa-migrate-'.bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot.'/home';
        $this->checkout = $this->homeRoot.'/alice/project';
        mkdir($this->checkout, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_a_recipe_run_file_under_the_clients_name_is_moved_to_the_reserved_name(): void
    {
        $this->put('docker-compose.yml', '# '.GeneratedCompose::LABEL."\nservices:\n  app:\n    image: nginx\n");

        $report = $this->migration('express')->migrate();

        $this->assertFileDoesNotExist($this->checkout.'/docker-compose.yml');
        $this->assertFileExists($this->checkout.'/'.EngineArtifacts::RUN_COMPOSE);
        $this->assertNotSame([], $report);
    }

    public function test_a_repository_shipped_compose_file_is_never_mistaken_for_a_recipe_run_file(): void
    {
        $this->gitInit();
        $this->put('docker-compose.yml', "services:\n  app:\n    build: .\n");
        $this->commitAll();

        $this->migration(Strategies::COMPOSE)->migrate();

        $this->assertFileExists($this->checkout.'/docker-compose.yml');
    }

    public function test_a_stash_is_restored_when_nothing_took_its_place(): void
    {
        $this->put('compose.yaml.panelalpha-local', "services:\n  db:\n    image: postgres\n");

        $report = $this->migration('express')->migrate();

        $this->assertFileExists($this->checkout.'/compose.yaml');
        $this->assertFileDoesNotExist($this->checkout.'/compose.yaml.panelalpha-local');
        $this->assertNotSame([], $report);
    }

    public function test_a_stash_is_discarded_once_the_original_name_is_trusted_again_under_git(): void
    {
        $this->gitInit();
        $this->put('compose.yaml', "services:\n  db:\n    image: postgres:16\n");
        $this->commitAll();
        $this->put('compose.yaml.panelalpha-local', "services:\n  db:\n    image: postgres:15\n");

        $this->migration('express')->migrate();

        $this->assertFileExists($this->checkout.'/compose.yaml');
        $this->assertFileDoesNotExist($this->checkout.'/compose.yaml.panelalpha-local');
        $this->assertStringContainsString('postgres:16', (string) file_get_contents($this->checkout.'/compose.yaml'));
    }

    public function test_a_stash_is_kept_alongside_the_original_when_there_is_no_git_to_trust(): void
    {
        $this->put('compose.yaml', "services:\n  db:\n    image: postgres:15\n");
        $this->put('compose.yaml.panelalpha-local', "services:\n  db:\n    image: postgres:14\n");

        $this->migration('express')->migrate();

        $this->assertFileExists($this->checkout.'/compose.yaml');
        $this->assertFileExists($this->checkout.'/compose.yaml.panelalpha-local');
    }

    public function test_the_normalized_client_compose_becomes_the_run_file_and_the_source_is_restored_from_git(): void
    {
        $this->gitInit();
        $this->put('docker-compose.yml', "services:\n  app:\n    build: .\n    mem_limit: 128m\n");
        $this->commitAll();
        // The previous engine hardened the client's file in place.
        $this->put('docker-compose.yml', "services:\n  app:\n    build: .\n    mem_limit: 512m\n    restart: unless-stopped\n");

        $report = $this->migration(Strategies::COMPOSE)->migrate();

        $this->assertStringContainsString(
            'mem_limit: 512m',
            (string) file_get_contents($this->checkout.'/'.EngineArtifacts::RUN_COMPOSE)
        );
        $this->assertStringContainsString(
            'mem_limit: 128m',
            (string) file_get_contents($this->checkout.'/docker-compose.yml')
        );
        $this->assertNotSame([], $report);
    }

    /**
     * ADR-0001 #06: an app config in `replace` mode had already overwritten
     * the client's compose file with its own before the previous engine ran.
     * The migration must keep that content under the app config's reserved
     * name before git restores the client's original — otherwise the next
     * `up` (ticket 05) would regenerate the run file from the client's
     * compose instead of the app config's.
     */
    public function test_an_app_config_replace_mode_compose_is_kept_under_its_reserved_name_before_restoring_the_client(): void
    {
        $this->gitInit();
        $this->put('docker-compose.yml', "services:\n  app:\n    build: .\n    mem_limit: 128m\n");
        $this->commitAll();
        // The app config's `replace` mode had written over the client's file.
        $this->put('docker-compose.yml', "services:\n  app:\n    image: from-app-config\n");

        $report = $this->migration(Strategies::COMPOSE, AppConfig::COMPOSE_REPLACE)->migrate();

        $this->assertStringContainsString(
            'from-app-config',
            (string) file_get_contents($this->checkout.'/'.EngineArtifacts::RUN_COMPOSE)
        );
        $this->assertStringContainsString(
            'from-app-config',
            (string) file_get_contents($this->checkout.'/'.EngineArtifacts::APP_CONFIG_COMPOSE)
        );
        $this->assertStringContainsString(
            'mem_limit: 128m',
            (string) file_get_contents($this->checkout.'/docker-compose.yml')
        );
        $this->assertNotSame([], $report);
    }

    /**
     * Idempotency, replace-mode case: once the run file exists, a second
     * migration is a full no-op (the outer `is_file($run)` guard in
     * {@see EngineArtifactMigration}), so it
     * must not touch the reserved app-config compose file either.
     */
    public function test_an_existing_app_config_compose_file_is_not_overwritten_on_a_second_migration(): void
    {
        $this->gitInit();
        $this->put('docker-compose.yml', "services:\n  app:\n    build: .\n");
        $this->commitAll();
        $this->put('docker-compose.yml', "services:\n  app:\n    image: from-app-config\n");

        $this->migration(Strategies::COMPOSE, AppConfig::COMPOSE_REPLACE)->migrate();
        $this->put(EngineArtifacts::APP_CONFIG_COMPOSE, "services:\n  app:\n    image: edited-since\n");

        $second = $this->migration(Strategies::COMPOSE, AppConfig::COMPOSE_REPLACE)->migrate();

        $this->assertStringContainsString(
            'edited-since',
            (string) file_get_contents($this->checkout.'/'.EngineArtifacts::APP_CONFIG_COMPOSE)
        );
        $this->assertSame([], $second);
    }

    public function test_a_recipe_strategy_never_produces_a_normalized_client_compose(): void
    {
        $this->put('docker-compose.yml', "services:\n  app:\n    build: .\n");

        $this->migration('express')->migrate();

        $this->assertFileDoesNotExist($this->checkout.'/'.EngineArtifacts::RUN_COMPOSE);
    }

    public function test_an_app_config_override_written_under_the_clients_name_is_moved_to_the_reserved_override(): void
    {
        $this->put(Paths::CLIENT_OVERRIDE_FILENAME, "services:\n  app:\n    volumes: ['./x:/x']\n");

        $report = $this->migration('express', AppConfig::COMPOSE_OVERRIDE)->migrate();

        $this->assertFileDoesNotExist($this->checkout.'/'.Paths::CLIENT_OVERRIDE_FILENAME);
        $this->assertFileExists($this->checkout.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);
        $this->assertNotSame([], $report);
    }

    public function test_a_client_override_is_left_alone_without_an_app_config_in_override_mode(): void
    {
        $this->put(Paths::CLIENT_OVERRIDE_FILENAME, "services:\n  app:\n    volumes: ['./x:/x']\n");

        $this->migration('express', null)->migrate();

        $this->assertFileExists($this->checkout.'/'.Paths::CLIENT_OVERRIDE_FILENAME);
        $this->assertFileDoesNotExist($this->checkout.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);
    }

    public function test_composer_json_is_restored_when_only_the_platform_pin_changed(): void
    {
        $this->gitInit();
        $this->put('composer.json', "{\n    \"require\": {\"php\": \"^8.1\"}\n}\n");
        $this->commitAll();
        $this->put('composer.json', "{\n    \"require\": {\"php\": \"^8.1\"},\n    \"config\": {\"platform\": {\"php\": \"8.1.99\"}}\n}\n");

        $report = $this->migration('express')->migrate();

        $this->assertJsonStringEqualsJsonString(
            "{\n    \"require\": {\"php\": \"^8.1\"}\n}\n",
            (string) file_get_contents($this->checkout.'/composer.json')
        );
        $this->assertNotSame([], $report);
    }

    public function test_composer_json_is_left_alone_when_more_than_the_platform_pin_changed(): void
    {
        $this->gitInit();
        $this->put('composer.json', "{\n    \"require\": {\"php\": \"^8.1\"}\n}\n");
        $this->commitAll();
        $edited = "{\n    \"require\": {\"php\": \"^8.1\", \"guzzlehttp/guzzle\": \"^7.0\"},\n"
            ."    \"config\": {\"platform\": {\"php\": \"8.1.99\"}}\n}\n";
        $this->put('composer.json', $edited);

        $this->migration('express')->migrate();

        $this->assertJsonStringEqualsJsonString($edited, (string) file_get_contents($this->checkout.'/composer.json'));
    }

    public function test_migration_is_idempotent(): void
    {
        $this->gitInit();
        $this->put('docker-compose.yml', "services:\n  app:\n    build: .\n    mem_limit: 128m\n");
        $this->commitAll();
        $this->put('docker-compose.yml', "services:\n  app:\n    build: .\n    mem_limit: 512m\n");

        $this->migration(Strategies::COMPOSE)->migrate();
        $second = $this->migration(Strategies::COMPOSE)->migrate();

        $this->assertSame([], $second);
    }

    private function migration(string $strategy, ?string $appConfigComposeMode = null): EngineArtifactMigration
    {
        $git = new TestableGitRepository($this->dind($strategy), function (array $command): string {
            return $this->shell($command);
        });

        return new EngineArtifactMigration(
            $git,
            $this->checkout,
            $strategy,
            $appConfigComposeMode,
            static function (string $from, string $to): void {
                rename($from, $to);
            },
            static function (string $from, string $to): void {
                copy($from, $to);
            },
            static function (string $path): void {
                unlink($path);
            },
        );
    }

    private function gitInit(): void
    {
        $this->shell(['git', 'init', '-q', $this->checkout]);
    }

    private function commitAll(): void
    {
        $this->git('add', '-A');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '-m', 'init');
    }

    private function git(string ...$args): string
    {
        return $this->shell(['git', '-C', $this->checkout, ...$args]);
    }

    /**
     * @param  list<string>  $command
     */
    private function shell(array $command): string
    {
        $process = new Process($command);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput() ?: $process->getOutput());
        }

        return $process->getOutput();
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->checkout.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function dind(string $strategy): Dind
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'deploy_strategy' => $strategy,
            'UID' => 1000,
            'GID' => 1000,
        ]);

        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function system(): System
    {
        return new class($this->tmpRoot, $this->homeRoot) extends System
        {
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
            ) {}

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->homesRoot;
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            // git marks its objects read-only, which Windows refuses to unlink.
            @chmod($item->getPathname(), 0777);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
