<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\PhpHosting\WordPress\ContainerSiteCopy;
use App\System\Project\PhpHosting\WordPress\SiteCopy;
use App\System\Projects;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Tables;
use Mockery;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;
use Tests\Unit\System\Project\PhpHosting\WordPress\FakeWordPressSite;

/** A push between two classic WordPress sites leaves the target its own wp-config.php and addresses. */
class ProjectsWordPressPushTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-wp-push-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_target_keeps_its_wp_config_and_answers_on_its_addresses_again(): void
    {
        $this->stagingPair(withWordPress: true);
        $live = new FakeWordPressSite([], ['home' => 'https://shop.example', 'siteurl' => 'https://shop.example', 'blog_public' => '1']);
        $staging = new FakeWordPressSite([], ['home' => 'https://staging.shop.test', 'siteurl' => 'https://staging.shop.test', 'blog_public' => '0']);

        $this->projects(['live' => $live, 'stg' => $staging])->pushToApp('stg', 'live');

        $config = '/shop.example/public_html/wp-config.php';
        $keep = [
            'sudo', 'setpriv', '--reuid', '2001', '--regid', '2002', '--clear-groups',
            'cp', '-P', '--remove-destination', "{$this->root}/live/.pre-push{$config}", "{$this->root}/live{$config}",
        ];
        $this->assertContains($keep, $this->commands);
        $this->assertGreaterThan(
            array_search(['fixPermissions'], $this->commands, true),
            array_search($keep, $this->commands, true),
            'copied once the target account owns its home again',
        );
        $this->assertSame(['home' => 'https://shop.example', 'siteurl' => 'https://shop.example', 'blog_public' => '1'], $live->options);
        $this->assertSame([[
            'search-replace', '(//|\\\\/\\\\/)staging\\.shop\\.test(?![A-Za-z0-9.-])', '${1}shop.example',
            '--regex', '--regex-delimiter=~', '--all-tables-with-prefix', '--precise', '--skip-columns=guid',
            '--skip-plugins', '--skip-themes', '--path=/home/site/site.test/public_html',
        ]], $live->searchReplaces());
    }

    public function test_a_table_push_rewrites_only_the_pushed_wordpress_tables_under_the_targets_names(): void
    {
        $this->stagingPair(withWordPress: true);
        User::findByUsernameOrFail('live')->mysqlDatabases()->create(['database' => 'live_wp']);
        $live = new FakeWordPressSite(
            ['DB_NAME' => 'live_wp', 'table_prefix' => 'wp_'],
            ['home' => 'https://shop.example', 'siteurl' => 'https://shop.example', 'blog_public' => '1'],
        );
        $staging = new FakeWordPressSite([], ['home' => 'https://staging.shop.test', 'siteurl' => 'https://staging.shop.test', 'blog_public' => '0']);

        $this->projects(['live' => $live, 'stg' => $staging])->pushToApp('stg', 'live', files: false, tables: [
            ['database' => 'stg_wp', 'name' => 'wp_posts'],
            ['database' => 'stg_wp', 'name' => 'legacy_log'],
        ]);

        $this->assertSame(['wp_posts', '--regex'], array_slice($live->searchReplaces()[0], 3, 2));
    }

    public function test_a_multisite_target_gets_its_main_site_host_back_in_the_network_tables_first(): void
    {
        $this->stagingPair(withWordPress: true);
        $network = ['MULTISITE' => true, 'PATH_CURRENT_SITE' => '/'];
        $live = new FakeWordPressSite(
            ['DOMAIN_CURRENT_SITE' => 'shop.example', ...$network],
            ['home' => 'https://shop.example', 'siteurl' => 'https://shop.example', 'blog_public' => '1'],
            ['table_prefix' => 'wp_'],
        );
        $staging = new FakeWordPressSite(
            ['DOMAIN_CURRENT_SITE' => 'staging.shop.test', ...$network],
            ['home' => 'https://staging.shop.test', 'siteurl' => 'https://staging.shop.test', 'blog_public' => '0'],
            ['table_prefix' => 'wp_'],
        );
        $live->networkSiteUrl = 'https://staging.shop.test/';

        $this->projects(['live' => $live, 'stg' => $staging])->pushToApp('stg', 'live');

        $commands = array_map(static fn (array $args): string => $args[0], $live->calls);
        $this->assertLessThan(array_search('search-replace', $commands, true), array_search('db', $commands, true));
        $this->assertStringContainsString("UPDATE `wp_blogs` SET domain = 'shop.example' WHERE blog_id = 1", $live->queries[1]);
        $this->assertSame('shop.example', $live->constants['DOMAIN_CURRENT_SITE']);
    }

    public function test_a_container_wordpress_push_answers_on_the_targets_domain(): void
    {
        $this->stagingPair(withWordPress: false, template: 'dind');
        $pushedLive = new FakeWordPressSite(['DB_NAME' => 'wordpress'], ['home' => 'https://staging.shop.test', 'siteurl' => 'https://staging.shop.test']);
        $staging = new FakeWordPressSite([], ['home' => 'https://staging.shop.test', 'siteurl' => 'https://staging.shop.test']);

        $this->projects(['live' => $pushedLive, 'stg' => $staging])->pushToApp('stg', 'live');

        $this->assertSame(['home' => 'https://shop.example', 'siteurl' => 'https://shop.example'], $pushedLive->options);
        $this->assertSame(
            ['(//|\\\\/\\\\/)staging\\.shop\\.test(?![A-Za-z0-9.-])'],
            array_column($pushedLive->searchReplaces(), 1),
        );
        $this->assertSame(['cache', 'flush'], array_slice((array) end($pushedLive->calls), 0, 2));
    }

    public function test_a_container_push_of_files_only_rewrites_nothing(): void
    {
        $this->stagingPair(withWordPress: false, template: 'dind');
        $live = new FakeWordPressSite(['DB_NAME' => 'wordpress'], ['home' => 'https://shop.example', 'siteurl' => 'https://shop.example']);
        $staging = new FakeWordPressSite([], ['home' => 'https://staging.shop.test', 'siteurl' => 'https://staging.shop.test']);

        $this->projects(['live' => $live, 'stg' => $staging])->pushToApp('stg', 'live', databases: false);

        $this->assertSame([], $live->calls);
        $this->assertSame([], $staging->calls);
    }

    public function test_a_push_without_wordpress_on_the_target_runs_no_wordpress_step(): void
    {
        $this->stagingPair(withWordPress: false);

        $this->projects([])->pushToApp('stg', 'live');

        $this->assertSame([], array_filter($this->commands, static fn (array $argv): bool => in_array('setpriv', $argv, true)));
    }

    private function stagingPair(bool $withWordPress, ?string $template = null): void
    {
        $live = $this->makeUser('live', ['UID' => 2001, 'GID' => 2002, 'mysql_prefix' => 'live_', 'template' => $template], 'shop.example');
        $this->makeMainDomain($live, 'shop.example');
        $stg = $this->makeUser('stg', ['mysql_prefix' => 'stg_', 'template' => $template], 'staging.shop.test');
        $this->makeMainDomain($stg, 'staging.shop.test');
        $stg->staging = $live->id;
        $stg->save();
        foreach (['live/shop.example', 'stg/staging.shop.test'] as $site) {
            mkdir("{$this->root}/{$site}/public_html", 0777, true);
            if ($withWordPress) {
                file_put_contents("{$this->root}/{$site}/public_html/wp-config.php", '<?php');
            }
        }
    }

    /** @param array<string, FakeWordPressSite> $sites */
    private function projects(array $sites): Projects
    {
        $wordPress = static fn (User $user) => $sites[$user->username]->wordPress();

        return new class ($this->system(), new SiteCopy($wordPress), new ContainerSiteCopy($wordPress)) extends Projects {
            public function __construct(
                System $system,
                private SiteCopy $fakeSiteCopy,
                private ContainerSiteCopy $fakeContainerSiteCopy,
            ) {
                parent::__construct($system);
            }

            protected function siteCopy(): SiteCopy
            {
                return $this->fakeSiteCopy;
            }

            protected function containerSiteCopy(): ContainerSiteCopy
            {
                return $this->fakeContainerSiteCopy;
            }
        };
    }

    private function system(): System
    {
        $commands = &$this->commands;

        return new class ($this->root, $commands) extends System {
            /** @param list<list<string>> $commands */
            public function __construct(private string $root, private array &$commands)
            {
            }

            public function project(User $model): Project
            {
                return new class ($this->root . '/' . $model->username, $this->commands) extends Project {
                    /** @param list<list<string>> $commands */
                    public function __construct(private string $home, private array &$commands)
                    {
                    }

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function copyVolumesForClone(): ?CopyVolumes
                    {
                        return null;
                    }

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function start(): void
                    {
                    }

                    public function fixPermissions(): void
                    {
                        $this->commands[] = ['fixPermissions'];
                    }

                    public function handStagedHomeToAccount(string $stagedHome): void
                    {
                    }

                    public function repointCopiedRunFile(User $source, ?string $path = null): void
                    {
                    }

                    public function provideCopiedImages(?string $path = null): void
                    {
                    }
                };
            }

            public function mysql(): Mysql
            {
                return new class extends Mysql {
                    public function __construct()
                    {
                        parent::__construct(null);
                    }

                    public function dumpToGzip(string $name, string $dest, ?string $table = null): void
                    {
                    }

                    public function runSitesDbScript(string $script, string $failurePrefix): void
                    {
                    }

                    public function tables(): Tables
                    {
                        return new class extends Tables {
                            public function __construct()
                            {
                                parent::__construct(new PDO('sqlite::memory:'));
                            }

                            public function exists(string $database, string $table): bool
                            {
                                return true;
                            }
                        };
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $this->commands[] = $argv;
                $path = (string) end($argv);
                $code = match (array_slice($argv, 0, 3)) {
                    ['sudo', 'test', '-d'] => is_dir($path) ? 0 : 1,
                    ['sudo', 'test', '-e'] => file_exists($path) ? 0 : 1,
                    ['sudo', 'test', '-L'] => is_link($path) ? 0 : 1,
                    default => 0,
                };

                $process = new Process($code === 0 ? ['true'] : ['false']);
                $process->run();

                return $process;
            }
        };
    }
}
