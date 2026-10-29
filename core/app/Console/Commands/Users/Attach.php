<?php

namespace App\Console\Commands\Users;

use App\Models\User;
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
            $this->error("User `{$username}` not found in database.");

            return 1;
        }

        $projectDir = $user->project()->projectDirPath();
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
                $this->error("User `{$username}` has no main domain.");

                return 1;
            }
            $workDir = $homeDir.$mainDomain->getDocumentRoot();
        }

        $runAs = $this->option('u');

        $cmd = ['sudo', 'docker', 'compose', '-f', $composeFile, 'exec'];

        if (is_string($runAs) && $runAs !== '') {
            $cmd[] = '-u';
            $cmd[] = $runAs;
            $cmd[] = '-w';
            $cmd[] = $workDir;
            $cmd[] = $serviceName;
            $cmd[] = 'bash';
        } else {
            $uid = $user->getUid();
            $gid = $user->getGid();
            if ($uid !== null && $gid !== null) {
                $cmd[] = '-u';
                $cmd[] = "{$uid}:{$gid}";
            }
            $cmd[] = '-w';
            $cmd[] = $workDir;
            $cmd[] = $serviceName;
            $cmd[] = 'bash';
        }

        $this->line("Attaching to <info>{$serviceName}</info> for user <info>{$username}</info> (workdir: {$workDir})...");

        $process = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes);

        if (! is_resource($process)) {
            $this->error('Failed to start process.');

            return 1;
        }

        $exitCode = proc_close($process);

        return $exitCode;
    }
}
