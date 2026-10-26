<?php

namespace App\Console\Commands\Users\ProjectSettings;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Models\User;
use App\System\Project\Settings;
use Illuminate\Console\Command;

class Get extends Command
{
    use ResolvesProject;

    protected $aliases = ['projects:settings:get'];

    protected $signature = 'project:settings:get
        {key : Setting key (e.g. cloudflare-api-token)}
        {--project= : Project username}
        {--username= : Deprecated alias for --project}';

    protected $description = 'Get an allowlisted project setting (secrets are redacted)';

    public function handle(): int
    {
        $this->foldProjectOption();

        $project = trim((string) $this->option('username'));
        $key = Settings::normalizeKey((string) $this->argument('key'));

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

        $row = $user->project()->settings()->get($key);
        if (! $row['set']) {
            $this->warn("Setting '{$key}' is not set.");

            return 1;
        }

        $this->line((string) $row['value']);

        return 0;
    }
}
