<?php

namespace App\Console\Commands\Hub;

use App\Lib\Hub\HubClient;
use App\Lib\Hub\HubException;
use App\Lib\Hub\HubGrants;
use App\Lib\Hub\HubLifecycle;
use App\Models\HubAccessLog;
use App\Models\HubConnection;
use App\Models\PersonalAccessToken;
use Illuminate\Console\Command;

/** Whether this engine is connected to PanelAlpha Connect, and what the Hub has been doing with it. */
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

        /** @var ?PersonalAccessToken $token */
        $token = PersonalAccessToken::query()->find($connection->token_id);
        $grants = $connection->grantList();

        $this->info('Connected to PanelAlpha Connect');
        $this->table([], [
            ['Hub', $connection->hub_url],
            ['Account', trim(($connection->account_name ?? '').' (id '.$connection->account_id.')')],
            ['Approved by', $connection->approved_by ?? '-'],
            ['Engine address', 'https://'.$connection->ip.':2011'],
            ['Connected since', $connection->connected_at?->toDateTimeString() ?? '-'],
            ['Connection', (string) $connection->connection_id],
            ['Permissions', implode("\n", HubGrants::describe($grants))],
            ['Token', $token === null ? 'missing' : sprintf(
                '#%d, expires %s%s',
                $token->id,
                $token->expires_at?->toDateTimeString() ?? 'never',
                $token->isRevoked() ? ' (revoked)' : ''
            )],
            ['Hub secret', $connection->hub_secret !== null ? 'present' : 'missing'],
            ['Last Hub call', $connection->last_hub_call_at?->toDateTimeString() ?? 'never'],
        ]);

        if (! $this->option('local')) {
            $this->hubView($connection);
        }

        $this->recentCalls();

        return self::SUCCESS;
    }

    private function hubView(HubConnection $connection): void
    {
        try {
            $response = (new HubClient($connection->hub_url))->connection($connection);
        } catch (HubException $e) {
            $this->warn('Could not ask the Hub: '.$e->getMessage());

            return;
        }

        if ($response->status === 403) {
            HubLifecycle::forget('hub_unknown');
            $this->error('The Hub no longer knows this connection. Its tokens are revoked here; '
                .'get a new code from Connect engine and run `pae hub:connect CODE` to connect again.');

            return;
        }
        if ($response->status !== 200) {
            $this->warn('The Hub answered: '.$response->message()." (HTTP {$response->status})");

            return;
        }

        $json = $response->json;
        $this->newLine();
        $this->line('<info>The Hub\'s view</info>');
        $this->table([], [
            ['Status', (string) ($json['status'] ?? '?')],
            ['Account', (string) ($json['account']['id'] ?? '?')],
            ['Grants', implode(', ', array_map('strval', (array) ($json['grants'] ?? []))) ?: 'base only'],
        ]);
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
