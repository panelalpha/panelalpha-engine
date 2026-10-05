<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\DeployProject;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Project\CreateInspection;
use App\Lib\Project\CreateInspector;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST /users and POST /projects, byte for byte, on every path that stops
 * before a deploy: what the API answers is a contract with the panel, the MCP
 * tools and every script, and the CLI shares the code behind it.
 */
class ProjectCreateHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        Setting::clearRuntimeSettings();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        app(DeployPlanContext::class)->clear();
        app(RecipeChoiceContext::class)->clear();
        parent::tearDown();
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, Domain::query()->count());
        $this->assertSame([], User::query()->pluck('username')->diff(['taken'])->values()->all());
        Queue::assertNothingPushed();
    }

    /** @return list<string> */
    private static function uris(): array
    {
        return ['/api/users', '/api/projects'];
    }

    private function assertBody(TestResponse $response, int $status, array $body): void
    {
        $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame($body, json_decode((string) $response->getContent(), true));
    }

    public function test_an_invalid_name(): void
    {
        foreach (self::uris() as $uri) {
            $this->assertBody($this->postJson($uri, ['name' => 'ab']), 422, [
                'message' => "'ab' is not a valid project name. A project name is 3-15 characters: lowercase letters a-z and digits, starting with a letter.",
                'errors' => ['name' => ["'ab' is not a valid project name. A project name is 3-15 characters: lowercase letters a-z and digits, starting with a letter."]],
                'problems' => [[
                    'field' => 'name',
                    'code' => 'name_invalid',
                    'message' => "'ab' is not a valid project name. A project name is 3-15 characters: lowercase letters a-z and digits, starting with a letter.",
                    'expected' => '3-15 characters: lowercase letters a-z and digits, starting with a letter',
                    'pattern' => '^[a-z][a-z0-9]{2,14}$',
                    'examples' => ['shop', 'blog2', 'myapp01'],
                ]],
            ]);
        }
        $this->assertNothingCreated();
    }

    public function test_a_duplicate_username_and_a_bad_template_together(): void
    {
        $this->makeUser('taken');

        foreach (self::uris() as $uri) {
            $this->assertBody($this->postJson($uri, ['username' => 'taken', 'domain' => 'x.example.test', 'template' => 'nope']), 422, [
                'message' => "A project named 'taken' already exists. Choose another name. (and 1 more error)",
                'errors' => [
                    'username' => ["A project named 'taken' already exists. Choose another name."],
                    'template' => ['Template directory does not exist.'],
                ],
                'problems' => [
                    ['field' => 'username', 'code' => 'name_taken', 'message' => "A project named 'taken' already exists. Choose another name."],
                    ['field' => 'template', 'code' => 'template_not_found', 'message' => 'Template directory does not exist.'],
                ],
            ]);
        }
        $this->assertNothingCreated();
    }

    public function test_an_invalid_domain(): void
    {
        foreach (self::uris() as $uri) {
            $this->assertBody($this->postJson($uri, ['username' => 'fresh', 'domain' => 'Bad_Domain']), 422, [
                'message' => 'The domain format is invalid.',
                'errors' => ['domain' => ['The domain format is invalid.']],
                'problems' => [[
                    'field' => 'domain',
                    'code' => 'domain_regex',
                    'message' => 'The domain format is invalid.',
                    'expected' => 'a hostname, lowercase, without scheme or path',
                    'examples' => ['shop.example.com'],
                ]],
            ]);
        }
        $this->assertNothingCreated();
    }

    public function test_a_malformed_plan_and_recipe(): void
    {
        foreach (self::uris() as $uri) {
            $this->assertBody($this->postJson($uri, ['username' => 'fresh', 'stages' => ['nonsense' => []]]), 422, [
                'message' => "stages: 'nonsense' is not a stage; expected one of precheck, prepare, build, install, upgrade, start",
                'errors' => ['stages' => ["stages: 'nonsense' is not a stage; expected one of precheck, prepare, build, install, upgrade, start"]],
                'problems' => [['field' => 'stages', 'code' => 'stages_invalid', 'message' => "stages: 'nonsense' is not a stage; expected one of precheck, prepare, build, install, upgrade, start"]],
            ]);
            $this->assertBody($this->postJson($uri, ['username' => 'fresh', 'recipe' => 'Bad']), 422, [
                'message' => "'Bad' is not a recipe id. Inspect the source and pick one of the ids under application.candidates.",
                'errors' => ['recipe' => ["'Bad' is not a recipe id. Inspect the source and pick one of the ids under application.candidates."]],
                'problems' => [['field' => 'recipe', 'code' => 'recipe_invalid', 'message' => "'Bad' is not a recipe id. Inspect the source and pick one of the ids under application.candidates."]],
            ]);
        }
        $this->assertNothingCreated();
    }

    public function test_an_unknown_vault_reference(): void
    {
        foreach (self::uris() as $uri) {
            $this->assertBody($this->postJson($uri, ['username' => 'fresh', 'domain' => 'fresh.example.test', 'git_repo' => 'https://github.com/acme/app.git', 'git_token' => 'vault:999']), 422, [
                'message' => "No vault entry for 'vault:999'. Use the `vault:<id>` vault_secret_create returned.",
                'errors' => ['git_token' => ["No vault entry for 'vault:999'. Use the `vault:<id>` vault_secret_create returned."]],
                'problems' => [['field' => 'git_token', 'code' => 'git_token_invalid', 'message' => "No vault entry for 'vault:999'. Use the `vault:<id>` vault_secret_create returned."]],
            ]);
            $this->assertBody($this->postJson($uri, ['username' => 'fresh', 'domain' => 'fresh.example.test', 'env_vars' => ['A' => 'vault:999']]), 422, [
                'message' => "No vault entry for 'vault:999'. Use the `vault:<id>` vault_secret_create returned.",
                'errors' => ['env_vars' => ["No vault entry for 'vault:999'. Use the `vault:<id>` vault_secret_create returned."]],
                'problems' => [['field' => 'env_vars', 'code' => 'env_vars_invalid', 'message' => "No vault entry for 'vault:999'. Use the `vault:<id>` vault_secret_create returned."]],
            ]);
        }
        $this->assertNothingCreated();
    }

    public function test_a_provision_failure_is_a_500_and_leaves_nothing(): void
    {
        DB::statement("CREATE TRIGGER refuse_domains BEFORE INSERT ON domains BEGIN SELECT RAISE(ABORT, 'refused'); END");

        foreach (self::uris() as $uri) {
            $this->assertBody($this->postJson($uri, ['username' => 'fresh', 'domain' => 'fresh.example.test']), 500, [
                'message' => 'Server Error',
            ]);
        }
        $this->assertNothingCreated();
    }

    public function test_the_stream_opt_in(): void
    {
        $this->assertBody($this->postJson('/api/users', ['name' => 'fresh'], ['X-Deploy-Stream' => 'xml']), 400, [
            'message' => 'Unsupported X-Deploy-Stream value: xml',
        ]);
        $this->assertBody($this->postJson('/api/projects', ['name' => 'fresh'], ['X-Deploy-Stream' => 'ndjson']), 400, [
            'message' => 'X-Deploy-Stream is not supported on POST /projects; use POST /users for a synchronous create.',
        ]);
        // Validation still answers as plain JSON when a stream was asked for.
        $this->assertSame(422, $this->postJson('/api/users', ['name' => 'ab'], ['X-Deploy-Stream' => 'ndjson'])->getStatusCode());
        $this->assertNothingCreated();
    }

    public function test_a_repository_on_an_address_the_project_cannot_reach(): void
    {
        foreach (self::uris() as $uri) {
            $this->postJson($uri, ['username' => 'fresh', 'domain' => 'fresh.example.test', 'git_repo' => 'http://10.10.0.25:18660/r7.git'])
                ->assertStatus(422)
                ->assertJsonPath('problems.0.field', 'git_repo')
                ->assertJsonPath('problems.0.code', 'git_repo_private_address')
                ->assertJsonPath('problems.0.message', '10.10.0.25 is a private, loopback, link-local or reserved address. '
                    . "A project's network does not reach those, so the clone would fail. Use a repository on a public address.");
            $this->postJson($uri, ['username' => 'fresh', 'domain' => 'fresh.example.test', 'git_repo' => 'http://localhost/acme/app.git'])
                ->assertStatus(422)
                ->assertJsonPath('problems.0.code', 'git_repo_private_address');
        }
        $this->assertNothingCreated();
    }

    /** The sync path arms the deploy before provisioning; the async one leaves it to the job. */
    public function test_the_plan_and_recipe_reach_the_deploy_context(): void
    {
        $this->makeUser('taken');
        $stages = ['build' => [['id' => 'b', 'run' => 'make']]];

        $this->postJson('/api/projects', ['username' => 'taken', 'stages' => $stages, 'recipe' => 'php'])->assertStatus(422);
        $this->assertNull(app(DeployPlanContext::class)->get());
        $this->assertNull(app(RecipeChoiceContext::class)->get());

        $this->postJson('/api/users', ['username' => 'taken', 'stages' => $stages, 'recipe' => 'php'])->assertStatus(422);
        $this->assertNotNull(app(DeployPlanContext::class)->get());
        $this->assertSame('php', app(RecipeChoiceContext::class)->get());
    }

    /**
     * The inspection before the clone never refuses: a repository it finds
     * will not deploy is created all the same, and the answer and the
     * queued deploy carry what it found.
     */
    #[\PHPUnit\Framework\Attributes\Group('network')]
    public function test_a_repository_that_may_not_deploy_is_created_with_the_inspection(): void
    {
        $asked = new \ArrayObject();
        $this->app->instance(CreateInspector::class, new class ($asked) extends CreateInspector {
            public function __construct(private readonly \ArrayObject $asked)
            {
            }

            public function inspect(
                string $repoUrl,
                ?string $branch = null,
                ?string $token = null,
                ?string $recipe = null,
                ?DeployPlan $plan = null
            ): CreateInspection {
                $this->asked[] = [$repoUrl, $branch, $token, $recipe];

                return CreateInspection::fromReport([
                    'application' => ['strategy' => 'fallback', 'label' => 'Unknown', 'deployable' => true, 'issue' => null],
                ]);
            }
        });

        $response = $this->postJson('/api/projects', [
            'name' => 'inspected',
            'domain' => 'inspected.example.test',
            'tunnel' => 'none',
            'git_repo' => 'https://github.com/octocat/Spoon-Knife',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.inspection.verdict', CreateInspection::PLACEHOLDER);
        $response->assertJsonPath('data.inspection.strategy', 'fallback');
        $this->assertNotEmpty($response->json('data.inspection.reason'));
        $this->assertNotEmpty($response->json('data.inspection.suggestion'));
        // The rest of the answer is the task, as before.
        $response->assertJsonPath('data.job_type', DeployProject::class);
        $this->assertSame([['https://github.com/octocat/Spoon-Knife', null, null, null]], $asked->getArrayCopy());
        $this->assertTrue(User::query()->where('username', 'inspected')->exists());
        Queue::assertPushed(
            DeployProject::class,
            static fn (DeployProject $job): bool => ($job->inspection['verdict'] ?? null) === CreateInspection::PLACEHOLDER
        );
    }

    public function test_a_create_without_a_repository_inspects_nothing(): void
    {

        $response = $this->postJson('/api/projects', [
            'name' => 'plainone',
            'domain' => 'plainone.example.test',
            'tunnel' => 'none',
        ]);

        $response->assertStatus(202);
        $this->assertNull($response->json('data.inspection'));
        $this->assertArrayHasKey('inspection', (array) $response->json('data'));
    }
}
