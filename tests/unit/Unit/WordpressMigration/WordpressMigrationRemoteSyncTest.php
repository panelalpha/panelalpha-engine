<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\RemoteSyncStep;
use RuntimeException;

/**
 * remote-sync completes the step only when the plugin reports success=true
 * and a migration id. success=false must not store that id. The source site is
 * read back from the record, with no wp-admin login.
 */
class WordpressMigrationRemoteSyncTest extends WordpressMigrationTestCase
{
    public function test_success_false_does_not_complete_the_step(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => false,
                'migration_id' => 'mig-should-not-stick',
                'message' => 'destination refused the token',
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $migration = $this->migrationReadyForRemoteSync();

        try {
            (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration);
            $this->fail('Expected remote-sync failure');
        } catch (RuntimeException $e) {
            $this->assertSame('destination refused the token', $e->getMessage());
        }

        $migration->refresh();
        $step = collect($migration->steps)->firstWhere('name', WordpressMigration::STEP_REMOTE_SYNC);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $step['status']);
        $this->assertNull($migration->plugin_migration_id);
    }

    public function test_success_true_stores_the_plugin_migration_id(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'migration_id' => 'mig-ok',
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $migration = $this->migrationReadyForRemoteSync();
        (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration);

        $migration->refresh();
        $step = collect($migration->steps)->firstWhere('name', WordpressMigration::STEP_REMOTE_SYNC);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $step['status']);
        $this->assertSame('mig-ok', $migration->plugin_migration_id);
        $this->assertCount(1, $http->calls);
        $this->assertSame('https://example.com/wp-json/wp-migrator/v1/remote-sync', $http->calls[0]['url']);
    }

    public function test_the_kept_domain_is_sent_with_the_token_and_pinned_to_the_engine(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode(['success' => true, 'migration_id' => 'mig-ok'], JSON_THROW_ON_ERROR),
            200,
        ));
        $token = str_repeat('cd', 32);
        $migration = WordpressMigration::start(
            sourceUrl: 'https://www.example.com/blog',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject('example.com', null),
        );
        $migration->storeMigratorToken($token);
        $migration->storeResolvedSource('https://www.example.com/blog', null, SourceSite::REST_WP_JSON);

        (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration->fresh());

        $body = json_decode((string) end($http->calls)['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://example.com/blog?token=' . $token, $body['destination_url']);
        $this->assertSame(self::ENGINE_IPV4, $body['ip']);
        $this->assertStringNotContainsString($token, json_encode($migration->fresh()->step_log, JSON_THROW_ON_ERROR));
    }

    private function migrationReadyForRemoteSync(): WordpressMigration
    {
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject('examplecom-ab.panelalpha.online', null),
        );
        $migration->storeMigratorToken('tok-1');
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }
}
