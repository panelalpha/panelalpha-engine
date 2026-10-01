<?php

namespace App\Console\Commands\Files;

use App\Http\Requests\Files\UploadRequest;
use App\Lib\Project\ProjectFiles;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FilesUploadCommand extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['files:upload'];

    protected $signature = 'project:file:upload
                            {project : Project username}
                            {file : Local file to upload}
                            {--path= : Destination directory inside the project}';

    protected $description = 'Upload a local file into a project';

    public function handle(): int
    {
        $project = (string) $this->argument('project');
        $file = (string) $this->argument('file');
        $path = (string) ($this->option('path') ?? '');

        if ($path === '') {
            $this->error('--path is required (the destination directory inside the project)');
            return 1;
        }
        if (!is_file($file)) {
            $this->error("Local file not found: {$file}");
            return 1;
        }

        // Test mode skips the is_uploaded_file() check, which only ever holds
        // for a real POST through PHP-FPM.
        $upload = new UploadedFile($file, basename($file), null, null, true);

        Validator::make(['path' => $path, 'file' => $upload], (new UploadRequest())->rules())->validate();
        $user = User::findByUsernameOrFail($project);
        $dir = $user->project()->resolvePath($path);

        try {
            ProjectFiles::upload($user, $dir, $upload);
        } catch (\Exception $e) {
            // The file operation's own message says what to fix.
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $this->info(sprintf('Uploaded %s to %s', basename($file), rtrim($path, '/') . '/' . basename($file)));
        return 0;
    }
}
