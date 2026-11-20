<?php

namespace Tests\Integration\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationCancellation;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourcePluginOnFailure;
use App\System\Project\PhpHosting\WordPress\Migration\SourcePluginTokenCheck;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallSourcePluginStep;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use App\System\Project\PhpHosting\WordPress\Migration\WpAdminHtml;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;
use Tests\Support\RoutedSourceHttpClient;

/**
 * wp_admin installs the source plugin with a token of the import's own, never
 * the one the release ships with, stores it encrypted, and proves the plugin
 * on the source accepts it. A release before 1.2.0 that answers its transfer
 * routes without the token is a warning; a later one doing so stops the import.
 * A copy the source already had is overwritten, never deleted, and stays on
 * the source when the import fails or is cancelled.
 */
class WordpressMigrationInstallSourcePluginTest extends WordpressMigrationTestCase
{
    private const RELEASE_TOKEN = '77d58fa4553cae2431eb7c310eba82b306ecf39ebd184e484a8aac8e404b1d63';

    private const HOME = 'https://example.com';

    private const INFO = self::HOME.'/wp-json/wp-migrator/v1/info?migration_id=panelalpha-token-check';

    private const UPLOAD = self::HOME.'/wp-admin/update.php?action=upload-plugin&overwrite=update-plugin';

    private const DELETE = self::HOME.'/wp-admin/admin-ajax.php';

    private string $pluginState = 'none';

    private ?string $installedToken = null;

    private bool $overwriteHonoured = true;

    private bool $uploadRefused = false;

    private bool $uploadBroken = false;

    private bool $activationRefused = false;

    private bool $openTransferRoutes = false;

    private string $releaseVersion = '1.2.0';

    /** The version wp-admin prints for the installed plugin; null means the version the zip carried. */
    private ?string $shownVersion = null;

    /** The word wp-admin prints before the version; another language changes it. */
    private string $versionLabel = 'Version';

    private string $newerSlugState = 'none';

    private bool $newerSlugStays = false;

    /** Whether the upload also leaves a panelalpha-migrator copy listed, as a release from 1.2.1 on would. */
    private bool $uploadAlsoInstallsNewer = false;

    /** @var list<string> */
    private array $packagedTokens = [];

    /** @var list<string> */
    private array $packages = [];

    public function test_the_source_plugin_carries_a_token_of_its_own_stored_encrypted_on_the_record(): void
    {
        $http = $this->source();
        $migration = $this->migration();

        $this->step($http)->run($migration);

        $token = $migration->fresh()->secretsForWorker()['source_token'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $token);
        $this->assertNotSame(self::RELEASE_TOKEN, $token);
        $this->assertSame([$token], $this->packagedTokens);
        $this->assertSame($token, $this->installedToken);
        $this->assertNotSame($token, DB::table('wordpress_migrations')->where('id', $migration->id)->value('source_token'));
        $this->assertSame($token, $this->lastCallTo($http, self::INFO)['headers']['X-Migrator-Token'] ?? null);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
        $this->assertStringNotContainsString((string) $token, (string) json_encode($migration->fresh()->toStatusArray()));
        foreach ($this->packages as $package) {
            $this->assertFileDoesNotExist($package);
        }
        $this->assertSame([], $migration->fresh()->toStatusArray()['warnings']);
    }

    public function test_a_rerun_installs_the_plugin_with_the_same_token(): void
    {
        $http = $this->source();
        $migration = $this->migration();

        $this->step($http)->run($migration);
        $this->step($http)->run($migration->fresh());

        $this->assertCount(2, $this->packagedTokens);
        $this->assertSame($this->packagedTokens[0], $this->packagedTokens[1]);
        $this->assertSame($this->packagedTokens[0], $migration->fresh()->secretsForWorker()['source_token']);
    }

    public function test_a_copy_already_on_the_source_is_replaced_by_the_one_carrying_the_imports_token(): void
    {
        $this->pluginState = 'active';
        $this->installedToken = self::RELEASE_TOKEN;
        $http = $this->source();
        $migration = $this->migration();

        $this->step($http)->run($migration);

        $this->assertSame(1, $http->count('POST', self::UPLOAD));
        $this->assertSame(0, $http->count('POST', self::DELETE), 'the copy is overwritten, never deleted first');
        $this->assertSame('active', $this->pluginState);
        $this->assertSame($migration->fresh()->secretsForWorker()['source_token'], $this->installedToken);
        $this->assertTrue($migration->fresh()->source_plugin_preinstalled);
    }

    public function test_a_plugin_the_source_did_not_have_is_recorded_as_the_imports_own_and_stays_so(): void
    {
        $http = $this->source();
        $migration = $this->migration();

        $this->step($http)->run($migration);
        $this->step($http)->run($migration->fresh());

        $this->assertFalse($migration->fresh()->source_plugin_preinstalled, 'a rerun finds the import\'s own copy');
    }

    public function test_a_copy_already_there_survives_an_upload_that_fails(): void
    {
        $this->pluginState = 'active';
        $this->installedToken = self::RELEASE_TOKEN;
        $this->uploadBroken = true;
        $http = $this->source();
        $migration = $this->migration();

        $this->failAt(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, fn () => $this->step($http)->run($migration), $migration, $http);

        $this->assertOperatorsCopyKept($http);
    }

    public function test_a_copy_already_there_survives_a_run_that_fails_later(): void
    {
        $this->pluginState = 'inactive';
        $this->installedToken = self::RELEASE_TOKEN;
        $http = $this->source();
        $migration = $this->migration();
        $this->step($http)->run($migration);

        $this->failAt(WordpressMigration::STEP_MIGRATE_FILES, static fn () => throw new RuntimeException('transfer broke'), $migration->fresh(), $http);

        $this->assertSame(0, $http->count('POST', self::DELETE));
        $this->assertSame('active', $this->pluginState);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN), 'resume goes on with the plugin still there');
    }

    public function test_a_plugin_the_import_installed_is_removed_when_the_run_fails_later(): void
    {
        $http = $this->source();
        $migration = $this->migration();
        $this->step($http)->run($migration);

        $this->failAt(WordpressMigration::STEP_MIGRATE_FILES, static fn () => throw new RuntimeException('transfer broke'), $migration->fresh(), $http);

        $this->assertSame(1, $http->count('POST', self::DELETE));
        $this->assertSame('none', $this->pluginState);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN), 'resume installs it again');
    }

    public function test_a_plugin_the_import_uploaded_is_removed_when_its_activation_fails(): void
    {
        $this->activationRefused = true;
        $http = $this->source();
        $migration = $this->migration();

        $this->failAt(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, fn () => $this->step($http)->run($migration), $migration, $http);

        $this->assertSame(1, $http->count('POST', self::DELETE));
        $this->assertSame('none', $this->pluginState);
        $this->assertStringContainsString('Removed the migrator plugin', implode(' ', array_column($migration->fresh()->step_log, 'message')));
    }

    public function test_a_copy_already_there_is_kept_when_the_activation_fails(): void
    {
        $this->pluginState = 'inactive';
        $this->installedToken = self::RELEASE_TOKEN;
        $this->activationRefused = true;
        $http = $this->source();
        $migration = $this->migration();

        $this->failAt(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, fn () => $this->step($http)->run($migration), $migration, $http);

        $this->assertSame(0, $http->count('POST', self::DELETE));
        $this->assertSame('inactive', $this->pluginState);
    }

    public function test_an_upload_that_placed_nothing_fails_once_and_leaves_the_steps_as_they_were(): void
    {
        $this->uploadRefused = true;
        $http = $this->source();
        $migration = $this->migration();

        $this->failAt(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, fn () => $this->step($http)->run($migration), $migration, $http);

        $this->assertFalse($migration->fresh()->source_plugin_preinstalled);
        $this->assertSame(0, $http->count('POST', self::DELETE));
        $this->assertSame(WordpressMigration::STEP_STATUS_FAILED, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
        $this->assertStringNotContainsString('Removed the migrator plugin', implode(' ', array_column($migration->fresh()->step_log, 'message')));
    }

    public function test_a_cancel_leaves_a_copy_the_source_already_had(): void
    {
        $this->pluginState = 'active';
        $this->installedToken = self::RELEASE_TOKEN;
        $this->uploadBroken = true;
        $http = $this->source();
        $migration = $this->migration();
        $this->failAt(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, fn () => $this->step($http)->run($migration), $migration, $http);

        $this->cancel($migration->fresh(), $http);

        $this->assertOperatorsCopyKept($http);
        $this->assertSame(WordpressMigration::STATUS_CANCELLED, $migration->fresh()->status);
    }

    public function test_a_cancel_removes_the_plugin_the_import_installed(): void
    {
        $http = $this->source();
        $migration = $this->migration();
        $this->step($http)->run($migration);

        $this->cancel($migration->fresh(), $http);

        $this->assertSame(1, $http->count('POST', self::DELETE));
        $this->assertSame('none', $this->pluginState);
    }

    public function test_a_cancel_after_an_install_that_never_looked_leaves_the_source_alone(): void
    {
        $this->pluginState = 'active';
        $this->installedToken = self::RELEASE_TOKEN;
        $http = $this->source();
        $migration = $this->migration();
        $migration->markStepFailed(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN);

        $this->cancel($migration->fresh(), $http);

        $this->assertNull($migration->fresh()->source_plugin_preinstalled);
        $this->assertOperatorsCopyKept($http);
    }

    public function test_an_upload_wordpress_refuses_is_reported_in_its_own_words(): void
    {
        $this->uploadRefused = true;
        $migration = $this->migration();

        try {
            $this->step($this->source())->run($migration);
            $this->fail('Expected the plugin not to be installed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not leave wordpress-migrator installed', $e->getMessage());
            $this->assertStringContainsString(
                'Sorry, you are not allowed to install plugins on this site.',
                $e->getMessage(),
            );
        }

        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
    }

    public function test_a_plugin_that_refuses_the_imports_token_fails_the_step(): void
    {
        $this->shownVersion = '';
        $this->pluginState = 'active';
        $this->installedToken = self::RELEASE_TOKEN;
        $this->overwriteHonoured = false;
        $migration = $this->migration();

        try {
            $this->step($this->source())->run($migration);
            $this->fail('Expected the plugin to refuse the token');
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::UNAUTHORIZED, $e->errorType());
        }

        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
    }

    public function test_an_installed_version_other_than_the_pinned_one_stops_the_install(): void
    {
        $this->shownVersion = '1.1.4';
        $migration = $this->migration();

        try {
            $this->step($this->source())->run($migration);
            $this->fail('Expected the version mismatch to stop the install');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('An older or different copy of the migrator plugin is on the source site; remove it and try again', $e->getMessage());
            $this->assertStringContainsString('the source has version 1.1.4, this import installs 1.2.0', $e->getMessage());
        }

        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
    }

    public function test_the_pinned_version_shown_by_wp_admin_passes(): void
    {
        $this->shownVersion = '1.2.0';

        $this->step($this->source())->run($this->migration());

        $this->assertTrue(true);
    }

    public function test_a_version_shown_in_another_language_is_still_compared(): void
    {
        $this->versionLabel = 'Wersja';
        $this->shownVersion = '1.1.4';
        $migration = $this->migration();

        try {
            $this->step($this->source())->run($migration);
            $this->fail('Expected the version mismatch to stop the install');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('the source has version 1.1.4, this import installs 1.2.0', $e->getMessage());
        }

        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
    }

    public function test_the_pinned_version_shown_in_another_language_passes(): void
    {
        $this->versionLabel = 'Wersja';
        $this->shownVersion = '1.2.0';
        $migration = $this->migration();

        $this->step($this->source())->run($migration);

        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
    }

    public function test_a_version_wp_admin_does_not_show_goes_on_with_a_warning(): void
    {
        $this->shownVersion = '';
        $migration = $this->migration();

        $this->step($this->source())->run($migration);

        $status = $migration->fresh()->toStatusArray();
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
        $this->assertSame([InstallSourcePluginStep::VERSION_UNKNOWN_WARNING], array_column($status['warnings'], 'code'));
        $this->assertStringContainsString('could not check that it is 1.2.0', $status['warnings'][0]['message']);
    }

    public function test_a_rerun_that_reads_the_version_clears_the_warning(): void
    {
        $this->shownVersion = '';
        $migration = $this->migration();
        $this->step($this->source())->run($migration);
        $this->assertSame([InstallSourcePluginStep::VERSION_UNKNOWN_WARNING], array_column($migration->fresh()->toStatusArray()['warnings'], 'code'));

        $this->shownVersion = null;
        $this->step($this->source())->run($migration->fresh());

        $this->assertSame([], $migration->fresh()->toStatusArray()['warnings']);
    }

    public function test_the_version_row_is_read_from_real_wordpress_markup(): void
    {
        $page = '<html><body><table>'
            .'<tr class="inactive update" data-slug="wordpress-migrator" data-plugin="wordpress-migrator/wordpress-migrator.php">'
            .'<td><div class="inactive second plugin-version-author-uri">Version 1.1.4 | By <a href="#">PanelAlpha</a> | <a href="#">View details</a></div></td></tr>'
            .'<tr class="plugin-update-tr inactive" data-plugin="wordpress-migrator/wordpress-migrator.php">'
            .'<td colspan="3"><div class="update-message">There is a new version available.</div></td></tr>'
            .'<tr class="active" data-slug="other-plugin" data-plugin="other-plugin/other-plugin.php">'
            .'<td><div class="active second plugin-version-author-uri">Versión 1.2.1-beta | Por <a href="#">Someone</a></div></td></tr>'
            .'<tr class="inactive" data-slug="ten-up" data-plugin="ten-up/ten-up.php">'
            .'<td><div class="inactive second plugin-version-author-uri">By 10up | View details</div></td></tr>'
            .'</table></body></html>';

        $this->assertSame(
            ['wordpress-migrator' => '1.1.4', 'other-plugin' => '1.2.1-beta'],
            WpAdminHtml::pluginVersions($page),
        );
    }

    public function test_a_copy_under_the_newer_slug_is_removed_before_the_upload(): void
    {
        $this->newerSlugState = 'active';
        $http = $this->source();

        $this->step($http)->run($this->migration());

        $this->assertSame('none', $this->newerSlugState);
        $this->assertSame(1, $http->count('POST', self::UPLOAD));
    }

    public function test_a_copy_under_the_newer_slug_that_cannot_be_removed_stops_the_install(): void
    {
        $this->newerSlugState = 'inactive';
        $this->newerSlugStays = true;
        $migration = $this->migration();
        $http = $this->source();

        try {
            $this->step($http)->run($migration);
            $this->fail('Expected the newer copy to stop the install');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('The Engine removes it before the import', $e->getMessage());
            $this->assertStringContainsString('Remove panelalpha-migrator in wp-admin', $e->getMessage());
        }

        $this->assertSame(0, $http->count('POST', self::UPLOAD));
    }

    public function test_a_copy_under_the_newer_slug_listed_after_the_upload_stops_the_install(): void
    {
        $this->uploadAlsoInstallsNewer = true;
        $migration = $this->migration();

        try {
            $this->step($this->source())->run($migration);
            $this->fail('Expected the newer copy listed next to the pinned plugin to stop the install');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('next to wordpress-migrator', $e->getMessage());
            $this->assertStringContainsString('Remove panelalpha-migrator in wp-admin', $e->getMessage());
        }

        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
    }

    public function test_a_release_before_the_token_was_required_goes_on_with_a_warning(): void
    {
        $this->openTransferRoutes = true;
        $this->releaseVersion = '1.1.4';
        $migration = $this->migration();

        $this->step($this->source())->run($migration);

        $status = $migration->fresh()->toStatusArray();
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
        $this->assertSame([SourcePluginTokenCheck::WARNING], array_column($status['warnings'], 'code'));
        $this->assertStringContainsString('The Engine removes it as soon as the import ends, fails or is cancelled', $status['warnings'][0]['message']);
    }

    public function test_a_release_that_must_require_the_token_but_answers_without_it_fails_the_step(): void
    {
        $this->openTransferRoutes = true;
        $migration = $this->migration();

        try {
            $this->step($this->source())->run($migration);
            $this->fail('Expected the step to stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('answers its transfer routes without a token', $e->getMessage());
        }

        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN));
        $this->assertSame([], $migration->fresh()->toStatusArray()['warnings']);
    }

    private function step(RoutedSourceHttpClient $http): InstallSourcePluginStep
    {
        return new InstallSourcePluginStep(new SourceConnection(fn () => $http), $this->zip());
    }

    private function migration(): WordpressMigration
    {
        $migration = WordpressMigration::start(
            sourceUrl: self::HOME,
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject('example.com', null),
        );
        $migration->storeResolvedSource(self::HOME, self::HOME.'/wp-login.php', SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }

    private function source(): RoutedSourceHttpClient
    {
        $plugin = 'plugin=wordpress-migrator/wordpress-migrator.php';

        return (new RoutedSourceHttpClient)
            ->on('POST', self::HOME.'/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', self::HOME.'/wp-admin', RoutedSourceHttpClient::admin())
            ->on('POST', self::HOME.'/wp-admin/plugins.php', fn (): SourceHttpResponse => $this->pluginList())
            ->on('GET', self::HOME.'/wp-admin/plugin-install.php?tab=upload', new SourceHttpResponse('<input name="_wpnonce" value="up1">', 200))
            ->on('POST', self::UPLOAD, fn (): SourceHttpResponse => $this->uploadBroken ? throw new RuntimeException('Connection reset by peer') : $this->upload())
            ->on('POST', self::HOME.'/wp-admin/plugins.php?action=activate&'.$plugin.'&_wpnonce=a1', fn (): SourceHttpResponse => $this->moveTo($this->activationRefused ? $this->pluginState : 'active'))
            ->on('POST', self::HOME.'/wp-admin/plugins.php?action=deactivate&'.$plugin.'&_wpnonce=d1', fn (): SourceHttpResponse => $this->moveTo('inactive'))
            ->on('POST', self::DELETE, fn (array $headers, ?string $body): SourceHttpResponse => $this->deleteAnswer((string) $body))
            ->on('GET', self::INFO, fn (array $headers): SourceHttpResponse => ($headers['X-Migrator-Token'] ?? null) === $this->installedToken
                ? RoutedSourceHttpClient::restError(404, 'not_found')
                : RoutedSourceHttpClient::restError(401, 'rest_forbidden'))
            ->on('POST', self::HOME.'/wp-json/wp-migrator/v1/remote-sync', fn (): SourceHttpResponse => $this->transferRoute())
            ->on('POST', self::HOME.'/wp-json/wp-migrator/v1/remote-sync/process-batch', fn (): SourceHttpResponse => $this->transferRoute())
            ->on('GET', self::HOME.'/wp-json/wp-migrator/v1/remote-sync/status/panelalpha_token_check', fn (): SourceHttpResponse => $this->transferRoute())
            ->on('POST', self::HOME.'/wp-json/wp-migrator/v1/transfer-htaccess', fn (): SourceHttpResponse => $this->transferRoute());
    }

    private function transferRoute(): SourceHttpResponse
    {
        return $this->openTransferRoutes
            ? RoutedSourceHttpClient::restError(400, 'missing_destination')
            : RoutedSourceHttpClient::restError(401, 'rest_forbidden');
    }

    private function deleteAnswer(string $body): SourceHttpResponse
    {
        if (str_contains($body, 'slug=panelalpha-migrator')) {
            if (! $this->newerSlugStays) {
                $this->newerSlugState = 'none';
            }

            return new SourceHttpResponse('<html></html>', 200);
        }

        return $this->moveTo('none');
    }

    private function pluginList(): SourceHttpResponse
    {
        $plugin = 'plugin=wordpress-migrator%2Fwordpress-migrator.php';
        $link = match ($this->pluginState) {
            'active' => '<a href="plugins.php?action=deactivate&amp;'.$plugin.'&amp;_wpnonce=d1">Deactivate</a>',
            'inactive' => '<a href="plugins.php?action=activate&amp;'.$plugin.'&amp;_wpnonce=a1">Activate</a>',
            default => '',
        };

        $newer = 'plugin=panelalpha-migrator%2Fpanelalpha-migrator.php';
        $newerLink = match ($this->newerSlugState) {
            'active' => '<a href="plugins.php?action=deactivate&amp;'.$newer.'&amp;_wpnonce=d2">Deactivate</a>',
            'inactive' => '<a href="plugins.php?action=activate&amp;'.$newer.'&amp;_wpnonce=a2">Activate</a>',
            default => '',
        };
        $version = $this->shownVersion ?? $this->releaseVersion;
        $state = $this->pluginState === 'active' ? 'active' : 'inactive';
        $row = $this->pluginState === 'none' || $version === ''
            ? ''
            : '<table>'
                .'<tr class="'.$state.' update" data-slug="wordpress-migrator" data-plugin="wordpress-migrator/wordpress-migrator.php">'
                .'<td><div class="'.$state.' second plugin-version-author-uri">'.$this->versionLabel.' '.$version
                .' | By PanelAlpha | View details</div></td></tr>'
                .'<tr class="plugin-update-tr '.$state.'" data-plugin="wordpress-migrator/wordpress-migrator.php">'
                .'<td colspan="3"><div class="update-message">There is a new version available.</div></td></tr>'
                .'</table>';

        return new SourceHttpResponse('<html><script>var s={"_ajax_nonce":"n0nce42"};</script>'.$link.$newerLink.$row.'</html>', 200);
    }

    private function upload(): SourceHttpResponse
    {
        if ($this->uploadRefused) {
            return new SourceHttpResponse(
                '<div class="error"><p>Sorry, you are not allowed to install plugins on this site.</p></div>',
                200,
            );
        }

        if ($this->pluginState === 'none' || $this->overwriteHonoured) {
            $this->pluginState = $this->pluginState === 'none' ? 'inactive' : $this->pluginState;
            $this->installedToken = end($this->packagedTokens) ?: null;
        }

        if ($this->uploadAlsoInstallsNewer) {
            $this->newerSlugState = 'inactive';
        }

        return new SourceHttpResponse('<html>Plugin installed successfully.</html>', 200);
    }

    /** What the run does when a step fails: the step marked failed, then the source plugin's cleanup. */
    private function failAt(string $step, callable $work, WordpressMigration $migration, RoutedSourceHttpClient $http): void
    {
        try {
            $work();
            $this->fail("Expected {$step} to fail");
        } catch (RuntimeException) {
        }
        $migration->markStepFailed($step);
        (new SourcePluginOnFailure(new SourceConnection(fn () => $http)))->afterFailure($migration->fresh(), $step);
    }

    private function cancel(WordpressMigration $migration, RoutedSourceHttpClient $http): void
    {
        (new MigrationCancellation(new SourceConnection(fn () => $http), remover: new RecordingSiteRemover))->run($migration->requestCancel());
    }

    private function assertOperatorsCopyKept(RoutedSourceHttpClient $http): void
    {
        $this->assertSame(0, $http->count('POST', self::DELETE));
        $this->assertSame('active', $this->pluginState);
        $this->assertSame(self::RELEASE_TOKEN, $this->installedToken);
    }

    private function moveTo(string $state): SourceHttpResponse
    {
        $this->pluginState = $state;

        return new SourceHttpResponse('<html></html>', 200);
    }

    /**
     * @return array{method: string, url: string, body: ?string, headers: array<string, string>}
     */
    private function lastCallTo(RoutedSourceHttpClient $http, string $url): array
    {
        $calls = array_values(array_filter($http->calls, static fn (array $call): bool => $call['url'] === $url));
        $this->assertNotSame([], $calls);

        return end($calls);
    }

    private function zip(): WordpressMigratorZip
    {
        $test = $this;

        return new class($test) extends WordpressMigratorZip
        {
            public function __construct(private WordpressMigrationInstallSourcePluginTest $test) {}

            public function packageWithToken(string $token, ?string $releaseUrl = null): string
            {
                return $this->test->package($token);
            }
        };
    }

    public function package(string $token): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'wp-migrator-package-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('wordpress-migrator/wordpress-migrator.php', "<?php\n/**\n * Version: {$this->releaseVersion}\n */\n");
        $zip->addFromString('wordpress-migrator/config.php', "<?php\nreturn ['token' => '{$token}'];\n");
        $zip->close();
        $this->packagedTokens[] = $token;
        $this->packages[] = $path;

        return $path;
    }
}
