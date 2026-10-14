<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Credentials\AppCredentials;
use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\ManifestException;
use App\Models\Setting;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * ~/.panelalpha/app-credentials.env: written before the recipe's prepare hook
 * on every deploy, 0600, the same values each time, removed when nothing is
 * declared, and the passwords masked in the deploy log.
 */
class AppCredentialDeliveryTest extends TestCase
{
    use InMemoryDatabase;

    private const YAML = "credentials:\n  login_path: /login\n  adopt_from: .panelalpha/demo/admin.env\n  fields:\n"
        . "    DEMO_ADMIN_USER: {kind: username}\n    DEMO_ADMIN_PASSWORD: {kind: password}\n";

    private string $tmpRoot;
    private string $home;
    private string $projectDir;
    private string $username;
    private RecordingHostSystem $system;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        // The deploy asks for the main domain; this project has none.
        $this->bootInMemoryDatabase();

        $this->username = 'ac' . bin2hex(random_bytes(4));
        $this->tmpRoot = sys_get_temp_dir() . '/pa-appcred-' . bin2hex(random_bytes(4));
        $this->home = $this->tmpRoot . '/home/' . $this->username;
        $this->projectDir = $this->home . '/project';
        mkdir($this->projectDir, 0777, true);
        mkdir($this->tmpRoot . '/users/' . $this->username, 0777, true);
        $this->system = new RecordingHostSystem(new LocalHostSystem($this->tmpRoot, $this->tmpRoot . '/home'));
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        // Settings are cached statically; later tests must not read this database's.
        (new \ReflectionProperty(Setting::class, 'allSettings'))->setValue(null, null);
        parent::tearDown();
    }

    public function test_the_file_is_written_before_the_prepare_hook_runs(): void
    {
        $this->writeProject(self::YAML, "echo prepared\n");
        $model = $this->model();

        $this->dind($model)->prepareFromSources();

        $this->assertCount(1, $this->system->hookRuns, 'the prepare hook ran once');
        $seen = $this->system->hookRuns[0];
        $this->assertNotNull($seen, 'the credentials file existed when the hook ran');
        $stored = $model->getAppCredentials();
        $this->assertSame(AppCredentials::envFile($stored), $seen);
        $this->assertSame(['DEMO_ADMIN_USER', 'DEMO_ADMIN_PASSWORD'], array_keys($stored['fields']));
        $this->assertSame('/login', $stored['login_path']);

        $file = $this->home . '/' . AppCredentials::ENV_FILE;
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertSame(0700, fileperms(dirname($file)) & 0777, 'a ~/.panelalpha the engine creates is private');
        $this->assertStringContainsString(
            "DEMO_ADMIN_PASSWORD='" . $stored['fields']['DEMO_ADMIN_PASSWORD']['value'] . "'",
            (string) file_get_contents($file)
        );
    }

    public function test_a_rebuild_delivers_the_same_values(): void
    {
        $this->writeProject(self::YAML, "echo prepared\n");
        $model = $this->model();
        $this->dind($model)->prepareFromSources();
        $first = $model->getAppCredentials();
        $firstFile = file_get_contents($this->home . '/' . AppCredentials::ENV_FILE);

        $this->dind($model)->prepareFromSources();

        $this->assertSame($first, $model->getAppCredentials());
        $this->assertSame($firstFile, file_get_contents($this->home . '/' . AppCredentials::ENV_FILE));
    }

    public function test_an_existing_panelalpha_directory_is_made_private(): void
    {
        mkdir($this->home . '/.panelalpha', 0755);
        chmod($this->home . '/.panelalpha', 0755);
        $this->writeProject(self::YAML, null);

        $this->dind($this->model())->prepareFromSources();

        $this->assertSame(0700, fileperms($this->home . '/.panelalpha') & 0777);
        $this->assertSame(0600, fileperms($this->home . '/' . AppCredentials::ENV_FILE) & 0777);
    }

    public function test_a_deploy_without_credentials_still_makes_it_private(): void
    {
        mkdir($this->home . '/.panelalpha', 0755);
        chmod($this->home . '/.panelalpha', 0755);
        file_put_contents($this->projectDir . '/index.html', '<!DOCTYPE html><title>x</title>');

        $this->dind($this->model())->prepareFromSources();

        $this->assertSame(0700, fileperms($this->home . '/.panelalpha') & 0777);
    }

    public function test_a_new_account_home_gets_a_private_panelalpha_directory(): void
    {
        (new ProjectAggregate($this->system, $this->model()))->createHomeDir();

        $this->assertSame(0700, fileperms($this->home . '/.panelalpha') & 0777);
    }

    public function test_an_account_deployed_before_keeps_the_password_the_recipe_generated(): void
    {
        mkdir($this->home . '/.panelalpha/demo', 0700, true);
        file_put_contents($this->home . '/.panelalpha/demo/admin.env', "DEMO_ADMIN_USER=admin\nDEMO_ADMIN_PASSWORD=0f1e2d3c4b5a69788796a5b4c3d2e1f0\n");
        $this->writeProject(self::YAML, null);
        $model = $this->model();

        $this->dind($model)->prepareFromSources();

        $this->assertSame('0f1e2d3c4b5a69788796a5b4c3d2e1f0', $model->getAppCredentials()['fields']['DEMO_ADMIN_PASSWORD']['value']);
    }

    public function test_the_projects_env_vars_win(): void
    {
        $this->writeProject(self::YAML, null);
        $model = $this->model(['env_vars' => ['DEMO_ADMIN_PASSWORD' => 'Owner-Chosen-42']]);

        $this->dind($model)->prepareFromSources();

        $this->assertSame('Owner-Chosen-42', $model->getAppCredentials()['fields']['DEMO_ADMIN_PASSWORD']['value']);
        $this->assertStringContainsString("DEMO_ADMIN_PASSWORD='Owner-Chosen-42'", (string) file_get_contents($this->home . '/' . AppCredentials::ENV_FILE));
    }

    public function test_nothing_declared_removes_the_file_and_forgets_the_values(): void
    {
        $this->writeProject(self::YAML, null);
        $model = $this->model();
        $this->dind($model)->prepareFromSources();
        $this->assertFileExists($this->home . '/' . AppCredentials::ENV_FILE);

        $this->writeProject("description: no login any more\nprepare: echo hi\n", null);
        $this->dind($model)->prepareFromSources();

        $this->assertFileDoesNotExist($this->home . '/' . AppCredentials::ENV_FILE);
        $this->assertNull($model->getAppCredentials());
    }

    public function test_a_project_that_never_declared_any_gets_no_file(): void
    {
        file_put_contents($this->projectDir . '/index.html', '<!DOCTYPE html><title>x</title>');
        $model = $this->model();

        $this->dind($model)->prepareFromSources();

        $this->assertFileDoesNotExist($this->home . '/' . AppCredentials::ENV_FILE);
        $this->assertNull($model->getAppCredentials());
    }

    public function test_the_log_names_the_fields_and_masks_the_password(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $model = $this->model();
        $spec = CredentialSpec::parse(
            ['fields' => ['DEMO_ADMIN_USER' => ['kind' => 'username'], 'DEMO_ADMIN_PASSWORD' => ['kind' => 'password']]],
            fn (string $m) => new ManifestException($m)
        );

        $this->dind($model)->appCredentials()->deliver($spec, final: true);
        $password = $model->getAppCredentials()['fields']['DEMO_ADMIN_PASSWORD']['value'];
        // What an application might print back during its build or boot.
        DeployLogger::current($this->username)?->dim("seed: logging in as admin:{$password}");
        $logger->finish(DeployLogger::STATUS_FAILED, "login with {$password} refused");

        $lines = array_column($logger->read()['lines'], 'msg');
        $this->assertContains('App credentials delivered: DEMO_ADMIN_USER, DEMO_ADMIN_PASSWORD', $lines);
        $this->assertContains('seed: logging in as admin:***', $lines);
        $this->assertStringNotContainsString($password, (string) file_get_contents($logger->getLogPath()));
        $this->assertSame('login with *** refused', $logger->readLatest()['error']);
    }

    private function writeProject(string $yaml, ?string $prepare): void
    {
        file_put_contents($this->projectDir . '/index.html', '<!DOCTYPE html><title>x</title>');
        @mkdir($this->projectDir . '/.panelalpha/hooks', 0777, true);
        file_put_contents($this->projectDir . '/.panelalpha/panelalpha.yaml', $yaml);
        if ($prepare !== null) {
            file_put_contents($this->projectDir . '/.panelalpha/hooks/prepare.sh', $prepare);
        }
        $this->system->credentialsFile = $this->home . '/' . AppCredentials::ENV_FILE;
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate($this->system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function model(array $details = []): ModelsUser
    {
        return $this->makeUser($this->username, array_merge(['template' => 'dind', 'UID' => 1000, 'GID' => 1000], $details));
    }
}

/**
 * LocalHostSystem, plus a note of what the credentials file held each time the
 * prepare hook was started (null: not there yet).
 */
final class RecordingHostSystem extends System
{
    /** @var list<?string> */
    public array $hookRuns = [];

    public string $credentialsFile = '';

    public function __construct(private readonly LocalHostSystem $inner)
    {
    }

    public function engineDirPath(): string
    {
        return $this->inner->engineDirPath();
    }

    public function homesDirPath(): string
    {
        return $this->inner->homesDirPath();
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
        if (str_contains($line, 'bash ') && str_contains($line, AppConfig::SETUP_SCRIPT) && !str_contains($line, 'rm -f')) {
            $this->hookRuns[] = is_file($this->credentialsFile) ? (string) file_get_contents($this->credentialsFile) : null;
        }

        return $this->inner->exec($cmd, $env, $timeout);
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return $this->inner->runProcess($cmd, $env, $timeout);
    }
}
