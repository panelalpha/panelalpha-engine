<?php

namespace App\Console\Commands\Deploy;

use App\Console\Commands\Concerns\ParsesEnvVarOptions;
use App\Http\Requests\RecipeChoiceInput;
use App\Lib\Deploy\DeployLog\DeployLineFormat;
use App\Lib\Deploy\DeployLog\DeployLogFollower;
use App\Lib\Project\ArchiveDrop;
use App\Lib\Project\ProjectRebuild;
use App\Models\Task;
use App\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The CLI twin of POST deploy-archive, following the worker's log until the task ends or --timeout passes.
 * The archive must be a path the core container can read (/opt/panelalpha).
 */
class DeployArchiveCommand extends Command
{
    use ParsesEnvVarOptions;

    /** The archive formats the import reads. */
    private const EXTENSIONS = ['.tar.gz', '.tgz', '.zip'];

    /** 128 + SIGINT, as a shell reports a command Ctrl-C stopped. */
    private const INTERRUPTED = 130;

    protected $signature = 'project:deploy-archive
                            {--project= : Project username}
                            {--file= : Local .zip or .tar.gz archive to deploy}
                            {--recipe= : Deploy with this recipe instead of the detected one}
                            {--env-var=* : KEY=VALUE environment override, repeatable}
                            {--no-wait : Queue the deploy, print its task id and return}
                            {--quiet-deploy : Do not print the deploy log while waiting for it}
                            {--timeout=1800 : Seconds to wait for the deploy, a queue worker included, before giving up on watching it}';

    protected $description = 'Deploy a local zip or tar.gz archive into a container project, queued as POST deploy-archive queues it';

    public function handle(ProjectRebuild $rebuild, ArchiveDrop $drop): int
    {
        $user = $this->project();
        $file = $this->archiveFile();
        $envVars = $this->envVarOptions();
        if ($user === null || $file === null || $envVars === null) {
            return 1;
        }

        ProjectRebuild::refuseArchiveOnClassicProject($user);
        ProjectRebuild::refuseArchiveOnGitProject($user);
        $recipe = RecipeChoiceInput::parse($this->option('recipe'));

        $task = $this->queue($rebuild, $drop, $user, $file, $envVars, $recipe);
        $this->line("Queued the deploy of {$file} to {$user->username} as task {$task->id}.");
        if ($this->option('no-wait')) {
            return 0;
        }

        return $this->await($task, $file, $user);
    }

    private function project(): ?User
    {
        $username = trim((string) $this->option('project'));
        if ($username === '') {
            $this->error('--project is required');

            return null;
        }

        return User::findByUsernameOrFail($username);
    }

    private function archiveFile(): ?string
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $this->error('--file is required (the .zip or .tar.gz archive to deploy)');

            return null;
        }
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Archive not found or not readable: {$file}");

            return null;
        }
        if (self::extension($file) === null) {
            $this->error("Unsupported archive format, expected .zip or .tar.gz: {$file}");

            return null;
        }

        return $file;
    }

    /** The archive's format suffix, `.tar.gz` kept whole; null for a format the import does not read. */
    private static function extension(string $file): ?string
    {
        $base = strtolower(basename($file));
        foreach (self::EXTENSIONS as $extension) {
            if (str_ends_with($base, $extension)) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * The archive is the job's once the deploy is queued; until then it is removed here if anything refuses it.
     *
     * @param  array<string, string>  $envVars
     */
    private function queue(ProjectRebuild $rebuild, ArchiveDrop $drop, User $user, string $file, array $envVars, ?string $recipe): Task
    {
        $zipPath = $drop->put($user, $file, basename($file));
        try {
            $rebuild->assertArchive($user, $zipPath);

            return $rebuild->whileIdle($user, function () use ($rebuild, $user, $envVars, $zipPath, $recipe): Task {
                $rebuild->storeEnvVars($user, $envVars === [] ? null : ProjectRebuild::sentEnvVars($user, $envVars), $envVars);

                return $rebuild->queue($user, ProjectRebuild::DEPLOY_ARCHIVE, $zipPath, null, $recipe, ownsArchive: true);
            });
        } catch (\Throwable $e) {
            $this->removeArchive($drop, $user, $zipPath);
            throw $e;
        }
    }

    private function await(Task $task, string $file, User $user): int
    {
        $this->stopFollowingOnInterrupt($task);
        $timeout = max(1, (int) $this->option('timeout'));
        $task = (new DeployLogFollower($this->deployLineWriter()))->followTask($task, $timeout, $this->waitingForWorker(...));

        if (! $task->isTerminal()) {
            $this->errorOutput()->writeln("<error>Not finished after {$timeout}s; stopped following. The deploy goes on as task {$task->id}.</error>");

            return 1;
        }
        if ($task->status === Task::STATUS_COMPLETED) {
            $this->info("Deployed {$file} to {$user->username}.");

            return 0;
        }
        $this->errorOutput()->writeln('<error>'.OutputFormatter::escape(self::failure($task)).'</error>');

        return 1;
    }

    private function waitingForWorker(Task $task): void
    {
        $this->line("Waiting for a queue worker to start task {$task->id}.");
    }

    /** @return \Closure(array{ts?: int, level?: string, msg?: string}, int): void */
    private function deployLineWriter(): \Closure
    {
        if ($this->option('quiet-deploy')) {
            return static function (): void {};
        }
        $verbose = $this->output->isVerbose();

        return function (array $line, int $startedAt) use ($verbose): void {
            $rendered = DeployLineFormat::line($line, $startedAt, $verbose);
            if ($rendered !== null) {
                $this->output->writeln($rendered);
            }
        };
    }

    /** The task's error on one line, or what ended it when it carries none. */
    private static function failure(Task $task): string
    {
        $error = trim((string) preg_replace('/\s+/', ' ', (string) ($task->details['error'] ?? '')));
        if ($error !== '') {
            return $error;
        }

        return $task->status === Task::STATUS_CANCELLED ? 'The deploy was cancelled.' : 'The deploy failed.';
    }

    /** The deploy is the worker's: Ctrl-C stops only the printing. Needs pcntl, which core's php-cli has. */
    private function stopFollowingOnInterrupt(Task $task): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        $this->trap([\SIGINT], function () use ($task): void {
            $this->newLine();
            $this->warn("Stopped following. The deploy goes on as task {$task->id}.");
            exit(self::INTERRUPTED);
        });
    }

    private function errorOutput(): OutputInterface
    {
        $out = $this->output->getOutput();

        return $out instanceof ConsoleOutputInterface ? $out->getErrorOutput() : $out;
    }

    private function removeArchive(ArchiveDrop $drop, User $user, string $zipPath): void
    {
        try {
            $drop->remove($user, $zipPath);
        } catch (\Exception) {
            $this->warn("The archive {$zipPath} could not be removed from the project.");
        }
    }
}
