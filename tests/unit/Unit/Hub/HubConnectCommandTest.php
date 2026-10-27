<?php

namespace Tests\Unit\Hub;

use App\Lib\Hub\HubGrants;
use App\Lib\Hub\HubTokens;
use App\Lib\Hub\SpkiPin;
use App\Models\HubConnection;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;

/** `pae hub:connect CODE` against a faked Hub. */
class HubConnectCommandTest extends HubTestCase
{
    private const DEVICE_CODE = 'Zk3mQ0x9Wc2yH7tLr1aVbN4pS8uE6oJdKfTgYhUiOwM';
    private const CODE = 'WDJB-MJHT';

    private string $logFile = '';

    /** @var array<string, mixed>|null what `complete` was sent */
    private ?array $completed = null;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake(syncWithCarbon: true);
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hub-log-');
        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logFile,
            'logging.channels.single.level' => 'debug',
        ]);
        $this->app->forgetInstance('log');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    public function test_a_code_connects_with_exactly_the_granted_abilities_without_asking(): void
    {
        \App\Models\Setting::setRuntimeSettings(['default_ipv4' => self::ENGINE_IP, 'license_key' => 'FROM-SETTING']);
        $this->fakeHub([$this->approved(['admin_sso'], warnings: ['license_ip_mismatch', 'something_new'])]);

        $this->artisan('hub:connect', ['code' => 'wdjb mjht'])
            ->expectsOutputToContain('Connect code accepted by ' . self::HUB . '.')
            ->expectsOutputToContain('Account: Acme Ltd (code created by j***@acme.com)')
            ->expectsOutputToContain('  - Read the engine version, host details and resource usage')
            ->expectsOutputToContain("  - Read your projects' backup history")
            ->expectsOutputToContain('  - Create one-time admin login links to your apps')
            ->expectsOutputToContain('Warning: the license is registered for a different IP address')
            ->expectsOutputToContain('Warning: something_new')
            ->doesntExpectOutputToContain('License')
            ->doesntExpectOutputToContain('[y/N]')
            ->expectsOutputToContain('Connected to Acme Ltd.')
            ->expectsOutputToContain('Not your account? Disconnect with: pae hub:disconnect')
            ->assertExitCode(0);

        $create = $this->sentTo('/api/engine/v1/connect-sessions');
        $this->assertSame('wdjb mjht', $create['connect_code'], 'sent as typed; the Hub normalizes');
        $this->assertSame('engine', $create['product']);
        $this->assertSame(self::APP_ID, $create['app_id']);
        $this->assertSame(self::ENGINE_IP, $create['ip']);
        $this->assertSame(SpkiPin::fromFile($this->certificate), $create['tls_spki']);
        $this->assertNull($create['license_key'], 'the code names its license; the key on record would conflict');
        $this->assertSame('srv1.acme.com', $create['engine']['hostname']);
        Sleep::assertNeverSlept();

        $expected = HubGrants::abilities(['admin_sso']);
        $this->assertSame($expected, $this->completed['credential']['abilities']);
        $this->assertSame($create['tls_spki'], $this->completed['tls_spki']);

        /** @var PersonalAccessToken $token */
        $token = PersonalAccessToken::query()->findOrFail($this->completed['credential']['token_id']);
        $this->assertSame($expected, $token->abilities);
        $this->assertSame('hub:hc_01J8Z6Q4X9V3K2M7N5P1R0S8T6', $token->name, 'renamed once the Hub named the connection');
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $token->expires_at->timestamp, 5);

        $connection = HubConnection::active();
        $this->assertNotNull($connection);
        $this->assertSame('hc_01J8Z6Q4X9V3K2M7N5P1R0S8T6', $connection->connection_id);
        $this->assertSame(self::SECRET, $connection->hub_secret);
        $this->assertSame('42', $connection->account_id);
        $this->assertSame('101', $connection->license_id);
        $this->assertSame(['admin_sso'], $connection->grants);
        $this->assertSame($token->id, $connection->token_id);
        $this->assertNotNull($connection->last_hub_call_at, 'the Hub verified the token before answering');
        $this->withToken($this->completed['credential']['token'])->getJson('/api/projects/all')->assertOk();

        $this->assertNothingSentTo('/cancel');
    }

    public function test_the_code_is_required(): void
    {
        Http::fake();

        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $this->artisan('hub:connect')->run();

        Http::assertNothingSent();
    }

    public function test_the_link_flow_options_are_gone(): void
    {
        $definition = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all()['hub:connect']->getDefinition();

        foreach (['yes', 'expect-account', 'license'] as $option) {
            $this->assertFalse($definition->hasOption($option), "--{$option}");
        }
        $this->assertTrue($definition->getArgument('code')->isRequired());
    }

    public function test_an_unreachable_engine_revokes_the_minted_token(): void
    {
        $this->fakeHub([$this->approved()], complete: Http::response(
            ['error' => 'engine_unreachable', 'reason' => 'connect_timeout'],
            409
        ));

        $this->artisan('hub:connect', ['code' => self::CODE])
            ->expectsOutputToContain('The Hub could not reach https://' . self::ENGINE_IP . ':2011. Allow inbound TCP 2011')
            ->assertExitCode(1);

        /** @var PersonalAccessToken $token */
        $token = PersonalAccessToken::query()->where('name', 'like', 'hub:%')->sole();
        $this->assertNotNull($token->revoked_at);
        $this->assertNull(HubConnection::active());
    }

    public function test_any_other_complete_failure_revokes_the_token_too(): void
    {
        $this->fakeHub([$this->approved()], complete: Http::response(['error' => 'invalid_credential'], 400));

        $this->artisan('hub:connect', ['code' => self::CODE])
            ->expectsOutputToContain('invalid_credential')
            ->assertExitCode(1);

        $this->assertNotNull(PersonalAccessToken::query()->where('name', 'like', 'hub:%')->sole()->revoked_at);
    }

    public function test_an_unknown_grant_is_refused_before_anything_is_minted(): void
    {
        $this->fakeHub([$this->approved(['admin_sso', 'restart_apps'])]);

        $this->artisan('hub:connect', ['code' => self::CODE])
            ->expectsOutputToContain('The Hub requested a permission this engine does not know. Update the engine.')
            ->assertExitCode(1);

        $this->assertSame('unsupported_grant', $this->sentTo('/cancel')['reason']);
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_slow_down_lengthens_the_interval(): void
    {
        $this->fakeHub([$this->pending(), $this->error('slow_down'), $this->pending(), $this->approved()]);

        $this->artisan('hub:connect', ['code' => self::CODE])->assertExitCode(0);

        // The first poll goes at once.
        Sleep::assertSequence([
            Sleep::for(5)->seconds(),
            Sleep::for(10)->seconds(),
            Sleep::for(10)->seconds(),
        ]);
    }

    #[DataProvider('finalPollErrors')]
    public function test_final_poll_answers_exit_with_their_message(string $error, string $message): void
    {
        $this->fakeHub([$this->error($error)]);

        $this->artisan('hub:connect', ['code' => self::CODE])->expectsOutputToContain($message)->assertExitCode(1);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    /** @return array<string, array{string, string}> */
    public static function finalPollErrors(): array
    {
        return [
            'denied' => ['access_denied', 'Denied on the Hub.'],
            'expired' => ['expired_token', 'The code expired. Create a new one in PanelAlpha Connect.'],
            'invalid' => ['invalid_grant', 'The session is no longer valid.'],
        ];
    }

    public function test_poll_network_errors_are_retried_three_times_then_give_up(): void
    {
        $polls = 0;
        Http::fake([
            self::HUB . '/api/engine/v1/connect-sessions' => Http::response($this->claimBody(), 201),
            self::HUB . '/api/engine/v1/connect-sessions/poll' => function () use (&$polls) {
                $polls++;
                throw new ConnectionException('Connection refused');
            },
            self::HUB . '/api/engine/v1/connect-sessions/cancel' => Http::response(null, 204),
        ]);

        $this->artisan('hub:connect', ['code' => self::CODE])
            ->expectsOutputToContain('Could not reach PanelAlpha Connect')
            ->assertExitCode(1);

        $this->assertSame(4, $polls);
        $this->assertSame('local_error', $this->sentTo('/cancel')['reason']);
    }

    /** A refused claim mints nothing and leaves no session to cancel. */
    #[DataProvider('refusedClaims')]
    public function test_a_refused_code_mints_nothing(int $status, string $error, string $message): void
    {
        $this->fakeHub([], create: Http::response(['error' => $error], $status));

        $this->artisan('hub:connect', ['code' => self::CODE])->expectsOutputToContain($message)->assertExitCode(1);

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertNothingSentTo('/poll');
        $this->assertNothingSentTo('/cancel');
    }

    /** @return array<string, array{int, string, string}> */
    public static function refusedClaims(): array
    {
        return [
            'invalid' => [400, 'invalid_connect_code', 'That code is not valid any more. Create a new one in PanelAlpha Connect.'],
            'not allowed' => [403, 'not_allowed', 'The account that created the code can no longer connect engines.'],
            'license not active' => [403, 'license_not_active', 'The license of this code is not active.'],
            'slow down' => [429, 'slow_down', 'Too many attempts. Wait a minute and try again.'],
            'unknown error' => [409, 'something_else', 'PanelAlpha Connect refused the request: something_else (HTTP 409)'],
        ];
    }

    public function test_a_code_rejected_by_validation_prints_the_message(): void
    {
        $this->fakeHub([], create: Http::response(['message' => 'The connect code field must not be greater than 16 characters.'], 422));

        $this->artisan('hub:connect', ['code' => self::CODE])
            ->expectsOutputToContain('must not be greater than 16 characters')
            ->assertExitCode(1);
    }

    public function test_a_rate_limited_claim_says_when_to_retry(): void
    {
        $this->fakeHub([], create: Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '60']));

        $this->artisan('hub:connect', ['code' => self::CODE])->expectsOutputToContain('Try again in 60 s.')->assertExitCode(1);
    }

    public function test_a_code_longer_than_sixteen_characters_is_refused_locally(): void
    {
        Http::fake();

        $this->artisan('hub:connect', ['code' => 'WDJB-MJHT-WDJB-MJHT'])
            ->expectsOutputToContain('That is not a connect code.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_no_secret_reaches_a_log(): void
    {
        $this->fakeHub([$this->approved(['admin_sso'])]);
        $this->artisan('hub:connect', ['code' => self::CODE])->assertExitCode(0);

        $token = (string) $this->completed['credential']['token'];
        $this->withToken($token)->getJson('/api/projects/all')->assertOk();
        $this->artisan('hub:disconnect')->assertExitCode(0);

        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('hub.connected', $log, 'the audit line is written to this log');
        foreach ([$token, explode('|', $token)[1], self::SECRET, self::DEVICE_CODE, self::CODE] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        foreach (\App\Models\HubAccessLog::query()->get() as $row) {
            $this->assertStringNotContainsString(explode('|', $token)[1], json_encode($row->toArray()));
        }
    }

    public function test_a_plain_http_hub_is_refused(): void
    {
        config(['hub.url' => 'http://connect.example.test']);
        Http::fake();

        $this->artisan('hub:connect', ['code' => self::CODE])->expectsOutputToContain('must be an https URL')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_local_http_hub_is_allowed_only_in_a_local_environment(): void
    {
        config(['hub.url' => 'http://localhost:8000', 'app.env' => 'local']);
        Http::fake(['http://localhost:8000/*' => Http::response(['message' => 'nope'], 422)]);

        $this->artisan('hub:connect', ['code' => self::CODE])->expectsOutputToContain('nope')->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_connecting_without_any_license(): void
    {
        $this->fakeHub([$this->approved([], license: null)], completeLicense: null);

        $this->artisan('hub:connect', ['code' => self::CODE])
            ->doesntExpectOutputToContain('License')
            ->assertExitCode(0);

        $this->assertNull(HubConnection::active()?->license_id);
    }

    public function test_ip_overrides_the_address(): void
    {
        $this->fakeHub([$this->error('access_denied')]);

        $this->artisan('hub:connect', ['code' => self::CODE, '--ip' => '2a01:4f8::10'])->assertExitCode(1);

        $this->assertSame('2a01:4f8::10', $this->sentTo('/api/engine/v1/connect-sessions')['ip']);
    }

    #[DataProvider('notPublic')]
    public function test_private_loopback_and_link_local_addresses_are_refused_locally(string $ip): void
    {
        Http::fake();

        $this->artisan('hub:connect', ['code' => self::CODE, '--ip' => $ip])
            ->expectsOutputToContain('is not a public address')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function notPublic(): array
    {
        return [
            'private' => ['10.0.0.5'],
            'private 172' => ['172.20.0.1'],
            'cgnat' => ['100.64.1.1'],
            'loopback' => ['127.0.0.1'],
            'link-local' => ['169.254.169.254'],
            'v6 loopback' => ['::1'],
            'v6 link-local' => ['fe80::1'],
            'v6 ula' => ['fd00::1'],
            'mapped private' => ['::ffff:192.168.1.1'],
        ];
    }

    /** Refused before the code is claimed, so the code still works with --replace. */
    public function test_an_engine_already_connected_needs_replace(): void
    {
        $this->connection($this->hubToken());
        Http::fake();

        $this->artisan('hub:connect', ['code' => self::CODE])
            ->expectsOutputToContain('already connected to Acme Ltd (account 42)')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_replace_revokes_the_old_connection_only_once_the_new_one_completes(): void
    {
        $old = $this->hubToken([], 'hc_OLD');
        $oldConnection = $this->connection($old, [], 'hc_OLD');
        $other = ['id' => 77, 'name' => 'Other Ltd'];
        $this->fakeHub([$this->approved(account: $other)], completeAccount: $other);

        $this->artisan('hub:connect', ['code' => self::CODE, '--replace' => true])
            ->expectsOutputToContain('Connected to Other Ltd.')
            ->assertExitCode(0);

        $this->assertNotNull($old->accessToken->fresh()->revoked_at);
        $this->assertNull($oldConnection->fresh(), 'one connection at a time');
        $this->assertSame('77', HubConnection::active()?->account_id);
        $this->assertNull(PersonalAccessToken::query()->find($this->completed['credential']['token_id'])->revoked_at);
    }

    public function test_the_address_connect_verified_from_is_exempt_from_the_api_jail_instead_of_the_replaced_one(): void
    {
        $old = $this->hubToken([], 'hc_OLD');
        $this->connection($old, [], 'hc_OLD')->forceFill(['caller_ips' => ['198.51.100.20' => now()->toIso8601ZuluString()]])->save();
        $this->fail2ban->files['/etc/fail2ban/panelalpha-api-ignoreip'] = "198.51.100.20 # PanelAlpha Connect\n";
        $this->withServerVariables(['REMOTE_ADDR' => '2.28.195.31']);
        $this->fakeHub([$this->approved()]);

        $this->artisan('hub:connect', ['code' => self::CODE, '--replace' => true])->assertExitCode(0);

        $this->assertSame(['2.28.195.31'], array_keys(HubConnection::active()?->caller_ips ?? []));
        $this->assertSame("2.28.195.31 # PanelAlpha Connect\n", $this->fail2ban->files['/etc/fail2ban/panelalpha-api-ignoreip']);
        $this->assertSame(1, $this->fail2ban->reloads);
    }

    public function test_replace_that_fails_keeps_the_old_connection(): void
    {
        $old = $this->hubToken([], 'hc_OLD');
        $this->connection($old, [], 'hc_OLD');
        $this->fakeHub([$this->approved()], complete: Http::response(['error' => 'engine_unreachable', 'reason' => 'tls_pin_mismatch'], 409));

        $this->artisan('hub:connect', ['code' => self::CODE, '--replace' => true])
            ->expectsOutputToContain('The certificate on :2011 changed during connect.')
            ->assertExitCode(1);

        $this->assertNull($old->accessToken->fresh()->revoked_at);
        $this->assertSame('hc_OLD', HubConnection::active()?->connection_id);
    }

    /**
     * @param list<\GuzzleHttp\Promise\PromiseInterface> $polls
     * @param array<string, mixed>|null $completeLicense
     * @param array<string, mixed> $completeAccount
     */
    private function fakeHub(
        array $polls,
        mixed $complete = null,
        mixed $create = null,
        ?array $completeLicense = ['id' => 101, 'name' => 'PanelAlpha Pro', 'key_fragment' => '...7F3'],
        array $completeAccount = ['id' => 42, 'name' => 'Acme Ltd'],
    ): void {
        $sequence = Http::sequence();
        foreach ($polls as $poll) {
            $sequence->pushResponse($poll);
        }

        Http::fake([
            self::HUB . '/api/engine/v1/connect-sessions' => $create ?? Http::response($this->claimBody(), 201),
            self::HUB . '/api/engine/v1/connect-sessions/poll' => $sequence,
            self::HUB . '/api/engine/v1/connect-sessions/complete' => $complete ?? function (Request $request) use ($completeLicense, $completeAccount) {
                $this->completed = $request->data();
                // As the Hub does before answering: verify the token on /ping.
                $this->assertSame(HubTokens::PENDING, PersonalAccessToken::query()->find($this->completed['credential']['token_id'])?->name);
                // Verified with the pending token, as the Hub does before it answers.
                $this->withToken($this->completed['credential']['token'])->getJson('/api/projects/all')->assertOk();
                $this->app['auth']->forgetGuards();

                return Http::response([
                    'connection_id' => 'hc_01J8Z6Q4X9V3K2M7N5P1R0S8T6',
                    'hub_secret' => self::SECRET,
                    'account' => $completeAccount,
                    'license' => $completeLicense,
                ]);
            },
            self::HUB . '/api/engine/v1/connect-sessions/cancel' => Http::response(null, 204),
        ]);
    }

    /** @return array<string, mixed> what the Hub answers a claimed code */
    private function claimBody(): array
    {
        return ['device_code' => self::DEVICE_CODE, 'status' => 'approved', 'expires_in' => 540, 'interval' => 5];
    }

    private function pending(): \GuzzleHttp\Promise\PromiseInterface
    {
        return $this->error('authorization_pending');
    }

    private function error(string $error): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['error' => $error], 400);
    }

    /**
     * @param array<int, string> $grants
     * @param array<string, mixed>|null $license
     * @param array<string, mixed> $account
     * @param array<int, string> $warnings
     */
    private function approved(
        array $grants = [],
        ?array $license = ['id' => 101, 'name' => 'PanelAlpha Pro', 'key_fragment' => '...7F3'],
        array $account = ['id' => 42, 'name' => 'Acme Ltd'],
        array $warnings = [],
        string $startedFrom = 'hub',
    ): \GuzzleHttp\Promise\PromiseInterface {
        return Http::response([
            'status' => 'approved',
            'approval' => [
                'account' => $account,
                'approved_by' => 'j***@acme.com',
                'license' => $license,
                'grants' => $grants,
                'warnings' => $warnings,
                'started_from' => $startedFrom,
            ],
        ]);
    }

    /** @return array<string, mixed> the JSON body of the one request sent to a path ending in $suffix */
    private function sentTo(string $suffix): array
    {
        $matches = array_values(array_filter(
            Http::recorded()->all(),
            fn (array $pair): bool => str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH) ?? '', $suffix)
        ));
        $this->assertNotEmpty($matches, "Nothing was sent to *{$suffix}");

        return $matches[0][0]->data();
    }

    private function assertNothingSentTo(string $suffix): void
    {
        Http::assertNotSent(fn (Request $r): bool => str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?? '', $suffix));
    }
}
