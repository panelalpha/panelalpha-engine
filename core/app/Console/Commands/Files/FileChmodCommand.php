<?php

namespace App\Console\Commands\Files;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use Illuminate\Console\Command;

class FileChmodCommand extends Command
{
    use DispatchesApiRoute;

    protected $signature = 'project:file:chmod
                            {project : Project username}
                            {--path= : File or directory inside the project}
                            {--mode= : Three or four octal digits, such as 755}';

    protected $description = 'Set the mode of a file or directory (PUT /projects/{username}/files/chmod)';

    public function handle(): int
    {
        $project = (string) $this->argument('project');
        $path = (string) ($this->option('path') ?? '');
        $mode = (string) ($this->option('mode') ?? '');
        if ($path === '' || $mode === '') {
            $this->error('--path and --mode are required');

            return 1;
        }

        $response = $this->dispatchApiRoute(
            'PUT',
            '/projects/' . rawurlencode($project) . '/files/chmod',
            [
                'path' => $path,
                'mode' => $mode,
            ]
        );

        if ($response->getStatusCode() >= 400) {
            $this->error($this->errorMessage($response));

            return 1;
        }

        $this->info('Set mode ' . $mode . ' on ' . $path);

        return 0;
    }
}
