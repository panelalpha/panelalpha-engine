<?php

namespace Tests\Integration\WordpressMigration;

use App\Models\Domain;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\FinalizeStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\RemovePluginsStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\RoutedSourceHttpClient;

/**
 * The plugin removal and the .htaccess rollback find the destination by the kept domain's user, home, document root
 * and the source's subdirectory; on the source, the removal takes only a plugin the import installed.
 */
class WordpressMigrationDestinationInstallTest extends WordpressMigrationTestCase
{
    use WithTargetTables;

    private const LABEL = 'dest.example.test';

    private const STATUS_URL = 'https://wp.com/blog/wp-json/wp-migrator/v1/remote-sync/status/mig-fin';

    private const HTACCESS_URL = 'https://wp.com/blog/wp-json/wp-migrator/v1/transfer-htaccess';

    /** @var list<list<string>> */
    private array $wpCli = [];

    private string $sourcePlugin = 'active';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();
    }

    protected function tearDown(): void
    {
        $this->dropTargetTables();
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_destination_plugin_is_removed_in_the_source_subdirectory(): void
    {
        $this->domainOfAlice('/dest.example.test/public_html');

        (new RemovePluginsStep(new SourceConnection(fn () => new RoutedSourceHttpClient)))->run($this->manualPluginImport('https://wp.com/blog'));

        $path = '--path=/home/alice/dest.example.test/public_html/blog';
        $this->assertSame([
            ['plugin', 'deactivate', SourceWordpressClient::PLUGIN_SLUG, $path],
            ['plugin', 'uninstall', SourceWordpressClient::PLUGIN_SLUG, '--deactivate', $path],
        ], $this->wpCli);
    }

    public function test_the_destination_plugin_is_removed_at_the_document_root_without_a_subdirectory(): void
    {
        $this->domainOfAlice('/custom/root');

        $migration = $this->manualPluginImport('https://wp.com/wp-admin/');
        (new RemovePluginsStep(new SourceConnection(fn () => new RoutedSourceHttpClient)))->run($migration);

        $this->assertSame(['plugin', 'uninstall', SourceWordpressClient::PLUGIN_SLUG, '--deactivate', '--path=/home/alice/custom/root'], $this->wpCli[1]);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_REMOVE_PLUGINS));
    }

    public function test_a_successful_import_leaves_a_source_plugin_that_was_there_before(): void
    {
        $this->domainOfAlice('/dest.example.test/public_html');
        $migration = $this->wpAdminImport(true);
        $http = $this->adminSource();

        (new RemovePluginsStep(new SourceConnection(fn () => $http)))->run($migration);

        $this->assertSame('active', $this->sourcePlugin);
        $this->assertSame([], $http->calls);
        $this->assertCount(2, $this->wpCli, 'the destination plugin is still removed');
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_REMOVE_PLUGINS));
    }

    #[DataProvider('pluginsTheImportInstalled')]
    public function test_a_successful_import_removes_the_source_plugin_it_installed(?bool $preinstalled): void
    {
        $this->domainOfAlice('/dest.example.test/public_html');
        $migration = $this->wpAdminImport($preinstalled);

        (new RemovePluginsStep(new SourceConnection(fn () => $this->adminSource())))->run($migration);

        $this->assertSame('none', $this->sourcePlugin);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_REMOVE_PLUGINS));
    }

    /** @return array<string, array{?bool}> */
    public static function pluginsTheImportInstalled(): array
    {
        return ['the source had none' => [false], 'recorded before the check existed' => [null]];
    }

    public function test_the_plugin_removal_names_a_missing_domain(): void
    {
        $this->assertRemovalFails("Domain 'dest.example.test' not found for destination plugin removal");
    }

    public function test_the_plugin_removal_names_a_domain_without_a_user(): void
    {
        $this->domainRow(null);

        $this->assertRemovalFails("No user for domain 'dest.example.test'");
    }

    public function test_the_htaccess_rollback_removes_the_file_of_the_source_subdirectory(): void
    {
        $files = $this->domainOfAlice('/dest.example.test/public_html');
        $files->shouldReceive('remove')->once()->with('/home/alice/dest.example.test/public_html/blog/.htaccess');

        $migration = $this->atFinalize();
        (new FinalizeStep(new SourceConnection(fn () => $this->source())))->run($migration);

        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_FINALIZE));
        $this->assertStringContainsString('HTTP status changed from 200 to 500', json_encode($migration->fresh()->step_log, JSON_THROW_ON_ERROR));
    }

    public function test_a_failed_htaccess_removal_is_ignored_once_the_file_is_gone(): void
    {
        $files = $this->domainOfAlice('/dest.example.test/public_html');
        $files->shouldReceive('remove')->once()->andThrow(new RuntimeException('rm failed'));
        $files->shouldReceive('exists')->once()->with('/home/alice/dest.example.test/public_html/blog/.htaccess')->andReturn(false);

        $migration = $this->atFinalize();
        (new FinalizeStep(new SourceConnection(fn () => $this->source())))->run($migration);

        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_FINALIZE));
    }

    public function test_a_failed_htaccess_removal_fails_finalize_while_the_file_is_there(): void
    {
        $files = $this->domainOfAlice('/dest.example.test/public_html');
        $failure = new RuntimeException('rm failed');
        $files->shouldReceive('remove')->once()->andThrow($failure);
        $files->shouldReceive('exists')->once()->andReturn(true);

        try {
            (new FinalizeStep(new SourceConnection(fn () => $this->source())))->run($this->atFinalize());
            $this->fail('Finalize must fail while the .htaccess stays');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }
    }

    public function test_the_htaccess_rollback_names_a_missing_domain(): void
    {
        $this->assertRollbackFails("Domain 'dest.example.test' not found when rolling back .htaccess");
    }

    public function test_the_htaccess_rollback_names_a_domain_without_a_user(): void
    {
        $this->domainRow(null);

        $this->assertRollbackFails("No user for domain 'dest.example.test'");
    }

    private function assertRemovalFails(string $message): void
    {
        try {
            (new RemovePluginsStep(new SourceConnection(fn () => new RoutedSourceHttpClient)))->run($this->manualPluginImport('https://wp.com/blog'));
            $this->fail('The removal must fail');
        } catch (RuntimeException $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(RuntimeException::class, $e->getPrevious()::class);
        }
    }

    private function assertRollbackFails(string $message): void
    {
        try {
            (new FinalizeStep(new SourceConnection(fn () => $this->source())))->run($this->atFinalize());
            $this->fail('The rollback must fail');
        } catch (RuntimeException $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function manualPluginImport(string $sourceUrl): WordpressMigration
    {
        return WordpressMigration::start(
            sourceUrl: $sourceUrl,
            target: MigrationTarget::newProject(self::LABEL, null),
            method: WordpressMigration::METHOD_MANUAL_PLUGIN,
            sourceToken: self::SOURCE_TOKEN,
        )->fresh();
    }

    private function wpAdminImport(?bool $preinstalled): WordpressMigration
    {
        $migration = WordpressMigration::start('https://wp.com/blog', 'admin', 'secret', MigrationTarget::newProject(self::LABEL, null));
        $migration->storeResolvedSource('https://wp.com/blog', 'https://wp.com/blog/wp-login.php', SourceSite::REST_WP_JSON);
        if ($preinstalled !== null) {
            $migration->recordSourcePluginPreinstalled($preinstalled);
        }

        return $migration->fresh();
    }

    /** The source's wp-admin, whose wordpress-migrator is active until it is deactivated and deleted. */
    private function adminSource(): RoutedSourceHttpClient
    {
        $plugin = 'plugin=wordpress-migrator/wordpress-migrator.php';

        return (new RoutedSourceHttpClient)
            ->on('POST', 'https://wp.com/blog/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', 'https://wp.com/blog/wp-admin', RoutedSourceHttpClient::admin())
            ->on('POST', 'https://wp.com/blog/wp-admin/plugins.php', fn (): SourceHttpResponse => $this->sourcePluginList())
            ->on('POST', 'https://wp.com/blog/wp-admin/plugins.php?action=deactivate&'.$plugin.'&_wpnonce=d1', fn (): SourceHttpResponse => $this->moveSourcePlugin('inactive'))
            ->on('POST', 'https://wp.com/blog/wp-admin/admin-ajax.php', fn (): SourceHttpResponse => $this->moveSourcePlugin('none'));
    }

    private function sourcePluginList(): SourceHttpResponse
    {
        $plugin = 'plugin=wordpress-migrator%2Fwordpress-migrator.php';
        $link = match ($this->sourcePlugin) {
            'active' => '<a href="plugins.php?action=deactivate&amp;'.$plugin.'&amp;_wpnonce=d1">Deactivate</a>',
            'inactive' => '<a href="plugins.php?action=activate&amp;'.$plugin.'&amp;_wpnonce=a1">Activate</a>',
            default => '',
        };

        return new SourceHttpResponse('<html><script>var s={"_ajax_nonce":"n0nce42"};</script>'.$link.'</html>', 200);
    }

    private function moveSourcePlugin(string $state): SourceHttpResponse
    {
        $this->sourcePlugin = $state;

        return new SourceHttpResponse('<html></html>', 200);
    }

    private function atFinalize(): WordpressMigration
    {
        Http::fake(['*' => Http::sequence()->push('', 200)->push('', 500)]);
        $migration = WordpressMigration::start(
            sourceUrl: 'https://wp.com/blog',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject(self::LABEL, null),
            sourceToken: self::SOURCE_TOKEN,
        );
        $migration->storePluginMigrationId('mig-fin');
        $migration->storeResolvedSource('https://wp.com/blog', null, SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }

    private function source(): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, new SourceHttpResponse(
                json_encode(['success' => true, 'status' => ['status' => 'completed', 'phase' => 'completed']], JSON_THROW_ON_ERROR),
                200,
            ))
            ->on('POST', self::HTACCESS_URL, new SourceHttpResponse('{"success":true,"transferred":true}', 200));
    }

    private function domainRow(?string $documentRoot): void
    {
        DB::table('domains')->insert([
            'user_id' => 999,
            'domain' => self::LABEL,
            'type' => 'main',
            'details' => $documentRoot === null ? null : json_encode(['document_root' => $documentRoot]),
        ]);
    }

    /**
     * The domain row, whose user is a fake `alice` running WP-CLI and file operations nowhere.
     */
    private function domainOfAlice(string $documentRoot): FileManager&MockInterface
    {
        $this->domainRow($documentRoot);
        $files = Mockery::mock(FileManager::class);
        $user = $this->alice($files);
        Domain::retrieved(static function (Domain $domain) use ($user): void {
            $domain->setRelation('user', $user);
        });

        return $files;
    }

    private function alice(FileManager $files): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->shouldReceive('getBindIpAddresses')->andReturn(['ipv4' => [], 'ipv6' => []]);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('fileManager')->andReturn($files);
        $project->shouldReceive('runWpCli')->andReturnUsing(function (array $args): array {
            $this->wpCli[] = $args;

            return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => ''];
        });
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }
}
