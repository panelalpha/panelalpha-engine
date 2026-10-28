<?php

namespace App\Console\Commands\Users\ProjectSettings;

use App\Console\Commands\Concerns\ConfirmsChanges;
use App\Console\Commands\Concerns\ResolvesProject;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\User;
use App\System\Project\Settings;
use Illuminate\Console\Command;

class UnsetCommand extends Command
{
    use ConfirmsChanges;
    use ResolvesProject;

    protected $aliases = [
        'projects:settings:unset',
        'project:settings:delete',
        'projects:settings:delete',
    ];

    protected $signature = 'project:settings:unset
        {key : Setting key (e.g. cloudflare-api-token)}
        {--project= : Project username}
        {--username= : Deprecated alias for --project}
        {--force : Tear down tunnels when unsetting cloudflare-api-token; also skip confirmation}';

    protected $description = 'Unset an allowlisted project setting';

    public function handle(): int
    {
        $this->foldProjectOption();

        $project = trim((string) $this->option('username'));
        $key = Settings::normalizeKey((string) $this->argument('key'));
        $force = (bool) $this->option('force');

        if ($project === '') {
            $this->error('--project is required.');

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

        if ($this->refusedWithoutConfirmation()) {
            return 1;
        }

        $this->line('');
        $this->info("About to unset project setting '{$key}' for '{$user->username}'.");
        if ($key === Settings::KEY_CLOUDFLARE_API_TOKEN) {
            $this->comment(
                'This clears the Cloudflare API token and connector state. '
                .'Existing tunnel hostnames require --force (full teardown).'
            );
        }

        if (! $force && ! $this->confirm('Continue?')) {
            $this->info('Cancelled.');

            return 0;
        }

        try {
            $user->project()->settings()->unset($key, $force);
        } catch (CloudflareException $e) {
            $this->error($e->getMessage());

            return 1;
        } catch (\Throwable $e) {
            $this->error('Failed to unset setting: '.$e->getMessage());

            return 1;
        }

        $this->info("Setting '{$key}' unset.");
        $this->line('');

        return 0;
    }
}
