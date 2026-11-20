<?php

namespace Tests\Integration\Project;

use App\Exceptions\ProblemException;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\ApiAccessFirewall;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingFirewall;
use Tests\TestCase;

/** How ApiAccess spells, resolves and refuses a scope, word for word. */
class ApiAccessScopeRulesTest extends TestCase
{
    use InMemoryDatabase;

    private const CONTAINER_DETAILS = ['template' => 'dind', 'UID' => 1000, 'GID' => 1000];

    private RecordingFirewall $firewall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://engine.example.test',
        ]);
        $this->app->forgetInstance('encrypter');
        Queue::fake();
        $this->firewall = new RecordingFirewall();
        $this->makeUser('alice', self::CONTAINER_DETAILS);
        $this->makeUser('bob', self::CONTAINER_DETAILS);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_scope_reads_as_its_name_its_projects_or_no_project(): void
    {
        $this->assertSame('self', ApiAccess::scopeLabel(ApiAccess::SCOPE_SELF));
        $this->assertSame('all', ApiAccess::scopeLabel(ApiAccess::SCOPE_ALL));
        $this->assertSame('bob, alice', ApiAccess::scopeLabel(['bob', 'alice']));
        $this->assertSame('no project', ApiAccess::scopeLabel([]));
    }

    public function test_a_scope_that_is_no_scope_is_refused_in_these_words(): void
    {
        $this->assertSame(
            ['field' => 'scope', 'code' => ApiAccess::SCOPE_INVALID, 'message' => 'A scope is "self", "all", or a non-empty list of project names, not string.'],
            $this->refusal('everyone'),
        );
        $this->assertSame(
            ['field' => 'scope', 'code' => ApiAccess::SCOPE_INVALID, 'message' => 'A scope is "self", "all", or a non-empty list of project names, not an empty list.'],
            $this->refusal([]),
        );
    }

    public function test_a_listed_name_that_is_no_project_is_refused_before_a_token_is_minted(): void
    {
        $this->assertSame(
            ['field' => 'scope', 'code' => ApiAccess::PROJECT_UNKNOWN, 'message' => "'nobody' is not a project on this engine."],
            $this->refusal(['bob', 'nobody']),
        );
        $this->assertSame(
            ['field' => 'scope', 'code' => ApiAccess::PROJECT_UNKNOWN, 'message' => "'integer' is not a project on this engine."],
            $this->refusal([7]),
        );
        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame([], $this->access()->all());
    }

    public function test_a_list_is_stored_sorted_once_and_its_token_reaches_each_name_once(): void
    {
        $entry = $this->access()->allow($this->user('alice'), ['bob', 'bob', 'alice'], false);

        $this->assertSame(['alice', 'bob'], $entry['scope']);
        $this->assertSame(['api', 'project', 'project:alice', 'project:bob'], $this->abilities($entry));
    }

    public function test_the_same_names_spelled_otherwise_are_the_same_allowance_but_self_is_not_its_own_name(): void
    {
        $access = $this->access();
        $first = $access->allow($this->user('alice'), ['alice', 'bob'], false);

        $this->assertSame($first['token_id'], $access->allow($this->user('alice'), ['bob', 'alice', 'bob'], false)['token_id']);

        $listed = $access->allow($this->user('alice'), ['alice'], false);
        $self = $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $this->assertNotSame($first['token_id'], $listed['token_id']);
        $this->assertNotSame($listed['token_id'], $self['token_id']);
        $this->assertSame(ApiAccess::SCOPE_SELF, $self['scope']);
        $this->assertSame(['api', 'project', 'project:alice'], $this->abilities($self));
    }

    public function test_a_named_scope_is_rotated_as_it_is(): void
    {
        $access = $this->access();
        $access->allow($this->user('alice'), ApiAccess::SCOPE_ALL, true);

        $rotated = $access->rotate($this->user('alice'));

        $this->assertSame(ApiAccess::SCOPE_ALL, $rotated['scope']);
        $this->assertSame(['api', 'mcp'], $this->abilities($rotated));
    }

    public function test_a_rotation_whose_old_token_is_gone_keeps_every_name_that_is_still_a_project(): void
    {
        $access = $this->access();
        $first = $access->allow($this->user('alice'), ['alice', 'bob'], false);
        PersonalAccessToken::query()->whereKey($first['token_id'])->delete();

        $rotated = $access->rotate($this->user('alice'));

        $this->assertSame(['alice', 'bob'], $rotated['scope']);
        $this->assertSame(['api', 'project', 'project:alice', 'project:bob'], $this->abilities($rotated));
    }

    private function access(): ApiAccess
    {
        return new class ($this->firewall) extends ApiAccess {
            public function __construct(ApiAccessFirewall $recorder)
            {
                parent::__construct(firewall: $recorder);
            }

            protected function servedCertificate(): ?string
            {
                return null;
            }
        };
    }

    private function user(string $username): User
    {
        $user = User::findByUsername($username);
        $this->assertNotNull($user);

        return $user;
    }

    /** @param array<string, mixed> $entry */
    private function abilities(array $entry): array
    {
        return PersonalAccessToken::findOrFail((int) $entry['token_id'])->abilities;
    }

    /**
     * @param string|list<mixed> $scope
     * @return array<string, mixed>
     */
    private function refusal(string|array $scope): array
    {
        try {
            $this->access()->allow($this->user('alice'), $scope, false);
        } catch (ProblemException $e) {
            return $e->problems[0];
        }
        $this->fail('The scope was not refused.');
    }
}
