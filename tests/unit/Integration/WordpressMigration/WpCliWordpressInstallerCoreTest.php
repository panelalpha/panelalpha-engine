<?php

namespace Tests\Integration\WordpressMigration;

use App\Lib\Project\ClassicAppType;
use App\Models\Setting;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Migration\WpCliWordpressInstaller;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The destination's core: present, downloaded, installed with a throwaway admin, each as the account through WP-CLI.
 */
class WpCliWordpressInstallerCoreTest extends TestCase
{
    use InMemoryDatabase;

    private const PATH = '/home/alice/example.test/public_html';

    /** @var list<list<string>> */
    private array $calls = [];

    /** @var array{exit_code: int, stdout: string, stderr: string} */
    private array $answer = ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];

    private bool $versionFile = false;

    /** @var list<string> */
    private array $installs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $installs = &$this->installs;
        $this->app->instance(ClassicAppType::class, new class ($installs) extends ClassicAppType {
            /** @param  list<string>  $installs */
            public function __construct(private array &$installs) {}

            public function recordInstall(User $user, string $path): void
            {
                $this->installs[] = $path;
            }
        });
    }

    protected function tearDown(): void
    {
        Setting::flushCache();
        Mockery::close();
        parent::tearDown();
    }

    public function test_core_is_there_when_the_install_holds_its_version_file(): void
    {
        $this->versionFile = true;

        $this->assertTrue((new WpCliWordpressInstaller)->hasCore($this->user(), self::PATH));
    }

    public function test_core_is_missing_without_its_version_file(): void
    {
        $this->assertFalse((new WpCliWordpressInstaller)->hasCore($this->user(), self::PATH));
    }

    public function test_core_is_downloaded_into_the_install_path(): void
    {
        $this->answer['stdout'] = "Downloading WordPress 6.7.1 (en_US)...\nSuccess: WordPress downloaded.\n";

        (new WpCliWordpressInstaller)->downloadCore($this->user(), self::PATH);

        $this->assertSame([['core', 'download', '--path='.self::PATH]], $this->calls);
        $this->assertSame([self::PATH], $this->installs);
    }

    public function test_an_installed_site_reads_as_installed(): void
    {
        $this->answer['stdout'] = "6.7.1\n";

        $this->assertTrue((new WpCliWordpressInstaller)->isInstalled($this->user(), self::PATH));
        $this->assertSame(['core', 'is-installed', '--path='.self::PATH], $this->calls[0]);
    }

    public function test_the_install_uses_the_site_url_and_the_engines_admin_email(): void
    {
        Setting::set('email', 'ops@example.test');

        (new WpCliWordpressInstaller)->install($this->user(), self::PATH, 'https://example.test');

        $call = $this->calls[0];
        $this->assertSame(['core', 'install', '--url=https://example.test', '--title=Migrated site', '--admin_user=admin', '--admin_email=ops@example.test'], array_slice($call, 0, 6));
        $this->assertMatchesRegularExpression('/^--admin_password=[A-Za-z0-9]{20}$/', $call[6]);
        $this->assertSame([self::PATH], $this->installs);
    }

    public function test_without_a_valid_engine_email_the_admin_gets_a_placeholder_one(): void
    {
        Setting::set('email', 'not-an-address');

        (new WpCliWordpressInstaller)->install($this->user(), self::PATH, 'https://example.test');

        $this->assertSame('--admin_email=admin@localhost.localdomain', $this->calls[0][5]);
    }

    private function user(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->setDetails(['template' => 'default']);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('runWpCli')->andReturnUsing(function (array $args): array {
            $this->calls[] = $args;

            return $this->answer;
        });
        $files = Mockery::mock(FileManager::class);
        $files->shouldReceive('isFile')->andReturnUsing(fn (string $path): bool => $this->versionFile && $path === self::PATH.'/wp-includes/version.php');
        $project->shouldReceive('fileManager')->andReturn($files);
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }
}
