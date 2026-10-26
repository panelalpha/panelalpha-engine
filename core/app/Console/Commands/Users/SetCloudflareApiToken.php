<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ResolvesProject;
use Illuminate\Console\Command;

/**
 * Thin wrapper kept for scripts that still call the old command name.
 */
class SetCloudflareApiToken extends Command
{
    use ResolvesProject;

    protected $aliases = ['projects:set-cloudflare-api-token', 'users:set-cloudflare-api-token'];

    protected $signature = 'project:set-cloudflare-api-token
        {token? : Cloudflare API token (Account: Cloudflare Tunnel Edit + Zone: DNS Edit + Zone Cache Purge + Zone Settings Edit, with the account and zones selected under Resources)}
        {--project= : Project username}
        {--username= : Deprecated alias for --project}
        {--token= : Cloudflare API token (alternative to the positional argument)}
        {--force : Skip confirmation}';

    protected $description = 'Deprecated alias for project:settings:set cloudflare-api-token';

    public function handle(): int
    {
        $this->foldProjectOption();

        $project = trim((string) $this->option('username'));
        $positional = trim((string) ($this->argument('token') ?? ''));
        $optionToken = trim((string) ($this->option('token') ?? ''));
        if ($positional !== '' && $optionToken !== '' && $positional !== $optionToken) {
            $this->error('Conflicting tokens: positional argument vs --token.');

            return 1;
        }
        $token = $positional !== '' ? $positional : $optionToken;

        $params = [
            'key' => 'cloudflare-api-token',
            '--project' => $project,
        ];
        if ($token !== '') {
            $params['value'] = $token;
        }
        if ($this->option('force')) {
            $params['--force'] = true;
        }

        $this->warn('project:set-cloudflare-api-token is deprecated; use project:settings:set cloudflare-api-token.');

        return $this->call('project:settings:set', $params);
    }
}
