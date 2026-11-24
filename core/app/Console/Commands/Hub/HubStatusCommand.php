<?php

namespace App\Console\Commands\Hub;

use App\Lib\Hub\HubClient;
use App\Lib\Hub\HubException;
use App\Lib\Hub\HubGrants;
use App\Lib\Hub\HubLifecycle;
use App\Lib\Hub\HubResponse;
use App\Models\HubAccessLog;
use App\Models\HubConnection;
use App\Models\HubExchange;
use App\Models\HubSsoLogin;
use App\Models\PersonalAccessToken;
use Illuminate\Console\Command;

/** Whether this engine is connected to PanelAlpha Connect, what it reported, and what the Hub did with it. */
class HubStatusCommand extends Command
{
    protected $signature = 'hub:status {--local : Do not ask the Hub, show only what this engine holds}';

    protected $description = 'Show the PanelAlpha Connect connection and the Hub\'s recent calls';

    private const LOG_ROWS = 20;

    public function handle(): int
    {
        $connection = HubConnection::active();
        if ($connection === null) {
            $this->line('Not connected to PanelAlpha Connect. Get a code from Connect engine in PanelAlpha Connect and run `pae hub:connect CODE`.');
            $this->recentCalls();

            return self::SUCCESS;
        }

        $this->info('Connected to PanelAlpha Connect');
        $this->table([], [
            ['Hub', $connection->hub_url],
            ['Account', trim(($connection->account_name ?? '').' (id '.$connection->account_id.')')],
            ['Approved by', $connection->approved_by ?? '-'],
            ['Connected since', $connection->connected_at?->toDateTimeString() ?? '-'],
            ['Connection', (string) $connection->connection_id],
            ['Protocol', $connection->isPush() ? 'push: this engine reports to the Hub' : 'pull: the Hub calls this engine'],
            ['Permissions', implode("\n", HubGrants::describe($connection->grantList()))],
            ['Hub secret', $connection->hub_secret !== null ? 'present' : 'missing'],
            ...($connection->isPush() ? $this->pushRows($connection) : $this->pullRows($connection)),
        ]);

        if (! $this->option('local') && ! $this->hubView($connection)) {
            return self::SUCCESS;
        }

        if ($connection->isPush()) {
            $this->recentExchanges();
            $this->recentLogins();
        } else {
            $this->recentCalls();
        }

        return self::SUCCESS;
    }

    /** @return list<array{string, string}> */
    private function pushRows(HubConnection $connection): array
    {
        $backfill = $connection->last_backfill;

        return [
            ['Report interval', $connection->report_interval_seconds.' s'],
            ['Last report', $connection->last_report_at?->toDateTimeString() ?? 'never'],
            ['Last backfill', is_array($backfill) ? sprintf(
                '%s .. %s (step %s): %s, %s point(s) kept, %s',
                $backfill['from'] ?? '?', $backfill['to'] ?? '?', $backfill['step'] ?? '?',
                $backfill['result'] ?? '?', $backfill['kept'] ?? 0, $backfill['at'] ?? '?'
            ) : 'none'],
        ];
    }

    /** @return list<array{string, string}> */
    private function pullRows(HubConnection $connection): array
    {
        /** @var ?PersonalAccessToken $token */
        $token = PersonalAccessToken::query()->find($connection->token_id);

        return [
            ['Engine address', 'https://'.$connection->ip.':2011'],
            ['Token', $token === null ? 'missing' : sprintf(
                '#%d, expires %s%s',
                $token->id,
                $token->expires_at?->toDateTimeString() ?? 'never',
                $token->isRevoked() ? ' (revoked)' : ''
            )],
            ['Last Hub call', $connection->last_hub_call_at?->toDateTimeString() ?? 'never'],
            ['Upgrade to push', $connection->upgrade_attempted_at === null ? 'not tried yet' : 'last tried '.$connection->upgrade_attempted_at->toDateTimeString()],
        ];
    }

    /** @return bool false once the Hub said it no longer knows the connection, which is then removed here */
    private function hubView(HubConnection $connection): bool
    {
        try {
            $response = (new HubClient($connection->hub_url))->connection($connection);
        } catch (HubException $e) {
            $this->warn('Could not ask the Hub: '.$e->getMessage());

            return true;
        }

        if ($response->connectionUnknown()) {
            HubLifecycle::forget('hub_unknown');
            $this->error('The Hub no longer knows this connection. It is removed here; '
                .'get a new code from Connect engine and run `pae hub:connect CODE` to connect again.');

            return false;
        }
        if ($response->status !== 200) {
            $this->warn('The Hub answered: '.$response->message()." (HTTP {$response->status})");
            $this->refusalHint($response);

            return true;
        }

        $json = $response->json;
        $this->newLine();
        $this->line('<info>The Hub\'s view</info>');
        $this->table([], [
            ['Status', (string) ($json['status'] ?? '?')],
            ['Protocol', (string) ($json['protocol'] ?? '?')],
            ['Account', (string) ($json['account']['id'] ?? '?')],
            ['Grants', implode(', ', array_map('strval', (array) ($json['grants'] ?? []))) ?: 'base only'],
        ]);

        return true;
    }

    private function recentExchanges(): void
    {
        $rows = HubExchange::query()->orderByDesc('id')->limit(self::LOG_ROWS)->get();
        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<info>Last reports to the Hub</info>');
        $this->table(['At', 'Kind', 'Status', 'Took', 'Bytes', 'Detail'], $rows->map(fn (HubExchange $r): array => [
            $r->at->toDateTimeString(), $r->kind, $r->status === 0 ? 'no answer' : (string) $r->status,
            $r->duration_ms.' ms', (string) $r->bytes, $r->detail ?? '',
        ])->all());
    }

    private function recentLogins(): void
    {
        $rows = HubSsoLogin::query()->orderByDesc('id')->limit(self::LOG_ROWS)->get();
        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<info>Last admin logins from the Hub</info>');
        $this->table(['At', 'App', 'Logged in as', 'Hub user'], $rows->map(fn (HubSsoLogin $r): array => [
            $r->at->toDateTimeString(), $r->app, $r->app_user ?? '', $r->sub ?? '',
        ])->all());
    }

    private function refusalHint(HubResponse $response): void
    {
        if ($response->status === 403) {
            $this->line('Nothing was changed here. If the connection was removed in PanelAlpha Connect, run `pae hub:disconnect`.');
        }
    }

    private function recentCalls(): void
    {
        $rows = HubAccessLog::query()->orderByDesc('id')->limit(self::LOG_ROWS)->get();
        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<info>Last Hub calls</info>');
        $this->table(['At', 'Route', 'App', 'Status', 'From', 'Detail'], $rows->map(function (HubAccessLog $r): array {
            $sso = str_ends_with($r->route, '/sso');
            $route = $sso ? "<fg=yellow>{$r->route}</>" : $r->route;

            return [$r->at->toDateTimeString(), $route, $r->app ?? '', $r->status, $r->source_ip ?? '', $r->detail ?? ''];
        })->all());
    }
}
