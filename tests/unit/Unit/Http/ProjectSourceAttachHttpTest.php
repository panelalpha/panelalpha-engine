<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\DeployProject;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Project\CreateInspection;
use App\Lib\Project\CreateInspector;
use App\Lib\Project\ProjectRebuild;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST /projects/{username}/source: the git source a project created before its
 * source was known can be given, and every project it refuses.
 */
class ProjectSourceAttachHttpTest extends TestCase
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

        $this->fakeProbe();
        $this->fakeInspector();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /** No ls-remote leaves the test. */
    private function fakeProbe(): void
    {
        $this->app->instance(GitRemoteProbe::class, new class extends GitRemoteProbe {
            public function __construct()
            {
            }

            public function problem(
                string $repoField,
                string $repoUrl,
                ?string $token,
                string $tokenField = 'git_token',
                ?string $branch = null,
                string $branchField = 'git_branch'
            ): ?array {
                return null;
            }
        });
    }

    /** No github.com file list is read either. */
    private function fakeInspector(): void
    {
        $this->app->instance(CreateInspector::class, new class extends CreateInspector {
            public function __construct()
            {
            }

            public function inspect(
                string $repoUrl,
                ?string $branch = null,
                ?string $token = null,
                ?string $recipe = null,
                ?DeployPlan $plan = null
            ): CreateInspection {
                return CreateInspection::skipped('the test does not read the repository.');
            }
        });
    }

    /** @return array<string, mixed> */
    private static function body(): array
    {
        return ['git_repo' => 'https://github.com/octocat/Hello-World.git', 'git_branch' => 'master'];
    }

    public function test_a_dind_project_without_a_source_takes_the_repository(): void
    {
        $this->makeUser('attachme', ['template' => 'dind']);

        $response = $this->postJson('/api/projects/attachme/source', self::body());

        $response->assertStatus(202);
        $response->assertJsonPath('data.job_type', RebuildProject::class);
        $response->assertJsonPath('data.details.action', ProjectRebuild::ATTACH_SOURCE);
        $response->assertJsonPath('data.details.git_repo', 'https://github.com/octocat/Hello-World.git');
        $response->assertJsonPath('data.details.username', 'attachme');
        $this->assertArrayHasKey('inspection', (array) $response->json('data'));
        $this->assertArrayNotHasKey('git_token', (array) $response->json('data.details'));

        $user = User::findByUsernameOrFail('attachme');
        $this->assertSame('https://github.com/octocat/Hello-World.git', $user->getGitRepo());
        $this->assertSame('master', $user->getGitBranch());
        $this->assertSame('dind', $user->getTemplate());
        Queue::assertPushed(
            RebuildProject::class,
            static fn (RebuildProject $job): bool => $job->action === ProjectRebuild::ATTACH_SOURCE
        );
    }

    public function test_an_env_var_override_is_merged_onto_the_project(): void
    {
        $this->makeUser('mergeenv', ['template' => 'dind', 'env_vars' => ['KEEP' => '1', 'DROP' => '1']]);

        $this->postJson('/api/projects/mergeenv/source', self::body() + [
            'env_vars' => ['KEEP' => '2', 'DROP' => '', 'NEW' => '3'],
        ])->assertStatus(202);

        $this->assertSame(
            ['KEEP' => '2', 'NEW' => '3'],
            User::findByUsernameOrFail('mergeenv')->getEnvVars()
        );
    }

    public function test_a_project_that_already_has_a_source_is_refused(): void
    {
        $this->makeUser('hassource', ['template' => 'dind', 'git_repo' => 'https://github.com/acme/other.git']);

        $response = $this->postJson('/api/projects/hassource/source', self::body());

        $response->assertStatus(409);
        $response->assertJsonPath('problems.0.field', 'git_repo');
        $response->assertJsonPath('problems.0.code', 'source_already_attached');
        Queue::assertNothingPushed();
    }

    public function test_a_classic_project_is_refused(): void
    {
        $this->makeUser('classic');

        $response = $this->postJson('/api/projects/classic/source', self::body());

        $response->assertStatus(409);
        $response->assertJsonPath('problems.0.field', 'git_repo');
        $response->assertJsonPath('problems.0.code', 'project_not_eligible');
        $this->assertNull(User::findByUsernameOrFail('classic')->getGitRepo());
        Queue::assertNothingPushed();
    }

    public function test_a_dind_project_that_already_runs_an_application_is_refused(): void
    {
        $this->makeUser('alreadyapp', ['template' => 'dind', 'deployment_status' => 'success']);

        $response = $this->postJson('/api/projects/alreadyapp/source', self::body());

        $response->assertStatus(409);
        $response->assertJsonPath('problems.0.code', 'project_not_eligible');
        $this->assertNull(User::findByUsernameOrFail('alreadyapp')->getGitRepo());
        Queue::assertNothingPushed();
    }

    public function test_a_deploy_already_queued_is_refused_with_its_task(): void
    {
        $this->makeUser('busyone', ['template' => 'dind']);
        $task = Task::start(
            jobType: DeployProject::class,
            queue: 'default',
            username: 'busyone',
            details: ['action' => 'deploy'],
        );

        $response = $this->postJson('/api/projects/busyone/source', self::body());

        $response->assertStatus(409);
        $response->assertJsonPath('task_id', $task->id);
        $this->assertNull(User::findByUsernameOrFail('busyone')->getGitRepo());
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_vault_reference_is_refused(): void
    {
        $this->makeUser('vaulthit', ['template' => 'dind']);

        $response = $this->postJson('/api/projects/vaulthit/source', [
            'git_repo' => 'https://github.com/acme/app.git',
            'git_token' => 'vault:999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'git_token');
        $response->assertJsonPath('problems.0.code', 'git_token_invalid');
        Queue::assertNothingPushed();
    }

    public function test_a_repository_on_a_refused_address_is_refused(): void
    {
        $this->makeUser('privateone', ['template' => 'dind']);

        $response = $this->postJson('/api/projects/privateone/source', [
            'git_repo' => 'http://10.10.0.25:18660/r7.git',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'git_repo');
        $response->assertJsonPath('problems.0.code', 'git_repo_private_address');
        Queue::assertNothingPushed();
    }

    public function test_the_repository_is_required(): void
    {
        $this->makeUser('nosource', ['template' => 'dind']);

        $response = $this->postJson('/api/projects/nosource/source', []);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'git_repo');
        $response->assertJsonPath('problems.0.code', 'git_repo_required');
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_project_is_a_404(): void
    {
        $this->postJson('/api/projects/nobody/source', self::body())->assertStatus(404);
        Queue::assertNothingPushed();
    }
}
