<?php

namespace Tests\Integration\WordpressMigration;

use App\Models\Domain;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Migration\AccountFileCopy;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallDestinationPluginStep;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use Illuminate\Support\Facades\DB;
use Mockery;

/**
 * The destination plugin goes in through WP-CLI from a zip staged in the account's home, is activated, gets its
 * destination config with this import's token, and the staged zip is removed whatever happens.
 */
class InstallDestinationPluginInstallTest extends WordpressMigrationTestCase
{
    use WithTargetTables;

    private const LABEL = 'dest.example.test';

    private const SITE = '/home/alice/dest.example.test/public_html/blog';

    /** @var list<list<string>> */
    private array $wpCli = [];

    /** @var list<list<string>> */
    private array $execs = [];

    /** @var list<string> */
    private array $configs = [];

    private int $wpCliExit = 0;

    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();
        $this->zip = (string) tempnam(sys_get_temp_dir(), 'wp-migrator-install-test-');
        file_put_contents($this->zip, 'zip');
        DB::table('domains')->insert([
            'user_id' => 999,
            'domain' => self::LABEL,
            'type' => 'main',
            'details' => json_encode(['document_root' => '/dest.example.test/public_html']),
        ]);
        $user = $this->alice();
        Domain::retrieved(static function (Domain $domain) use ($user): void {
            $domain->setRelation('user', $user);
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->zip);
        $this->dropTargetTables();
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_plugin_is_installed_activated_and_configured_for_this_import(): void
    {
        $migration = $this->import();

        $this->step()->run($migration);

        $this->assertSame([
            ['plugin', 'install', '/home/alice/.panelalpha/wordpress-migrator/'.$migration->id.'.zip', '--force', '--path='.self::SITE],
            ['plugin', 'activate', 'wordpress-migrator', '--path='.self::SITE],
        ], $this->wpCli);
        $stored = $migration->fresh();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $stored?->migrator_token);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $stored?->stepStatus(WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN));
        $this->assertCount(1, $this->configs);
        $this->assertStringContainsString("'token' => '".$stored?->migrator_token."'", $this->configs[0]);
        $this->assertStringContainsString("'mode' => 'destination'", $this->configs[0]);
        $this->assertStringContainsString('removeFileBelow', $this->journal());
    }

    public function test_a_failed_install_still_removes_the_staged_zip(): void
    {
        $this->wpCliExit = 1;
        $migration = $this->import();

        try {
            $this->step()->run($migration);
            $this->fail('a plugin WP-CLI could not install must fail the step');
        } catch (\RuntimeException) {
        }

        $this->assertCount(1, $this->wpCli);
        $this->assertSame([], $this->configs);
        $this->assertStringContainsString('removeFileBelow', $this->journal());
        $this->assertNull($migration->fresh()?->migrator_token);
    }

    public function test_the_step_is_named_for_the_status_it_records(): void
    {
        $this->assertSame(WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN, $this->step()->name());
    }

    private function step(): InstallDestinationPluginStep
    {
        $zip = new class ($this->zip) extends WordpressMigratorZip {
            public function __construct(private readonly string $path) {}

            public function installZipFor(int $migrationId, ?string $releaseUrl = null): string
            {
                return $this->path;
            }
        };

        return new InstallDestinationPluginStep($zip, new AccountFileCopy($this->system()));
    }

    private function import(): WordpressMigration
    {
        return WordpressMigration::start(
            sourceUrl: 'https://wp.com/blog',
            target: MigrationTarget::newProject(self::LABEL, null),
            method: WordpressMigration::METHOD_MANUAL_PLUGIN,
            sourceToken: self::SOURCE_TOKEN,
        )->fresh() ?? throw new \RuntimeException('the import was not stored');
    }

    private function journal(): string
    {
        return implode("\n", array_map(static fn (array $argv): string => implode(' ', $argv), $this->execs));
    }

    private function system(): System
    {
        $test = $this;

        return new class ($test) extends System {
            public function __construct(private readonly InstallDestinationPluginInstallTest $test) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->recordExec((array) $cmd);

                return '';
            }
        };
    }

    /** @param  list<string>  $argv */
    public function recordExec(array $argv): void
    {
        $this->execs[] = $argv;
        if (($argv[2] ?? '') === 'exec "${@:2}" < "$1"' && str_contains(implode(' ', $argv), 'config.php')) {
            $this->configs[] = (string) file_get_contents($argv[4]);
        }
    }

    private function alice(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->setDetails(['template' => 'default', 'UID' => 1001, 'GID' => 1001]);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('runWpCli')->andReturnUsing(function (array $args): array {
            $this->wpCli[] = $args;

            return ['exit_code' => $this->wpCliExit, 'stdout' => 'ok', 'stderr' => $this->wpCliExit === 0 ? '' : 'Error: no zip'];
        });
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }
}
