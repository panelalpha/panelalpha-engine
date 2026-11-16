<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\NotFoundException;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\AppManager;
use App\System\Project\Dind\Paths;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/**
 * App management scripts call a bare `docker compose`. The engine's run file
 * is docker-compose.panelalpha.yml, which compose never finds by itself, so
 * every call failed with "no configuration file provided" until the script
 * was handed the deploy's own compose files.
 */
class AppManagerTest extends TestCase
{
    private string $tmpRoot;

    private string $homeRoot;

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<array{int, string, string}> */
    private array $replies = [];

    /** @var list<string> */
    public array $logged = [];

    private ?\Illuminate\Contracts\Foundation\Application $previousApp = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/pa-appmanager-'.bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot.'/home';
        putenv('PA_ROOT_LOCAL_ROOTS='.json_encode(['engine' => $this->tmpRoot]));

        $test = $this;
        $container = new Container;
        $container->instance('log', new class($test)
        {
            public function __construct(private AppManagerTest $test) {}

            public function warning(string $message): void
            {
                $this->test->logged[] = $message;
            }
        });
        $this->previousApp = Facade::getFacadeApplication();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApp);
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_a_compose_project_gets_the_run_file_and_the_client_override_the_deploy_layers(): void
    {
        $dind = $this->dindProject('wp', Strategies::COMPOSE);
        $dir = $this->appDir('wp');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        touch($dir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE);
        touch($dir.'/'.EngineArtifacts::RUN_CLIENT_OVERRIDE);

        $this->assertSame(
            [
                'env',
                'COMPOSE_FILE='.$dir.'/'.EngineArtifacts::RUN_COMPOSE.':'.$dir.'/'.EngineArtifacts::RUN_CLIENT_OVERRIDE,
                'COMPOSE_PATH_SEPARATOR=:',
                'bash',
                $dir.'/'.AppConfig::APP_SCRIPT,
                'users:list',
            ],
            $dind->apps()->scriptCommand('users:list')
        );
    }

    public function test_a_recipe_project_gets_the_run_file_and_the_engine_override(): void
    {
        $dind = $this->dindProject('rec', 'express');
        $dir = $this->appDir('rec');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);
        touch($dir.'/'.Paths::CLIENT_OVERRIDE_FILENAME);

        $this->assertSame(
            'COMPOSE_FILE='.$dir.'/'.EngineArtifacts::RUN_COMPOSE.':'.$dir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE,
            $dind->apps()->scriptCommand('info')[1]
        );
    }

    /** The script must see exactly the files the deploy's own compose calls use. */
    public function test_the_compose_files_match_the_deploys_compose_command(): void
    {
        $dind = $this->dindProject('same', Strategies::PAEMD);
        $dir = $this->appDir('same');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        touch($dir.'/'.Paths::CLIENT_OVERRIDE_FILENAME);
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);

        $deployFiles = [];
        $deploy = $dind->userAppComposeCommand([]);
        foreach ($deploy as $i => $arg) {
            if ($arg === '-f') {
                $deployFiles[] = $deploy[$i + 1];
            }
        }

        $this->assertSame(
            'COMPOSE_FILE='.implode(':', $deployFiles),
            $dind->apps()->scriptCommand('info')[1]
        );
        $this->assertSame($dir, $deploy[array_search('--project-directory', $deploy, true) + 1], 'project directory is the first file\'s directory');
    }

    public function test_the_script_runs_as_the_account_user_inside_dind_with_the_compose_env(): void
    {
        $dind = $this->dindProject('run', Strategies::COMPOSE);
        $dir = $this->appDir('run');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        $this->replies[] = [0, '', ''];
        $this->replies[] = [0, '[{"id":"1","username":"admin","email":"a@b.c","role":"administrator"}]', ''];

        $users = $dind->apps()->listUsers();

        $this->assertSame('admin', $users[0]['username']);
        $command = $this->commands[1];
        $this->assertSame('1000:1000', LocalRoot::values($command)[1] ?? null);
        $inAccount = LocalRoot::inAccount($command);
        $this->assertSame(['env', 'COMPOSE_FILE='.$dir.'/'.EngineArtifacts::RUN_COMPOSE], array_slice($inAccount, 0, 2));
        $this->assertSame(['bash', $dir.'/'.AppConfig::APP_SCRIPT, 'users:list'], array_slice($inAccount, -3));
    }

    /** A reassign id reaches the script as a third users:delete argument. */
    public function test_delete_user_passes_the_reassign_id_to_the_script(): void
    {
        $dind = $this->dindProject('reassign', Strategies::COMPOSE);
        $dir = $this->appDir('reassign');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        $this->replies[] = [0, '["users:list","users:delete"]', ''];
        $this->replies[] = [0, '[{"id":"7","username":"admin","email":"a@b.c","role":"administrator"}]', ''];
        $this->replies[] = [0, '{"success":true}', ''];

        $dind->apps()->deleteUser('7', '1');

        $this->assertSame(
            ['bash', $dir.'/'.AppConfig::APP_SCRIPT, 'users:delete', '7', '1'],
            array_slice($this->commands[2], -5)
        );
    }

    /** With no reassign id the script is left to pick the content's new owner. */
    public function test_delete_user_without_a_reassign_id_omits_the_argument(): void
    {
        $dind = $this->dindProject('noreassign', Strategies::COMPOSE);
        $dir = $this->appDir('noreassign');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        $this->replies[] = [0, '["users:list","users:delete"]', ''];
        $this->replies[] = [0, '[{"id":"7","username":"admin","email":"a@b.c","role":"administrator"}]', ''];
        $this->replies[] = [0, '{"success":true}', ''];

        $dind->apps()->deleteUser('7');

        $this->assertSame(
            ['bash', $dir.'/'.AppConfig::APP_SCRIPT, 'users:delete', '7'],
            array_slice($this->commands[2], -4)
        );
    }

    /** An app's users:delete answers as if it had removed an unknown id, so the list is what refuses it. */
    public function test_an_unknown_app_user_is_refused_before_the_delete_runs(): void
    {
        $dind = $this->dindProject('unknown', Strategies::COMPOSE);
        $dir = $this->appDir('unknown');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        $this->replies[] = [0, '["users:list","users:delete"]', ''];
        $this->replies[] = [0, '[{"id":"1","username":"admin","email":"a@b.c","role":"administrator"}]', ''];

        try {
            $dind->apps()->deleteUser('999', '1');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame("App user '999' not found.", $e->getMessage());
        }

        $joined = array_map(static fn (array $cmd): string => implode(' ', $cmd), $this->commands);
        $this->assertCount(2, $joined, 'info and users:list only');
        foreach ($joined as $command) {
            $this->assertStringNotContainsString('users:delete', $command);
        }
    }

    /**
     * The README promises the engine will not call a command `info` does not
     * list. It used to run anyway and return the script's unknown-action
     * output as if it had worked.
     */
    public function test_a_command_info_does_not_list_is_refused(): void
    {
        $dind = $this->dindProject('nostub', Strategies::COMPOSE);
        $dir = $this->appDir('nostub');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        // info() lists no users:sso, so the script is never asked for it.
        $this->replies[] = [0, '["users:list","users:add","users:delete","users:reset-password"]', ''];

        try {
            $dind->apps()->ssoCredentials('7');
            $this->fail('Expected the unsupported command to be refused');
        } catch (\Exception $e) {
            $this->assertStringContainsString("does not support the 'users:sso' command", $e->getMessage());
            $this->assertStringContainsString('users:list, users:add', $e->getMessage());
        }
        $this->assertCount(1, $this->commands, 'only info ran');
        $this->assertSame('info', $this->commands[0][count($this->commands[0]) - 1]);
    }

    public function test_a_listed_command_is_left_alone(): void
    {
        $dind = $this->dindProject('stub', Strategies::COMPOSE);
        $dir = $this->appDir('stub');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        $this->replies[] = [0, '["users:list","users:add"]', ''];
        $this->replies[] = [0, '[{"id":"1","username":"admin","email":"a@b.c","role":"administrator"}]', ''];

        $users = $dind->apps()->listUsers();

        $this->assertSame('admin', $users[0]['username']);
    }

    /** A script whose `info` fails is not refused for it: nothing is known. */
    public function test_a_failing_info_does_not_block_the_command(): void
    {
        $dind = $this->dindProject('noinfo', Strategies::COMPOSE);
        $dir = $this->appDir('noinfo');
        touch($dir.'/'.EngineArtifacts::RUN_COMPOSE);
        $this->replies[] = [1, '', '{"error":"Unknown action"}'];
        $this->replies[] = [0, '{"success":true}', ''];

        $dind->apps()->resetUserPassword('7', 'secret-password');

        $this->assertCount(2, $this->commands);
    }

    public function test_the_scripts_own_error_is_what_the_caller_sees(): void
    {
        $this->assertSame('User not found.', AppManager::failureMessage("{\"error\":\"User not found.\"}\n"));
        $this->assertSame(
            'WordPress is not installed yet.',
            AppManager::failureMessage("{\n    \"error\": \"WordPress is not installed yet.\"\n}")
        );
        $this->assertSame(
            'Invalid user ID',
            AppManager::failureMessage("WARN[0000] some compose warning\n{\"error\":\"Invalid user ID\"}\n")
        );
    }

    public function test_raw_compose_output_is_not_what_the_caller_sees(): void
    {
        $this->assertSame(
            'The application is not running. Deploy or start the project, then try again.',
            AppManager::failureMessage("no configuration file provided: not found\n")
        );
        $this->assertSame(
            'The application is not running. Deploy or start the project, then try again.',
            AppManager::failureMessage("service \"wordpress\" is not running\n")
        );
        $this->assertSame(
            'The application\'s management command failed. The engine log has the details.',
            AppManager::failureMessage("PHP Fatal error: something\n")
        );
    }

    /**
     * A missing compose file also says "no such file"; that must not be read
     * as the script being missing, and the raw text goes to the log only,
     * without the arguments (they can hold a password).
     */
    public function test_a_compose_failure_is_logged_and_not_mistaken_for_a_missing_script(): void
    {
        $dind = $this->dindProject('down', Strategies::COMPOSE);
        $run = $this->appDir('down').'/'.EngineArtifacts::RUN_COMPOSE;
        $this->replies[] = [0, '["users:reset-password"]', ''];
        $this->replies[] = [1, '', "open {$run}: no such file or directory\n"];

        try {
            $dind->apps()->resetUserPassword('7', 'Sup3rSecret!');
            $this->fail('Expected the failure to surface');
        } catch (\Exception $e) {
            $this->assertSame("The application's management command failed. The engine log has the details.", $e->getMessage());
        }

        $this->assertCount(2, $this->commands, 'info, then the command: no reinstall and retry');
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('users:reset-password', $this->logged[0]);
        $this->assertStringContainsString('no such file or directory', $this->logged[0]);
        $this->assertStringNotContainsString('Sup3rSecret!', $this->logged[0]);
    }

    /** A deploy that ran another stack than its app config is written for has no app script that reaches it. */
    public function test_a_project_deployed_on_another_stack_than_its_app_config_is_refused_before_anything_runs(): void
    {
        $dind = $this->dindProject('pinned', Strategies::PHP, [AppManager::DETAIL_APP_CONFIG_FITS => false]);
        touch($this->appDir('pinned').'/'.AppConfig::APP_SCRIPT);

        try {
            $dind->apps()->listUsers();
            $this->fail('Expected app management to be refused');
        } catch (\Exception $e) {
            $this->assertSame('App management is not supported for this application', $e->getMessage());
        }
        $this->assertSame([], $this->commands);
    }

    /** @param  array<string, mixed>  $details */
    private function dindProject(string $username, string $strategy, array $details = []): Dind
    {
        $model = new ModelsUser;
        $model->username = $username;
        $model->setDetails([
            'template' => 'dind',
            'deploy_strategy' => $strategy,
            'UID' => 1000,
            'GID' => 1000,
            ...$details,
        ]);

        mkdir($this->appDir($username), 0777, true);

        $runtime = (new Project($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function appDir(string $username): string
    {
        return $this->homeRoot.'/'.$username.'/project';
    }

    private function system(): System
    {
        $test = $this;

        return new class($this->tmpRoot, $this->homeRoot, $test) extends System
        {
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
                private AppManagerTest $test,
            ) {}

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return dirname($this->homesRoot);
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->homesRoot.'/'.$username;
            }

            public function projectDirPath(string $username): string
            {
                return $this->engineRoot.'/users/'.$username;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->test->reply(is_array($cmd) ? array_values($cmd) : [$cmd]);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }
        };
    }

    /**
     * The broker's `files.isFile` answers from the real tree; anything else
     * takes the next queued reply, recorded.
     *
     * @param  list<string>  $cmd
     */
    public function reply(array $cmd): Process
    {
        if (LocalRoot::action($cmd) === 'files.isFile') {
            return $this->process(is_file(LocalRoot::values($cmd)[0]) ? 0 : 1, '', '');
        }

        $this->commands[] = LocalRoot::plain($cmd);
        [$code, $out, $err] = array_shift($this->replies) ?? [0, '', ''];

        return $this->process($code, $out, $err);
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

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
