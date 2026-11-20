<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Project\CreateInspection;
use App\Lib\Project\CreateInspector;
use App\Lib\Project\ProjectRebuild;
use App\Models\Setting;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * PUT /projects/{username}/source: the create's typed source, set on a
 * project that exists, refused with the field as sent.
 */
class ProjectSourceSetHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Setting::clearRuntimeSettings();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
        $this->app->instance(GitRemoteProbe::class, new class extends GitRemoteProbe
        {
            public function __construct() {}

            public function problem(string $repoField, string $repoUrl, ?string $token, string $tokenField = 'git_token', ?string $branch = null, string $branchField = 'git_branch', ?array $deployKey = null): ?array
            {
                return null;
            }
        });
        $this->app->instance(CreateInspector::class, new class extends CreateInspector
        {
            public function __construct() {}

            public function inspect(string $repoUrl, ?string $branch = null, ?string $token = null, ?string $recipe = null, ?DeployPlan $plan = null): CreateInspection
            {
                return CreateInspection::skipped('the test does not read the repository.');
            }
        });
        $this->app->instance(ProjectRebuild::class, new class extends ProjectRebuild
        {
            public function assertArchive(User $user, string $zipPath): void {}

            protected function lockQueue(\App\Lib\Deploy\DeployLog\DeployLogPaths $paths)
            {
                return fopen('php://memory', 'r');
            }
        });
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_git_source_for_a_project_created_without_one(): void
    {
        $this->makeUser('waiting', ['template' => 'dind']);

        $response = $this->putJson('/api/projects/waiting/source', [
            'source' => ['type' => 'git', 'url' => 'https://github.com/acme/shop', 'branch' => 'main'],
            'build' => ['env_vars' => ['APP_ENV' => 'production'], 'recipe' => 'php'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.action', 'attach_source');
        $this->assertArrayHasKey('inspection', (array) $response->json('data'));
        $user = User::findByUsername('waiting');
        $this->assertSame('https://github.com/acme/shop', $user?->getGitRepo());
        $this->assertSame(['APP_ENV' => 'production'], $user?->getEnvVars());
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->action === 'attach_source' && $job->recipe === 'php');
    }

    public function test_a_git_refusal_names_the_typed_field(): void
    {
        $this->makeUser('hasgit', ['template' => 'dind', 'git_repo' => 'https://github.com/acme/old']);

        $response = $this->putJson('/api/projects/hasgit/source', ['source' => ['type' => 'git', 'url' => 'https://github.com/acme/new']]);

        $response->assertStatus(409);
        $response->assertJsonPath('problems.0.field', 'source.url');
        $response->assertJsonPath('problems.0.code', 'source_already_attached');
    }

    public function test_only_git_and_archive_attach(): void
    {
        $this->makeUser('waiting2', ['template' => 'dind']);

        foreach (['project', 'wordpress', 'none'] as $type) {
            $response = $this->putJson('/api/projects/waiting2/source', ['source' => ['type' => $type]]);
            $response->assertStatus(422);
            $response->assertJsonPath('problems.0.field', 'source.type');
            $response->assertJsonPath('problems.0.code', 'source_type_invalid');
        }
        $this->putJson('/api/projects/waiting2/source', ['source' => ['type' => 'git', 'url' => 'https://github.com/a/b'], 'name' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'unknown_field');
        Queue::assertNothingPushed();
    }

    public function test_an_archive_already_in_the_home(): void
    {
        $this->makeUser('zipper', ['template' => 'dind']);

        $response = $this->putJson('/api/projects/zipper/source', ['source' => ['type' => 'archive', 'path' => '/project/app.zip']]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.action', 'deploy_archive');
        $response->assertJsonPath('data.details.zip_path', '/project/app.zip');
        $response->assertJsonPath('data.details.source_type', 'archive');
        $this->assertSame(['type' => 'archive', 'path' => '/project/app.zip'], User::findByUsername('zipper')?->getDetails()['source'] ?? null);
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->zipPath === '/project/app.zip' && $job->archive === null);
    }

    public function test_an_archive_by_url_is_downloaded_by_the_deploy(): void
    {
        $this->makeUser('urlzip', ['template' => 'dind']);

        $response = $this->putJson('/api/projects/urlzip/source', ['source' => ['type' => 'archive', 'url' => 'https://example.com/releases/app.tar.gz']]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.archive_url', 'https://example.com/releases/app.tar.gz');
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->zipPath === null
            && $job->archive === ['kind' => 'url', 'value' => 'https://example.com/releases/app.tar.gz']);
    }

    public function test_an_upload_is_claimed_for_the_project_and_moved_in_by_the_deploy(): void
    {
        $this->makeUser('upzip', ['template' => 'dind']);
        $upload = $this->upload();

        $response = $this->putJson('/api/projects/upzip/source', ['source' => ['type' => 'archive', 'upload_id' => $upload->id]]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.upload_id', $upload->id);
        $this->assertSame('upzip', $upload->refresh()->claimed_by);
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->zipPath === null
            && $job->archive === ['kind' => 'upload', 'value' => $upload->id]);

        $this->makeUser('upzip2', ['template' => 'dind']);
        $again = $this->putJson('/api/projects/upzip2/source', ['source' => ['type' => 'archive', 'upload_id' => $upload->id]]);
        $again->assertStatus(422);
        $again->assertJsonPath('problems.0.code', 'upload_in_use');
    }

    public function test_an_archive_is_refused_where_it_cannot_replace_the_files(): void
    {
        $this->makeUser('classic');
        $this->makeUser('gitproj', ['template' => 'dind', 'git_repo' => 'https://github.com/acme/shop']);

        $classic = $this->putJson('/api/projects/classic/source', ['source' => ['type' => 'archive', 'path' => '/project/app.zip']]);
        $classic->assertStatus(409);
        $classic->assertJsonPath('problems.0.code', 'project_not_eligible');

        $git = $this->putJson('/api/projects/gitproj/source', ['source' => ['type' => 'archive', 'path' => '/project/app.zip']]);
        $git->assertStatus(409);
        $git->assertJsonPath('problems.0.code', 'archive_on_git_project');
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_project_is_a_404(): void
    {
        $this->putJson('/api/projects/nobody/source', ['source' => ['type' => 'archive', 'path' => '/project/app.zip']])->assertStatus(404);
    }

    private function upload(): Upload
    {
        $id = 'upl_'.bin2hex(random_bytes(8));
        $dir = storage_path('app/uploads/'.$id);
        @mkdir($dir, 0700, true);
        file_put_contents($dir.'/app.zip', "PK\x03\x04");
        $this->beforeApplicationDestroyed(static function () use ($dir): void {
            @unlink($dir.'/app.zip');
            @rmdir($dir);
        });

        return Upload::create([
            'id' => $id,
            'filename' => 'app.zip',
            'size' => 4,
            'path' => $id.'/app.zip',
            'expires_at' => Carbon::now()->addHour(),
        ]);
    }
}
