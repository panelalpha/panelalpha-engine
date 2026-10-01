<?php

namespace Tests\Unit\Console;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\ProblemException;
use App\Http\Resources\UserResource;
use App\Lib\Deploy\DeployLog\DeployLogStream;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Host\ProjectMemory;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Models\Admin;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `project:create` as a caller sees it: the exact lines, the exit code, and
 * what is left in the database. Paths that need a real deploy run against a
 * stand-in for the create, so the command's own handling is what is pinned.
 */
class ProjectCreateCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const INTRO = 'Creating a project — this takes a few minutes.';

    private ?User $created = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        // User details are encrypted at rest.
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        // Settings are cached statically, across tests.
        Setting::clearRuntimeSettings();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        app(DeployPlanContext::class)->clear();
        app(RecipeChoiceContext::class)->clear();
        parent::tearDown();
    }

    /** @param array<string, mixed> $args */
    private function create(array $args): array
    {
        $code = Artisan::call('project:create', $args);

        return [$code, Artisan::output()];
    }

    /**
     * A refused create: what the command printed before it gave up, and the
     * lines the console renderer prints for the exception it left behind.
     *
     * @param array<string, mixed> $args
     * @return array{string, list<string>}
     */
    private function refused(array $args): array
    {
        try {
            Artisan::call('project:create', $args);
        } catch (ValidationException | DeployAlreadyRunningException $e) {
            $lines = $e instanceof ValidationException
                ? array_values(array_map('strval', collect($e->errors())->flatten()->all()))
                : [$e->getMessage()];

            return [Artisan::output(), $lines];
        }
        $this->fail('project:create should have been refused');
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, Domain::query()->count(), 'no domain row is left behind');
        $this->assertSame([], User::query()->pluck('username')->diff(['taken'])->values()->all(), 'no project row is left behind');
    }

    public function test_an_invalid_name_is_refused(): void
    {
        [$out, $errors] = $this->refused(['--project' => 'ab']);

        $this->assertSame(self::INTRO . "\n", $out);
        $this->assertSame(["'ab' is not a valid project name. A project name is 3-15 characters: lowercase letters a-z and digits, starting with a letter."], $errors);
        $this->assertNothingCreated();
    }

    public function test_a_duplicate_username_is_refused(): void
    {
        $this->makeUser('taken');

        [$out, $errors] = $this->refused(['--project' => 'taken', '--domain' => 'x.example.test']);

        $this->assertSame(self::INTRO . "\n", $out);
        $this->assertSame(["A project named 'taken' already exists. Choose another name."], $errors);
        $this->assertNothingCreated();
    }

    public function test_every_problem_is_printed_on_its_own_line(): void
    {
        $this->makeUser('taken');

        [$out, $errors] = $this->refused(['--project' => 'taken', '--domain' => 'x.example.test', '--template' => 'nope']);

        $this->assertSame(self::INTRO . "\n", $out);
        $this->assertSame([
            "A project named 'taken' already exists. Choose another name.",
            'Template directory does not exist.',
        ], $errors);
        $this->assertNothingCreated();
    }

    public function test_an_invalid_domain_is_refused(): void
    {
        [$out, $errors] = $this->refused(['--project' => 'fresh', '--domain' => 'bad_domain']);

        $this->assertSame(self::INTRO . "\n", $out);
        $this->assertSame(['The domain format is invalid.'], $errors);
        $this->assertNothingCreated();
    }

    public function test_json_mode_prints_no_intro_and_the_same_error(): void
    {
        [$out, $errors] = $this->refused(['--project' => 'fresh', '--domain' => 'bad_domain', '--json' => true]);

        $this->assertSame('', $out);
        $this->assertSame(['The domain format is invalid.'], $errors);
    }

    public function test_an_unknown_template_is_refused(): void
    {
        [$out, $errors] = $this->refused(['--project' => 'fresh', '--domain' => 'fresh.example.test', '--template' => 'nope']);

        $this->assertSame(self::INTRO . "\n", $out);
        $this->assertSame(['Template directory does not exist.'], $errors);
        $this->assertNothingCreated();
    }

    public function test_a_malformed_recipe_is_refused_before_anything_is_created(): void
    {
        [$out, $errors] = $this->refused(['--project' => 'fresh', '--domain' => 'fresh.example.test', '--recipe' => 'Bad']);

        $this->assertSame(self::INTRO . "\n", $out);
        $this->assertSame(["'Bad' is not a recipe id. Inspect the source and pick one of the ids under application.candidates."], $errors);
        $this->assertNothingCreated();
    }

    public function test_a_malformed_env_var_is_refused_without_a_request(): void
    {
        [$code, $out] = $this->create(['--env-var' => ['NOEQ']]);

        $this->assertSame(1, $code);
        $this->assertSame("--env-var expects KEY=VALUE, got: NOEQ\n", $out);

        [$code, $out] = $this->create(['--env-var' => ['=v']]);
        $this->assertSame(1, $code);
        $this->assertSame("--env-var expects KEY=VALUE, got: =v\n", $out);
        $this->assertNothingCreated();
    }

    /** Armed before provisioning, as the API does, so a refused create still leaves them set. */
    public function test_the_recipe_and_an_empty_plan_are_armed_for_the_deploy(): void
    {
        $this->makeUser('taken');
        app(DeployPlanContext::class)->set(DeployPlan::fromArray(['build' => [['id' => 'b', 'run' => 'make']]]));

        $this->refused(['--project' => 'taken', '--recipe' => 'php']);

        $this->assertNull(app(DeployPlanContext::class)->get());
        $this->assertSame('php', app(RecipeChoiceContext::class)->get());
    }

    public function test_a_failed_create_from_a_repository_points_at_the_deploy_log(): void
    {
        [$out, $errors] = $this->refused(['--repo' => 'git@github.com:a/b.git', '--project' => 'fresh']);

        $this->assertSame("Creating a project for git@github.com:a/b.git — this takes a few minutes.\n"
            . "Deploy log: pae project:deploy:list\n", $out);
        $this->assertSame(['SSH remotes are not supported: the engine clones anonymously or with an HTTPS token '
            . '(`git_token`), and holds no SSH keys. Use https://github.com/a/b.git instead.'], $errors);
        $this->assertNothingCreated();
    }

    /** The user row and its main domain are one transaction: a failed domain insert takes the user with it. */
    public function test_a_provision_failure_leaves_no_half_created_account(): void
    {
        DB::statement("CREATE TRIGGER refuse_domains BEFORE INSERT ON domains BEGIN SELECT RAISE(ABORT, 'refused'); END");

        try {
            $this->create(['--project' => 'fresh', '--domain' => 'fresh.example.test']);
            $this->fail('the refused insert should reach artisan');
        } catch (QueryException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }
        $this->assertNothingCreated();
    }

    public function test_a_deploy_failure_is_reported_with_the_deploy_log_hint(): void
    {
        $this->stubCreate(function (): never {
            throw ProblemException::one('deploy', 'deploy_failed', 'The build failed: npm ci exited with code 1.', [
                'stage' => 'build',
                'deploy_log_offset' => 0,
            ]);
        });

        [$out, $errors] = $this->refused(['--repo' => 'https://github.com/acme/app.git', '--project' => 'acme']);

        $this->assertSame("Creating a project for https://github.com/acme/app.git — this takes a few minutes.\n"
            . "Deploy log: pae project:deploy:list\n", $out);
        $this->assertSame(['The build failed: npm ci exited with code 1.'], $errors);
    }

    /**
     * What installer.sh reads: under --json the hint is on stdout, and the
     * deploy log and then the reason are on stderr.
     */
    public function test_a_failed_json_create_through_artisan_puts_the_reason_last_on_stderr(): void
    {
        $this->stubCreate(function (): never {
            DeployLogStream::emit(['type' => 'line', 'ts' => time(), 'stage' => null, 'level' => 'error', 'msg' => 'Deploy failed: npm ci exited with code 1.']);
            throw ProblemException::one('deploy', 'deploy_failed', 'The build failed: npm ci exited with code 1.');
        });

        $stdout = new StreamOutput(fopen('php://memory', 'w+'));
        $stderr = new StreamOutput(fopen('php://memory', 'w+'));
        $output = new class ($stdout, $stderr) extends ConsoleOutput {
            public function __construct(private StreamOutput $out, StreamOutput $err)
            {
                parent::__construct();
                $this->setErrorOutput($err);
            }

            public function doWrite(string $message, bool $newline): void
            {
                $this->out->doWrite($message, $newline);
            }
        };

        $exit = $this->app->make(Kernel::class)->handle(new ArrayInput([
            'command' => 'project:create',
            '--repo' => 'https://github.com/acme/app.git',
            '--project' => 'acme',
            '--json' => true,
        ]), $output);

        $read = static function (StreamOutput $o): string {
            rewind($o->getStream());
            return (string) stream_get_contents($o->getStream());
        };
        $this->assertSame(1, $exit);
        $this->assertSame("Deploy log: pae project:deploy:list\n", $read($stdout));
        $this->assertSame("    0:00 Deploy failed: npm ci exited with code 1.\n"
            . "The build failed: npm ci exited with code 1.\n", $this->plain($read($stderr)));
    }

    public function test_a_running_deploy_is_reported_by_its_message(): void
    {
        $this->stubCreate(function (): never {
            throw new DeployAlreadyRunningException('A deployment is already running for this user.');
        });

        [$out, $errors] = $this->refused(['--repo' => 'https://github.com/acme/app.git', '--project' => 'acme']);

        $this->assertSame("Creating a project for https://github.com/acme/app.git — this takes a few minutes.\n"
            . "Deploy log: pae project:deploy:list\n", $out);
        $this->assertSame(['A deployment is already running for this user.'], $errors);
    }

    /** Not caught: artisan reports and renders it, and exits 1. */
    public function test_an_unexpected_failure_is_left_to_artisan(): void
    {
        $this->stubCreate(function (): never {
            throw new \RuntimeException('docker: not found');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('docker: not found');

        $this->create(['--project' => 'acme']);
    }

    public function test_success_prints_the_deploy_log_and_where_the_project_lives(): void
    {
        $this->stubCreate(fn (array $params): User => $this->deployed($params));

        [$code, $out] = $this->create(['--project' => 'acme', '--domain' => 'acme.example.test']);

        $this->assertSame(0, $code, $out);
        $this->assertSame(self::INTRO . "\n"
            . "    0:00 Deploy started (source: dind template)\n"
            . "Project acme created.\n"
            . "Live at https://acme.example.test\n", $this->plain($out));
    }

    public function test_success_in_json_mode_prints_the_project_resource(): void
    {
        $this->stubCreate(fn (array $params): User => $this->deployed($params));

        [$code, $out] = $this->create(['--project' => 'acme', '--domain' => 'acme.example.test', '--json' => true, '--quiet-deploy' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertNotNull($this->created);
        $this->assertSame((new UserResource($this->created))->response()->getContent() . "\n", $out);
    }

    public function test_options_reach_the_create_as_its_fields(): void
    {
        $seen = null;
        $this->stubCreate(function (array $params) use (&$seen): User {
            $seen = $params;

            return $this->deployed($params);
        });

        $this->create([
            '--repo' => 'acme/app',
            '--branch' => ' main ',
            '--git-token' => 'tok',
            '--project' => 'acme',
            '--domain' => 'acme.example.test',
            '--email' => 'ops@example.com',
            '--template' => 'dind',
            '--recipe' => 'php',
            '--env-var' => ['A=1', 'B=x=y'],
            '--quiet-deploy' => true,
        ]);

        $expected = [
            'username' => 'acme',
            'domain' => 'acme.example.test',
            'email' => 'ops@example.com',
            'template' => 'dind',
            'recipe' => 'php',
            'git_branch' => 'main',
            'git_token' => 'tok',
            'git_repo' => 'https://github.com/acme/app',
            'env_vars' => ['A' => '1', 'B' => 'x=y'],
            // Filled in by validation, as the API does.
            'memory_limit' => ProjectMemory::defaultMb(),
        ];
        ksort($expected);
        ksort($seen);
        $this->assertSame($expected, $seen);

        $input = app(ProjectCreator::class)->input;
        $this->assertSame('tok', $input->gitToken);
        $this->assertSame(['A' => '1', 'B' => 'x=y'], $input->envVars);
        $this->assertSame('php', $input->recipe);
        $this->assertNull($input->stages);
        $this->assertSame('username', $input->nameField);
    }

    /** The CLI no longer goes through the HTTP router, so no root admin row is minted either. */
    public function test_the_command_does_not_touch_the_router(): void
    {
        Event::listen(RouteMatched::class, fn () => $this->fail('project:create dispatched an API route'));

        $this->refused(['--project' => 'ab']);

        $this->stubCreate(fn (array $params): User => $this->deployed($params));
        [$code] = $this->create(['--project' => 'acme', '--domain' => 'acme.example.test', '--quiet-deploy' => true]);
        $this->assertSame(0, $code);

        $this->assertSame(0, Admin::query()->count());
    }

    /** Strip the console colour tags the deploy lines carry. */
    private function plain(string $out): string
    {
        return (string) preg_replace('#</?(?:fg|bg|options)=[^>]*>|</>#', '', $out);
    }

    /**
     * What a successful create leaves behind: the rows, and a deploy log line
     * on the live stream.
     *
     * @param array<string, mixed> $params
     */
    private function deployed(array $params): User
    {
        $user = $this->makeUser((string) $params['username'], ['template' => 'dind'], (string) ($params['domain'] ?? 'acme.example.test'));
        $this->makeMainDomain($user, $user->domain);
        DeployLogStream::emit(['type' => 'line', 'ts' => time(), 'stage' => null, 'level' => 'info', 'msg' => 'Deploy started (source: dind template)']);

        return $this->created = $user;
    }

    /**
     * Stand in for the create itself. `$behaviour` gets the validated fields
     * and returns the project, or throws what the create would.
     *
     * @param \Closure(array<string, mixed>): User $behaviour
     */
    private function stubCreate(\Closure $behaviour): void
    {
        $this->app->instance(ProjectCreator::class, new class ($behaviour) extends ProjectCreator {
            public ?NewProjectInput $input = null;

            public function __construct(private readonly \Closure $behaviour)
            {
            }

            public function create(NewProjectInput $input): User
            {
                $this->input = $input;

                return ($this->behaviour)($input->params);
            }
        });
    }
}
