<?php

namespace App\Console\Commands\Files;

use App\Http\Requests\Files\FetchRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FileFetchCommand extends Command
{
    protected $signature = 'project:file:fetch
                            {project : Project username}
                            {--url= : http or https URL}
                            {--path= : Destination directory inside the project}
                            {--filename= : Name to store, when the URL has none}';

    protected $description = 'Fetch an http or https URL into a project';

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

        Validator::make($params, (new FetchRequest())->rules())->validate();
        $user = User::findByUsernameOrFail($project);
        $dir = $user->project()->resolvePath($path);

        try {
            $user->project()->fileManager()->fetch($url, $dir, $params['filename'] ?? null);
        } catch (\Exception $e) {
            // The file operation's own message says what to fix.
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $this->info('Fetched ' . $url . ' into ' . $path);

        return 0;
    }
}
