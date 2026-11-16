<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\CopyProject;
use App\Jobs\DeployProject;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Git\DeployKey;
use App\Lib\Project\CreateInspection;
use App\Lib\Project\CreateInspector;
use App\Lib\Project\ProvisionEnvironment;
use App\Lib\Project\SystemProvisionEnvironment;
use App\Models\Domain;
use App\Models\SecretVaultEntry;
use App\Models\Setting;
use App\Models\Task;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * POST /projects with the typed body: its shape refused with the field as
 * sent, and each source type queued the way it is deployed.
 */
class ProjectCreateTypedTest extends TestCase
{
    use InMemoryDatabase;

    private \ArrayObject $probed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Setting::clearRuntimeSettings();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
        LocalRoot::answerLocally();
        $this->fakeTheRemote();
        $this->app->instance(ProvisionEnvironment::class, new class(new SystemProvisionEnvironment) implements ProvisionEnvironment
        {
            public function __construct(private readonly ProvisionEnvironment $host) {}

            public function usernameRowExists(string $username): bool
            {
                return $this->host->usernameRowExists($username);
            }

            public function usernameAvailableOnHost(string $username): bool
            {
                return $this->host->usernameAvailableOnHost($username);
            }

            public function usernameHolders(string $username): array
            {
                return $this->host->usernameHolders($username);
            }

            public function templateExists(string $template): bool
            {
                return in_array($template, ['default', 'dind'], true);
            }

            public function domainOrAliasExists(string $domain): bool
            {
                return $this->host->domainOrAliasExists($domain);
            }

            public function freeDedicatedIpExists(int $family): bool
            {
                return $this->host->freeDedicatedIpExists($family);
            }
        });
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        app(DeployPlanContext::class)->clear();
        app(RecipeChoiceContext::class)->clear();
        LocalRoot::answerByBroker();
        parent::tearDown();
    }

    public function test_an_empty_container_project(): void
    {
        $response = $this->create([
            'name' => 'emptyone',
            'runtime' => 'container',
            'domain' => ['name' => 'emptyone.example.test', 'tunnel' => 'none'],
            'limits' => ['memory' => 256, 'disk' => 2048],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.source_type', 'none');
        $response->assertJsonPath('data.username', 'emptyone');
        $details = User::findByUsername('emptyone')?->getDetails() ?? [];
        $this->assertSame('dind', $details['template']);
        $this->assertSame(256, $details['memory_limit']);
        $this->assertSame(2048, $details['disk_space_limit']);
        Queue::assertPushed(DeployProject::class, static fn (DeployProject $job): bool => $job->followUp === ['source_type' => 'none']);
    }

    public function test_an_empty_project_is_a_container_unless_asked_otherwise(): void
    {
        $this->create(['name' => 'defaultrt', 'domain' => ['name' => 'defaultrt.example.test', 'tunnel' => 'none'], 'source' => ['type' => 'none']])
            ->assertStatus(202);
        $this->create(['name' => 'classicrt', 'runtime' => 'php_hosting', 'domain' => ['name' => 'classicrt.example.test', 'tunnel' => 'none']])
            ->assertStatus(202);

        $this->assertSame('dind', User::findByUsername('defaultrt')?->getTemplate());
        $this->assertNull(User::findByUsername('classicrt')?->getTemplate());
    }

    public function test_a_body_of_only_a_name_and_an_email_is_an_empty_container_project(): void
    {
        Setting::setRuntimeSettings(['default_wildcard_domain' => 'sites.example.test']);

        $this->create(['name' => 'nameonly'])->assertStatus(202)->assertJsonPath('data.details.source_type', 'none');
        $this->create(['name' => 'withmail', 'email' => 'owner@example.com'])->assertStatus(202);

        $this->assertSame('dind', User::findByUsername('nameonly')?->getTemplate());
        $this->assertSame('dind', User::findByUsername('withmail')?->getTemplate());
    }

    public function test_an_empty_body_and_an_email_alone_are_empty_container_projects_too(): void
    {
        Setting::setRuntimeSettings(['default_wildcard_domain' => 'sites.example.test']);

        $empty = $this->call('POST', '/api/projects', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}');
        $emailOnly = $this->create(['email' => 'owner@example.com']);

        foreach ([$empty, $emailOnly] as $response) {
            $response->assertStatus(202)->assertJsonPath('data.details.source_type', 'none');
            $this->assertSame('dind', User::findByUsername((string) $response->json('data.username'))?->getTemplate());
        }
    }

    public function test_a_key_only_the_flat_body_has_keeps_the_classic_default(): void
    {
        Setting::setRuntimeSettings(['default_wildcard_domain' => 'sites.example.test']);

        $this->create(['username' => 'flatname', 'email' => 'owner@example.com'])->assertStatus(202);
        $this->create(['name' => 'flatdomain', 'domain' => 'flatdomain.example.test', 'tunnel' => 'none'])->assertStatus(202);

        $this->assertNull(User::findByUsername('flatname')?->getTemplate());
        $this->assertNull(User::findByUsername('flatdomain')?->getTemplate());
    }

    public function test_a_git_source_with_a_token(): void
    {
        $response = $this->create([
            'name' => 'gitone',
            'domain' => ['name' => 'gitone.example.test', 'tunnel' => 'none'],
            'source' => [
                'type' => 'git',
                'url' => 'https://github.com/octocat/Hello-World.git',
                'branch' => 'master',
                'auth' => ['type' => 'token', 'token' => 'plain-read-token-1234'],
            ],
            'build' => ['env_vars' => ['APP_ENV' => 'production']],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.source_type', 'git');
        $user = User::findByUsername('gitone');
        $this->assertSame('https://github.com/octocat/Hello-World.git', $user?->getGitRepo());
        $this->assertSame('master', $user?->getGitBranch());
        $this->assertSame('plain-read-token-1234', $user?->getGitToken());
        $this->assertSame('dind', $user?->getTemplate());
        $this->assertSame(['APP_ENV' => 'production'], $user?->getEnvVars());
        $this->assertSame([['git_repo', 'https://github.com/octocat/Hello-World.git', 'plain-read-token-1234', 'master', null]], $this->probed->getArrayCopy());
    }

    public function test_flat_fields_are_refused_with_where_they_go(): void
    {
        $response = $this->create([
            'name' => 'mixed',
            'runtime' => 'container',
            'git_repo' => 'https://github.com/octocat/Hello-World.git',
            'memory_limit' => 512,
        ]);

        $response->assertStatus(422);
        $this->assertSame([
            ['field' => 'git_repo', 'code' => 'legacy_field', 'replacement' => 'source.url'],
            ['field' => 'memory_limit', 'code' => 'legacy_field', 'replacement' => 'limits.memory'],
        ], $this->problems($response, ['field', 'code', 'replacement']));
        $this->assertNothingCreated();
    }

    public function test_unknown_keys_are_refused_where_they_are(): void
    {
        $response = $this->create([
            'name' => 'unknowns',
            'colour' => 'blue',
            'limits' => ['ram' => 1],
            'source' => ['type' => 'git', 'url' => 'https://github.com/a/b', 'depth' => 1],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            [['colour', 'unknown_field'], ['limits.ram', 'unknown_field'], ['source.depth', 'unknown_field']],
            array_map(static fn (array $p): array => [$p['field'], $p['code']], $response->json('problems')),
        );
        $this->assertNothingCreated();
    }

    public function test_the_flat_body_refuses_unknown_keys_too(): void
    {
        $response = $this->create(['name' => 'flatone', 'bogus' => 1]);

        $response->assertStatus(422);
        $this->assertSame(
            [['bogus', 'unknown_field']],
            array_map(static fn (array $p): array => [$p['field'], $p['code']], $response->json('problems')),
        );
        $this->assertNothingCreated();
    }

    public function test_a_value_refusal_names_the_typed_field(): void
    {
        $response = $this->create([
            'name' => 'A',
            'domain' => ['name' => 'not a domain', 'tunnel' => 'sideways'],
            'limits' => ['memory' => 'lots'],
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('problems'), 'field');
        sort($fields);
        $this->assertSame(['domain.name', 'domain.tunnel', 'limits.memory', 'name'], $fields);
        $this->assertNothingCreated();
    }

    public function test_a_provisioning_refusal_names_the_typed_field(): void
    {
        $this->makeUser('takenname');

        $response = $this->create(['name' => 'takenname', 'runtime' => 'container', 'domain' => ['tunnel' => 'none']]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'name');
        $response->assertJsonPath('problems.0.code', 'name_taken');
    }

    public function test_an_ssh_remote_needs_a_deploy_key_and_a_deploy_key_an_ssh_remote(): void
    {
        $ssh = $this->create(['name' => 'sshnokey', 'source' => ['type' => 'git', 'url' => 'git@github.com:acme/shop.git']]);
        $ssh->assertStatus(422);
        $ssh->assertJsonPath('problems.0.field', 'source.url');
        $ssh->assertJsonPath('problems.0.code', 'source_ssh_needs_deploy_key');
        $ssh->assertJsonPath('problems.0.suggestion', 'https://github.com/acme/shop.git');

        $https = $this->create(['name' => 'keynossh', 'source' => [
            'type' => 'git', 'url' => 'https://github.com/acme/shop.git', 'auth' => ['type' => 'deploy_key', 'key' => 'vault:1'],
        ]]);
        $https->assertStatus(422);
        $https->assertJsonPath('problems.0.field', 'source.auth.type');
        $https->assertJsonPath('problems.0.code', 'source_deploy_key_needs_ssh');
        $this->assertNothingCreated();
    }

    public function test_a_deploy_key_is_a_vault_reference(): void
    {
        $response = $this->create(['name' => 'rawkey', 'source' => [
            'type' => 'git', 'url' => 'git@github.com:acme/shop.git', 'auth' => ['type' => 'deploy_key', 'key' => '-----BEGIN OPENSSH PRIVATE KEY-----'],
        ]]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'source.auth.key');
        $response->assertJsonPath('problems.0.code', 'source_auth_key_invalid');
    }

    public function test_an_ssh_create_stores_the_vault_key_on_the_project(): void
    {
        $entry = $this->vaultDeployKey();

        $response = $this->create([
            'name' => 'sshone',
            'domain' => ['name' => 'sshone.example.test', 'tunnel' => 'none'],
            'source' => [
                'type' => 'git',
                'url' => 'git@github.com:acme/shop.git',
                'branch' => 'main',
                'auth' => ['type' => 'deploy_key', 'key' => $entry->reference()],
            ],
        ]);

        $response->assertStatus(202);
        $user = User::findByUsername('sshone');
        $this->assertSame('git@github.com:acme/shop.git', $user?->getGitRepo());
        $this->assertSame(self::key()['private_key'], DeployKey::stored($user)['private_key'] ?? null);
        $this->assertSame('sshone', $entry->refresh()->project);
        $this->assertSame([['git_repo', 'git@github.com:acme/shop.git', null, 'main', self::key()['private_key']]], $this->probed->getArrayCopy());
        $this->assertStringNotContainsString('PRIVATE', (string) $response->getContent());
    }

    public function test_an_unpinned_ssh_host_is_refused_before_anything_is_created(): void
    {
        $entry = $this->vaultDeployKey();

        $response = $this->create(['name' => 'sshother', 'source' => [
            'type' => 'git', 'url' => 'ssh://git@git.example.com:2222/acme/shop.git', 'auth' => ['type' => 'deploy_key', 'key' => $entry->reference()],
        ]]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'source.url');
        $response->assertJsonPath('problems.0.code', 'git_repo_ssh_host_not_pinned');
        $this->assertNothingCreated();
    }

    public function test_a_deploy_key_of_another_project_is_refused_on_the_key_field(): void
    {
        $entry = $this->vaultDeployKey('someoneelse');

        $response = $this->create(['name' => 'thief', 'source' => [
            'type' => 'git', 'url' => 'git@github.com:acme/shop.git', 'auth' => ['type' => 'deploy_key', 'key' => $entry->reference()],
        ]]);

        $response->assertStatus(422);
        $this->assertSame('source.auth.key', array_key_first((array) $response->json('errors')));
    }

    public function test_a_source_that_contradicts_the_runtime(): void
    {
        $response = $this->create(['name' => 'wpcont', 'runtime' => 'container', 'source' => self::wordpress()]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'runtime');
        $response->assertJsonPath('problems.0.code', 'source_conflicts_with_runtime');
    }

    public function test_build_is_refused_where_nothing_builds(): void
    {
        $classic = $this->create(['name' => 'classicb', 'runtime' => 'php_hosting', 'build' => ['env_vars' => ['A' => 'b']]]);
        $classic->assertStatus(422);
        $classic->assertJsonPath('problems.0.field', 'build');
        $classic->assertJsonPath('problems.0.code', 'field_not_allowed');

        $empty = $this->create(['name' => 'emptyb', 'runtime' => 'container', 'build' => ['recipe' => 'php', 'env_vars' => ['A' => 'b']]]);
        $empty->assertStatus(422);
        $empty->assertJsonPath('problems.0.field', 'build.recipe');
        $this->assertNothingCreated();
    }

    public function test_an_archive_takes_exactly_one_of_upload_id_and_url(): void
    {
        foreach ([[], ['upload_id' => 'upl_x', 'url' => 'https://example.com/a.zip']] as $fields) {
            $response = $this->create(['name' => 'archone', 'source' => ['type' => 'archive'] + $fields]);
            $response->assertStatus(422);
            $response->assertJsonPath('problems.0.code', 'source_archive_needs_one');
        }

        $path = $this->create(['name' => 'archpath', 'source' => ['type' => 'archive', 'path' => '/project/app.zip']]);
        $path->assertStatus(422);
        $path->assertJsonPath('problems.0.field', 'source.path');
        $path->assertJsonPath('problems.0.code', 'unknown_field');
        $this->assertNothingCreated();
    }

    public function test_an_unknown_upload_is_refused_before_anything_is_created(): void
    {
        $response = $this->create(['name' => 'noupload', 'domain' => ['tunnel' => 'none'], 'source' => ['type' => 'archive', 'upload_id' => 'upl_missing']]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'source.upload_id');
        $response->assertJsonPath('problems.0.code', 'upload_not_found');
        $this->assertNothingCreated();
    }

    public function test_an_upload_is_claimed_by_the_project_created_with_it(): void
    {
        $upload = $this->upload();

        $response = $this->create([
            'name' => 'fromzip',
            'domain' => ['name' => 'fromzip.example.test', 'tunnel' => 'none'],
            'source' => ['type' => 'archive', 'upload_id' => $upload->id],
            'build' => ['recipe' => 'php'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.source_type', 'archive');
        $response->assertJsonPath('data.details.upload_id', $upload->id);
        $this->assertSame('fromzip', $upload->refresh()->claimed_by);
        $this->assertSame(['type' => 'archive', 'upload_id' => $upload->id], User::findByUsername('fromzip')?->getDetails()['source'] ?? null);
        Queue::assertPushed(DeployProject::class, static fn (DeployProject $job): bool => $job->followUp === ['source_type' => 'archive', 'upload_id' => $upload->id]
            && $job->recipe === 'php');

        $second = $this->create(['name' => 'fromzip2', 'domain' => ['tunnel' => 'none'], 'source' => ['type' => 'archive', 'upload_id' => $upload->id]]);
        $second->assertStatus(422);
        $second->assertJsonPath('problems.0.code', 'upload_in_use');
    }

    public function test_a_failed_create_lets_its_upload_go(): void
    {
        $upload = $this->upload();
        $this->makeUser('takenzip');

        $this->create(['name' => 'takenzip', 'domain' => ['tunnel' => 'none'], 'source' => ['type' => 'archive', 'upload_id' => $upload->id]])
            ->assertStatus(422);

        $this->assertNull($upload->refresh()->claimed_by);
    }

    public function test_an_archive_url_the_engine_may_not_reach(): void
    {
        $response = $this->create(['name' => 'privurl', 'domain' => ['tunnel' => 'none'], 'source' => ['type' => 'archive', 'url' => 'http://'.TestNetwork::LOOPBACK.'/app.zip']]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'source.url');
        $response->assertJsonPath('problems.0.code', 'host_refused');
        $this->assertNothingCreated();
    }

    public function test_a_wordpress_source_queues_a_php_hosting_create(): void
    {
        $response = $this->create(['name' => 'wpone', 'domain' => ['name' => 'wpone.example.test', 'tunnel' => 'none'], 'source' => self::wordpress()]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.source_type', 'wordpress');
        $this->assertNull(User::findByUsername('wpone')?->getTemplate());
        $this->assertStringNotContainsString('s3cret-pass', (string) $response->getContent());
        Queue::assertPushed(DeployProject::class, function (DeployProject $job): bool {
            $followUp = (array) $job->followUp;

            return $followUp['source_type'] === 'wordpress'
                && $followUp['admin_user'] === 'boss'
                && $followUp['admin_password'] !== 's3cret-pass'
                && Crypt::decryptString($followUp['admin_password']) === 's3cret-pass';
        });
    }

    public function test_a_wordpress_admin_is_checked_before_anything_is_created(): void
    {
        $response = $this->create(['name' => 'wpbad', 'source' => [
            'type' => 'wordpress', 'title' => 'Shop', 'locale' => 'english', 'admin' => ['user' => 'boss', 'email' => 'nope', 'password' => 'short'],
        ]]);

        $response->assertStatus(422);
        $this->assertSame(
            ['source.locale', 'source.admin.email', 'source.admin.password'],
            array_column($response->json('problems'), 'field'),
        );
        $this->assertNothingCreated();
    }

    public function test_a_copy_of_a_project(): void
    {
        $original = $this->makeUser('original', ['template' => 'dind', 'memory_limit' => 256]);
        $this->makeMainDomain($original, 'original.example.test');

        $response = $this->create(['name' => 'thecopy', 'domain' => ['name' => 'thecopy.example.test'], 'source' => ['type' => 'project', 'from' => 'original', 'copy_databases' => false]]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.job_type', CopyProject::class);
        $response->assertJsonPath('data.username', 'thecopy');
        $response->assertJsonPath('data.details.action', 'copy');
        $response->assertJsonPath('data.details.from', 'original');
        $response->assertJsonPath('data.details.copy_databases', false);
        $copy = User::findByUsername('thecopy');
        $this->assertSame('dind', $copy?->getTemplate());
        $this->assertSame(['type' => 'project', 'from' => 'original'], $copy?->getDetails()['source'] ?? null);
        $this->assertTrue(Domain::existsByName('thecopy.example.test'));
        Queue::assertPushed(CopyProject::class, static fn (CopyProject $job): bool => $job->sourceId === $original->id
            && $job->copyId === $copy?->id && $job->copyDatabases === false);
    }

    public function test_a_copy_takes_nothing_but_its_name_and_domain(): void
    {
        $response = $this->create([
            'name' => 'copyset',
            'runtime' => 'container',
            'limits' => ['memory' => 512],
            'domain' => ['name' => 'copyset.example.test', 'tunnel' => 'none'],
            'source' => ['type' => 'project', 'from' => 'original'],
        ]);

        $response->assertStatus(422);
        $this->assertSame(['runtime', 'limits', 'domain.tunnel'], array_column($response->json('problems'), 'field'));
    }

    public function test_a_copy_of_a_project_that_does_not_exist(): void
    {
        $response = $this->create(['name' => 'orphan', 'source' => ['type' => 'project', 'from' => 'nosuch']]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'source.from');
        $response->assertJsonPath('problems.0.code', 'source_project_not_found');
        $this->assertNothingCreated();
    }

    public function test_the_flat_body_is_still_a_flat_create(): void
    {
        $response = $this->postJson('/api/projects', ['name' => 'flatone', 'domain' => 'flatone.example.test', 'tunnel' => 'none', 'template' => 'dind']);

        $response->assertStatus(202);
        $this->assertArrayNotHasKey('source_type', (array) $response->json('data.details'));
        Queue::assertPushed(DeployProject::class, static fn (DeployProject $job): bool => $job->followUp === null);
    }

    /** @param array<string, mixed> $body */
    private function create(array $body): TestResponse
    {
        return $this->postJson('/api/projects', $body);
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function problems(TestResponse $response, array $keys): array
    {
        return array_map(
            static fn (array $p): array => array_intersect_key($p, array_flip($keys)),
            (array) $response->json('problems'),
        );
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, Domain::query()->count());
        $this->assertSame(0, Task::query()->count());
        Queue::assertNothingPushed();
    }

    /** @return array<string, mixed> */
    private static function wordpress(): array
    {
        return ['type' => 'wordpress', 'title' => 'Shop', 'admin' => ['user' => 'boss', 'email' => 'boss@example.com', 'password' => 's3cret-pass']];
    }

    /** @return array{private_key: string, public_key: string, fingerprint: string, known_hosts: array<string, list<string>>} */
    private static function key(): array
    {
        return [
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----\n",
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl panelalpha-test',
            'fingerprint' => 'SHA256:test',
            'known_hosts' => [],
        ];
    }

    private function vaultDeployKey(?string $project = null): SecretVaultEntry
    {
        $entry = new SecretVaultEntry([
            'ref_hash' => SecretVaultEntry::hashRef(bin2hex(random_bytes(16))),
            'type' => SecretVaultEntry::TYPE_DEPLOY_KEY,
            'scope' => SecretVaultEntry::SCOPE_PROJECT,
            'project' => $project,
            'link_expires_at' => Carbon::now(),
        ]);
        $entry->setSecret((string) json_encode(self::key()));
        $entry->save();

        return $entry;
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

    private function fakeTheRemote(): void
    {
        $this->probed = new \ArrayObject;
        $this->app->instance(GitRemoteProbe::class, new class($this->probed) extends GitRemoteProbe
        {
            public function __construct(private readonly \ArrayObject $asked) {}

            public function problem(
                string $repoField,
                string $repoUrl,
                ?string $token,
                string $tokenField = 'git_token',
                ?string $branch = null,
                string $branchField = 'git_branch',
                ?array $deployKey = null,
            ): ?array {
                $this->asked[] = [$repoField, $repoUrl, $token, $branch, $deployKey['private_key'] ?? null];

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
    }
}
