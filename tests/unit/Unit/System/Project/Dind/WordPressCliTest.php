<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\WordPressCli;
use App\System\Project\PhpHosting\WordPress\Core;
use App\System\Project\PhpHosting\WordPress\DatabaseClientMissing;
use App\System\Project\PhpHosting\WordPress\MustUsePlugin;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Project\PhpHosting\WordPress\WordPressNotFound;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/**
 * WP-CLI for WordPress in a project's own containers. Every request to the account's daemon is answered by a fake
 * of it, so container choice, the phar copy, the user WP-CLI runs as and the failure messages are all exercised.
 */
class WordPressCliTest extends TestCase
{
    private const ENVIRONMENT = ['2', 'HOME', '/tmp', 'WP_CLI_CACHE_DIR', '/tmp/.wp-cli-cache'];

    private string $tmp;

    /** @var list<array<string, mixed>>|null the broker's rows of the running containers; null when the daemon cannot say */
    private ?array $rows = [];

    /** @var array<string, string> */
    private array $probes = [];

    /** @var list<string> */
    private array $calls = [];

    /** @var list<list<string>> */
    private array $execs = [];

    /** @var list<list<string>> */
    private array $copies = [];

    private bool $copyFails = false;

    /** @var array{0: int, 1: string, 2: string} */
    private array $wpCliReply = [0, '', ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/pa-wpcli-'.bin2hex(random_bytes(4));
        mkdir(dirname($this->enginePhar()), 0777, true);
        file_put_contents($this->enginePhar(), str_repeat('x', 1234));
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->tmp]))->run();
        parent::tearDown();
    }

    public function test_it_runs_in_the_wordpress_container_as_the_owner_of_wp_content(): void
    {
        $this->containers('cli1|wpcli|wordpress:cli', 'db1|db|mysql:8.0', 'wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n0");
        $this->wpCliReply = [0, "[]\n", ''];

        $result = $this->wpCli(['plugin', 'list', '--format=json']);

        $this->assertSame(['stdout' => "[]\n", 'stderr' => '', 'exit_code' => 0], $result);
        $this->assertSame(['probe wp1', 'copy wp1', 'run wp1'], $this->calls, 'the cli and db containers are never probed');
        $this->assertSame(
            ['wp', 'wp1', '33:33', '/var/www/html', ...self::ENVIRONMENT, 'php', '-d', 'memory_limit=512M', ...WpCli::PHP_FLAGS, WordPressCli::CONTAINER_PHAR,
                '--path=/var/www/html', '--exec='.MustUsePlugin::MEND_ON_LOAD, 'plugin', 'list', '--format=json'],
            $this->lastExec()
        );
    }

    public function test_the_engines_phar_replaces_one_of_another_size(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n99");

        $this->wpCli(['core', 'version']);

        $this->assertSame([['wp', 'wp1', WordPressCli::ENGINE_FILE, WordPressCli::CONTAINER_PHAR]], $this->copies);
        $this->assertSame(['probe wp1', 'copy wp1', 'run wp1'], $this->calls);
    }

    public function test_a_phar_the_container_cannot_take_says_so(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n0");
        $this->copyFails = true;

        $this->expectRuntimeFailure('Could not copy WP-CLI into the container: its /tmp is not writable.', fn () => $this->wpCli(['core', 'version']));
        $this->assertSame(['probe wp1', 'copy wp1'], $this->calls);
    }

    public function test_without_the_engines_phar_nothing_is_asked(): void
    {
        unlink($this->enginePhar());
        $this->containers('wp1|wordpress|wordpress:latest');

        $this->expectRuntimeFailure("WP-CLI is missing from the engine: {$this->enginePhar()} is not readable.", fn () => $this->wpCli(['core', 'version']));
        $this->assertSame([], $this->calls);
    }

    public function test_the_probe_runs_as_the_containers_default_user_in_its_working_directory(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n1234");

        $this->wpCli(['core', 'version']);

        $this->assertSame(['wp', 'wp1', '', '', '0', 'sh', '-c', WordPressCli::PROBE, 'sh', '', WordPressCli::CONTAINER_PHAR], $this->execs[0]);
    }

    public function test_a_root_owned_install_gets_allow_root_and_a_current_phar_is_not_copied_again(): void
    {
        $this->containers('app1|app|myimage');
        $this->probe('app1', "/app/public\n0:0\n1234");

        $this->wpCli(['core', 'version']);

        $this->assertSame(['--allow-root', 'core', 'version'], array_slice($this->lastExec(), -3));
        $this->assertSame('0:0', $this->lastExec()[2]);
        $this->assertSame(['probe app1', 'run app1'], $this->calls);
    }

    public function test_containers_without_wordpress_are_skipped(): void
    {
        $this->containers('nginx1|web|nginx:alpine', 'fpm1|php|php:8.3-fpm');
        $this->probe('fpm1', "/srv/site\n82:82\n1234");

        $this->wpCli(['option', 'get', 'siteurl']);

        $this->assertSame('82:82', $this->lastExec()[2]);
        $this->assertSame(['probe nginx1', 'probe fpm1', 'run fpm1'], $this->calls);
    }

    public function test_wp_clis_own_exit_code_and_output_come_back(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n1234");
        $this->wpCliReply = [1, '', "Error: The 'nope' plugin could not be found.\n"];

        $result = $this->wpCli(['plugin', 'get', 'nope']);

        $this->assertSame(['stdout' => '', 'stderr' => "Error: The 'nope' plugin could not be found.\n", 'exit_code' => 1], $result);
    }

    public function test_a_stopped_project_says_so(): void
    {
        $this->containers();

        $this->expectRuntimeFailure('The application is not running. Deploy or start the project, then try again.', fn () => $this->wpCli(['core', 'version']));
    }

    public function test_a_daemon_that_cannot_list_its_containers_says_the_application_is_not_running(): void
    {
        $this->rows = null;

        $this->expectRuntimeFailure('The application is not running. Deploy or start the project, then try again.', fn () => $this->wpCli(['core', 'version']));
    }

    public function test_no_wordpress_anywhere_says_so(): void
    {
        $this->containers('db1|db|mysql:8.0');

        $this->expectNotFound(
            "No WordPress install was found in this project's containers. Pass path with the WordPress directory as the container sees it.",
            ['core', 'version']
        );
    }

    public function test_an_explicit_path_is_what_the_probe_looks_for(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');

        $this->expectNotFound('No WordPress with PHP was found at /var/www/site.', ['core', 'version', '--path=/var/www/site/']);
        $this->assertSame(['probe wp1 /var/www/site'], $this->calls);
    }

    public function test_a_path_is_taken_out_of_the_arguments_and_the_rest_follow_the_flags(): void
    {
        $this->containers('web1|web|wordpress:latest');
        $this->probe('web1', "/var/www/html\n33:33\n1234");

        $this->wpCli(['plugin', '--path=/var/www/html/', 'install', '--activate', 'akismet'], 'web');

        $this->assertSame(['probe web1 /var/www/html', 'run web1'], $this->calls);
        $this->assertSame(['--path=/var/www/html', '--exec='.MustUsePlugin::MEND_ON_LOAD, 'plugin', 'install', '--activate', 'akismet'], array_slice($this->lastExec(), -6));
    }

    public function test_the_last_path_wins(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/srv/site\n33:33\n1234\n1\n1013:1013");

        $this->wpCli(['--path=wp', 'db', 'export', '--path=/srv/site//', 'dump.sql']);

        $this->assertSame(['probe wp1 /srv/site', 'run wp1'], $this->calls);
        $this->assertSame(['db', 'export', 'dump.sql'], array_slice($this->lastExec(), -3));
    }

    public function test_a_service_picks_the_container(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest', 'wp2|blog|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n1234");
        $this->probe('wp2', "/var/www/html/blog\n33:33\n1234");

        $this->wpCli(['core', 'version'], 'blog');

        $this->assertContains('--path=/var/www/html/blog', $this->lastExec());
        $this->assertSame('/var/www/html/blog', $this->lastExec()[3]);
        $this->assertSame(['probe wp2', 'run wp2'], $this->calls);
    }

    public function test_a_service_may_be_named_by_the_containers_name(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest|site-wordpress-1', 'wp2|wordpress|wordpress:latest|blog-wordpress-1');
        $this->probe('wp1', "/var/www/html\n33:33\n1234");
        $this->probe('wp2', "/var/www/html\n33:33\n1234");

        $this->wpCli(['core', 'version'], 'blog-wordpress-1');

        $this->assertSame(['probe wp2', 'run wp2'], $this->calls);
    }

    public function test_an_unknown_service_says_so(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');

        $this->expectRuntimeFailure('No running container of this project belongs to service blog.', fn () => $this->wpCli(['core', 'version'], 'blog'));
    }

    public function test_a_relative_path_is_taken_from_the_containers_working_directory(): void
    {
        mkdir($this->tmp.'/app/blog/wp-includes', 0777, true);
        touch($this->tmp.'/app/blog/wp-includes/version.php');

        $probe = new Process(['sh', '-c', WordPressCli::PROBE, 'sh', 'blog', $this->tmp.'/none.phar'], $this->tmp.'/app');
        $probe->run();

        $this->assertSame($this->tmp.'/app/blog', strtok($probe->getOutput(), "\n"), $probe->getErrorOutput());
    }

    public function test_the_probe_finds_the_install_owner_and_the_phar_already_there(): void
    {
        $root = $this->tmp.'/site';
        mkdir($root.'/wp-includes', 0777, true);
        mkdir($root.'/wp-content');
        touch($root.'/wp-includes/version.php');
        file_put_contents($this->tmp.'/old.phar', 'abc');

        $probe = new Process(['sh', '-c', WordPressCli::PROBE, 'sh', $root, $this->tmp.'/old.phar']);
        $probe->run();

        if (trim((string) shell_exec('command -v php')) === '') {
            $this->assertSame(3, $probe->getExitCode());

            return;
        }
        $owner = fileowner($root.'/wp-content').':'.filegroup($root.'/wp-content');
        $tree = fileowner($root).':'.filegroup($root);
        $client = trim((string) shell_exec('command -v mysql && command -v mysqldump && command -v mysqlcheck')) !== '' ? '1' : '0';
        $this->assertSame("{$root}\n{$owner}\n3\n{$client}\n{$tree}\n", $probe->getOutput());

        $missing = new Process(['sh', '-c', WordPressCli::PROBE, 'sh', $this->tmp.'/nowhere', $this->tmp.'/old.phar']);
        $missing->run();
        $this->assertSame(4, $missing->getExitCode());
    }

    public function test_an_old_must_use_plugin_is_mended_before_wordpress_loads(): void
    {
        $dir = $this->tmp.'/site/wp-content/mu-plugins';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/panelalpha-app.php', "<?php\nif (PHP_SAPI === 'cli') {\n    exit(1);\n}\n");
        $runner = 'class WP_CLI { public static function get_runner(): object { return new class { public array $config = ['
            .var_export('path', true).' => '.var_export($this->tmp.'/site/', true).']; }; } }';

        foreach ([1, 2] as $_) {
            $mend = new Process([PHP_BINARY, '-r', $runner.MustUsePlugin::MEND_ON_LOAD]);
            $mend->run();
            $this->assertSame(0, $mend->getExitCode(), $mend->getErrorOutput());
        }

        $this->assertSame(
            "<?php\nif (PHP_SAPI === 'cli' && (get_included_files()[0] ?? '') === __FILE__) {\n    exit(1);\n}\n",
            file_get_contents($dir.'/panelalpha-app.php')
        );
    }

    /** WP-CLI loads the plugin as a must-use plugin; it must not take over the run. */
    public function test_the_shipped_plugin_runs_its_actions_only_as_the_main_script(): void
    {
        $plugin = dirname(__DIR__, 6).'/core/resources/sources/github.com/wordpress/wordpress/files/wp-content/mu-plugins/panelalpha-app.php';
        file_put_contents($this->tmp.'/host.php', '<?php function add_action() {} include $argv[1]; echo "host still running";');

        $loaded = new Process([PHP_BINARY, $this->tmp.'/host.php', $plugin, 'core', 'version']);
        $loaded->run();

        $this->assertSame('host still running', $loaded->getOutput(), $loaded->getErrorOutput());
    }

    public function test_a_database_command_runs_in_the_first_container_with_a_mysql_client(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest', 'cli1|wp-cli|wordpress:cli', 'db1|db|mysql:8.0');
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0");
        $this->probe('cli1', "/var/www/html\n33:33\n1234\n1\n1013:1013");

        $this->wpCli(['db', 'export', '/var/www/html/backup.sql', '--tables=wp_posts']);

        $this->assertSame(['probe wp1', 'probe db1', 'probe cli1', 'run cli1'], $this->calls);
        $this->assertSame('1013:1013', $this->lastExec()[2], 'as the owner of the tree the dump is written into');
    }

    public function test_any_other_command_still_runs_in_the_wordpress_container(): void
    {
        $this->containers('cli1|wp-cli|wordpress:cli', 'wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0");
        $this->probe('cli1', "/var/www/html\n33:33\n1234\n1\n1013:1013");

        $this->wpCli(['db', 'tables']);
        $tables = $this->lastExec();
        $this->wpCli(['plugin', 'list']);

        $this->assertSame(['probe wp1', 'run wp1', 'probe wp1', 'run wp1'], $this->calls);
        $this->assertSame('33:33', $tables[2]);
    }

    public function test_a_database_command_with_no_mysql_client_anywhere_says_so(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest', 'db1|db|mysql:8.0');
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0");

        try {
            $this->wpCli(['db', 'query', 'SELECT 1']);
            $this->fail('Expected DatabaseClientMissing');
        } catch (DatabaseClientMissing $e) {
            $this->assertSame(
                "WP-CLI's database commands need the MySQL client (mysql, mysqldump and mysqlcheck), and no running container that has WordPress has one."
                .' Run them in a service whose image has the client, such as wordpress:cli with the same volumes.',
                $e->getMessage()
            );
        }
        $this->assertSame(['probe wp1', 'probe db1'], $this->calls);
    }

    public function test_a_service_without_a_mysql_client_names_it(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest', 'cli1|wp-cli|wordpress:cli');
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0");

        try {
            $this->wpCli(['db', 'import', 'dump.sql'], 'wordpress');
            $this->fail('Expected DatabaseClientMissing');
        } catch (DatabaseClientMissing $e) {
            $this->assertStringContainsString('no running container in service wordpress that has WordPress has one', $e->getMessage());
        }
    }

    public function test_a_service_without_wordpress_names_it(): void
    {
        $this->containers('db1|db|mysql:8.0');

        $this->expectNotFound('No WordPress with PHP was found in service db. Pass path with the WordPress directory as the container sees it.', ['core', 'version'], 'db');
    }

    public function test_only_the_db_subcommands_that_run_the_client_need_it(): void
    {
        $this->assertTrue(WordPressCli::needsDatabaseClient(['db', 'query', 'SELECT 1']));
        $this->assertTrue(WordPressCli::needsDatabaseClient(['--skip-plugins', 'db', 'export', '-', '--path=/x']));
        $this->assertTrue(WordPressCli::needsDatabaseClient(['db', 'import', 'a.sql']));
        $this->assertTrue(WordPressCli::needsDatabaseClient(['db', 'import', 'a.sql', '--exec=exec("sed -i x a.sql");']));
        $this->assertFalse(WordPressCli::needsDatabaseClient(['db', 'tables']));
        $this->assertFalse(WordPressCli::needsDatabaseClient(['db', 'size']));
        $this->assertFalse(WordPressCli::needsDatabaseClient(['plugin', 'list']));
        $this->assertFalse(WordPressCli::needsDatabaseClient(['option', 'get', 'db']));
        $this->assertFalse(WordPressCli::needsDatabaseClient([]));
    }

    public function test_maintenance_mode_runs_in_the_wordpress_container_as_the_owner_of_the_install_directory(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest', 'cli1|wp-cli|wordpress:cli');
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0\n1013:1013");

        $this->wpCli(['maintenance-mode', 'activate']);

        $this->assertSame(['probe wp1', 'run wp1'], $this->calls, 'no MySQL client is needed');
        $this->assertSame('1013:1013', $this->lastExec()[2], 'as the owner of the directory .maintenance goes into');
    }

    public function test_only_maintenance_mode_writes_into_the_install_directory(): void
    {
        $this->assertTrue(WordPressCli::writesInstallDirectory(['maintenance-mode', 'activate']));
        $this->assertTrue(WordPressCli::writesInstallDirectory(['--skip-plugins', 'maintenance-mode', 'deactivate', '--path=/x']));
        $this->assertTrue(WordPressCli::writesInstallDirectory(['maintenance-mode', 'is-active']));
        $this->assertFalse(WordPressCli::writesInstallDirectory(['plugin', 'install', 'maintenance-mode']));
        $this->assertFalse(WordPressCli::writesInstallDirectory(['option', 'get', 'siteurl']));
        $this->assertFalse(WordPressCli::writesInstallDirectory([]));
    }

    public function test_a_missing_mysql_client_is_its_own_exception(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0");

        $this->expectException(DatabaseClientMissing::class);
        $this->wpCli(Core::DB_PROBE);
    }

    public function test_a_database_probe_without_a_mysql_client_comes_back_as_a_result(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n1\n33:33");
        $this->wpCliReply = [127, '', "/usr/bin/env: 'mysql': No such file or directory\n"];

        $result = $this->wpCli(Core::DB_PROBE);

        $this->assertSame(127, $result['exit_code']);
        $this->assertSame(['db', 'query', 'SELECT 1'], array_slice($this->lastExec(), -3));
        $this->assertDoesNotMatchRegularExpression(Core::MYSQL_CLIENT_ERROR_PATTERN, $result['stderr']);
    }

    public function test_project_routes_wp_cli_on_a_container_project_to_the_runner(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n1234");
        $this->wpCliReply = [0, "6.8.3\n", ''];

        $result = $this->dind()->project()->runWpCli(['core', 'version']);

        $this->assertSame("6.8.3\n", $result['stdout']);
    }

    /**
     * Each row is `id|service|image[|name]`; the name defaults to compose's.
     */
    private function containers(string ...$rows): void
    {
        $this->rows = array_map(static function (string $row): array {
            [$id, $service, $image, $name] = array_pad(explode('|', $row), 4, null);

            return [
                'Id' => $id,
                'Names' => ['/'.($name ?? "project-{$service}-1")],
                'Image' => $image,
                'Labels' => ['com.docker.compose.project' => 'project', 'com.docker.compose.service' => $service],
            ];
        }, $rows);
    }

    private function probe(string $id, string $out): void
    {
        $this->probes[$id] = $out."\n";
    }

    private function enginePhar(): string
    {
        return $this->tmp.'/templates/user/dind/project/'.WordPressCli::ENGINE_PHAR;
    }

    /** @return list<string> */
    private function lastExec(): array
    {
        $last = end($this->execs);
        $this->assertIsArray($last);

        return $last;
    }

    /**
     * @param  list<string>  $args
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function wpCli(array $args, ?string $service = null): array
    {
        return $this->dind()->wordPressCli()->run($args, $service);
    }

    /** @param  \Closure(): mixed  $run */
    private function expectRuntimeFailure(string $message, \Closure $run): void
    {
        try {
            $run();
            $this->fail("Expected: {$message}");
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(WordPressNotFound::class, $e);
            $this->assertNotInstanceOf(DatabaseClientMissing::class, $e);
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** @param  list<string>  $args */
    private function expectNotFound(string $message, array $args, ?string $service = null): void
    {
        try {
            $this->wpCli($args, $service);
            $this->fail('Expected WordPressNotFound');
        } catch (WordPressNotFound $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function dind(): Dind
    {
        $model = new ModelsUser;
        $model->username = 'wp';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);

        $runtime = (new Project($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function system(): System
    {
        return new class($this->tmp, $this) extends System
        {
            public function __construct(private string $root, private WordPressCliTest $test) {}

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->root.'/home/'.$username;
            }

            public function projectDirPath(string $username): string
            {
                return $this->root.'/users/'.$username;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->test->answer(array_values((array) $cmd));
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->test->execute(array_values((array) $cmd));
            }
        };
    }

    /** @param  list<string>  $cmd */
    public function answer(array $cmd): string
    {
        $values = LocalRoot::values($cmd);

        return match (LocalRoot::action($cmd)) {
            'account-docker.listContainers' => $this->listed($values),
            'account-docker.putEngineFile' => $this->copied($values),
            default => throw new \LogicException('Unexpected request: '.implode(' ', $cmd)),
        };
    }

    /** @param  list<string>  $cmd */
    public function execute(array $cmd): Process
    {
        $this->assertSame('account-docker.exec', LocalRoot::action($cmd), implode(' ', $cmd));
        $values = LocalRoot::values($cmd);
        $this->execs[] = $values;
        $id = $values[1];
        $argv = array_slice($values, 5 + 2 * (int) $values[4]);

        if ($argv[0] === 'php') {
            $this->calls[] = "run {$id}";

            return $this->process(...$this->wpCliReply);
        }
        $this->calls[] = "probe {$id}".($argv[4] === '' ? '' : " {$argv[4]}");

        return isset($this->probes[$id]) ? $this->process(0, $this->probes[$id], '') : $this->process(4, '', '');
    }

    /** @param  list<string>  $values */
    private function listed(array $values): string
    {
        $this->assertSame(['wp', '', '0'], $values, 'the running containers, unfiltered');
        if ($this->rows === null) {
            throw new DockerErrorException('container wp is not running');
        }

        return (string) json_encode($this->rows);
    }

    /** @param  list<string>  $values */
    private function copied(array $values): string
    {
        $this->calls[] = "copy {$values[1]}";
        $this->copies[] = $values;
        if ($this->copyFails) {
            throw new DockerErrorException('Error response from daemon: Could not find the file /tmp in container wp1');
        }

        return '';
    }

    private function process(int $code, string $out, string $err): Process
    {
        $process = new Process([
            PHP_BINARY, '-r',
            'fwrite(STDOUT, '.var_export($out, true).'); fwrite(STDERR, '.var_export($err, true).'); exit('.$code.');',
        ]);
        $process->run();

        return $process;
    }
}
