<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use App\System\Privileged;
use App\System\Project;
use Illuminate\Console\Command;

class Attach extends Command
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:attach', 'users:attach'];

    protected $signature = 'project:attach {project : Project username} {--u= : User to run as inside the container (e.g. root)}';

    protected $description = 'Attach to a project\'s container with an interactive bash shell';

    public function handle(): int
    {
        $username = $this->argument('project');
        assert(is_string($username));

        $user = User::findByUsername($username);
        if (! $user) {
            $this->error("Project `{$username}` not found in database.");

            return 1;
        }

        $projectDir = $this->project($user)->projectDirPath();
        $composeFile = "{$projectDir}/docker-compose.yml";

        if (! file_exists($composeFile)) {
            $this->error("Compose file not found: {$composeFile}");

            return 1;
        }

        $isDind = $user->getTemplate() === 'dind';
        $serviceName = $isDind ? 'dind' : 'php';

        $homeDir = $user->getHomeDir();

        if ($isDind) {
            $workDir = "{$homeDir}/project";
        } else {
            $mainDomain = $user->getMainDomain();
            if (! $mainDomain) {
                $this->error("Project `{$username}` has no main domain.");

                return 1;
            }
            $workDir = $homeDir.$mainDomain->getDocumentRoot();
        }

        $runAs = $this->option('u');

        if (! is_string($runAs) || $runAs === '') {
            $uid = $user->getUid();
            $gid = $user->getGid();
            $runAs = $uid !== null && $gid !== null ? "{$uid}:{$gid}" : null;
        }

        $cmd = Privileged::compose($composeFile)->execWithTty($serviceName, ['bash'], user: $runAs, workDir: $workDir);

        $this->line("Attaching to <info>{$serviceName}</info> for project <info>{$username}</info> (workdir: {$workDir})...");

        return $this->runOnTerminal($cmd);
    }

    protected function project(User $user): Project
    {
        return $user->project();
    }

    /** @param  list<string>  $cmd */
    protected function runOnTerminal(array $cmd): int
    {
        $process = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes);

        if (! is_resource($process)) {
            $this->error('Failed to start process.');

            return 1;
        }

        $exitCode = proc_close($process);

        return $exitCode;
    }
}
