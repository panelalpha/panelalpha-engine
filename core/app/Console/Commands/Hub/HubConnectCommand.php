<?php

namespace App\Console\Commands\Hub;

use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Hub\EngineAddress;
use App\Lib\Hub\EngineIdentity;
use App\Lib\Hub\HubAudit;
use App\Lib\Hub\HubCallerAddress;
use App\Lib\Hub\HubClient;
use App\Lib\Hub\HubConnectionCalls;
use App\Lib\Hub\HubException;
use App\Lib\Hub\HubGrants;
use App\Lib\Hub\HubResponse;
use App\Lib\Hub\HubTokens;
use App\Lib\Hub\HubUrl;
use App\Lib\Hub\SpkiPin;
use App\Models\HubConnection;
use App\Models\PersonalAccessToken;
use App\System;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Connect this engine to a PanelAlpha Connect account with a one-time code
 * from the Hub's Connect engine dialog. The user chose the account, license and
 * permissions there, so the session is approved when the code is claimed; the
 * engine mints a token limited to a few API routes and hands it to the Hub
 * over its own verified TLS connection.
 *
 * The device code and the connect code live only in this process. Neither is
 * logged or written anywhere.
 */
class HubConnectCommand extends Command
{
    protected $signature = 'hub:connect
                            {code : One-time code from Connect engine in PanelAlpha Connect}
                            {--ip= : The public address PanelAlpha Connect reaches this engine at (default: the main IPv4)}
                            {--replace : Replace the current connection once the new one completes}';

    protected $description = 'Connect this engine to a PanelAlpha Connect account';

    private const POLL_RETRIES = 3;

    private const SLOW_DOWN_SECONDS = 5;

    private const CODE_MAX_LENGTH = 16;

    /** What a refused claim means, keyed by the Hub's `error`. */
    private const CLAIM_ERRORS = [
        'invalid_connect_code' => 'That code is not valid any more. Create a new one in PanelAlpha Connect.',
        'not_allowed' => 'The account that created the code can no longer connect engines.',
        'license_not_active' => 'The license of this code is not active.',
        'slow_down' => 'Too many attempts. Wait a minute and try again.',
    ];

    private const WARNINGS = [
        'ip_differs_from_observed_ip' => 'the Hub sees this engine at a different address than it reports',
        'license_ip_mismatch' => 'the license is registered for a different IP address',
        'license_has_engine' => "the license's current engine will be replaced",
        'engine_connected_elsewhere' => 'this engine moves from another account',
    ];

    private ?HubClient $hub = null;

    private string $connectCode = '';

    private ?string $deviceCode = null;

    private ?int $mintedTokenId = null;

    private bool $settled = false;

    public function handle(): int
    {
        try {
            return $this->connectEngine();
        } catch (HubException $e) {
            $this->error($e->getMessage());
            $this->abandon('local_error');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->abandon('local_error');

            throw $e;
        }
    }

    /** @throws HubException */
    private function connectEngine(): int
    {
        $existing = HubConnection::active();
        if ($existing !== null && ! $this->option('replace')) {
            $this->error(sprintf(
                'This engine is already connected to %s (account %s). Run with --replace to connect it elsewhere, '
                .'or `pae hub:disconnect` first.',
                $existing->account_name ?? '?',
                $existing->account_id
            ));

            return self::FAILURE;
        }

        // Sent as typed: the Hub ignores case, dashes and spaces.
        $this->connectCode = trim((string) $this->argument('code'));
        if ($this->connectCode === '' || mb_strlen($this->connectCode) > self::CODE_MAX_LENGTH) {
            $this->error('That is not a connect code. Copy the command from Connect engine in PanelAlpha Connect.');

            return self::FAILURE;
        }

        if (($problem = EngineIdentity::appIdProblem()) !== null) {
            throw new HubException($problem);
        }

        $base = HubUrl::base();
        $ip = $this->engineIp();
        $pin = SpkiPin::fromFile(SpkiPin::servedCertificatePath());
        $this->hub = new HubClient($base);

        // Ctrl+C cancels the session and revokes anything minted. Needs pcntl,
        // which core's php-cli has.
        if (extension_loaded('pcntl')) {
            $this->trap([\SIGINT, \SIGTERM], function (): void {
                $this->newLine();
                $this->warn('Cancelled.');
                $this->abandon('operator_cancelled');
                exit(self::FAILURE);
            });
        }

        $session = $this->createSession($ip, $pin);
        if ($session === null) {
            return self::FAILURE;
        }

        $approval = $this->waitForApproval($session['expires_at'], $session['interval']);
        if ($approval === null) {
            return self::FAILURE;
        }

        $grants = is_array($approval['grants'] ?? null) ? $approval['grants'] : [];
        if (HubGrants::unknown($grants) !== []) {
            $this->abandon('unsupported_grant');
            $this->error('The Hub requested a permission this engine does not know. Update the engine.');

            return self::FAILURE;
        }

        $this->showApproval($approval, $grants);

        return $this->deliver($approval, $grants, $ip, $pin, $base);
    }

    /** @throws HubException */
    private function engineIp(): string
    {
        $ip = $this->option('ip');
        $ip = is_string($ip) && trim($ip) !== '' ? trim($ip) : EngineAddress::default();
        if ($ip === null) {
            throw new HubException('This engine has no main IPv4 on record. Pass its public address with --ip.');
        }
        if (($problem = EngineAddress::problem($ip)) !== null) {
            throw new HubException($problem);
        }

        return $ip;
    }

    /**
     * @return ?array{expires_at: int, interval: int}
     *
     * @throws HubException
     */
    private function createSession(string $ip, string $pin): ?array
    {
        $engine = array_filter([
            'version' => EngineIdentity::version(),
            'hostname' => HostFacts::host(new System)['hostname'] ?? null,
        ], fn (mixed $v): bool => is_string($v) && $v !== '');

        // A code already names its license (or none), so no key is sent.
        $response = $this->hub()->createSession([
            'product' => EngineIdentity::PRODUCT,
            'app_id' => EngineIdentity::appId(),
            'ip' => $ip,
            'tls_spki' => $pin,
            'license_key' => null,
            'engine' => (object) $engine,
            'connect_code' => $this->connectCode,
        ]);

        if ($response->status !== 201) {
            $claimError = $response->status !== 422 ? self::CLAIM_ERRORS[$response->error() ?? ''] ?? null : null;
            $this->error($claimError ?? 'PanelAlpha Connect refused the request: '.$this->describe($response));

            return null;
        }

        return $this->claimed($response->json);
    }

    /**
     * A claimed code is approved already: no link to open, and the first poll
     * returns the approval.
     *
     * @param  array<string, mixed>  $json
     * @return array{expires_at: int, interval: int}
     *
     * @throws HubException
     */
    private function claimed(array $json): array
    {
        if (! is_string($json['device_code'] ?? null) || $json['device_code'] === '') {
            throw new HubException('PanelAlpha Connect answered without a device_code.');
        }

        $this->deviceCode = $json['device_code'];
        $this->line('Connect code accepted by '.HubUrl::base().'.');

        return [
            'expires_at' => now()->getTimestamp() + max(1, (int) ($json['expires_in'] ?? 600)),
            'interval' => max(1, (int) ($json['interval'] ?? 5)),
        ];
    }

    /** @return ?array<string, mixed> the approval, or null when there is none */
    private function waitForApproval(int $expiresAt, int $interval): ?array
    {
        $failures = 0;
        $expired = 'The code expired. Create a new one in PanelAlpha Connect.';
        // A claimed code is approved already, so the first poll goes at once.
        $pollAtOnce = true;

        while (true) {
            if (now()->getTimestamp() >= $expiresAt) {
                $this->error($expired);
                $this->abandon('operator_cancelled');

                return null;
            }

            if ($pollAtOnce) {
                $pollAtOnce = false;
            } else {
                Sleep::for($interval)->seconds();
            }

            try {
                $response = $this->hub()->poll((string) $this->deviceCode);
                $failures = 0;
            } catch (HubException $e) {
                if (++$failures > self::POLL_RETRIES) {
                    $this->error($e->getMessage());
                    $this->abandon('local_error');

                    return null;
                }

                continue;
            }

            if ($response->status === 200 && ($response->json['status'] ?? null) === 'approved') {
                $approval = $response->json['approval'] ?? null;
                if (! is_array($approval) || ! is_array($approval['account'] ?? null)) {
                    $this->error('PanelAlpha Connect sent an approval this engine cannot read.');
                    $this->abandon('local_error');

                    return null;
                }

                return $approval;
            }

            switch ($response->error()) {
                case 'authorization_pending':
                    continue 2;
                case 'slow_down':
                    $interval += self::SLOW_DOWN_SECONDS;

                    continue 2;
                case 'access_denied':
                    $this->settled = true;
                    $this->error('Denied on the Hub.');

                    return null;
                case 'expired_token':
                    $this->settled = true;
                    $this->error($expired);

                    return null;
                case 'invalid_grant':
                    $this->settled = true;
                    $this->error('The session is no longer valid.');

                    return null;
            }

            if ($response->status === 429) {
                $interval += self::SLOW_DOWN_SECONDS;

                continue;
            }

            $this->error('PanelAlpha Connect answered: '.$this->describe($response));
            $this->abandon('local_error');

            return null;
        }
    }

    /**
     * Who this engine is about to belong to, before it does: the code's
     * account and creator, what the Hub may do, and any warning nobody saw
     * on a web page.
     *
     * @param  array<string, mixed>  $approval
     * @param  array<int, string>  $grants
     */
    private function showApproval(array $approval, array $grants): void
    {
        $this->newLine();
        $this->line(sprintf(
            'Account: %s (code created by %s)',
            (string) ($approval['account']['name'] ?? '?'),
            (string) ($approval['approved_by'] ?? '?')
        ));
        $this->line('Permissions:');
        foreach (HubGrants::describe($grants) as $line) {
            $this->line('  - '.$line);
        }
        foreach (is_array($approval['warnings'] ?? null) ? $approval['warnings'] : [] as $warning) {
            if (is_string($warning) && $warning !== '') {
                $this->warn('Warning: '.(self::WARNINGS[$warning] ?? $warning));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $approval
     * @param  array<int, string>  $grants
     *
     * @throws HubException
     */
    private function deliver(
        array $approval,
        array $grants,
        string $ip,
        string $pin,
        string $base
    ): int {
        $abilities = HubGrants::abilities($grants);
        if (! HubGrants::isHubScoped($abilities)) {
            throw new HubException('Refusing to mint a Hub token beyond the routes the Hub may call.');
        }

        // Named after its connection once there is one; until then, pending.
        $new = HubTokens::mint($abilities);
        $this->mintedTokenId = (int) $new->accessToken->id;

        $response = $this->hub()->complete((string) $this->deviceCode, [
            'token' => $new->plainTextToken,
            'token_id' => $this->mintedTokenId,
            'abilities' => $abilities,
            'expires_at' => $new->accessToken->expires_at?->utc()->toIso8601ZuluString(),
        ], $pin);
        unset($new);

        if ($response->status === 409 && $response->error() === 'engine_unreachable') {
            $this->revokeMinted();
            $this->error($this->unreachableMessage((string) ($response->json['reason'] ?? ''), $ip));

            return self::FAILURE;
        }

        $connectionId = $response->json['connection_id'] ?? null;
        $secret = $response->json['hub_secret'] ?? null;
        if ($response->status !== 200 || ! is_string($connectionId) || $connectionId === ''
            || ! is_string($secret) || preg_match('/\A[0-9a-f]{64}\z/i', $secret) !== 1) {
            $this->revokeMinted();
            $this->error('PanelAlpha Connect did not complete the connection: '.$this->describe($response));

            return self::FAILURE;
        }

        $account = is_array($response->json['account'] ?? null) ? $response->json['account'] : $approval['account'];
        $license = array_key_exists('license', $response->json) ? $response->json['license'] : ($approval['license'] ?? null);
        $license = is_array($license) ? $license : null;

        $connection = DB::transaction(function () use (
            $connectionId, $secret, $account, $license, $approval, $grants, $ip, $pin, $base
        ): HubConnection {
            // One connection at a time: the one this replaces, and anything a
            // failed attempt left, stops working now.
            HubTokens::revokeAll(except: $this->mintedTokenId);
            PersonalAccessToken::query()->whereKey($this->mintedTokenId)
                ->update(['name' => HubTokens::nameFor($connectionId)]);
            HubConnection::query()->delete();

            $connection = HubConnection::query()->create([
                'connection_id' => $connectionId,
                'hub_url' => $base,
                'hub_secret' => $secret,
                'account_id' => (string) ($account['id'] ?? ''),
                'account_name' => is_string($account['name'] ?? null) ? $account['name'] : null,
                'approved_by' => is_string($approval['approved_by'] ?? null) ? $approval['approved_by'] : null,
                'license_id' => isset($license['id']) ? (string) $license['id'] : null,
                'license_name' => is_string($license['name'] ?? null) ? $license['name'] : null,
                'ip' => $ip,
                'tls_spki' => $pin,
                'grants' => array_values($grants),
                'token_id' => $this->mintedTokenId,
                'status' => HubConnection::STATUS_CONNECTED,
                'connected_at' => now(),
            ]);

            HubConnectionCalls::stampVerificationCalls($connection, $this->mintedTokenId);

            return $connection;
        });
        HubCallerAddress::sync();

        $this->settled = true;
        HubAudit::log('hub.connected', [
            'connection_id' => $connection->connection_id,
            'account_id' => $connection->account_id,
            'license_id' => $connection->license_id,
            'token_id' => $connection->token_id,
            'grants' => implode(',', $grants),
        ]);

        $this->info('Connected to '.($connection->account_name ?? 'account '.$connection->account_id).'.');
        $this->line('Not your account? Disconnect with: pae hub:disconnect');

        return self::SUCCESS;
    }

    private function unreachableMessage(string $reason, string $ip): string
    {
        return match ($reason) {
            'connect_failed', 'connect_timeout' => "The Hub could not reach https://{$ip}:2011. Allow inbound TCP 2011 "
                .'from the Hub, or pass the right address with --ip.',
            'tls_pin_mismatch' => 'The certificate on :2011 changed during connect. Run the command again.',
            'unauthorized' => 'The token was rejected. Check the engine API.',
            'app_id_mismatch' => 'Another engine answered at this address.',
            default => 'The Hub could not verify this engine: '.($reason !== '' ? $reason : 'engine_unreachable'),
        };
    }

    private function describe(HubResponse $response): string
    {
        $text = $response->message();
        $errors = $response->json['errors'] ?? null;
        if (is_array($errors)) {
            foreach ($errors as $field => $messages) {
                $text .= "\n  {$field}: ".implode(' ', array_map('strval', (array) $messages));
            }
        }
        if ($response->status === 429 && $response->retryAfter !== null) {
            $text .= " Try again in {$response->retryAfter} s.";
        }

        return $text." (HTTP {$response->status})";
    }

    /**
     * Undo whatever this run left behind: the minted token, and the session on
     * the Hub. Safe to call more than once and from a signal handler.
     */
    private function abandon(string $reason): void
    {
        $this->revokeMinted();

        if ($this->settled || $this->deviceCode === null || $this->hub === null) {
            return;
        }
        $this->settled = true;
        $this->hub->cancel($this->deviceCode, $reason);
    }

    private function revokeMinted(): void
    {
        if ($this->mintedTokenId !== null && ! $this->settled) {
            HubTokens::revoke($this->mintedTokenId);
            $this->mintedTokenId = null;
        }
    }

    private function hub(): HubClient
    {
        return $this->hub ?? throw new HubException('Not talking to a Hub yet.');
    }
}
