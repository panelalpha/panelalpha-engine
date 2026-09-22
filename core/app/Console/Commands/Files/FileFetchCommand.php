<?php

namespace App\Console\Commands\Files;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use Illuminate\Console\Command;

class FileFetchCommand extends Command
{
    use DispatchesApiRoute;

    protected $signature = 'project:file:fetch
                            {project : Project username}
                            {--url= : http or https URL}
                            {--path= : Destination directory inside the project}
                            {--filename= : Name to store, when the URL has none}';

    protected $description = 'Fetch an http or https URL into a project (POST /projects/{username}/files/fetch)';

    public function handle(): int
    {
        $project = (string) $this->argument('project');
        $url = (string) ($this->option('url') ?? '');
        $path = (string) ($this->option('path') ?? '');
        $filename = $this->option('filename');
        if ($url === '' || $path === '') {
            $this->error('--url and --path are required');

            return 1;
        }

        $params = [
            'url' => $url,
            'path' => $path,
        ];
        if (is_string($filename) && $filename !== '') {
            $params['filename'] = $filename;
        }

        $response = $this->dispatchApiRoute(
            'POST',
            '/projects/' . rawurlencode($project) . '/files/fetch',
            $params
        );

        if ($response->getStatusCode() >= 400) {
            $this->error($this->errorMessage($response));

            return 1;
        }

        $this->info('Fetched ' . $url . ' into ' . $path);

        return 0;
    }
}
