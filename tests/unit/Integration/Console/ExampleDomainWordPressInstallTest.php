<?php

namespace Tests\Integration\Console;

use App\Console\Commands\System\CreateExampleDomain;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Services\Mysql;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\RecordingMysql;
use Tests\Support\RecordingSystem;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** `system:example:create` without a repository, past the project's deploy: the database, the wp-config and the WordPress install it runs. */
class ExampleDomainWordPressInstallTest extends TestCase
{
    use InMemoryDatabase;

    private RecordingMysql $mysql;

    /** @var list<list<string>> */
    public array $wpCli = [];

    /** @var array<string, array{exit_code: int, stdout: string, stderr: string}> first wp-cli argument pair => answer */
    public array $answers = [];

    /** @var list<list<string>> */
    public array $configs = [];

    public ?string $configFailure = null;

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'env.USERS_DB_HOST' => null]);
        Setting::clearRuntimeSettings();
        Queue::fake();
        LocalRoot::answerLocally();
        Setting::set('default_ipv4', TestNetwork::public(7));
        $this->mysql = new RecordingMysql();
        $mysql = $this->mysql;
        $this->app->instance(System::class, new class($mysql) extends System
        {
            public function __construct(private readonly RecordingMysql $recording) {}

            public function mysql(): Mysql
            {
                return $this->recording;
            }
        });
        $this->app->instance(ProjectCreator::class, new class extends ProjectCreator
        {
            public function __construct() {}

            public function create(NewProjectInput $input): User
            {
                $user = User::make(['username' => $input->params['username'], 'domain' => $input->params['domain'], 'details' => ['mysql_prefix' => 'wordpress_']]);
                $user->save();
                $user->domains()->create(['domain' => $input->params['domain'], 'type' => 'main', 'details' => ['document_root' => '/public_html']]);

                return $user;
            }
        });
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        LocalRoot::answerByBroker();
        parent::tearDown();
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    public function wpCliAnswer(array $args): array
    {
        $this->wpCli[] = $args;

        return $this->answers[$args[0].' '.$args[1]] ?? ['exit_code' => 0, 'stdout' => 'Success.', 'stderr' => ''];
    }

    private function example(): int
    {
        $test = $this;
        $command = new class($test) extends CreateExampleDomain
        {
            public function __construct(private readonly ExampleDomainWordPressInstallTest $test)
            {
                parent::__construct();
            }

            protected function project(User $user): Project
            {
                $test = $this->test;

                return new class(new RecordingSystem(), $user, $test) extends Project
                {
                    public function __construct(System $system, User $user, private readonly ExampleDomainWordPressInstallTest $test)
                    {
                        parent::__construct($system, $user);
                    }

                    public function runWpCli(array $args, ?string $service = null): array
                    {
                        return $this->test->wpCliAnswer($args);
                    }
                };
            }

            protected function createWpConfig(User $user, string $path, array $options): void
            {
                $this->test->configs[] = [$path, ...$options];
                if ($this->test->configFailure !== null) {
                    throw new RuntimeException($this->test->configFailure);
                }
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput([]), $output);
        $this->printed = $output->fetch();

        return $code;
    }

    private function path(): string
    {
        $user = User::findByUsername('wordpress');

        return $user->getHomeDir().'/public_html';
    }

    public function test_wordpress_is_downloaded_configured_installed_and_themed(): void
    {
        Setting::set('email', 'owner@example.test');

        $this->assertSame(0, $this->example());

        $url = 'https://'.TestNetwork::public(7);
        $this->assertStringContainsString("Example domain with WordPress created: {$url}", $this->printed);
        $this->assertSame(['createDatabase', 'wordpress_example'], $this->mysql->recorded()[0]);
        $this->assertSame('createUser', $this->mysql->recorded()[1][0]);
        $this->assertSame('wordpress_example', $this->mysql->recorded()[1][1]);
        $password = $this->mysql->recorded()[1][2];
        $this->assertSame(16, strlen($password));
        $this->assertSame(['updatePrivileges', 'wordpress_example', 'wordpress_example', 'ALL PRIVILEGES'], $this->mysql->recorded()[2]);
        $this->assertTrue(MysqlDatabase::query()->where('database', 'wordpress_example')->exists());
        $this->assertTrue(MysqlUser::query()->where('user', 'wordpress_example')->exists());
        $this->assertSame([[
            $this->path(), '--dbname=wordpress_example', '--dbuser=wordpress_example', "--dbpass={$password}", '--dbhost=database-users.shared-hosting.palocal',
        ]], $this->configs);
        $this->assertSame(['core', 'download', '--path='.$this->path()], $this->wpCli[0]);
        $install = $this->wpCli[1];
        $this->assertSame(['core', 'install', '--path='.$this->path(), "--url={$url}", '--title=Example Website', '--admin_user=admin'], array_slice($install, 0, 6));
        $this->assertSame(['--admin_email=owner@example.test', '--skip-email'], array_slice($install, 7));
        $this->assertSame(['theme', 'install', 'twentytwentyfour', '--activate', '--path='.$this->path()], $this->wpCli[2]);
    }

    public function test_the_configured_sites_db_host_and_a_fallback_admin_email_are_used(): void
    {
        config(['env.USERS_DB_HOST' => 'sites-db.example.test']);
        Setting::set('email', 'not an address');

        $this->assertSame(0, $this->example());

        $this->assertContains('--dbhost=sites-db.example.test', $this->configs[0]);
        $this->assertContains('--admin_email=user@localhost.localdomain', $this->wpCli[1]);
    }

    public function test_a_failed_download_stops_before_any_database_is_made(): void
    {
        $this->answers['core download'] = ['exit_code' => 3, 'stdout' => 'partial', 'stderr' => ''];

        $this->assertSame(1, $this->example());

        $this->assertStringContainsString('Error during wp-cli core download: partial (exit code 3)', $this->printed);
        $this->assertSame([], $this->mysql->recorded());
    }

    public function test_a_failed_config_create_stops_before_the_install(): void
    {
        $this->configFailure = 'wp-config.php exists';

        $this->assertSame(1, $this->example());

        $this->assertStringContainsString('Error during wp-cli config create: wp-config.php exists', $this->printed);
        $this->assertCount(1, $this->wpCli);
    }

    public function test_a_failed_install_is_reported_with_its_error(): void
    {
        $this->answers['core install'] = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error establishing a database connection'];

        $this->assertSame(1, $this->example());

        $this->assertStringContainsString('Error during wp-cli core install: Error establishing a database connection (exit code 1)', $this->printed);
        $this->assertCount(2, $this->wpCli);
    }

    public function test_a_theme_that_does_not_install_leaves_the_example_created(): void
    {
        $this->answers['theme install'] = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'download failed'];

        $this->assertSame(0, $this->example());

        $this->assertStringContainsString('Example domain with WordPress created', $this->printed);
    }
}
