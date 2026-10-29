<?php

namespace App\Console\Commands\Users\ProjectSettings;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Models\User;
use Illuminate\Console\Command;

class ListCommand extends Command
{
    use ResolvesProject;

    protected $aliases = ['projects:settings:list'];

    protected $signature = 'project:settings:list
        {--project= : Project username}
        {--username= : Deprecated alias for --project}';

    protected $description = 'List allowlisted project settings (secrets are redacted)';

    public function handle(): int
    {
        $this->foldProjectOption();

        $project = trim((string) $this->option('username'));
        if ($project === '') {
            $this->error('--project is required.');

            return 1;
        }

        $user = User::findByUsername($project);
        if (! $user) {
            $this->error("Project '{$project}' not found.");

            return 1;
        }

        $rows = [];
        foreach ($user->project()->settings()->list() as $row) {
            $rows[] = [
                $row['key'],
                $row['set'] ? (string) $row['value'] : '-',
                $row['secret'] ? 'yes' : 'no',
                $row['set'] ? 'yes' : 'no',
            ];
        }

        $this->table(['Key', 'Value', 'Secret', 'Set'], $rows);

        return 0;
    }
}
