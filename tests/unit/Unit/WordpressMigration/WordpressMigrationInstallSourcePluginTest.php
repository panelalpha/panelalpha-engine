<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourcePluginTokenCheck;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallSourcePluginStep;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * wp_admin installs the source plugin with a token of the import's own, never
 * the one the release ships with, stores it encrypted, and proves the plugin
 * on the source accepts it. A release before 1.2.0 that answers its transfer
 * routes without the token is a warning; a later one doing so stops the import.
 */
class WordpressMigrationInstallSourcePluginTest extends WordpressMigrationTestCase
{
    private const RELEASE_TOKEN = '77d58fa4553cae2431eb7c310eba82b306ecf39ebd184e484a8aac8e404b1d63';

    private const HOME = 'https://example.com';

    private const INFO = self::HOME . '/wp-json/wp-migrator/v1/info?migration_id=panelalpha-token-check';

    private const UPLOAD = self::HOME . '/wp-admin/update.php?action=upload-plugin';

    private const DELETE = self::HOME . '/wp-admin/admin-ajax.php';

    private string $pluginState = 'none';

    private ?string $installedToken = null;

    private bool $deleteWorks = true;

    private bool $openTransferRoutes = false;

    private string $releaseVersion = '1.2.0';

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

        $urls = array_column($http->calls, 'url');
        $this->assertLessThan(array_search(self::UPLOAD, $urls, true), array_search(self::DELETE, $urls, true));
        $this->assertSame($migration->fresh()->secretsForWorker()['source_token'], $this->installedToken);
    }

    public function test_a_plugin_that_refuses_the_imports_token_fails_the_step(): void
    {
        $this->pluginState = 'active';
        $this->installedToken = self::RELEASE_TOKEN;
        $this->deleteWorks = false;
        $migration = $this->migration();

        try {
            $this->step($this->source())->run($migration);
            $this->fail('Expected the plugin to refuse the token');
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::UNAUTHORIZED, $e->errorType());
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
        $migration->storeResolvedSource(self::HOME, self::HOME . '/wp-login.php', SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }

    private function source(): RoutedSourceHttpClient
    {
        $plugin = 'plugin=wordpress-migrator/wordpress-migrator.php';

        return (new RoutedSourceHttpClient())
            ->on('POST', self::HOME . '/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', self::HOME . '/wp-admin', RoutedSourceHttpClient::admin())
            ->on('POST', self::HOME . '/wp-admin/plugins.php', fn (): SourceHttpResponse => $this->pluginList())
            ->on('GET', self::HOME . '/wp-admin/plugin-install.php?tab=upload', new SourceHttpResponse('<input name="_wpnonce" value="up1">', 200))
            ->on('POST', self::UPLOAD, fn (): SourceHttpResponse => $this->upload())
            ->on('POST', self::HOME . '/wp-admin/plugins.php?action=activate&' . $plugin . '&_wpnonce=a1', fn (): SourceHttpResponse => $this->moveTo('active'))
            ->on('POST', self::HOME . '/wp-admin/plugins.php?action=deactivate&' . $plugin . '&_wpnonce=d1', fn (): SourceHttpResponse => $this->moveTo('inactive'))
            ->on('POST', self::DELETE, fn (): SourceHttpResponse => $this->deleteWorks ? $this->moveTo('none') : new SourceHttpResponse('-1', 200))
            ->on('GET', self::INFO, fn (array $headers): SourceHttpResponse => ($headers['X-Migrator-Token'] ?? null) === $this->installedToken
                ? RoutedSourceHttpClient::restError(404, 'not_found')
                : RoutedSourceHttpClient::restError(401, 'rest_forbidden'))
            ->on('POST', self::HOME . '/wp-json/wp-migrator/v1/remote-sync', fn (): SourceHttpResponse => $this->transferRoute())
            ->on('POST', self::HOME . '/wp-json/wp-migrator/v1/remote-sync/process-batch', fn (): SourceHttpResponse => $this->transferRoute())
            ->on('GET', self::HOME . '/wp-json/wp-migrator/v1/remote-sync/status/panelalpha_token_check', fn (): SourceHttpResponse => $this->transferRoute())
            ->on('POST', self::HOME . '/wp-json/wp-migrator/v1/transfer-htaccess', fn (): SourceHttpResponse => $this->transferRoute());
    }

    private function transferRoute(): SourceHttpResponse
    {
        return $this->openTransferRoutes
            ? RoutedSourceHttpClient::restError(400, 'missing_destination')
            : RoutedSourceHttpClient::restError(401, 'rest_forbidden');
    }

    private function pluginList(): SourceHttpResponse
    {
        $plugin = 'plugin=wordpress-migrator%2Fwordpress-migrator.php';
        $link = match ($this->pluginState) {
            'active' => '<a href="plugins.php?action=deactivate&amp;' . $plugin . '&amp;_wpnonce=d1">Deactivate</a>',
            'inactive' => '<a href="plugins.php?action=activate&amp;' . $plugin . '&amp;_wpnonce=a1">Activate</a>',
            default => '',
        };

        return new SourceHttpResponse('<html><script>var s={"_ajax_nonce":"n0nce42"};</script>' . $link . '</html>', 200);
    }

    private function upload(): SourceHttpResponse
    {
        if ($this->pluginState === 'none') {
            $this->pluginState = 'inactive';
            $this->installedToken = end($this->packagedTokens) ?: null;
        }

        return new SourceHttpResponse('<html>Plugin installed successfully.</html>', 200);
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

        return new class($test) extends WordpressMigratorZip {
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
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('wordpress-migrator/wordpress-migrator.php', "<?php\n/**\n * Version: {$this->releaseVersion}\n */\n");
        $zip->addFromString('wordpress-migrator/config.php', "<?php\nreturn ['token' => '{$token}'];\n");
        $zip->close();
        $this->packagedTokens[] = $token;
        $this->packages[] = $path;

        return $path;
    }
}
