<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationProjectCreator;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\PreviewTunnel;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourcePluginOnFailure;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\CreateProjectStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallWordpressStep;
use RuntimeException;

/**
 * Steps a resumed import runs again leave one project, one core and one
 * database; a wp_admin failure takes the plugin off the source and resume
 * puts it back.
 */
class WordpressMigrationRerunTest extends ImportTargetTestCase
{
    public function test_create_project_records_the_name_before_it_creates(): void
    {
        $migration = $this->started();
        $creator = $this->failingCreator();

        try {
            $this->createProject($creator)->run($migration);
            $this->fail('Expected the creation to fail');
        } catch (RuntimeException) {
        }

        $this->assertSame('wpcom', $migration->fresh()->target_project);
    }

    public function test_a_rerun_adopts_the_project_it_created(): void
    {
        $migration = $this->started();
        $migration->storeTargetProject('wpcom');
        $this->project('wpcom', 'wp.com');
        $creator = $this->failingCreator();

        $this->createProject($creator)->run($migration);

        $migration->refresh();
        $this->assertSame(0, $creator->calls);
        $this->assertSame('wpcom', $migration->target_project);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->stepStatus(WordpressMigration::STEP_CREATE_PROJECT));
    }

    public function test_a_project_of_that_name_on_another_domain_is_not_adopted(): void
    {
        $migration = $this->started();
        $migration->storeTargetProject('wpcom');
        $this->project('wpcom', 'someone-else.example');

        $this->expectException(RuntimeException::class);
        $this->createProject($this->failingCreator())->run($migration);
    }

    public function test_install_wordpress_run_twice_downloads_core_and_creates_the_database_once(): void
    {
        $this->project('wpcom', 'wp.com');
        $migration = $this->started();
        $installer = new RecordingInstaller();
        $step = new InstallWordpressStep($installer);

        $step->run($migration);
        $first = $migration->fresh();
        $step->run($first);

        $this->assertSame(1, $this->callsTo($installer->calls, 'downloadCore'));
        $this->assertSame(1, $this->callsTo($installer->calls, 'install'));
        $this->assertCount(1, $installer->databases);
        $this->assertSame([$first->created_database => $first->created_database_user], $installer->databases);
        $this->assertStringStartsWith($this->user('wpcom')->getMysqlPrefix() . 'wp', (string) $first->created_database);
        $this->assertSame(2, $this->callsTo($installer->calls, 'writeConfig'));
    }

    public function test_a_wp_admin_failure_after_the_plugin_reached_the_source_removes_it_and_restarts_the_transfer(): void
    {
        $migration = $this->atFiles();
        $migration->markStepFailed(WordpressMigration::STEP_MIGRATE_FILES);

        (new SourcePluginOnFailure(new SourceConnection(fn () => $this->loggedInSource())))
            ->afterFailure($migration, WordpressMigration::STEP_MIGRATE_FILES);

        $migration->refresh();
        foreach (SourcePluginOnFailure::PLUGIN_STEPS as $step) {
            $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->stepStatus($step));
        }
        $this->assertNull($migration->plugin_migration_id);
        $this->assertSame(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, $migration->nextIncompleteStep()['name']);
        $this->assertSame('secret', $migration->secretsForWorker()['password']);
    }

    public function test_a_plugin_that_cannot_be_removed_stays_and_resume_continues_from_the_failed_step(): void
    {
        $migration = $this->atFiles();
        $migration->markStepFailed(WordpressMigration::STEP_MIGRATE_FILES);

        (new SourcePluginOnFailure(new SourceConnection(fn () => new RoutedSourceHttpClient())))
            ->afterFailure($migration, WordpressMigration::STEP_MIGRATE_FILES);

        $migration->refresh();
        $this->assertSame(WordpressMigration::STEP_MIGRATE_FILES, $migration->nextIncompleteStep()['name']);
        $this->assertSame('mig-files', $migration->plugin_migration_id);
        $log = $migration->step_log;
        $this->assertStringContainsString('stays on the source site', (string) end($log)['message']);
    }

    public function test_a_manual_plugin_or_an_early_failure_leaves_the_source_alone(): void
    {
        $http = new RoutedSourceHttpClient();
        $cleanup = new SourcePluginOnFailure(new SourceConnection(fn () => $http));
        $early = $this->started();
        $manual = WordpressMigration::start(
            sourceUrl: 'https://wp.com',
            target: MigrationTarget::newProject('wp.com', null),
            method: WordpressMigration::METHOD_MANUAL_PLUGIN,
            sourceToken: str_repeat('ab', 32),
        );

        $cleanup->afterFailure($early, WordpressMigration::STEP_CREATE_PROJECT);
        $cleanup->afterFailure($manual, WordpressMigration::STEP_MIGRATE_FILES);

        $this->assertSame([], $http->calls);
    }

    private function started(): WordpressMigration
    {
        return WordpressMigration::start('https://wp.com', 'admin', 'secret', MigrationTarget::newProject('wp.com', null));
    }

    private function atFiles(): WordpressMigration
    {
        $migration = $this->started();
        foreach ($migration->steps as $step) {
            if ($step['name'] === WordpressMigration::STEP_MIGRATE_FILES) {
                break;
            }
            $migration->markStepCompleted($step['name']);
        }
        $migration->storePluginMigrationId('mig-files');
        $migration->storeResolvedSource('https://wp.com', 'https://wp.com/wp-login.php', SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }

    private function loggedInSource(): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient())
            ->on('POST', 'https://wp.com/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', 'https://wp.com/wp-admin', RoutedSourceHttpClient::admin());
    }

    private function createProject(MigrationProjectCreator $creator): CreateProjectStep
    {
        $noPreview = new PreviewTunnel(static fn () => throw new RuntimeException('Connect is down'));

        return new CreateProjectStep($creator, $noPreview, static fn (): bool => true);
    }

    private function failingCreator(): MigrationProjectCreator
    {
        return new class implements MigrationProjectCreator {
            public int $calls = 0;

            public function create(string $username, ?string $domain = null): string
            {
                $this->calls++;

                throw new RuntimeException("The username '{$username}' has already been taken.");
            }
        };
    }

    /**
     * @param list<array<int, mixed>> $calls
     */
    private function callsTo(array $calls, string $name): int
    {
        return count(array_filter($calls, static fn (array $call): bool => $call[0] === $name));
    }
}
