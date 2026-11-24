<?php

namespace App\Console\Commands\Hub;

use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Hub\EngineAddress;
use App\Lib\Hub\EngineIdentity;
use App\Lib\Hub\HubAudit;
use App\Lib\Hub\HubCallerAddress;
use App\Lib\Hub\HubClient;
use App\Lib\Hub\HubException;
use App\Lib\Hub\HubGrants;
use App\Lib\Hub\HubHostFacts;
use App\Lib\Hub\HubLifecycle;
use App\Lib\Hub\HubReporter;
use App\Lib\Hub\HubResponse;
use App\Lib\Hub\HubTokens;
use App\Lib\Hub\HubUrl;
use App\Models\HubConnection;
use App\System;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Connect this engine to a PanelAlpha Connect account with a one-time code
 * from the Hub's Connect engine dialog. The user chose the account, license and
 * permissions there, so the session is approved when the code is claimed. The
 * engine reports to the Hub from then on and the Hub never calls it, so it
 * hands over no token; its first report confirms the connection.
 *
 * The device code and the connect code live only in this process. Neither is
 * logged or written anywhere.
 */
class HubConnectCommand extends Command
{
    protected $signature = 'hub:connect
                            {code : One-time code from Connect engine in PanelAlpha Connect}
                            {--ip= : The address PanelAlpha Connect shows for this engine (default: the main IPv4; a private one is fine, the Hub never connects to it)}
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

    /**
     * The code is sent as typed: the Hub ignores case, dashes and spaces.
     *
     * @throws HubException
     */
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
        $this->hub = new HubClient($base);
        $this->cancelOnInterrupt();

        $session = $this->createSession($ip);
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

        return $this->completeConnection($approval, $grants, $ip, $base);
    }

    /** Ctrl+C cancels the session. Needs pcntl, which core's php-cli has. */
    private function cancelOnInterrupt(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        $this->trap([\SIGINT, \SIGTERM], function (): void {
            $this->newLine();
            $this->warn('Cancelled.');
            $this->abandon('operator_cancelled');
            exit(self::FAILURE);
        });
    }

    /** @throws HubException */
    private function engineIp(): string
    {
        $ip = $this->option('ip');
        $ip = is_string($ip) && trim($ip) !== '' ? trim($ip) : EngineAddress::default();
        if ($ip === null) {
            throw new HubException('This engine has no main IPv4 on record. Pass its address with --ip.');
        }
        if (($problem = EngineAddress::problem($ip)) !== null) {
            throw new HubException($problem);
        }

        return $ip;
    }

    /**
     * A code already names its license (or none), so no key is sent.
     *
     * @return ?array{expires_at: int, interval: int}
     *
     * @throws HubException
     */
    private function createSession(string $ip): ?array
    {
        $engine = array_filter([
            'version' => EngineIdentity::version(),
            'hostname' => HostFacts::host(new System)['hostname'] ?? null,
        ], fn (mixed $v): bool => is_string($v) && $v !== '');

        $response = $this->hub()->createSession([
            'product' => EngineIdentity::PRODUCT,
            'app_id' => EngineIdentity::appId(),
            'ip' => $ip,
            'protocol' => HubConnection::PROTOCOL_PUSH,
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

    /**
     * A claimed code is approved already, so the first poll goes at once.
     *
     * @return ?array<string, mixed> the approval, or null when there is none
     */
    private function waitForApproval(int $expiresAt, int $interval): ?array
    {
        $failures = 0;
        $expired = 'The code expired. Create a new one in PanelAlpha Connect.';
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
     * One connection at a time: once the new one is stored, the one it replaces and any Hub token a connection made
     * before push left behind stop working. The first report proves the connection works; without it the Hub is told
     * and nothing is kept.
     *
     * @param  array<string, mixed>  $approval
     * @param  array<int, string>  $grants
     *
     * @throws HubException
     */
    private function completeConnection(array $approval, array $grants, string $ip, string $base): int
    {
        $response = $this->hub()->complete((string) $this->deviceCode);
        $this->settled = true;

        $connectionId = $response->json['connection_id'] ?? null;
        $secret = $response->json['hub_secret'] ?? null;
        if ($response->status !== 200 || ! is_string($connectionId) || $connectionId === ''
            || ! is_string($secret) || preg_match('/\A[0-9a-f]{64}\z/i', $secret) !== 1) {
            $this->error('PanelAlpha Connect did not complete the connection: '.$this->describe($response));

            return self::FAILURE;
        }

        $connection = $this->storeConnection($response->json, $approval, $grants, $ip, $base, $connectionId, $secret);

        return $this->confirmWithFirstReport($connection) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $approval
     * @param  array<int, string>  $grants
     */
    private function storeConnection(array $json, array $approval, array $grants, string $ip, string $base, string $connectionId, string $secret): HubConnection
    {
        $account = is_array($json['account'] ?? null) ? $json['account'] : $approval['account'];
        $license = array_key_exists('license', $json) ? $json['license'] : ($approval['license'] ?? null);
        $license = is_array($license) ? $license : null;
        $interval = $json['report']['interval_seconds'] ?? null;

        $connection = DB::transaction(function () use ($connectionId, $secret, $account, $license, $approval, $grants, $ip, $base, $interval): HubConnection {
            HubTokens::revokeAll();
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
                'grants' => array_values($grants),
                'status' => HubConnection::STATUS_CONNECTED,
                'connected_at' => now(),
                'protocol' => HubConnection::PROTOCOL_PUSH,
            ]);
            $connection->forceFill([
                'report_interval_seconds' => is_int($interval) ? max(HubReporter::MIN_INTERVAL, min(HubReporter::MAX_INTERVAL, $interval)) : HubReporter::MIN_INTERVAL,
            ])->save();

            return $connection;
        });
        HubCallerAddress::sync();

        return $connection;
    }

    /** The first report, sent now: the Hub counts the connection as made only once one arrives. */
    private function confirmWithFirstReport(HubConnection $connection): bool
    {
        try {
            HubHostFacts::refreshIfStale($connection);
        } catch (\Throwable) {
        }

        $response = (new HubReporter($this->hub()))->send($connection);
        if ($response === null || $response->status !== 200) {
            $this->error('The first report to PanelAlpha Connect failed: '
                .($response === null ? 'the Hub could not be reached' : $this->describe($response)).'. Nothing was kept.');
            $this->forgetUnconfirmed($connection);

            return false;
        }

        HubAudit::log('hub.connected', [
            'connection_id' => $connection->connection_id,
            'account_id' => $connection->account_id,
            'license_id' => $connection->license_id,
            'protocol' => HubConnection::PROTOCOL_PUSH,
            'grants' => implode(',', $connection->grantList()),
        ]);
        $this->info('Connected to '.($connection->account_name ?? 'account '.$connection->account_id).'.');
        $this->line('Not your account? Disconnect with: pae hub:disconnect');

        return true;
    }

    private function forgetUnconfirmed(HubConnection $connection): void
    {
        try {
            $this->hub()->disconnect($connection, 'first_report_failed');
        } catch (HubException) {
        }
        HubLifecycle::forget('first_report_failed');
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

    /** Cancel the session on the Hub. Safe to call more than once and from a signal handler. */
    private function abandon(string $reason): void
    {
        if ($this->settled || $this->deviceCode === null || $this->hub === null) {
            return;
        }
        $this->settled = true;
        $this->hub->cancel($this->deviceCode, $reason);
    }

    private function hub(): HubClient
    {
        return $this->hub ?? throw new HubException('Not talking to a Hub yet.');
    }
}
