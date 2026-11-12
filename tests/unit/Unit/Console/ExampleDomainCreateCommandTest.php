<?php

namespace Tests\Unit\Console;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Lib\Host\ProjectMemory;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Models\Admin;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * `system:example:create` as the installer sees it. The WordPress branch is
 * exercised up to its first wp-cli run, which a stand-in root broker refuses.
 */
class ExampleDomainCreateCommandTest extends TestCase
{
    use InMemoryDatabase;

    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        // Settings are cached statically, across tests.
        Setting::clearRuntimeSettings();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        LocalRoot::answerByBroker();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        if ($this->tmpRoot !== '') {
            array_map('unlink', glob($this->tmpRoot . '/*') ?: []);
            rmdir($this->tmpRoot);
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $args */
    private function example(array $args = []): array
    {
        $code = Artisan::call('system:example:create', $args);

        return [$code, Artisan::output()];
    }

    /**
     * The messages a refused run leaves for the console renderer. The command
     * itself prints nothing before it gives up.
     *
     * @param array<string, mixed> $args
     * @return list<string>
     */
    private function refused(array $args): array
    {
        try {
            Artisan::call('system:example:create', $args);
        } catch (ValidationException $e) {
            $this->assertSame('', Artisan::output());

            return array_values(array_map('strval', collect($e->errors())->flatten()->all()));
        }
        $this->fail('system:example:create should have been refused');
    }

    public function test_a_second_run_does_nothing(): void
    {
        Setting::set('example-domain-created', '1');

        [$code, $out] = $this->example(['--git-repo' => 'acme/app']);

        $this->assertSame(0, $code);
        $this->assertSame("Example domain has been created before.\n", $out);
        $this->assertSame(0, User::query()->count());
    }

    public function test_an_unreadable_repository_is_refused(): void
    {
        $this->assertSame(
            [TestNetwork::LOOPBACK.' is an address this engine does not fetch from.'],
            $this->refused(['--git-repo' => 'http://'.TestNetwork::LOOPBACK.':1/acme/app.git'])
        );
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Domain::query()->count());
        $this->assertEmpty(Setting::get('example-domain-created'));
        // Chosen before the create, and kept whatever it answers.
        $this->assertSame('app.local', Setting::get('vhost-default-ip-domain'));
        Queue::assertNothingPushed();
    }

    public function test_an_invalid_repository_is_a_validation_error(): void
    {
        $this->assertSame(["'https://not a repo' names a host but no repository."], $this->refused(['--git-repo' => 'not a repo']));
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_wildcard_domain_is_derived_from_the_public_address(): void
    {
        Setting::set('default_ipv4', TestNetwork::public(7));
        $this->stubCreate(function (): never {
            throw ProblemException::one('git_repo', 'git_repo_unreadable', 'The repository could not be read.');
        });

        $this->assertSame(['The repository could not be read.'], $this->refused(['--git-repo' => 'acme/app']));
        $this->assertSame(str_replace('.', '-', TestNetwork::public(7)).'.sslip.io', Setting::get('default_wildcard_domain'));
        $this->assertSame('app.'.str_replace('.', '-', TestNetwork::public(7)).'.sslip.io', Setting::get('vhost-default-ip-domain'));
    }

    public function test_several_problems_are_printed_one_per_line(): void
    {
        $this->stubCreate(function (): never {
            throw ProblemException::of([
                ['field' => 'username', 'code' => 'name_taken', 'message' => "A project named 'app' already exists. Choose another name."],
                ['field' => 'template', 'code' => 'template_not_found', 'message' => 'Template directory does not exist.'],
            ]);
        });

        $this->assertSame([
            "A project named 'app' already exists. Choose another name.",
            'Template directory does not exist.',
        ], $this->refused(['--git-repo' => 'acme/app']));
        $this->assertEmpty(Setting::get('example-domain-created'));
    }

    /** Not caught: artisan reports and renders it, and exits 1. */
    public function test_an_unexpected_failure_is_left_to_artisan(): void
    {
        $this->stubCreate(function (): never {
            throw new \RuntimeException('boom');
        });

        try {
            $this->example(['--git-repo' => 'acme/app']);
            $this->fail('the exception should reach artisan');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertEmpty(Setting::get('example-domain-created'));
    }

    public function test_a_create_that_left_no_project_is_reported(): void
    {
        $this->stubCreate(fn (array $params): ?User => null);

        try {
            $this->example(['--git-repo' => 'acme/app']);
            $this->fail('a missing project should reach artisan');
        } catch (NotFoundException $e) {
            $this->assertSame("Project 'app' not found.", $e->getMessage());
        }
        // Set before the project is looked for.
        $this->assertSame('1', Setting::get('example-domain-created'));
    }

    public function test_a_git_example_is_flagged_and_announced(): void
    {
        Setting::set('default_ipv4', TestNetwork::public(7));
        $seen = null;
        $this->stubCreate(function (array $params) use (&$seen): User {
            $seen = $params;
            $user = $this->makeUser($params['username'], ['git_repo' => $params['git_repo']], $params['domain']);
            $this->makeMainDomain($user, $params['domain']);

            return $user;
        });

        [$code, $out] = $this->example(['--git-repo' => 'acme/app']);

        $this->assertSame(0, $code, $out);
        $this->assertSame('Example domain with Git repository created: https://'.TestNetwork::public(7)."\n", $out);
        $expected = [
            'git_repo' => 'https://github.com/acme/app',
            'username' => 'app',
            'domain' => 'app.'.str_replace('.', '-', TestNetwork::public(7)).'.sslip.io',
            // Filled in by validation, as the API does.
            'memory_limit' => ProjectMemory::defaultMb(),
        ];
        ksort($expected);
        ksort($seen);
        $this->assertSame($expected, $seen);
        $this->assertTrue(Domain::findByName('app.'.str_replace('.', '-', TestNetwork::public(7)).'.sslip.io')->isExampleDomain());
        $this->assertSame('1', Setting::get('example-domain-created'));
    }

    /**
     * wp-cli runs as the account, which has its UID and GID only after its deploy, so the deploy comes first.
     */
    public function test_wordpress_is_installed_as_the_account_its_deploy_made(): void
    {
        $execAs = $this->rootRefusingWpCli();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'apache');
        $this->stubCreate(function (array $params): User {
            $user = $this->makeUser($params['username'], [], $params['domain']);
            $domain = $this->makeMainDomain($user, $params['domain']);
            $domain->setDetails(['php_version' => '8.3']);
            $domain->save();

            return $user;
        });

        [$code, $out] = $this->example();

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Error during wp-cli core download: wp-cli refused', $out);
        $this->assertContains('1001:1002', file($execAs, FILE_IGNORE_NEW_LINES) ?: []);
        $this->assertSame('1', Setting::get('example-domain-created'));
        $this->assertSame(0, Task::query()->count(), 'deployed in the command, not queued');
    }

    /** A repository needs nothing from the account once it exists, so its example is still left to the queue. */
    public function test_an_example_from_a_repository_is_still_queued(): void
    {
        $this->stubCreate(function (array $params): User {
            $user = $this->makeUser($params['username'], ['git_repo' => $params['git_repo']], $params['domain']);
            $this->makeMainDomain($user, $params['domain']);

            return $user;
        });

        [$code, $out] = $this->example(['--git-repo' => 'acme/app']);

        $this->assertSame(0, $code, $out);
        $this->assertSame(1, Task::query()->count());
        $this->assertNull(User::findByUsername('app')?->getUid(), 'its deploy has not run');
    }

    /**
     * A root broker that answers every request with a refusal, and writes down the argv of the one that runs wp-cli.
     *
     * @return string the file it writes that argv to
     */
    private function rootRefusingWpCli(): string
    {
        $this->tmpRoot = sys_get_temp_dir() . '/pa-example-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot);
        $log = $this->tmpRoot . '/exec-as';
        $client = $this->tmpRoot . '/pa-root';
        file_put_contents($client, "#!/bin/sh\n[ \"\$1\" = account-container.exec ] && printf '%s\\n' \"\$@\" > " . escapeshellarg($log) . "\necho 'wp-cli refused' >&2\nexit 1\n");
        chmod($client, 0755);
        LocalRoot::answerWith($client);

        return $log;
    }

    /** The CLI no longer goes through the HTTP router, so no root admin row is minted either. */
    public function test_the_command_does_not_touch_the_router(): void
    {
        Event::listen(RouteMatched::class, fn () => $this->fail('system:example:create dispatched an API route'));

        $this->refused(['--git-repo' => 'not a repo']);

        $this->stubCreate(function (array $params): User {
            $user = $this->makeUser($params['username'], ['git_repo' => $params['git_repo']], $params['domain']);
            $this->makeMainDomain($user, $params['domain']);

            return $user;
        });
        [$code] = $this->example(['--git-repo' => 'acme/app']);
        $this->assertSame(0, $code);

        $this->assertSame(0, Admin::query()->count());
    }

    /**
     * Stand in for the create: `$behaviour` gets the validated fields and returns
     * the project it made, or null; a create in this process has deployed.
     *
     * @param \Closure(array<string, mixed>): ?User $behaviour
     */
    private function stubCreate(\Closure $behaviour): void
    {
        $this->app->instance(ProjectCreator::class, new class ($behaviour) extends ProjectCreator {
            public function __construct(private readonly \Closure $behaviour)
            {
            }

            public function queue(NewProjectInput $input, ?\App\Lib\Project\Create\CreateFollowUp $followUp = null): Task
            {
                $user = ($this->behaviour)($input->params);

                return Task::start(jobType: 'stub', queue: 'default', username: $user?->username, details: []);
            }

            public function create(NewProjectInput $input): User
            {
                $user = ($this->behaviour)($input->params) ?? throw new \LogicException('The create made no project.');
                $user->setDetails(['UID' => 1001, 'GID' => 1002]);
                $user->save();

                return $user;
            }
        });
    }
}
