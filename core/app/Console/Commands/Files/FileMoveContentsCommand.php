<?php

namespace App\Console\Commands\Files;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use Illuminate\Console\Command;

class FileMoveContentsCommand extends Command
{
    use DispatchesApiRoute;

    protected $signature = 'project:file:move-contents
                            {project : Project username}
                            {--source= : Directory whose children are moved}
                            {--dest= : Existing destination directory}
                            {--override=1 : Overwrite a colliding child (1 or 0)}';

    protected $description = 'Move the immediate children of a directory (POST /projects/{username}/files/move-contents)';

    public function handle(): int
    {
        $project = (string) $this->argument('project');
        $source = (string) ($this->option('source') ?? '');
        $dest = (string) ($this->option('dest') ?? '');
        if ($source === '' || $dest === '') {
            $this->error('--source and --dest are required');

            return 1;
        }

        $override = $this->option('override');
        $response = $this->dispatchApiRoute(
            'POST',
            '/projects/' . rawurlencode($project) . '/files/move-contents',
            [
                'source_path' => $source,
                'dest_path' => $dest,
                'override' => filter_var($override, FILTER_VALIDATE_BOOLEAN),
            ]
        );

        if ($response->getStatusCode() >= 400) {
            $this->error($this->errorMessage($response));

            return 1;
        }

        $this->info('Moved children of ' . $source . ' into ' . $dest);

        return 0;
    }
}
