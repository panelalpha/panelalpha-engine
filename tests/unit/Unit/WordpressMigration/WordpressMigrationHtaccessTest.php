<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\MigrationTransfer;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Queue;

/**
 * Different HTTP codes before and after .htaccess remove the file and leave a warning.
 */
class WordpressMigrationHtaccessTest extends WordpressMigrationTestCase
{
    public function test_http_status_change_removes_htaccess_and_warns(): void
    {
        Queue::fake();

        $http = new FakeSourceHttpClient();
        // migration status → completed
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => [
                    'status' => 'completed',
                    'phase' => 'files',
                    'migration_id' => 'mig-1',
                ],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        // transfer-htaccess success
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'transferred' => true,
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $client = new SourceWordpressClient($http);
        $client->useSite(new SourceSite('https://example.com'));
        $client->useSourceToken(self::SOURCE_TOKEN);

        $removed = [];
        $statuses = [200, 500];
        $transfer = new MigrationTransfer(
            $client,
            static function () use (&$statuses): int {
                return array_shift($statuses) ?? 500;
            },
            static function (string $url) use (&$removed): void {
                $removed[] = $url;
            },
        );

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'pw',
        );
        $migration->target_label = 'examplecom-abcd.panelalpha.online';
        $migration->storePluginMigrationId('mig-1');
        $migration->save();

        $transfer->finalize(
            $migration,
            'mig-1',
            'https://examplecom-abcd.panelalpha.online',
        );

        $this->assertSame(['https://examplecom-abcd.panelalpha.online'], $removed);

        $migration->refresh();
        $logJson = json_encode($migration->step_log, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Skipped keeping transferred .htaccess', $logJson);
        $this->assertStringContainsString('200', $logJson);
        $this->assertStringContainsString('500', $logJson);
    }

    public function test_same_http_status_keeps_htaccess(): void
    {
        Queue::fake();

        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => ['status' => 'completed', 'phase' => 'files'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode(['success' => true, 'transferred' => true], JSON_THROW_ON_ERROR),
            200,
        ));

        $client = new SourceWordpressClient($http);
        $client->useSite(new SourceSite('https://example.com'));
        $client->useSourceToken(self::SOURCE_TOKEN);

        $removed = [];
        $transfer = new MigrationTransfer(
            $client,
            static fn (): int => 200,
            static function () use (&$removed): void {
                $removed[] = true;
            },
        );

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'pw',
        );

        $transfer->finalize($migration, 'mig-1', 'https://examplecom-abcd.panelalpha.online');

        $this->assertSame([], $removed);
        $this->assertSame([], $migration->fresh()->step_log ?? []);
    }
}
