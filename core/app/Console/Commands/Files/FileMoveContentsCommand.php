<?php

namespace App\Console\Commands\Files;

use App\Http\Requests\Files\MoveContentsRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FileMoveContentsCommand extends Command
{
    protected $signature = 'project:file:move-contents
                            {project : Project username}
                            {--source= : Directory whose children are moved}
                            {--dest= : Existing destination directory}
                            {--override=1 : Overwrite a colliding child (1 or 0)}';

    protected $description = 'Move the immediate children of a directory';

    public function handle(): int
    {
        $project = (string) $this->argument('project');
        $source = (string) ($this->option('source') ?? '');
        $dest = (string) ($this->option('dest') ?? '');
        if ($source === '' || $dest === '') {
            $this->error('--source and --dest are required');

            return 1;
        }

        $override = filter_var($this->option('override'), FILTER_VALIDATE_BOOLEAN);
        Validator::make(
            ['source_path' => $source, 'dest_path' => $dest, 'override' => $override],
            (new MoveContentsRequest())->rules()
        )->validate();
        $user = User::findByUsernameOrFail($project);
        $from = $user->project()->resolvePath($source);
        $to = $user->project()->resolvePath($dest);

        try {
            $user->project()->fileManager()->moveDirectoryContents($from, $to, $override);
        } catch (\Exception $e) {
            // The file operation's own message says what to fix.
            throw ValidationException::withMessages(['dest_path' => $e->getMessage()]);
        }

        $this->info('Moved children of ' . $source . ' into ' . $dest);

        return 0;
    }
}
