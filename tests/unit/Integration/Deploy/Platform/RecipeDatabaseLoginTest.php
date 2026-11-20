<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A copy names a database of its own, so a recipe's `database-login` puts DB_*
 * and APP_URL back into the config file the installer wrote, on every boot.
 */
class RecipeDatabaseLoginTest extends TestCase
{
    private const COMMAND = 'database-login';

    private const COPY_ENV = [
        'DB_HOST' => 'db.copy',
        'DB_PORT' => '3307',
        'DB_USERNAME' => 'copy_app',
        'DB_PASSWORD' => 'copy-secret',
        'DB_DATABASE' => 'copy_app',
        'APP_URL' => 'https://copy.example.com',
    ];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-db-login-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_phpbb_config_is_pointed_at_the_copys_database(): void
    {
        $this->install('config.php', <<<'PHP'
            <?php
            // phpBB auto-generated configuration file
            // Do not change anything in this file!
            $dbms = 'phpbb\\db\\driver\\mysqli';
            $dbhost = 'db.source';
            $dbport = '';
            $dbname = 'source_app';
            $dbuser = 'source_app';
            $dbpasswd = 'it\'s-the-source';
            $table_prefix = 'phpbb_';
            $phpbb_adm_relative_path = 'adm/';
            $acm_type = 'phpbb\\cache\\driver\\file';

            @define('PHPBB_INSTALLED', true);
            @define('PHPBB_ENVIRONMENT', 'production');
            PHP);

        $this->bootTwice($this->command('phpbb'));

        $this->assertSame([
            'dbms' => 'phpbb\\db\\driver\\mysqli',
            'dbhost' => 'db.copy',
            'dbport' => '3307',
            'dbname' => 'copy_app',
            'dbuser' => 'copy_app',
            'dbpasswd' => 'copy-secret',
            'table_prefix' => 'phpbb_',
        ], $this->evaluate('config.php', '', 'compact("dbms", "dbhost", "dbport", "dbname", "dbuser", "dbpasswd", "table_prefix")'));
    }

    public function test_osticket_config_is_pointed_at_the_copys_database(): void
    {
        $this->install('include/ost-config.php', <<<'PHP'
            <?php
            if(!strcasecmp(basename($_SERVER['SCRIPT_NAME']),basename(__FILE__)) || !defined('INCLUDE_DIR'))
                die('kwaheri rafiki!');
            define('OSTINSTALLED',TRUE);
            define('SECRET_SALT','abc123');
            define('DBTYPE','mysql');
            define('DBHOST','db.source');
            define('DBNAME','source_app');
            define('DBUSER','source_app');
            define('DBPASS','source-secret');
            define('TABLE_PREFIX','ost_');
            PHP);

        $this->bootTwice($this->command('osticket'));

        $this->assertSame(
            ['db.copy:3307', 'copy_app', 'copy_app', 'copy-secret', 'ost_', 'abc123'],
            $this->evaluate('include/ost-config.php', 'define("INCLUDE_DIR", "/");', '[DBHOST, DBNAME, DBUSER, DBPASS, TABLE_PREFIX, SECRET_SALT]')
        );
    }

    public function test_opencart_storefront_and_admin_configs_are_pointed_at_the_copy(): void
    {
        foreach (['upload/config.php' => 'Catalog', 'upload/admin/config.php' => 'Admin'] as $file => $application) {
            $admin = $application === 'Admin';
            $this->install($file, "<?php\n// APPLICATION\ndefine('APPLICATION', '{$application}');\n\n// HTTP\n"
                .($admin ? "define('HTTP_SERVER', 'https://source.example.com/admin/');\ndefine('HTTP_CATALOG', 'https://source.example.com/');\n" : "define('HTTP_SERVER', 'https://source.example.com/');\n")
                ."\n// DB\ndefine('DB_DRIVER', 'mysqli');\ndefine('DB_HOSTNAME', 'db.source');\ndefine('DB_USERNAME', 'source_app');\n"
                ."define('DB_PASSWORD', 'source-secret');\ndefine('DB_DATABASE', 'source_app');\ndefine('DB_PREFIX', 'oc_');\ndefine('DB_PORT', '3306');\n");
        }

        $this->bootTwice($this->command('opencart'));

        $db = ['db.copy', 'copy_app', 'copy-secret', 'copy_app', '3307', 'oc_'];
        $read = '[DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT, DB_PREFIX, HTTP_SERVER]';
        $this->assertSame([...$db, 'https://copy.example.com/'], $this->evaluate('upload/config.php', '', $read));
        $this->assertSame(
            [...$db, 'https://copy.example.com/admin/', 'https://copy.example.com/'],
            $this->evaluate('upload/admin/config.php', '', substr($read, 0, -1).', HTTP_CATALOG]')
        );
    }

    public function test_flarum_config_is_pointed_at_the_copys_database_and_address(): void
    {
        $this->install('config.php', '<?php return '.var_export([
            'debug' => false,
            'database' => [
                'driver' => 'mysql', 'host' => 'db.source', 'port' => 3306, 'database' => 'source_app',
                'username' => 'source_app', 'password' => 'source-secret', 'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'prefix' => 'flarum_', 'strict' => false, 'engine' => 'InnoDB',
                'prefix_indexes' => true,
            ],
            'url' => 'https://source.example.com',
            'paths' => ['api' => 'api', 'admin' => 'admin'],
        ], true).';');

        $this->bootTwice($this->command('flarum'));

        $config = $this->evaluate('config.php', '', 'include $argv[1]');
        $this->assertSame('https://copy.example.com', $config['url']);
        $this->assertSame(['host' => 'db.copy', 'port' => 3307, 'database' => 'copy_app', 'username' => 'copy_app', 'password' => 'copy-secret'], array_intersect_key(
            $config['database'],
            array_flip(['host', 'port', 'database', 'username', 'password'])
        ));
        $this->assertSame('flarum_', $config['database']['prefix']);
        $this->assertSame(['api' => 'api', 'admin' => 'admin'], $config['paths']);
    }

    public function test_magento_env_php_is_pointed_at_the_copys_database(): void
    {
        $this->install('app/etc/env.php', "<?php\nreturn ".var_export([
            'backend' => ['frontName' => 'admin_x'],
            'crypt' => ['key' => 'k'],
            'db' => ['table_prefix' => '', 'connection' => ['default' => [
                'host' => 'db.source', 'dbname' => 'source_app', 'username' => 'source_app', 'password' => 'source-secret',
                'model' => 'mysql4', 'engine' => 'innodb', 'initStatements' => 'SET NAMES utf8;', 'active' => '1',
            ]]],
        ], true).";\n");

        $this->bootTwice($this->command('magento'));

        $env = $this->evaluate('app/etc/env.php', '', 'include $argv[1]');
        $this->assertSame([
            'host' => 'db.copy:3307', 'dbname' => 'copy_app', 'username' => 'copy_app', 'password' => 'copy-secret',
            'model' => 'mysql4', 'engine' => 'innodb', 'initStatements' => 'SET NAMES utf8;', 'active' => '1',
        ], $env['db']['connection']['default']);
        $this->assertSame(['key' => 'k'], $env['crypt']);
    }

    public function test_suitecrm_config_is_pointed_at_the_copys_database_and_address(): void
    {
        $this->install('config.php', "<?php\n// created: 2026-10-01 10:00:00\n\$sugar_config = ".var_export([
            'dbconfig' => [
                'db_host_name' => 'db.source', 'db_host_instance' => '', 'db_user_name' => 'source_app',
                'db_password' => 'source-secret', 'db_name' => 'source_app', 'db_type' => 'mysql', 'db_port' => '',
                'db_manager' => 'MysqliManager',
            ],
            'host_name' => 'source.example.com',
            'installer_locked' => true,
            'site_url' => 'https://source.example.com',
            'unique_key' => 'u',
        ], true).";\n");

        $this->bootTwice($this->command('suitecrm'));

        $config = $this->evaluate('config.php', '', '$sugar_config');
        $this->assertSame([
            'db_host_name' => 'db.copy', 'db_host_instance' => '', 'db_user_name' => 'copy_app',
            'db_password' => 'copy-secret', 'db_name' => 'copy_app', 'db_type' => 'mysql', 'db_port' => '3307',
            'db_manager' => 'MysqliManager',
        ], $config['dbconfig']);
        $this->assertSame(['copy.example.com', 'https://copy.example.com', 'u'], [$config['host_name'], $config['site_url'], $config['unique_key']]);
    }

    public function test_chamilo_1_11_configuration_is_pointed_at_the_copy(): void
    {
        $this->install('app/config/configuration.php', <<<'PHP'
            <?php
            $_configuration['db_host'] = 'db.source';
            $_configuration['db_port'] = '3306';
            $_configuration['main_database'] = 'source_app';
            $_configuration['db_user'] = 'source_app';
            $_configuration['db_password'] = 'source-secret';
            $_configuration['db_manager_enabled'] = false;
            $_configuration['root_web'] = 'https://source.example.com/';
            $_configuration['security_key'] = 'sk';
            PHP);

        $this->bootTwice($this->command('chamilo'));

        $this->assertSame(
            ['db.copy', '3307', 'copy_app', 'copy_app', 'copy-secret', 'https://copy.example.com/', 'sk'],
            $this->evaluate('app/config/configuration.php', '', 'array_values(array_diff_key($_configuration, ["db_manager_enabled" => 1]))')
        );
    }

    public function test_chamilo_2_dotenv_is_pointed_at_the_copys_database(): void
    {
        $this->install('.env', "DATABASE_HOST='db.source'\nDATABASE_PORT='3306'\nDATABASE_NAME='source_app'\n"
            ."DATABASE_USER='source_app'\nDATABASE_PASSWORD='source-secret'\nAPP_SECRET='s'\nAPP_INSTALLED='1'\n");

        $this->bootTwice($this->command('chamilo'));

        $this->assertSame("DATABASE_HOST='db.copy'\nDATABASE_PORT='3307'\nDATABASE_NAME='copy_app'\n"
            ."DATABASE_USER='copy_app'\nDATABASE_PASSWORD='copy-secret'\nAPP_SECRET='s'\nAPP_INSTALLED='1'\n", $this->read('.env'));
    }

    public function test_forte_htconfig_is_pointed_at_the_copys_database_and_address(): void
    {
        $this->install('.htconfig.php', <<<'PHP'
            <?php

            $db_host = 'db.source';
            $db_port = '0';
            $db_user = 'source_app';
            $db_pass = 'source-secret';
            $db_data = 'source_app';
            $db_type = '0'; // Integer. 0 or unset for mysql, 1 for postgres
            $db_vrsn = '10.11';

            App::$config['system']['timezone'] = 'UTC';
            App::$config['system']['baseurl'] = 'https://source.example.com';
            PHP);

        $this->bootTwice($this->command('codeberg.org/fortified/forte'));

        $this->assertSame(
            ['db.copy', '3307', 'copy_app', 'copy-secret', 'copy_app', '0', 'https://copy.example.com', 'UTC'],
            $this->evaluate('.htconfig.php', 'class App { public static $config = []; }', '[$db_host, $db_port, $db_user, $db_pass, $db_data, $db_type, App::$config["system"]["baseurl"], App::$config["system"]["timezone"]]')
        );
    }

    public function test_indexhibit_config_is_pointed_at_the_copys_database(): void
    {
        $this->install('ndxzsite/config/config.php', <<<'PHP'
            <?php  if (!defined('SITE')) exit('No direct script access allowed');

            $indx['db'] 		= 'source_app';
            $indx['user'] 		= 'source_app';
            $indx['pass'] 		= 'source-secret';
            $indx['host'] 		= 'db.source';
            $indx['sql']		= 'mysql';

            if (!defined('PX')) { define('PX', 'ndxz_'); }
            PHP);

        $this->bootTwice($this->command('github.com/indexhibit/indexhibit'));

        $this->assertSame(
            ['db' => 'copy_app', 'user' => 'copy_app', 'pass' => 'copy-secret', 'host' => 'db.copy', 'sql' => 'mysql'],
            $this->evaluate('ndxzsite/config/config.php', 'define("SITE", 1);', '$indx')
        );
    }

    public function test_live_helper_chat_settings_are_pointed_at_the_copys_database(): void
    {
        $this->install('settings/settings.ini.php', "<?php\n return ".var_export(['settings' => [
            'site' => ['title' => 'Live helper Chat', 'installed' => true, 'secrethash' => 'h'],
            'db' => [
                'host' => 'db.source', 'user' => 'source_app', 'password' => 'source-secret', 'database' => 'source_app',
                'port' => 3306, 'use_slaves' => false, 'tz' => '',
            ],
        ]], true).";\n?>");

        $this->bootTwice($this->command('github.com/livehelperchat/livehelperchat'));

        $settings = $this->evaluate('settings/settings.ini.php', '', 'include $argv[1]')['settings'];
        $this->assertSame(
            ['host' => 'db.copy', 'user' => 'copy_app', 'password' => 'copy-secret', 'database' => 'copy_app', 'port' => 3307, 'use_slaves' => false, 'tz' => ''],
            $settings['db']
        );
        $this->assertSame('h', $settings['site']['secrethash']);
    }

    public function test_cloudlog_database_and_base_url_are_pointed_at_the_copy(): void
    {
        $this->install('application/config/database.php', <<<'PHP'
            <?php
            defined('BASEPATH') OR exit('No direct script access allowed');
            $active_group = 'default';
            $query_builder = TRUE;

            $db['default'] = array(
            	'dsn'	=> '',
            	'hostname' => 'db.source',
            	'username' => 'source_app',
            	'password' => 'source-secret',
            	'database' => 'source_app',
            	'dbdriver' => 'mysqli',
            	'dbprefix' => '',
            );
            PHP);
        $this->install('application/config/config.php', <<<'PHP'
            <?php
            defined('BASEPATH') OR exit('No direct script access allowed');
            $config['directory'] = "";
            $config['base_url']	= 'https://source.example.com/';
            $config['index_page'] = '';
            PHP);

        $this->bootTwice($this->command('github.com/magicbug/cloudlog'));

        $this->assertSame(
            ['db.copy', 'copy_app', 'copy-secret', 'copy_app', 'mysqli'],
            array_values(array_intersect_key(
                $this->evaluate('application/config/database.php', 'define("BASEPATH", 1);', '$db["default"]'),
                array_flip(['hostname', 'username', 'password', 'database', 'dbdriver'])
            ))
        );
        $this->assertSame(
            'https://copy.example.com/',
            $this->evaluate('application/config/config.php', 'define("BASEPATH", 1);', '$config["base_url"]')
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function notInstalledYet(): iterable
    {
        yield 'phpbb, the empty config.php the recipe touches' => ['phpbb', ['config.php' => '']];
        yield 'osticket, the sample config copied for the installer' => ['osticket', [
            'include/ost-config.php' => "<?php\ndefine('OSTINSTALLED',FALSE);\ndefine('DBHOST','%CONFIG-DBHOST');\ndefine('DBNAME','%CONFIG-DBNAME');\n",
        ]];
        yield 'opencart, the empty configs the recipe touches' => ['opencart', ['upload/config.php' => '', 'upload/admin/config.php' => '']];
        yield 'flarum, no config.php' => ['flarum', []];
        yield 'magento, no env.php' => ['magento', []];
        yield 'suitecrm, no config.php' => ['suitecrm', []];
        yield 'chamilo 2, the .env before the installer finished' => ['chamilo', [
            '.env' => "DATABASE_HOST='{{DATABASE_HOST}}'\nDATABASE_NAME='{{DATABASE_NAME}}'\nAPP_INSTALLED='{{APP_INSTALLED}}'\n",
        ]];
        yield 'forte, the empty .htconfig.php the recipe creates' => ['codeberg.org/fortified/forte', ['.htconfig.php' => '']];
        yield 'indexhibit, only the example config' => ['github.com/indexhibit/indexhibit', [
            'ndxzsite/config/config.example.php' => "<?php\n\$indx['db'] = 'databasename';\n",
        ]];
        yield 'live helper chat, the empty settings file the recipe creates' => ['github.com/livehelperchat/livehelperchat', ['settings/settings.ini.php' => '']];
        yield 'cloudlog, nothing behind the symlinks yet' => ['github.com/magicbug/cloudlog', []];
    }

    /**
     * @param  array<string, string>  $files
     */
    #[DataProvider('notInstalledYet')]
    public function test_a_site_whose_installer_has_not_run_is_left_to_it(string $recipe, array $files): void
    {
        foreach ($files as $path => $contents) {
            $this->install($path, $contents);
        }

        $boot = $this->boot($this->command($recipe));

        $this->assertSame(0, $boot['status'], $boot['output']);
        $this->assertStringNotContainsString('now points at', $boot['output']);
        foreach ($files as $path => $contents) {
            $this->assertSame($contents, $this->read($path), $path);
        }
    }

    public function test_without_a_database_in_the_environment_the_config_is_left_alone(): void
    {
        $config = "<?php\n\$dbhost = 'db.source';\n\$dbname = 'source_app';\n@define('PHPBB_INSTALLED', true);\n";
        $this->install('config.php', $config);

        $boot = $this->boot($this->command('phpbb'), ['DB_DATABASE' => '']);

        $this->assertSame(0, $boot['status'], $boot['output']);
        $this->assertSame($config, $this->read('config.php'));
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function recipes(): iterable
    {
        foreach (['chamilo', 'flarum', 'magento', 'opencart', 'osticket', 'phpbb', 'suitecrm'] as $app) {
            yield $app => [$app, null];
        }
        yield 'forte' => ['codeberg.org/fortified/forte', 'php'];
        yield 'indexhibit' => ['github.com/indexhibit/indexhibit', 'php-plain'];
        yield 'live helper chat' => ['github.com/livehelperchat/livehelperchat', 'php'];
        yield 'cloudlog' => ['github.com/magicbug/cloudlog', 'php-plain'];
    }

    #[DataProvider('recipes')]
    public function test_the_generated_entrypoint_carries_the_command_and_parses(string $recipe, ?string $extends): void
    {
        $run = $this->command($recipe)->run;
        $script = $extends === null
            ? StageScript::render(PlatformRegistry::find($recipe))
            : StageScript::render(PlatformRegistry::find($extends), null, [], [], [], $this->sourceConfig($recipe));

        $this->assertSame(2, substr_count($script, $run), 'once for install, once for upgrade');
        exec('sh -n -c '.escapeshellarg($script).' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    /**
     * The command from a shipped app (`phpbb`) or a source recipe
     * (`github.com/owner/repo`), checked to run on both phases a boot can be.
     */
    private function command(string $recipe): PlatformCommand
    {
        $byPhase = [];
        foreach ([PlatformStage::INSTALL, PlatformStage::UPGRADE] as $phase) {
            foreach ($this->commandsOf($recipe, $phase) as $command) {
                if ($command->id === self::COMMAND) {
                    $byPhase[$phase] = $command;
                }
            }
        }
        $this->assertSame([PlatformStage::INSTALL, PlatformStage::UPGRADE], array_keys($byPhase), "{$recipe} has no ".self::COMMAND.' command on install and upgrade');

        return $byPhase[PlatformStage::INSTALL];
    }

    /** @return list<PlatformCommand> */
    private function commandsOf(string $recipe, string $phase): array
    {
        if (! str_contains($recipe, '/')) {
            return PlatformRegistry::find($recipe)?->stage($phase) ?? [];
        }

        return $this->sourceConfig($recipe)->commands($phase);
    }

    private function sourceConfig(string $recipe): AppConfig
    {
        $config = AppConfigDirectory::read(new LocalAppConfigSource, SourceRecipes::defaultDirectory().'/'.$recipe, true);
        $this->assertNotNull($config);

        return $config;
    }

    private function install(string $path, string $contents): void
    {
        $file = $this->dir.'/app/'.$path;
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }
        file_put_contents($file, $contents);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->dir.'/app/'.$path);
    }

    /**
     * Two boots of the copy: the first rewrites the login, the second finds
     * nothing left to change.
     */
    private function bootTwice(PlatformCommand $command): void
    {
        $first = $this->boot($command);
        $this->assertSame(0, $first['status'], $first['output']);
        $this->assertStringContainsString('booted', $first['output']);
        $this->assertStringContainsString('now points at database copy_app', $first['output']);

        $files = $this->snapshot();
        $second = $this->boot($command);
        $this->assertSame(0, $second['status'], $second['output']);
        $this->assertStringNotContainsString('now points at', $second['output']);
        $this->assertSame($files, $this->snapshot(), 'a second boot rewrites nothing');
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir.'/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /**
     * @param  array<string, string>  $env  overrides of the copy's environment
     * @return array{status: int, output: string}
     */
    private function boot(PlatformCommand $command, array $env = []): array
    {
        $run = str_replace('/app/', $this->dir.'/app/', $command->run);
        $assignments = '';
        foreach ([...self::COPY_ENV, ...$env] as $key => $value) {
            $assignments .= $key.'='.escapeshellarg($value).' ';
        }
        exec($assignments.'sh -c '.escapeshellarg("set -e\n{$run}\necho booted").' 2>&1', $output, $status);

        return ['status' => $status, 'output' => implode("\n", $output)];
    }

    /**
     * What a PHP process that includes the rewritten file sees: proof the
     * file still parses, and of the values in it.
     */
    private function evaluate(string $path, string $prelude, string $expression): mixed
    {
        $code = $prelude.(str_starts_with($expression, 'include') ? '' : ' include $argv[1];')
            .' echo json_encode('.$expression.');';
        exec('php -r '.escapeshellarg($code).' '.escapeshellarg($this->dir.'/app/'.$path).' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));

        return json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
    }
}
