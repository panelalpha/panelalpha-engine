<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\RemoteSyncStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\ResolveSourceStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\VerifySourcePluginStep;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One job run finds the source once, stores what it found, and logs into
 * wp-admin at most once; manual_plugin never logs in at all.
 */
class WordpressMigrationSourceConnectionTest extends WordpressMigrationTestCase
{
    private const TOKEN = 'a3f1c2d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function test_each_method_has_its_own_source_plugin_step_after_resolving_the_source(): void
    {
        $wpAdmin = Migration::stepNames(WordpressMigration::METHOD_WP_ADMIN);
        $manual = Migration::stepNames(WordpressMigration::METHOD_MANUAL_PLUGIN);

        $this->assertSame(WordpressMigration::STEP_RESOLVE_SOURCE, $wpAdmin[0]);
        $this->assertSame(WordpressMigration::STEP_RESOLVE_SOURCE, $manual[0]);
        $this->assertContains(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, $wpAdmin);
        $this->assertNotContains(WordpressMigration::STEP_VERIFY_SOURCE_PLUGIN, $wpAdmin);
        $this->assertContains(WordpressMigration::STEP_VERIFY_SOURCE_PLUGIN, $manual);
        $this->assertNotContains(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, $manual);
        $this->assertSame(array_search(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, $wpAdmin, true), array_search(WordpressMigration::STEP_VERIFY_SOURCE_PLUGIN, $manual, true));
    }

    public function test_resolve_stores_the_home_login_url_and_rest_mode_and_the_run_logs_in_once(): void
    {
        $http = $this->wordpressAt('https://example.com', 'https://example.com/secret-login')
            ->on('POST', 'https://example.com/wp-json/wp-migrator/v1/remote-sync', new SourceHttpResponse('{"success":true,"migration_id":"m1"}', 200));
        $source = new SourceConnection(fn () => $http);
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com/secret-login',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject('example.com', null),
            loginUrl: 'https://example.com/secret-login',
        );
        $migration->storeMigratorToken('dest-token');

        (new ResolveSourceStep($source))->run($migration);
        $source->admin($migration);
        (new RemoteSyncStep($source))->run($migration->fresh());

        $migration->refresh();
        $this->assertSame('https://example.com', $migration->source_url);
        $this->assertSame('https://example.com/secret-login', $migration->login_url);
        $this->assertSame(SourceSite::REST_WP_JSON, $migration->rest_mode);
        $this->assertSame('m1', $migration->plugin_migration_id);
        $this->assertSame(1, $http->count('POST', 'https://example.com/secret-login'));
    }

    public function test_a_later_run_reads_the_stored_site_and_logs_in_only_for_wp_admin_work(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('POST', 'https://example.com/secret-login', new SourceHttpResponse('ok', 200))
            ->on('GET', 'https://example.com/wp-admin', RoutedSourceHttpClient::admin())
            ->on('POST', 'https://example.com/wp-json/wp-migrator/v1/remote-sync', new SourceHttpResponse('{"success":true,"migration_id":"m2"}', 200));
        $migration = $this->resolvedWpAdminMigration();
        $source = new SourceConnection(fn () => $http);

        (new RemoteSyncStep($source))->run($migration);
        $this->assertSame(0, $http->count('POST', 'https://example.com/secret-login'));

        $source->admin($migration);
        $source->admin($migration);
        $this->assertSame(1, $http->count('POST', 'https://example.com/secret-login'));
        $this->assertSame(0, $http->count('GET', 'https://example.com/secret-login'));
    }

    public function test_manual_plugin_resolves_and_verifies_without_wp_admin(): void
    {
        $http = $this->siteWithPlugin(RoutedSourceHttpClient::restError(404, 'not_found'));
        $source = new SourceConnection(fn () => $http);
        $migration = $this->manualMigration();

        (new ResolveSourceStep($source))->run($migration);
        (new VerifySourcePluginStep($source))->run($migration->fresh());

        $migration->refresh();
        $this->assertSame('https://example.com', $migration->source_url);
        $this->assertNull($migration->login_url);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $this->stepStatus($migration, WordpressMigration::STEP_VERIFY_SOURCE_PLUGIN));
        $this->assertFalse($http->count('POST', 'https://example.com/wp-login.php') > 0);
        $this->assertSame(self::TOKEN, end($http->calls)['headers']['X-Migrator-Token'] ?? null);
    }

    public function test_verify_fails_on_a_refused_token_and_keeps_the_step_pending(): void
    {
        $http = $this->siteWithPlugin(RoutedSourceHttpClient::restError(403, 'rest_forbidden'));
        $migration = $this->manualMigration();
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);

        try {
            (new VerifySourcePluginStep(new SourceConnection(fn () => $http)))->run($migration);
            $this->fail('Expected the token to be refused');
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::UNAUTHORIZED, $e->errorType());
        }

        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $this->stepStatus($migration->fresh(), WordpressMigration::STEP_VERIFY_SOURCE_PLUGIN));
    }

    public function test_manual_plugin_has_no_wp_admin_access(): void
    {
        $migration = $this->manualMigration();

        $this->expectException(RuntimeException::class);
        (new SourceConnection(fn () => new RoutedSourceHttpClient()))->admin($migration);
    }

    public function test_manual_plugin_record_keeps_the_token_encrypted_and_tells_the_user_to_remove_the_plugin(): void
    {
        $migration = $this->manualMigration();

        $raw = DB::table('wordpress_migrations')->where('id', $migration->id)->value('source_token');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, $migration->fresh()->secretsForWorker()['source_token']);

        $status = $migration->fresh()->toStatusArray();
        $this->assertSame(WordpressMigration::METHOD_MANUAL_PLUGIN, $status['method']);
        $this->assertNull($status['username']);
        $this->assertCount(1, $status['notices']);
        $this->assertStringContainsString('Remove the wordpress-migrator plugin', $status['notices'][0]);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($status));

        $migration->clearSecrets();
        $this->assertNull($migration->fresh()->source_token);
    }

    private function resolvedWpAdminMigration(): WordpressMigration
    {
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject('example.com', null),
        );
        $migration->storeMigratorToken('dest-token');
        $migration->storeResolvedSource('https://example.com', 'https://example.com/secret-login', SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }

    private function manualMigration(): WordpressMigration
    {
        return WordpressMigration::start(
            sourceUrl: 'https://example.com',
            target: MigrationTarget::newProject('example.com', null),
            method: WordpressMigration::METHOD_MANUAL_PLUGIN,
            sourceToken: self::TOKEN,
        );
    }

    private function wordpressAt(string $home, string $loginUrl): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient())
            ->on('GET', $loginUrl, RoutedSourceHttpClient::loginPage($home, $loginUrl))
            ->on('GET', $home . '/wp-json/', RoutedSourceHttpClient::restIndex($home))
            ->on('POST', $loginUrl, new SourceHttpResponse('ok', 200))
            ->on('GET', $home . '/wp-admin', RoutedSourceHttpClient::admin());
    }

    private function siteWithPlugin(SourceHttpResponse $info): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com', ['wp/v2', 'wp-migrator/v1']))
            ->on('GET', 'https://example.com/wp-json/wp-migrator/v1/info?migration_id=panelalpha-token-check', $info);
    }

    private function stepStatus(WordpressMigration $migration, string $name): ?string
    {
        return collect($migration->steps)->firstWhere('name', $name)['status'] ?? null;
    }
}
