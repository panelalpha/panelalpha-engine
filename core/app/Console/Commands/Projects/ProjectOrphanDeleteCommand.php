<?php

namespace App\Console\Commands\Projects;

use App\Console\Commands\Concerns\ConfirmsChanges;
use App\Lib\Project\ProjectOrphans;
use Illuminate\Console\Command;

class ProjectOrphanDeleteCommand extends Command
{
    use ConfirmsChanges;

    protected $signature = 'project:orphan:delete {name : The name to free} {--force : Skip the confirmation}';

    protected $description = 'Remove what holds a name without a project: its container, account, home and project directory';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('Give at least one project name to free.');

            return 1;
        }

        if (! $this->option('force') && ! $this->canBeAsked()) {
            $this->error('Freeing a name without confirmation requires --force.');

            return 1;
        }

        if (! $this->option('force') && ! $this->confirm("Everything that holds '{$name}' will be removed, are you sure?")) {
            return 0;
        }

        try {
            app(ProjectOrphans::class)->delete($name);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info("'{$name}' is free to be a project again.");

        return 0;
    }
}
