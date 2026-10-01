<?php

namespace App\Console\Commands\Files;

use App\Http\Requests\Files\ChmodRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FileChmodCommand extends Command
{
    protected $signature = 'project:file:chmod
                            {project : Project username}
                            {--path= : File or directory inside the project}
                            {--mode= : Three or four octal digits, such as 755}';

    protected $description = 'Set the mode of a file or directory';

    public function handle(): int
    {
        $project = (string) $this->argument('project');
        $path = (string) ($this->option('path') ?? '');
        $mode = (string) ($this->option('mode') ?? '');
        if ($path === '' || $mode === '') {
            $this->error('--path and --mode are required');

            return 1;
        }

        Validator::make(['path' => $path, 'mode' => $mode], (new ChmodRequest())->rules())->validate();
        $user = User::findByUsernameOrFail($project);
        $resolved = $user->project()->resolvePath($path);

        try {
            $user->project()->fileManager()->chmod($resolved, $mode);
        } catch (\Exception $e) {
            // The file operation's own message says what to fix.
            throw ValidationException::withMessages(['path' => $e->getMessage()]);
        }

        $this->info('Set mode ' . $mode . ' on ' . $path);

        return 0;
    }
}
