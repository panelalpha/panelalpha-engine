<?php

namespace App\Console\Commands\Files;

use App\Console\Commands\Concerns\StreamsFileToOutput;
use App\Http\Requests\Files\DownloadRequest;
use App\Lib\Project\ProjectFiles;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class FilesDownloadCommand extends Command
{
    use StreamsFileToOutput;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['files:download'];

    protected $signature = 'project:file:download
                            {project : Project username}
                            {--path= : File path inside the project}
                            {--out= : Local file to write (default: stdout)}';

    protected $description = 'Download a file from a project';

    public function handle(): int
    {
        $project = (string) $this->argument('project');
        $path = (string) ($this->option('path') ?? '');
        $out = $this->option('out');

        if ($path === '') {
            $this->error('--path is required');
            return 1;
        }

        Validator::make(['path' => $path], (new DownloadRequest())->rules())->validate();
        $user = User::findByUsernameOrFail($project);
        $source = ProjectFiles::readablePathOrFail($user, $path);

        return $this->streamFile($source, is_string($out) && $out !== '' ? $out : null);
    }
}
