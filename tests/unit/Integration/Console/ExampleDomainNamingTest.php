<?php

namespace Tests\Integration\Console;

use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Models\Domain;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * `system:example:create` choosing the example's name, and its WordPress install up to the users' database: the
 * host is asked through a scripted root broker whether a name is free and runs wp-cli.
 */
class ExampleDomainNamingTest extends TestCase
{
    use InMemoryDatabase;

    private ScriptedRoot $root;

    /** @var \ArrayObject<int, string> the usernames each create was given */
    private \ArrayObject $created;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Setting::clearRuntimeSettings();
        Queue::fake();
        $this->root = new ScriptedRoot;
        $this->created = new \ArrayObject;
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'apache');
        $this->stubCreate();
    }

    protected function tearDown(): void
    {
        $this->root->close();
        Setting::clearRuntimeSettings();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        parent::tearDown();
    }

    public function test_a_repository_named_by_its_github_path_is_deployed_from_github(): void
    {
        $this->stubCreate(git: true);
        $this->root->on('users.uid', '', 1);

        $this->assertSame(0, Artisan::call('system:example:create', ['--git-repo' => 'github.com/acme/tool']), Artisan::output());

        $this->assertSame('https://github.com/acme/tool', User::findByUsername('tool')->getGitRepo());
    }

    public function test_a_name_the_engine_already_has_is_skipped_for_a_numbered_one(): void
    {
        $this->makeUser('wordpress');
        $this->root->on('users.uid', '', 1)->on('account-container.exec', 'wp-cli refused', 1);

        $code = Artisan::call('system:example:create');

        $this->assertSame(1, $code);
        $this->assertCount(1, $this->created);
        $this->assertMatchesRegularExpression('/^wordpress\d{4}$/', $this->created[0]);
        $this->assertSame(Setting::get('vhost-default-ip-domain'), User::findByUsername($this->created[0])->domain);
        $this->assertStringContainsString('Error during wp-cli core download: wp-cli refused (exit code 1)', Artisan::output());
    }

    public function test_no_example_is_made_when_the_host_holds_every_name_it_tries(): void
    {
        try {
            Artisan::call('system:example:create');
            $this->fail('a name the host holds was taken');
        } catch (\Exception $e) {
            $this->assertSame('Cannot get available username', $e->getMessage());
        }

        $this->assertSame([], $this->created->getArrayCopy());
        $this->assertCount(11, array_filter($this->root->actions(), static fn (string $action): bool => $action === 'users.uid'));
        $this->assertEmpty(Setting::get('example-domain-created'));
    }

    public function test_wordpress_stops_at_a_users_database_it_cannot_reach(): void
    {
        config(['env.USERS_DB_HOST' => TestNetwork::LOOPBACK.';port=1']);
        $this->root->on('users.uid', '', 1);

        try {
            Artisan::call('system:example:create');
            $this->fail('the install went on without its database');
        } catch (\PDOException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(['wordpress'], $this->created->getArrayCopy());
        $this->assertSame(0, MysqlDatabase::query()->count());
        $execs = array_filter($this->root->requests(), static fn (array $argv): bool => in_array('account-container.exec', $argv, true));
        $this->assertCount(1, $execs, 'wp-cli downloaded the core and was not asked for its config');
        $this->assertStringContainsString('download', implode(' ', array_merge(...array_values($execs))));
    }

    private function stubCreate(bool $git = false): void
    {
        $this->app->instance(ProjectCreator::class, new class ($this->created, $git) extends ProjectCreator {
            /** @param \ArrayObject<int, string> $created */
            public function __construct(private readonly \ArrayObject $created, private readonly bool $git)
            {
            }

            public function queue(NewProjectInput $input, ?\App\Lib\Project\Create\CreateFollowUp $followUp = null): Task
            {
                $user = $this->create($input);

                return Task::start(jobType: 'stub', queue: 'default', username: $user->username, details: []);
            }

            public function create(NewProjectInput $input): User
            {
                $params = $input->params;
                $this->created[] = $params['username'];
                $user = User::make([
                    'username' => $params['username'],
                    'domain' => $params['domain'],
                    'details' => ($this->git ? ['git_repo' => $params['git_repo']] : []) + ['UID' => 1001, 'GID' => 1002],
                ]);
                $user->save();
                Domain::query()->create([
                    'user_id' => $user->id,
                    'domain' => $params['domain'],
                    'type' => 'main',
                    'details' => ['document_root' => "/{$params['domain']}/public_html", 'php_version' => '8.3'],
                ]);

                return $user;
            }
        });
    }
}
