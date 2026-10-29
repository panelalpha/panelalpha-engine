<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\RemovePluginsStep;
use RuntimeException;

/**
 * A wp_admin import tries to remove the plugin from both sites before it
 * reports a failure, so one failing never keeps the other copy installed.
 */
class WordpressMigrationRemovePluginsTest extends WordpressMigrationTestCase
{
    public function test_the_source_plugin_is_removed_even_when_the_destination_removal_fails(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('POST', 'https://wp.com/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', 'https://wp.com/wp-admin', RoutedSourceHttpClient::admin());
        $migration = WordpressMigration::start('https://wp.com', 'admin', 'secret', MigrationTarget::newProject(null, null));
        $migration->storeResolvedSource('https://wp.com', 'https://wp.com/wp-login.php', SourceSite::REST_WP_JSON);

        try {
            (new RemovePluginsStep(new SourceConnection(fn () => $http)))->run($migration->fresh());
            $this->fail('Expected the destination removal to fail without a target');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('target_label is missing', $e->getMessage());
        }

        $this->assertSame(1, $http->count('POST', 'https://wp.com/wp-admin/plugins.php'));
    }

    public function test_the_destination_removal_is_tried_when_the_source_removal_fails(): void
    {
        $migration = WordpressMigration::start('https://wp.com', 'admin', 'secret', MigrationTarget::newProject(null, null));
        $migration->storeResolvedSource('https://wp.com', 'https://wp.com/wp-login.php', SourceSite::REST_WP_JSON);

        try {
            (new RemovePluginsStep(new SourceConnection(fn () => new RoutedSourceHttpClient())))->run($migration->fresh());
            $this->fail('Expected both removals to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('login failed', $e->getMessage());
            $this->assertStringContainsString('target_label is missing', $e->getMessage());
        }

        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_REMOVE_PLUGINS));
    }
}
