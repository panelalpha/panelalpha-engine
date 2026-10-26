<?php

namespace App\Console\Commands\Users\ProjectSettings;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\User;
use App\System\Project\Settings;
use Illuminate\Console\Command;

class Set extends Command
{
    use ResolvesProject;

    protected $aliases = ['projects:settings:set'];

    protected $signature = 'project:settings:set
        {key : Setting key (e.g. cloudflare-api-token)}
        {value? : Setting value}
        {--project= : Project username}
        {--username= : Deprecated alias for --project}
        {--value= : Setting value (alternative to the positional argument)}
        {--force : Skip confirmation}';

    protected $description = 'Set an allowlisted project setting (stored on the project/user)';

    public function handle(): int
    {
        $this->foldProjectOption();

        $project = trim((string) $this->option('username'));
        $key = Settings::normalizeKey((string) $this->argument('key'));
        $positional = (string) ($this->argument('value') ?? '');
        $optionValue = (string) ($this->option('value') ?? '');
        if ($positional !== '' && $optionValue !== '' && $positional !== $optionValue) {
            $this->error('Conflicting values: positional argument vs --value.');

            return 1;
        }
        $value = $positional !== '' ? $positional : $optionValue;

        if ($project === '') {
            $this->error('--project is required.');

            return 1;
        }
        if ($value === '') {
            $this->error('Value is required (positional argument or --value).');

            return 1;
        }

        try {
            Settings::assertKnownKey($key);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $user = User::findByUsername($project);
        if (! $user) {
            $this->error("Project '{$project}' not found.");

            return 1;
        }

        $this->line('');
        $this->info("Set project setting '{$key}' for '{$user->username}'");
        if ($key === Settings::KEY_CLOUDFLARE_API_TOKEN) {
            $this->line('Token will be validated against the Cloudflare API and stored encrypted.');
            $this->line('Required permissions: Account Cloudflare Tunnel Edit, Zone DNS Edit, Zone Cache Purge, Zone Settings Edit.');
            // The permissions are not enough on their own, and neither omission
            // explains itself: a token without the account resource answers
            // "no accessible accounts", and one without the zone answers "not
            // under any Cloudflare zone accessible with this API token".
            $this->line('Account Resources must include the account, and Zone Resources the zones the project uses.');
            $this->line('Create one at https://dash.cloudflare.com/profile/api-tokens (Create Custom Token).');
        }

        if (! $this->option('force') && ! $this->confirm('Continue?')) {
            $this->info('Cancelled.');

            return 0;
        }

        try {
            $result = $user->project()->settings()->set($key, $value);
        } catch (CloudflareException $e) {
            $this->error($e->getMessage());

            return 1;
        } catch (\Throwable $e) {
            $this->error('Failed to set setting: '.$e->getMessage());

            return 1;
        }

        $this->info($result['message'] ?? "Setting '{$key}' saved.");
        if (! empty($result['account_name']) && ! empty($result['account_id'])) {
            $this->line("Account: {$result['account_name']} ({$result['account_id']})");
        }
        $this->line('');

        return 0;
    }
}
