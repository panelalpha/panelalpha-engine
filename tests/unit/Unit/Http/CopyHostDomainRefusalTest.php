<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Project\PublicRouting\HostDomain;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A clone, a typed copy and a staging copy take the server's own name only with the copy's own `host_domain`
 * permission, and are refused it in the words every other path uses, over REST and the CLI alike.
 */
class CopyHostDomainRefusalTest extends TestCase
{
    use InMemoryDatabase;

    private const HOST = 'host.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => '', 'cert_domain' => self::HOST]);
        $source = $this->makeUser('source', ['template' => 'dind']);
        $this->makeMainDomain($source, 'source.example.test');
        LocalRoot::answerLocally();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        LocalRoot::answerByBroker();
        parent::tearDown();
    }

    public function test_a_clone_onto_the_servers_name_is_refused(): void
    {
        $response = $this->postJson('/api/projects/source/clone', ['new_username' => 'srccopy', 'domain' => self::HOST]);

        $this->assertRefused($response, 'domain');
    }

    public function test_a_typed_copy_onto_the_servers_name_is_refused(): void
    {
        $response = $this->postJson('/api/projects', [
            'name' => 'srccopy',
            'domain' => ['name' => self::HOST],
            'source' => ['type' => 'project', 'from' => 'source'],
        ]);

        $this->assertRefused($response, 'domain.name');
    }

    public function test_a_staging_copy_onto_the_servers_name_is_refused_with_or_without_www(): void
    {
        $this->assertRefused($this->postJson('/api/projects/source/staging', ['new_username' => 'srcstage', 'domain' => self::HOST]), 'domain');
        $this->assertRefused($this->postJson('/api/projects/source/staging', ['new_username' => 'srcstage', 'domain' => 'www.'.self::HOST]), 'domain');
    }

    public function test_the_staging_command_refuses_the_servers_name_in_the_same_words(): void
    {
        $this->artisan('project:staging', ['project' => 'source', '--new-username' => 'srcstage', '--domain' => self::HOST])
            ->expectsOutput(self::refusal())
            ->assertExitCode(1);

        $this->assertNothingCopied();
    }

    public function test_the_sources_permission_does_not_let_its_copy_take_the_name(): void
    {
        (new PublicRoutingGrants)->allow('source', [PublicRoutingGrants::HOST_DOMAIN]);

        $this->assertRefused($this->postJson('/api/projects/source/staging', ['new_username' => 'srcstage', 'domain' => self::HOST]), 'domain');
    }

    public function test_a_staging_copy_the_operator_allowed_takes_the_servers_name(): void
    {
        (new PublicRoutingGrants)->allow('srcstage', [PublicRoutingGrants::HOST_DOMAIN]);

        $this->postJson('/api/projects/source/staging', ['new_username' => 'srcstage', 'domain' => self::HOST])->assertStatus(202);

        $this->assertSame(self::HOST, User::findByUsername('srcstage')?->domain);
    }

    public function test_a_typed_copy_the_operator_allowed_takes_the_servers_name(): void
    {
        (new PublicRoutingGrants)->allow('srccopy', [PublicRoutingGrants::HOST_DOMAIN]);

        $this->postJson('/api/projects', [
            'name' => 'srccopy',
            'domain' => ['name' => self::HOST],
            'source' => ['type' => 'project', 'from' => 'source'],
        ])->assertStatus(202);

        $this->assertSame(self::HOST, User::findByUsername('srccopy')?->domain);
    }

    public function test_a_name_already_on_the_engine_is_still_refused_as_taken(): void
    {
        $this->postJson('/api/projects/source/clone', ['new_username' => 'srccopy', 'domain' => 'source.example.test'])
            ->assertStatus(422)
            ->assertJsonPath('errors.domain.0', 'Domain already exists.')
            ->assertJsonPath('problems.0.code', 'domain_taken');
        $this->postJson('/api/projects/source/staging', ['new_username' => 'srcstage', 'domain' => 'source.example.test'])
            ->assertStatus(422)
            ->assertJsonPath('errors.domain.0', 'Domain already exists.');
        $this->artisan('project:staging', ['project' => 'source', '--new-username' => 'srcstage', '--domain' => 'source.example.test'])
            ->expectsOutput('Domain already exists.')
            ->assertExitCode(1);
    }

    private function assertRefused(TestResponse $response, string $field): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', $field);
        $response->assertJsonPath('problems.0.code', HostDomain::CLAIM_REFUSED);
        $response->assertJsonPath('problems.0.message', self::refusal());
        $this->assertNothingCopied();
    }

    private static function refusal(): string
    {
        return (string) (HostDomain::claimRefusal('srccopy', self::HOST)['message'] ?? '');
    }

    private function assertNothingCopied(): void
    {
        $this->assertSame(['source'], User::query()->pluck('username')->all());
        $this->assertFalse(Domain::domainOrAliasExists(self::HOST));
        $this->assertSame(0, Task::query()->count());
        Queue::assertNothingPushed();
    }
}
