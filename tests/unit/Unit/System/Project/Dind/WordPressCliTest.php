<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\WordPressCli;
use App\System\Project\PhpHosting\WordPress\Core;
use App\System\Project\PhpHosting\WordPress\DatabaseClientMissing;
use App\System\Project\PhpHosting\WordPress\MustUsePlugin;
use App\System\Project\PhpHosting\WordPress\WordPressNotFound;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * WP-CLI for WordPress in a project's own containers. The account script is
 * run for real against a `docker` shim on PATH, so container choice, the phar
 * copy, the user WP-CLI runs as and the failure messages are all exercised.
 */
class WordPressCliTest extends TestCase
{
    private string $tmp;

    /** @var list<list<string>> */
    private array $commands = [];

    private ?Process $reply = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/pa-wpcli-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/bin', 0777, true);
        file_put_contents($this->tmp.'/wp-cli.phar', str_repeat('x', 1234));
        file_put_contents($this->tmp.'/bin/docker', self::SHIM);
        chmod($this->tmp.'/bin/docker', 0755);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->tmp]))->run();
        parent::tearDown();
    }

    public function test_it_runs_in_the_wordpress_container_as_the_owner_of_wp_content(): void
    {
        $this->containers("cli1|wpcli|wordpress:cli\ndb1|db|mysql:8.0\nwp1|wordpress|wordpress:latest");
        $this->probe('wp1', "/var/www/html\n33:33\n0");

        $process = $this->runScript(['plugin', 'list', '--format=json']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(
            'RAN -u 33:33 -e HOME=/tmp -e WP_CLI_CACHE_DIR=/tmp/.wp-cli-cache -w /var/www/html wp1 '
            .'php -d memory_limit=512M '.$this->dest().' --path=/var/www/html --exec='.MustUsePlugin::MEND_ON_LOAD.' plugin list --format=json',
            trim($process->getOutput())
        );
        $this->assertSame(['probe wp1', 'copy wp1', 'run wp1'], $this->calls(), 'the cli and db containers are never probed');
        $this->assertSame(1234, filesize($this->dest()), 'the phar was streamed into the container');
    }

    public function test_a_root_owned_install_gets_allow_root_and_a_current_phar_is_not_copied_again(): void
    {
        $this->containers('app1|app|myimage');
        $this->probe('app1', "/app/public\n0:0\n1234");

        $process = $this->runScript(['core', 'version']);

        $this->assertStringContainsString('--allow-root core version', $process->getOutput());
        $this->assertSame(['probe app1', 'run app1'], $this->calls());
    }

    public function test_containers_without_wordpress_are_skipped(): void
    {
        $this->containers("nginx1|web|nginx:alpine\nfpm1|php|php:8.3-fpm");
        $this->probe('fpm1', "/srv/site\n82:82\n1234");

        $process = $this->runScript(['option', 'get', 'siteurl']);

        $this->assertStringContainsString('-u 82:82', $process->getOutput());
        $this->assertSame(['probe nginx1', 'probe fpm1', 'run fpm1'], $this->calls());
    }

    public function test_wp_clis_own_exit_code_and_output_come_back(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');
        $this->probe('wp1', "/var/www/html\n33:33\n1234");
        putenv('SHIM_EXIT=1');

        try {
            $process = $this->runScript(['plugin', 'get', 'nope']);
        } finally {
            putenv('SHIM_EXIT');
        }

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringNotContainsString(WordPressCli::MARKER, $process->getErrorOutput());
    }

    public function test_a_stopped_project_says_so(): void
    {
        $this->containers('');

        $process = $this->runScript(['core', 'version']);

        $this->assertSame(70, $process->getExitCode());
        $this->assertSame(
            WordPressCli::MARKER.' The application is not running. Deploy or start the project, then try again.',
            trim($process->getErrorOutput())
        );
    }

    public function test_no_wordpress_anywhere_says_so(): void
    {
        $this->containers('db1|db|mysql:8.0');

        $process = $this->runScript(['core', 'version']);

        $this->assertSame(WordPressCli::NOT_FOUND_EXIT, $process->getExitCode());
        $this->assertStringContainsString('No WordPress install was found in this project\'s containers.', $process->getErrorOutput());
    }

    public function test_an_explicit_path_is_what_the_probe_looks_for(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');

        $process = $this->runScript(['core', 'version', '--path=/var/www/site/']);

        $this->assertSame(['probe wp1 /var/www/site'], $this->calls());
        $this->assertSame(WordPressCli::NOT_FOUND_EXIT, $process->getExitCode());
        $this->assertStringContainsString('No WordPress with PHP was found at /var/www/site.', $process->getErrorOutput());
    }

    public function test_a_service_picks_the_container(): void
    {
        $this->containers("wp1|wordpress|wordpress:latest\nwp2|blog|wordpress:latest");
        $this->probe('wp1', "/var/www/html\n33:33\n1234");
        $this->probe('wp2', "/var/www/html/blog\n33:33\n1234");

        $process = $this->runScript(['core', 'version'], 'blog');

        $this->assertStringContainsString('--path=/var/www/html/blog', $process->getOutput());
        $this->assertSame(['probe wp2', 'run wp2'], $this->calls());
    }

    public function test_an_unknown_service_says_so(): void
    {
        $this->containers('wp1|wordpress|wordpress:latest');

        $process = $this->runScript(['core', 'version'], 'blog');

        $this->assertSame(70, $process->getExitCode());
        $this->assertSame(
            WordPressCli::MARKER.' No running container of this project belongs to service blog.',
            trim($process->getErrorOutput())
        );
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

    public function test_a_script_failure_is_an_exception_and_wp_cli_output_is_a_result(): void
    {
        $this->reply = $this->process(70, '', WordPressCli::MARKER." No WordPress install was found.\n");
        try {
            $this->dind()->wordPressCli()->run(['core', 'version']);
            $this->fail('Expected the script failure to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('No WordPress install was found.', $e->getMessage());
        }

        $this->reply = $this->process(1, '', "Error: The 'nope' plugin could not be found.\n");
        $result = $this->dind()->wordPressCli()->run(['plugin', 'get', 'nope']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame("Error: The 'nope' plugin could not be found.\n", $result['stderr']);

        $exec = array_search('exec', $this->commands[0], true);
        $this->assertSame(['-u', '1000:1000', '-T', 'dind', 'bash', '-c'], array_slice($this->commands[0], $exec + 1, 6));
        $this->assertSame(['wp', '', '', WordPressCli::MEMORY_LIMIT, MustUsePlugin::MEND_ON_LOAD, '0', '0', 'core', 'version'], array_slice($this->commands[0], -9));
    }

    public function test_no_wordpress_is_its_own_exception_and_other_script_failures_are_not(): void
    {
        $this->reply = $this->process(WordPressCli::NOT_FOUND_EXIT, '', WordPressCli::MARKER." No WordPress with PHP was found at /var/www/site.\n");
        try {
            $this->dind()->wordPressCli()->run(['core', 'is-installed']);
            $this->fail('Expected WordPressNotFound');
        } catch (WordPressNotFound $e) {
            $this->assertSame('No WordPress with PHP was found at /var/www/site.', $e->getMessage());
        }

        $this->reply = $this->process(70, '', WordPressCli::MARKER." The application is not running.\n");
        try {
            $this->dind()->wordPressCli()->run(['core', 'is-installed']);
            $this->fail('Expected the script failure to surface');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(WordPressNotFound::class, $e);
        }
    }

    public function test_a_database_command_runs_in_the_first_container_with_a_mysql_client(): void
    {
        $this->containers("wp1|wordpress|wordpress:latest\ncli1|wp-cli|wordpress:cli\ndb1|db|mysql:8.0");
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0");
        $this->probe('cli1', "/var/www/html\n33:33\n1234\n1\n1013:1013");

        $process = $this->runScript(['db', 'export', '/var/www/html/backup.sql', '--tables=wp_posts']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(['probe wp1', 'probe db1', 'probe cli1', 'run cli1'], $this->calls());
        $this->assertStringContainsString('-u 1013:1013', $process->getOutput(), 'as the owner of the tree the dump is written into');
    }

    public function test_any_other_command_still_runs_in_the_wordpress_container(): void
    {
        $this->containers("cli1|wp-cli|wordpress:cli\nwp1|wordpress|wordpress:latest");
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0");
        $this->probe('cli1', "/var/www/html\n33:33\n1234\n1\n1013:1013");

        $tables = $this->runScript(['db', 'tables']);
        $this->runScript(['plugin', 'list']);

        $this->assertSame(['probe wp1', 'run wp1', 'probe wp1', 'run wp1'], $this->calls());
        $this->assertStringContainsString('-u 33:33', $tables->getOutput());
    }

    public function test_a_database_command_with_no_mysql_client_anywhere_says_so(): void
    {
        $this->containers("wp1|wordpress|wordpress:latest\ndb1|db|mysql:8.0");
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0");

        $process = $this->runScript(['db', 'query', 'SELECT 1']);

        $this->assertSame(WordPressCli::NO_DATABASE_CLIENT_EXIT, $process->getExitCode());
        $this->assertSame(['probe wp1', 'probe db1'], $this->calls());
        $this->assertStringContainsString("WP-CLI's database commands need the MySQL client", $process->getErrorOutput());
        $this->assertStringContainsString('such as wordpress:cli', $process->getErrorOutput());
    }

    public function test_a_service_without_a_mysql_client_names_it(): void
    {
        $this->containers("wp1|wordpress|wordpress:latest\ncli1|wp-cli|wordpress:cli");
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0");

        $process = $this->runScript(['db', 'import', 'dump.sql'], 'wordpress');

        $this->assertSame(WordPressCli::NO_DATABASE_CLIENT_EXIT, $process->getExitCode());
        $this->assertStringContainsString('no running container in service wordpress that has WordPress has one', $process->getErrorOutput());
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
        $this->containers("wp1|wordpress|wordpress:latest\ncli1|wp-cli|wordpress:cli");
        $this->probe('wp1', "/var/www/html\n33:33\n1234\n0\n1013:1013");

        $process = $this->runScript(['maintenance-mode', 'activate']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(['probe wp1', 'run wp1'], $this->calls(), 'no MySQL client is needed');
        $this->assertStringContainsString('-u 1013:1013', $process->getOutput(), 'as the owner of the directory .maintenance goes into');
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

    public function test_the_runner_is_told_which_commands_run_as_the_install_owner(): void
    {
        $command = fn (array $args): array => array_slice((new WordPressCli($this->dind()))->command($args), 8, 2);

        $this->assertSame(['0', '0'], $command(['plugin', 'list']));
        $this->assertSame(['0', '1'], $command(['maintenance-mode', 'activate']));
        $this->assertSame(['1', '1'], $command(['db', 'export', 'backup.sql']));
    }

    public function test_a_missing_mysql_client_is_its_own_exception(): void
    {
        $this->reply = $this->process(WordPressCli::NO_DATABASE_CLIENT_EXIT, '', WordPressCli::MARKER." WP-CLI's database commands need the MySQL client.\n");

        try {
            $this->dind()->wordPressCli()->run(Core::DB_PROBE);
            $this->fail('Expected DatabaseClientMissing');
        } catch (DatabaseClientMissing $e) {
            $this->assertSame("WP-CLI's database commands need the MySQL client.", $e->getMessage());
        }
        $this->assertSame(['1', '1', 'db', 'query', 'SELECT 1'], array_slice($this->commands[0], -5));
    }

    public function test_a_database_probe_without_a_mysql_client_comes_back_as_a_result(): void
    {
        $this->reply = $this->process(127, '', "/usr/bin/env: 'mysql': No such file or directory\n");

        $result = $this->dind()->wordPressCli()->run(Core::DB_PROBE);

        $this->assertSame(127, $result['exit_code']);
        $this->assertSame(['db', 'query', 'SELECT 1'], array_slice($this->commands[0], -3));
        $this->assertDoesNotMatchRegularExpression(Core::MYSQL_CLIENT_ERROR_PATTERN, $result['stderr']);
    }

    public function test_project_routes_wp_cli_on_a_container_project_to_the_runner(): void
    {
        $this->reply = $this->process(0, "6.8.3\n", '');

        $result = $this->dind()->project()->runWpCli(['core', 'version']);

        $this->assertSame("6.8.3\n", $result['stdout']);
    }

    private const SHIM = <<<'SH'
        #!/bin/bash
        log="$SHIM_DIR/calls"
        if [ "$1" = ps ]; then cat "$SHIM_DIR/ps"; exit 0; fi
        shift
        if [ "$1" = -i ]; then echo "copy $2" >> "$log"; shift 2; exec "$@"; fi
        if [ "$1" = -u ]; then
          id=$9; echo "run $id" >> "$log"; echo "RAN $*"; exit "${SHIM_EXIT:-0}"
        fi
        id=$1; want=$6
        echo "probe $id${want:+ $want}" >> "$log"
        [ -f "$SHIM_DIR/probe-$id" ] || exit 4
        cat "$SHIM_DIR/probe-$id"
        SH;

    private function containers(string $rows): void
    {
        file_put_contents($this->tmp.'/ps', $rows === '' ? '' : $rows."\n");
    }

    private function probe(string $id, string $out): void
    {
        file_put_contents($this->tmp.'/probe-'.$id, $out."\n");
    }

    private function dest(): string
    {
        return $this->tmp.'/container.phar';
    }

    /** @return list<string> */
    private function calls(): array
    {
        return file($this->tmp.'/calls', FILE_IGNORE_NEW_LINES) ?: [];
    }

    /** @param list<string> $args */
    private function runScript(array $args, ?string $service = null): Process
    {
        $command = (new WordPressCli($this->dind()))->command($args, $service);
        $command[2] = strtr($command[2], [
            escapeshellarg(WordPressCli::PHAR) => escapeshellarg($this->tmp.'/wp-cli.phar'),
            escapeshellarg(WordPressCli::CONTAINER_PHAR) => escapeshellarg($this->dest()),
        ]);

        $process = new Process($command, null, [
            'PATH' => $this->tmp.'/bin:'.getenv('PATH'),
            'SHIM_DIR' => $this->tmp,
            'SHIM_EXIT' => getenv('SHIM_EXIT') ?: '0',
        ]);
        $process->run();

        return $process;
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
        $test = $this;

        return new class($this->tmp, $test) extends System
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

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->test->replyTo(is_array($cmd) ? array_values($cmd) : [$cmd]);
            }
        };
    }

    /** @param list<string> $cmd */
    public function replyTo(array $cmd): Process
    {
        $this->commands[] = $cmd;

        return $this->reply ?? $this->process(0, '', '');
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
