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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `system:example:create` as the installer sees it. The WordPress branch runs
 * wp-cli on the host and is not exercised here; everything up to it is.
 */
class ExampleDomainCreateCommandTest extends TestCase
{
    use InMemoryDatabase;

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
            ['Could not reach 127.0.0.1. The engine must be able to open an HTTPS connection to it.'],
            $this->refused(['--git-repo' => 'http://127.0.0.1:1/acme/app.git'])
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
        Setting::set('default_ipv4', '203.0.113.7');
        $this->stubCreate(function (): never {
            throw ProblemException::one('git_repo', 'git_repo_unreadable', 'The repository could not be read.');
        });

        $this->assertSame(['The repository could not be read.'], $this->refused(['--git-repo' => 'acme/app']));
        $this->assertSame('203-0-113-7.sslip.io', Setting::get('default_wildcard_domain'));
        $this->assertSame('app.203-0-113-7.sslip.io', Setting::get('vhost-default-ip-domain'));
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
        Setting::set('default_ipv4', '203.0.113.7');
        $seen = null;
        $this->stubCreate(function (array $params) use (&$seen): User {
            $seen = $params;
            $user = $this->makeUser($params['username'], ['git_repo' => $params['git_repo']], $params['domain']);
            $this->makeMainDomain($user, $params['domain']);

            return $user;
        });

        [$code, $out] = $this->example(['--git-repo' => 'acme/app']);

        $this->assertSame(0, $code, $out);
        $this->assertSame("Example domain with Git repository created: https://203.0.113.7\n", $out);
        $expected = [
            'git_repo' => 'https://github.com/acme/app',
            'username' => 'app',
            'domain' => 'app.203-0-113-7.sslip.io',
            // Filled in by validation, as the API does.
            'memory_limit' => ProjectMemory::defaultMb(),
        ];
        ksort($expected);
        ksort($seen);
        $this->assertSame($expected, $seen);
        $this->assertTrue(Domain::findByName('app.203-0-113-7.sslip.io')->isExampleDomain());
        $this->assertSame('1', Setting::get('example-domain-created'));
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
     * Stand in for the create. `$behaviour` gets the validated fields and
     * returns the project it made, or null for one that made nothing.
     *
     * @param \Closure(array<string, mixed>): ?User $behaviour
     */
    private function stubCreate(\Closure $behaviour): void
    {
        $this->app->instance(ProjectCreator::class, new class ($behaviour) extends ProjectCreator {
            public function __construct(private readonly \Closure $behaviour)
            {
            }

            public function queue(NewProjectInput $input): Task
            {
                $user = ($this->behaviour)($input->params);

                return Task::start(jobType: 'stub', queue: 'default', username: $user?->username, details: []);
            }
        });
    }
}
