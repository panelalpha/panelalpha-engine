<?php

namespace Tests\Unit\Project;

use App\Exceptions\ProblemException;
use App\Jobs\ApplyEnvVars;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\ApiAccessFirewall;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use ArrayObject;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingFirewall;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * The projects allowed to use the Engine API: the token each is minted with,
 * the scope it carries, and the address, token and certificate the engine hands
 * the application.
 */
class ApiAccessTest extends TestCase
{
    use InMemoryDatabase;

    /** Generated for 203-0-113-7.panelalpha.direct, self-signed, valid until 2036. */
    private const SELF_SIGNED_CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDgTCCAmmgAwIBAgIUcWNjULtRoZAfKjybHd93nk4ikJowDQYJKoZIhvcNAQEL
BQAwSzELMAkGA1UEBhMCVVMxEzARBgNVBAoMClBhbmVsQWxwaGExJzAlBgNVBAMM
HjIwMy0wLTExMy00NS5wYW5lbGFscGhhLmRpcmVjdDAeFw0yNjEwMDcxMDU2NTVa
Fw0zNjEwMDQxMDU2NTVaMEsxCzAJBgNVBAYTAlVTMRMwEQYDVQQKDApQYW5lbEFs
cGhhMScwJQYDVQQDDB4yMDMtMC0xMTMtNDUucGFuZWxhbHBoYS5kaXJlY3QwggEi
MA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDUHRXW1Uc6itPgE6/ZeF0P1WiM
4xEP/TFHWfQeXlODvyV1fet/h4VRJuKT4i+49FWtGAdts1bAiDQvS3rU9wc/aSYh
Wah4wNSs8yZEzmBwDVseJOIyshQvl3jHW8IUUhXax9SOu3g44LlteJWCXMGlO10P
k5171tTXfbaqtwCe/cEwkiiqppAyKps4xf4raL8dr6p78mTDrDjnYSAfX9+NqlaF
YcZdm/XXX6j0DDBEps+gwf5hfB7DcQTbsUGl0xN6G9WFADJyLsfWXMVxbHtThAsd
vE+GO/pWQc58yDUa8Z8IWlaWAZGQnaNgCa65N7ygX7tIV9xyXGS3jrQPFCAxAgMB
AAGjXTBbMB0GA1UdDgQWBBRjSUeICsAEe7nyiKpPEcvr5Hq1ZDAPBgNVHRMBAf8E
BTADAQH/MCkGA1UdEQQiMCCCHjIwMy0wLTExMy00NS5wYW5lbGFscGhhLmRpcmVj
dDANBgkqhkiG9w0BAQsFAAOCAQEAgwqzSH+AsUvUMHG7POouaSXaq9f08V5fjuJH
kDurBjOpIyDfeaArlCfMMn8LnEuWsvzzh+mfx13jkIgPF40aLTVBxx5N88S/VgKf
xmoef76VS/G2Qc1M8Is2VQ8HhRUxbbaVAy+YG9bQMRCwzgTmh4IZPaQIJlnLSkfB
fkAVaChsE+5SxBEDGlbqB+NihBX7KUJE+uqhsJIxah7NTHgRrl5qoF9OSxJGTjT4
cZpDXjutZoCp4T7WsjYwIw1m/O1DrhujImm4H1uJZucGt7fOemk0sN4cscTwQuVl
ogQaptadgRZB38mEkeTkEL1b3wPb5NtofaUPwss/jCbBOSK8lA==
-----END CERTIFICATE-----
PEM;

    /** The same name under a test CA, so not self-signed, valid until 2036. */
    private const CA_SIGNED_CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDaTCCAlGgAwIBAgIUWaHwv6yRs2grVHxFCGc1cqX6eM0wDQYJKoZIhvcNAQEL
BQAwMjETMBEGA1UECgwKUGFuZWxBbHBoYTEbMBkGA1UEAwwSUGFuZWxBbHBoYSBU
ZXN0IENBMB4XDTI2MTAxMjE1NDMyMVoXDTM2MTAwOTE1NDMyMVowPTETMBEGA1UE
CgwKUGFuZWxBbHBoYTEmMCQGA1UEAwwdMjAzLTAtMTEzLTcucGFuZWxhbHBoYS5k
aXJlY3QwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQC77yy8AHejxPIP
Js4LxyBb0tW7ilwVJkTOCRQjDTD19WoUQeagA6Iz8q7ma33AzKjk+Wol9z26sb4s
ztNmvGUTajo6m8aRH2qljE2LPa7uyHpfPB6v9Lbx7BEqXG+aEtHp+3T18V8IIqZJ
e1aUYD5TJEMSPGGGs729eHUGIxBAOMbMLSqfW6NwEwEMWBmIoYKLmQQMSK98KODa
pzUEDz3qg1ui9UxR7pKoYZ3GFzLcuvSO74+hhYdh9249pWHwGxiIALuxYqmCIazx
MxM4WWiHp8lHYKPygzaO6CyeazchAPbmr6cCt5UEKeXPAI4RbJa8yJ382+/dXz1t
FEkJTSQdAgMBAAGjbDBqMCgGA1UdEQQhMB+CHTIwMy0wLTExMy03LnBhbmVsYWxw
aGEuZGlyZWN0MB0GA1UdDgQWBBSkAH3EbZEMovRslEXTWcDErLtV9zAfBgNVHSME
GDAWgBRw0ZNobWBBzMDiO2q/GnKzYkYmkDANBgkqhkiG9w0BAQsFAAOCAQEAjsfi
elrXMbVWXgyPfFK4E4eDGKe3rwV3FFtzZYU9CHJMn8i4vLO30GDYHOQU3Yh1c+cu
CxT8FIcghmm0N70iFY1VsaAZkoQNu5HWJ8otesGjtn20rDrqdlkJCPCja60gvENF
2+KwfVwJzdqzvrcJoQKlCto9IFCRw5kseswCqAsr8kXF638ABla5fGFVh0MEEW6U
287orPti/t9A+oK+4zRIxaRQPvGFQweKYU1NwkpM8wIwqrWsa4JIGmZ392z/78Uv
FcVCDQ49plxTTZFNyD2BkMwXgMWW3sKMEzRIyz0uXOSO0ke7Oa3vOvDYFFzkg5NZ
QrLsaYNC5YBfKkIapA==
-----END CERTIFICATE-----
PEM;

    /** A container project: only one of those can be handed the API address and token. */
    private const CONTAINER_DETAILS = ['template' => 'dind', 'UID' => 1000, 'GID' => 1000];

    private RecordingFirewall $firewall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://203-0-113-7.panelalpha.direct',
        ]);
        $this->app->forgetInstance('encrypter');
        Queue::fake();
        $this->firewall = new RecordingFirewall();
        $this->makeUser('alice', self::CONTAINER_DETAILS);
        $this->makeUser('bob', self::CONTAINER_DETAILS);
        $this->makeUser('classic1');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_allowing_mints_a_token_the_project_alone_may_use(): void
    {
        $entry = $this->access()->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $this->assertSame('alice', $entry['project']);
        $this->assertSame(ApiAccess::SCOPE_SELF, $entry['scope']);
        $this->assertFalse($entry['mcp']);
        $this->assertArrayNotHasKey('token', $entry);
        $this->assertSame(['alice'], $this->firewall->last());
        $this->assertSame(['api', 'project', 'project:alice'], $this->token((int) $entry['token_id'])->abilities);
    }

    public function test_every_project_may_be_allowed_with_mcp_beside_the_api(): void
    {
        $entry = $this->access()->allow($this->user('alice'), ApiAccess::SCOPE_ALL, true);

        $this->assertSame(ApiAccess::SCOPE_ALL, $entry['scope']);
        $this->assertTrue($entry['mcp']);
        $this->assertSame(['api', 'mcp'], $this->token((int) $entry['token_id'])->abilities);
    }

    public function test_a_list_scope_mints_a_token_for_each_named_project(): void
    {
        $entry = $this->access()->allow($this->user('alice'), ['bob', 'alice'], false);

        $this->assertSame(['alice', 'bob'], $entry['scope']);
        $this->assertSame(
            ['api', 'project', 'project:alice', 'project:bob'],
            $this->token((int) $entry['token_id'])->abilities,
        );
    }

    public function test_a_list_scope_naming_no_project_is_refused(): void
    {
        $e = $this->refusal(fn () => $this->access()->allow($this->user('alice'), ['nobody'], false));

        $this->assertSame('scope', $e->problems[0]['field']);
        $this->assertSame(ApiAccess::PROJECT_UNKNOWN, $e->problems[0]['code']);
    }

    public function test_a_scope_that_is_not_a_scope_is_refused(): void
    {
        foreach (['everyone', ''] as $scope) {
            $e = $this->refusal(fn () => $this->access()->allow($this->user('alice'), $scope, false));

            $this->assertSame('scope', $e->problems[0]['field']);
            $this->assertSame(ApiAccess::SCOPE_INVALID, $e->problems[0]['code']);
        }

        $e = $this->refusal(fn () => $this->access()->allow($this->user('alice'), [], false));
        $this->assertSame(ApiAccess::SCOPE_INVALID, $e->problems[0]['code']);
    }

    public function test_allowing_a_project_it_already_allowed_replaces_the_token(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $first = $access->allow($alice, ApiAccess::SCOPE_SELF, false);

        $second = $access->allow($alice, ApiAccess::SCOPE_ALL, false);

        $this->assertNotSame($first['token_id'], $second['token_id']);
        $this->assertSame(ApiAccess::SCOPE_ALL, $second['scope']);
        $this->assertSame($first['allowed_at'], $second['allowed_at'], 'the first allowance is the date it was allowed');
        $this->assertCount(1, $access->all());
        $this->assertQueuedApply('alice', (int) $first['token_id'], 2);
        $this->assertSame(['alice'], $this->firewall->last());
    }

    public function test_rotating_keeps_the_scope_and_asks_for_the_previous_token(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $first = $access->allow($alice, ['bob'], true);

        $second = $access->rotate($alice);

        $this->assertSame(['bob'], $second['scope']);
        $this->assertTrue($second['mcp']);
        $this->assertNotSame($first['token_id'], $second['token_id']);
        $this->assertSame(
            ['api', 'mcp', 'project', 'project:bob'],
            $this->token((int) $second['token_id'])->abilities,
        );
        $this->assertQueuedApply('alice', (int) $first['token_id'], 2);
    }

    public function test_rotating_a_project_that_is_not_allowed_is_refused(): void
    {
        $e = $this->refusal(fn () => $this->access()->rotate($this->user('alice')));

        $this->assertSame(ApiAccess::NOT_ALLOWED, $e->problems[0]['code']);
        $this->assertSame([], $this->firewall->applied);
    }

    public function test_denying_revokes_the_token_and_takes_the_rule_away(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $entry = $access->allow($alice, ApiAccess::SCOPE_SELF, false);

        $this->assertTrue($access->deny($alice));

        $this->assertNotNull($this->token((int) $entry['token_id'])->revoked_at);
        $this->assertNull($access->entryFor($alice));
        $this->assertSame([], $access->all());
        $this->assertSame([], $this->firewall->last());
        $this->assertSame(['alice'], $this->firewall->touched[1], 'and the account losing it is told to close its guard');
        $this->assertQueuedApply('alice', null, 2);
    }

    public function test_denying_a_project_that_was_not_allowed_changes_nothing(): void
    {
        $access = $this->access();

        $this->assertFalse($access->deny($this->user('alice')));
        $this->assertSame([], $this->firewall->applied);
        Queue::assertNotPushed(ApplyEnvVars::class);
    }

    /** The account is going away, so there is nothing to apply the values to. */
    public function test_a_denial_without_an_apply_leaves_the_task_queue_alone(): void
    {
        $access = $this->access();
        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);
        Queue::fake();

        $this->assertTrue($access->deny($this->user('alice'), apply: false));

        Queue::assertNotPushed(ApplyEnvVars::class);
    }

    public function test_nothing_is_handed_to_a_project_that_is_not_allowed(): void
    {
        $this->assertSame([], $this->access()->envFor($this->user('alice')));
    }

    public function test_the_environment_names_the_engine_and_carries_its_own_token(): void
    {
        $access = $this->access(self::SELF_SIGNED_CERT);
        $entry = $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $env = $access->envFor($this->user('alice'));

        $this->assertSame('https://203-0-113-7.panelalpha.direct:2011/api', $env[ApiAccess::ENV_URL]);
        $this->assertStringStartsWith((string) $entry['token_id'] . '|', $env[ApiAccess::ENV_TOKEN]);
        $this->assertSame(self::SELF_SIGNED_CERT, $env[ApiAccess::ENV_CA]);
    }

    public function test_the_certificate_domain_names_the_engine_when_it_is_set(): void
    {
        \App\Models\Setting::set('cert_domain', 'engine.example.test');
        $access = $this->access(self::SELF_SIGNED_CERT);
        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $env = $access->envFor($this->user('alice'));

        $this->assertSame('https://engine.example.test:2011/api', $env[ApiAccess::ENV_URL]);
    }

    public function test_a_trusted_certificate_for_the_name_is_not_handed_over(): void
    {
        $access = $this->access(self::CA_SIGNED_CERT);
        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $env = $access->envFor($this->user('alice'));

        $this->assertArrayNotHasKey(ApiAccess::ENV_CA, $env);
        $this->assertArrayHasKey(ApiAccess::ENV_TOKEN, $env);
    }

    public function test_a_certificate_for_another_name_is_handed_over(): void
    {
        $access = $this->access(self::SELF_SIGNED_CERT);
        \App\Models\Setting::set('cert_domain', 'other.example.test');
        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $env = $access->envFor($this->user('alice'));

        $this->assertSame(self::SELF_SIGNED_CERT, $env[ApiAccess::ENV_CA]);
    }

    public function test_an_engine_without_a_certificate_hands_over_none(): void
    {
        $access = $this->access(null);
        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $env = $access->envFor($this->user('alice'));

        $this->assertArrayNotHasKey(ApiAccess::ENV_CA, $env);
        $this->assertArrayHasKey(ApiAccess::ENV_URL, $env);
    }

    public function test_the_stored_token_is_not_readable_without_the_app_key(): void
    {
        $access = $this->access();
        Setting::set(ApiAccess::SETTING, (string) json_encode([[
            'project' => 'alice',
            'scope' => ApiAccess::SCOPE_SELF,
            'mcp' => false,
            'token_id' => 1,
            'token' => 'not-an-encrypted-token',
            'allowed_at' => now()->toIso8601String(),
        ]]));

        $this->assertSame([], $access->envFor($this->user('alice')));
        $this->assertNotNull($access->entryFor($this->user('alice')), 'the entry is still reported, so it can be rotated or denied');
    }

    /** Allowing what is already allowed must not spend a token or restart anything. */
    public function test_allowing_the_same_scope_again_changes_nothing(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $first = $access->allow($alice, ApiAccess::SCOPE_SELF, false);
        Queue::fake();

        $second = $access->allow($alice, ApiAccess::SCOPE_SELF, false);

        $this->assertSame($first['token_id'], $second['token_id']);
        $this->assertSame($first['allowed_at'], $second['allowed_at']);
        $this->assertCount(1, $access->all());
        Queue::assertNotPushed(ApplyEnvVars::class);
        $this->assertSame(1, Task::query()->where('job_type', ApplyEnvVars::class)->count(), 'only the first allowance queued one');
        $this->assertSame(['alice'], $this->firewall->last(), 'the firewall is told again either way');
        $this->assertSame([['alice'], ['alice']], $this->firewall->touched, 'so is the account whose guard has to follow');
    }

    /** The MCP flag is half of what the token may do, so it is half of the comparison. */
    public function test_allowing_the_same_scope_with_mcp_changed_replaces_the_token(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $first = $access->allow($alice, ApiAccess::SCOPE_SELF, false);

        $second = $access->allow($alice, ApiAccess::SCOPE_SELF, true);

        $this->assertNotSame($first['token_id'], $second['token_id']);
        $this->assertTrue($second['mcp']);
        $this->assertQueuedApply('alice', (int) $first['token_id'], 2);
    }

    /** The same scope spelled differently is the same allowance. */
    public function test_a_scope_naming_the_same_projects_in_another_order_changes_nothing(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $first = $access->allow($alice, ['bob', 'alice'], false);
        Queue::fake();

        $second = $access->allow($alice, ['alice', 'bob'], false);

        $this->assertSame($first['token_id'], $second['token_id']);
        $this->assertSame(['alice', 'bob'], $second['scope'], 'the stored order does not depend on how it was named');
        Queue::assertNotPushed(ApplyEnvVars::class);
    }

    /** A classic project has nowhere to put the address or the token, so it is refused. */
    public function test_a_project_that_is_not_a_container_project_cannot_be_allowed(): void
    {
        $e = $this->refusal(fn () => $this->access()->allow($this->user('classic1'), ApiAccess::SCOPE_SELF, false));

        $this->assertSame('project', $e->problems[0]['field']);
        $this->assertSame(ApiAccess::CONTAINER_ONLY, $e->problems[0]['code']);
        $this->assertSame([], $this->firewall->applied);
        Queue::assertNotPushed(ApplyEnvVars::class);
    }

    public function test_a_project_that_is_not_a_container_project_cannot_be_rotated(): void
    {
        $e = $this->refusal(fn () => $this->access()->rotate($this->user('classic1')));

        $this->assertSame(ApiAccess::CONTAINER_ONLY, $e->problems[0]['code']);
    }

    /** A denial is a cleanup and never refuses: it has to work on any project kind. */
    public function test_a_classic_project_can_still_be_denied(): void
    {
        $this->assertFalse($this->access()->deny($this->user('classic1')));
    }

    /**
     * A replacement whose apply fails leaves the old token where the application runs:
     * the entry keeps it, the listing says so, and the next successful apply revokes it.
     */
    public function test_a_failed_apply_leaves_the_token_it_replaced_tracked(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $first = $access->allow($alice, ApiAccess::SCOPE_SELF, false);
        $second = $access->allow($alice, ApiAccess::SCOPE_ALL, false);

        $this->assertSame((int) $first['token_id'], $second['pending_revoke_token_id']);
        $this->assertSame(
            'previous token still valid until the apply that replaces it finishes',
            $second['warning'],
            'the apply has not run yet'
        );

        $access->confirmRevocation('alice', (int) $first['token_id']);

        $this->assertNotNull($this->token((int) $first['token_id'])->revoked_at);
        $this->assertNull($this->token((int) $second['token_id'])->revoked_at, 'the token in force is untouched');
        $this->assertNull($access->entryFor($alice)['pending_revoke_token_id']);
        $this->assertNull($access->entryFor($alice)['warning']);
    }

    /** An apply that fails says so, so the token it would have retired is not called pending for ever. */
    public function test_a_failed_apply_is_recorded_as_one_that_failed(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $first = $access->allow($alice, ApiAccess::SCOPE_SELF, false);
        $access->allow($alice, ApiAccess::SCOPE_ALL, false);

        $access->markPendingFailure('alice');

        $entry = $access->entryFor($alice);
        $this->assertSame((int) $first['token_id'], $entry['pending_revoke_token_id']);
        $this->assertSame('previous token still valid: the apply that replaces it failed', $entry['warning']);

        $access->markPendingFailure('bob');
        $this->assertSame(
            'previous token still valid: the apply that replaces it failed',
            $access->entryFor($alice)['warning'],
            'another project\'s failure changes nothing'
        );
    }

    /** A second replacement carries an already failed predecessor forward as failed. */
    public function test_a_failure_survives_the_next_replacement(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $access->allow($alice, ApiAccess::SCOPE_SELF, false);
        $second = $access->allow($alice, ApiAccess::SCOPE_ALL, false);
        $access->markPendingFailure('alice');

        $third = $access->rotate($alice);

        $this->assertSame($second['pending_revoke_token_id'], $third['pending_revoke_token_id']);
        $this->assertSame('previous token still valid: the apply that replaces it failed', $third['warning']);
    }

    /** The apply after an earlier failure carries two stale tokens, and retires both. */
    public function test_the_next_successful_apply_revokes_the_tracked_token_too(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $first = $access->allow($alice, ApiAccess::SCOPE_SELF, false);
        $second = $access->allow($alice, ApiAccess::SCOPE_ALL, false);
        $third = $access->rotate($alice);

        $this->assertSame((int) $first['token_id'], $third['pending_revoke_token_id']);

        $access->confirmRevocation('alice', (int) $second['token_id']);

        $this->assertNotNull($this->token((int) $first['token_id'])->revoked_at);
        $this->assertNotNull($this->token((int) $second['token_id'])->revoked_at);
        $this->assertNull($this->token((int) $third['token_id'])->revoked_at);
        $this->assertNull($access->entryFor($alice)['pending_revoke_token_id']);
    }

    /** A denial has to take every token the project holds, tracked or not. */
    public function test_a_denial_revokes_the_tracked_token_too(): void
    {
        $access = $this->access();
        $alice = $this->user('alice');
        $first = $access->allow($alice, ApiAccess::SCOPE_SELF, false);
        $second = $access->allow($alice, ApiAccess::SCOPE_ALL, false);

        $this->assertTrue($access->deny($alice));

        $this->assertNotNull($this->token((int) $first['token_id'])->revoked_at);
        $this->assertNotNull($this->token((int) $second['token_id'])->revoked_at);
        $this->assertSame([], $access->all());
    }

    /** Every read-modify-write of the setting is one critical section. */
    public function test_every_change_to_the_setting_runs_in_one_locked_section(): void
    {
        $sections = new ArrayObject(['count' => 0]);
        $access = $this->watchedAccess(sections: $sections);
        $alice = $this->user('alice');

        $access->allow($alice, ApiAccess::SCOPE_SELF, false);
        $this->assertSame(1, $sections['count']);

        $access->allow($alice, ApiAccess::SCOPE_SELF, false);
        $this->assertSame(2, $sections['count'], 'an allowance that changes nothing still reads the setting under the lock');

        $access->rotate($alice);
        $access->confirmRevocation('alice', null);
        $access->deny($alice);
        $this->assertSame(5, $sections['count']);

        $access->entryFor($alice);
        $access->all();
        $access->envFor($alice);
        $this->assertSame(5, $sections['count'], 'reads take no lock');
    }

    /**
     * Two writers cannot be inside the section at once: the second waits for the
     * first, and once both have run neither entry is missing.
     */
    public function test_a_second_writer_waits_for_the_first_and_no_entry_is_lost(): void
    {
        $path = sys_get_temp_dir() . '/pa-api-access-lock-' . bin2hex(random_bytes(4));
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path]);
        Cache::forgetDriver('file');

        $holder = Cache::lock(ApiAccess::LOCK, 30);
        $this->assertTrue($holder->get(), 'the test holds the setting\'s lock');

        $started = microtime(true);
        try {
            $this->watchedAccess(1)->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);
            $this->fail('a second writer walked into the section');
        } catch (LockTimeoutException) {
            $this->assertGreaterThanOrEqual(0.5, microtime(true) - $started, 'it waited for the lock');
        } finally {
            $holder->release();
        }

        $access = $this->access();
        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);
        $access->allow($this->user('bob'), ApiAccess::SCOPE_SELF, false);

        $this->assertSame(['alice', 'bob'], array_column($access->all(), 'project'));
    }

    /**
     * The shipped install answers on `https://<host>:2011` already, and the API port
     * must not be added twice.
     */
    public function test_the_engine_address_is_the_host_it_reports_on_the_api_port_once(): void
    {
        $access = $this->access();
        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        foreach (['https://1.2.3.4:2011', 'https://1.2.3.4', 'https://1.2.3.4/'] as $appUrl) {
            config(['app.url' => $appUrl]);

            $this->assertSame(
                'https://1.2.3.4:2011/api',
                $access->envFor($this->user('alice'))[ApiAccess::ENV_URL],
                $appUrl
            );
        }
    }

    public function test_an_engine_address_that_is_not_one_is_not_handed_over(): void
    {
        $access = $this->access();
        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        foreach (['', 'not-a-url', '1.2.3.4:2011', 'https://1.2.3.4:2011/panel'] as $appUrl) {
            config(['app.url' => $appUrl]);

            $env = $access->envFor($this->user('alice'));
            $this->assertArrayNotHasKey(ApiAccess::ENV_URL, $env, $appUrl);
            $this->assertArrayHasKey(ApiAccess::ENV_TOKEN, $env, $appUrl);
        }
    }

    /**
     * A URL with the port twice parses as no host at all, which used to make a
     * trusted certificate for the name look like one it does not cover.
     */
    public function test_a_trusted_certificate_is_not_handed_over_when_the_reported_address_carries_the_port(): void
    {
        config(['app.url' => 'https://203-0-113-7.panelalpha.direct:2011']);
        $access = $this->access(self::CA_SIGNED_CERT);
        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $env = $access->envFor($this->user('alice'));

        $this->assertSame('https://203-0-113-7.panelalpha.direct:2011/api', $env[ApiAccess::ENV_URL]);
        $this->assertArrayNotHasKey(ApiAccess::ENV_CA, $env);
    }

    private function access(?string $servedCertificate = null): ApiAccess
    {
        return new class ($this->firewall, $servedCertificate) extends ApiAccess {
            public function __construct(private ApiAccessFirewall $recorder, private ?string $certificate)
            {
                parent::__construct(firewall: $this->recorder);
            }

            protected function servedCertificate(): ?string
            {
                return $this->certificate;
            }
        };
    }

    /**
     * An access whose critical section a test can count: the lock is real, but the
     * wait is short enough for a test to hold a lock of its own and watch it expire.
     */
    private function watchedAccess(int $lockWait = 5, ?ArrayObject $sections = null): ApiAccess
    {
        return new class ($this->firewall, $lockWait, $sections ?? new ArrayObject(['count' => 0])) extends ApiAccess {
            public function __construct(
                private ApiAccessFirewall $recorder,
                private int $wait,
                private ArrayObject $sections,
            ) {
                parent::__construct(firewall: $this->recorder);
            }

            protected function mutate(callable $work): mixed
            {
                $this->sections['count']++;

                return Cache::lock(ApiAccess::LOCK, $this->wait)->block($this->wait, $work);
            }
        };
    }

    private function user(string $username): User
    {
        $user = User::findByUsername($username);
        $this->assertNotNull($user);

        return $user;
    }

    private function token(int $id): PersonalAccessToken
    {
        $token = PersonalAccessToken::findOrFail($id);
        $this->assertInstanceOf(PersonalAccessToken::class, $token);

        return $token;
    }

    private function assertQueuedApply(string $username, ?int $revokeTokenId, int $tasks): void
    {
        Queue::assertPushed(ApplyEnvVars::class, static function (ApplyEnvVars $job) use ($username, $revokeTokenId): bool {
            return $job->username === $username && $job->revokeTokenId === $revokeTokenId;
        });
        $this->assertSame($tasks, Task::query()
            ->where('job_type', ApplyEnvVars::class)
            ->where('username', $username)
            ->count());
    }

    private function refusal(callable $call): ProblemException
    {
        try {
            $call();
        } catch (ProblemException $e) {
            return $e;
        }
        $this->fail('The call was not refused.');
    }
}
