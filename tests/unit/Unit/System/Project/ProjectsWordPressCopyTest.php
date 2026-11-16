<?php

namespace Tests\Unit\System\Project;

use App\Exceptions\ProblemException;
use App\Lib\Project\ClassicAppType;
use App\Models\Domain as DomainModel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\Domain as DomainProject;
use App\System\Project\PhpHosting\WordPress\SiteCopy;
use App\System\Project\PhpHosting\WordPress\WordPress;
use App\System\Project\Runtime;
use App\System\Projects;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\DefinerMap;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Tables;
use App\System\Services\Mysql\Users;
use Mockery;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Unit\System\Project\PhpHosting\WordPress\FakeWordPressSite;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A staging copy of a classic WordPress is pointed at the wp-config.php of the
 * copied install, wherever in the home that install is.
 */
class ProjectsWordPressCopyTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<array{string, string|null}> project and install directory the copy's WordPress was read at */
    private array $pointed = [];

    /** @var array<string, string> */
    private array $copiedOptions = ['home' => 'https://shop.example/blog', 'siteurl' => 'https://shop.example/blog', 'blog_public' => '1'];

    private ?FakeWordPressSite $copiedSite = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir().'/pa-wp-copy-'.bin2hex(random_bytes(6));
        $this->app->instance(ClassicAppType::class, new ClassicAppType(static fn (array $argv): string => ''));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_subdirectory_wordpress_copy_is_pointed_at_the_copied_install(): void
    {
        $this->stagingPair('public_html/blog');

        $this->projects()->copy('live', 'stg');

        $this->assertSame(
            [['stg', $this->root.'/stg/staging.shop.test/public_html/blog']],
            $this->pointed,
        );
        $this->assertSame('stg_wp', $this->copiedSite?->constants['DB_NAME']);
        $this->assertSame('stg_wpuser', $this->copiedSite?->constants['DB_USER']);
        $this->assertSame('https://staging.shop.test/blog', $this->copiedSite?->options['home']);
        $this->assertSame(
            $this->root.'/stg/staging.shop.test/public_html/blog',
            User::findByUsernameOrFail('stg')->app_path,
            'the copy records its own install, so wp/* finds it without a path',
        );
    }

    public function test_a_wordpress_at_the_document_root_is_copied_as_before(): void
    {
        $this->copiedOptions = ['home' => 'https://shop.example', 'siteurl' => 'https://shop.example', 'blog_public' => '1'];
        $this->stagingPair(null);

        $this->projects()->copy('live', 'stg');

        $this->assertSame(
            [['stg', $this->root.'/stg/staging.shop.test/public_html']],
            $this->pointed,
        );
        $this->assertSame('stg_wp', $this->copiedSite?->constants['DB_NAME']);
        $this->assertSame('https://staging.shop.test', $this->copiedSite?->options['home']);
    }

    public function test_a_copy_whose_wordpress_wp_config_is_missing_is_refused(): void
    {
        $this->stagingPair('public_html/blog', copyConfig: false);

        try {
            $this->projects()->copy('live', 'stg');
            $this->fail('the copy should have been refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('holds no wp-config.php of its WordPress', $e->getMessage());
            $this->assertStringContainsString("the source's database", $e->getMessage());
        }

        $this->assertSame([], $this->pointed);
    }

    public function test_a_copy_whose_wp_config_is_above_its_wordpress_runs_wp_cli_in_the_wordpress_directory(): void
    {
        $this->stagingPair('public_html/blog', configIn: 'public_html');

        $this->projects()->copy('live', 'stg');

        $this->assertSame(
            [['stg', $this->root.'/stg/staging.shop.test/public_html/blog']],
            $this->pointed,
        );
        $this->assertSame('stg_wp', $this->copiedSite?->constants['DB_NAME']);
    }

    public function test_a_subdirectory_wordpress_with_no_record_is_found_below_the_document_root_and_recorded(): void
    {
        $this->stagingPair('public_html/blog', recorded: false);

        $this->projects()->copy('live', 'stg');

        $this->assertSame(
            [['stg', $this->root.'/stg/staging.shop.test/public_html/blog']],
            $this->pointed,
        );
        $this->assertSame('stg_wp', $this->copiedSite?->constants['DB_NAME']);
        $this->assertSame($this->root.'/live/shop.example/public_html/blog', User::findByUsernameOrFail('live')->app_path);
    }

    public function test_a_copy_of_a_document_root_holding_two_wordpress_sites_is_refused_before_anything_is_copied(): void
    {
        $this->stagingPair('public_html/blog', recorded: false);
        $this->writeCore('live/shop.example/public_html/shop');
        $this->writeConfig('live/shop.example/public_html/shop');

        $this->assertCopyRefusedAsUnclear('more than one WordPress below');
    }

    public function test_a_copy_of_a_wp_config_no_wordpress_loads_is_refused_before_anything_is_copied(): void
    {
        $this->stagingPair('public_html/blog', recorded: false);
        mkdir($this->root.'/live/shop.example/public_html/old');
        $this->writeConfig('live/shop.example/public_html/old');

        $this->assertCopyRefusedAsUnclear('public_html/old/wp-config.php is loaded by no WordPress');
    }

    private function assertCopyRefusedAsUnclear(string $message): void
    {
        try {
            $this->projects()->copy('live', 'stg');
            $this->fail('the copy should have been refused');
        } catch (ProblemException $e) {
            $this->assertSame('wordpress_install_unclear', $e->problems[0]['code']);
            $this->assertStringContainsString($message, $e->problems[0]['message']);
        }

        $this->assertSame([], $this->pointed);
        $this->assertNotContains(['copyHomeDirFrom', $this->root.'/live'], $this->commands);
    }

    /**
     * A live WordPress install at the subdirectory given, or at the document root, with its wp-config.php there or in
     * the directory given, and its staging copy. An unrecorded install is known by its files alone.
     */
    private function stagingPair(?string $install, bool $copyConfig = true, ?string $configIn = null, bool $recorded = true): void
    {
        $live = $this->makeUser('live', ['mysql_prefix' => 'live_'], 'shop.example');
        $this->makeMainDomain($live, 'shop.example');
        $live->app_path = $install === null || ! $recorded ? null : $this->root.'/live/shop.example/'.$install;
        $live->save();
        $live->mysqlDatabases()->create(['database' => 'live_wp']);
        $live->mysqlUsers()->create(['user' => 'live_wpuser']);

        $stg = $this->makeUser('stg', ['mysql_prefix' => 'stg_'], 'staging.shop.test');
        $stg->staging = $live->id;
        $stg->status = 'pending';
        $stg->save();
        $this->makeMainDomain($stg, 'staging.shop.test');

        $wordPressDir = $install ?? 'public_html';
        foreach (['live/shop.example/', 'stg/staging.shop.test/'] as $site) {
            $this->writeCore($site.$wordPressDir);
        }
        $this->writeConfig('live/shop.example/'.($configIn ?? $wordPressDir));
        if ($copyConfig) {
            $this->writeConfig('stg/staging.shop.test/'.($configIn ?? $wordPressDir));
        }
    }

    private function writeCore(string $directory): void
    {
        mkdir($this->root.'/'.$directory.'/wp-includes', 0777, true);
        file_put_contents($this->root.'/'.$directory.'/wp-includes/version.php', '<?php');
    }

    private function writeConfig(string $directory): void
    {
        file_put_contents($this->root.'/'.$directory.'/wp-config.php', '<?php');
    }

    private function projects(): Projects
    {
        $siteCopy = new SiteCopy(function (User $user, ?string $path = null): WordPress {
            $this->pointed[] = [$user->username, $path];
            $this->copiedSite = new FakeWordPressSite(
                ['DB_NAME' => 'live_wp', 'DB_USER' => 'live_wpuser', 'DB_PASSWORD' => 'live-secret'],
                $this->copiedOptions,
                home: $this->root.'/'.$user->username,
            );

            return $this->copiedSite->wordPress($path);
        });
        $system = $this->system();

        return new class($system, $siteCopy) extends Projects
        {
            public function __construct(System $system, private SiteCopy $fakeSiteCopy)
            {
                parent::__construct($system);
            }

            protected function siteCopy(): SiteCopy
            {
                return $this->fakeSiteCopy;
            }
        };
    }

    private function system(): System
    {
        $commands = &$this->commands;

        return new class($this->root, $commands) extends System
        {
            /** @param list<list<string>> $commands */
            public function __construct(
                private string $root,
                private array &$commands,
            ) {}

            public function project(User $model): Project
            {
                $root = $this->root;
                $commands = &$this->commands;

                return new class($root.'/'.$model->username, $commands) extends Project
                {
                    /** @param list<list<string>> $commands */
                    public function __construct(
                        private string $home,
                        private array &$commands,
                    ) {}

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function runtime(): Runtime
                    {
                        return new class implements Runtime
                        {
                            public function kind(): string
                            {
                                return 'php';
                            }

                            public function exists(): bool
                            {
                                return true;
                            }

                            public function composeFilePath(): string
                            {
                                return '';
                            }

                            public function defaultServiceName(): string
                            {
                                return '';
                            }

                            public function materialize(): void {}

                            public function start(): void {}

                            public function stop(): void {}

                            public function remove(): void {}

                            public function awaitReady(int $tries = 12, int $intervalSeconds = 5): void {}

                            public function isRunning(): bool
                            {
                                return true;
                            }

                            public function build(): void {}

                            public function buildIfMissing(): void {}

                            public function createDomainConfig(DomainModel $domain): void {}

                            public function reloadWebserver(): void {}

                            public function rebuildDomain(DomainModel $domain): void {}

                            public function deleteDomainConfig(string $domainName): void {}

                            public function reloadCron(): void {}
                        };
                    }

                    public function domain(DomainModel $domainModel): DomainProject
                    {
                        return new class($this, $domainModel) extends DomainProject
                        {
                            public function create(): void {}
                        };
                    }

                    public function copyVolumesForClone(): ?CopyVolumes
                    {
                        return null;
                    }

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function createDirectories(): void {}

                    public function createLinuxUser(): array
                    {
                        return ['UID' => 2001, 'GID' => 2002];
                    }

                    public function createFromTemplate(): void {}

                    public function start(): void {}

                    public function fixPermissions(): void
                    {
                        $this->commands[] = ['fixPermissions'];
                    }

                    public function configureQuota(): bool
                    {
                        return true;
                    }

                    public function waitForAllRunning(int $tries = 12, int $intervalSeconds = 5): void {}

                    public function copyHomeDirFrom(Project $source, bool $sourceRunning = false): void
                    {
                        $this->commands[] = ['copyHomeDirFrom', $source->homeDirPath()];
                    }

                    public function copyProjectConfigFrom(Project $source): void {}

                    public function syncGeneratedProxyRules(): void {}
                };
            }

            public function mysql(): Mysql
            {
                $record = (object) [
                    'sourcePrivileges' => ['live_wp' => 'ALL PRIVILEGES'],
                    'created' => [],
                    'users' => [],
                ];

                return new class($record) extends Mysql
                {
                    public function __construct(private object $record)
                    {
                        parent::__construct(null);
                    }

                    public function databases(): Databases
                    {
                        $record = $this->record;

                        return new class($record) extends Databases
                        {
                            public function __construct(private object $record)
                            {
                                parent::__construct(new PDO('sqlite::memory:'));
                            }

                            public function createDatabase(string $name): void
                            {
                                $this->record->created[] = $name;
                            }
                        };
                    }

                    public function users(): Users
                    {
                        $record = $this->record;

                        return new class($record) extends Users
                        {
                            public function __construct(private object $record)
                            {
                                parent::__construct(new PDO('sqlite::memory:'), '%');
                            }

                            public function createUser(string $name, string $password): void
                            {
                                $this->record->users[] = $name;
                            }
                        };
                    }

                    public function privileges(): Privileges
                    {
                        $record = $this->record;

                        return new class($record) extends Privileges
                        {
                            public function __construct(private object $record)
                            {
                                parent::__construct(new PDO('sqlite::memory:'), '%');
                            }

                            public function showPrivileges(string $user): array
                            {
                                return $this->record->sourcePrivileges;
                            }

                            public function updatePrivileges(string $user, string $database, string $privileges): void {}
                        };
                    }

                    public function copyInSitesDb(string $from, string $to, ?string $table, DefinerMap $definers, string $failurePrefix): void {}

                    public function dropTableInSitesDb(string $database, string $table): void {}

                    public function tables(): Tables
                    {
                        return new class extends Tables
                        {
                            public function __construct()
                            {
                                parent::__construct(new PDO('sqlite::memory:'));
                            }

                            public function exists(string $database, string $table): bool
                            {
                                return false;
                            }
                        };
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $this->commands[] = $argv;
                if (LocalRoot::action($argv) === 'files.listEntryTypes') {
                    return FakeProcess::forCommand($argv);
                }
                $plain = is_array($cmd) && LocalRoot::isRequest($argv) ? LocalRoot::plain($argv) : $argv;
                $path = (string) end($plain);
                $code = match (array_slice($plain, 0, 2)) {
                    ['test', '-d'] => is_dir($path) ? 0 : 1,
                    ['test', '-e'] => file_exists($path) ? 0 : 1,
                    ['test', '-L'] => is_link($path) ? 0 : 1,
                    default => 0,
                };

                $process = new Process($code === 0 ? ['true'] : ['false']);
                $process->run();

                return $process;
            }
        };
    }
}
