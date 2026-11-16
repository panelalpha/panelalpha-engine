<?php

namespace App\Console\Commands\Users;

use App\Exceptions\ProblemException;
use App\Exceptions\ProjectBusyException;
use App\Lib\Project\ProjectDeleter;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

class Delete extends Command
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:delete', 'users:delete'];

    protected $signature = 'project:delete {project?* : One or more project usernames} {--all : Every project on this engine} {--force : Skip confirmation prompts}';

    protected $description = 'Delete projects entirely: database rows, server config files and home directory';

    /**
     * Without a terminal confirm() answers no, so a non-interactive run needs
     * --force. Exits non-zero when anything was left behind, so uninstall.sh
     * never reports a clean removal over a half-deleted account.
     */
    public function handle(): int
    {
        $usernames = $this->argument('project');
        $forced = (bool) $this->option('force');

        if (! $forced && ! $this->input->isInteractive()) {
            $this->failLine('Deleting without confirmation requires --force.');

            return 1;
        }

        if ($this->option('all')) {
            if ($usernames !== []) {
                $this->failLine('Pass project names or --all, not both.');

                return 1;
            }

            $usernames = array_map(static fn (User $user): string => (string) $user->username, User::getAll());
            if ($usernames === []) {
                $this->info('No projects on this engine.');

                return 0;
            }

            if (! $this->confirmAll(count($usernames))) {
                return 0;
            }
            $forced = true;
        }

        if ($usernames === []) {
            $this->failLine('Name at least one project, or pass --all.');

            return 1;
        }

        $failed = false;
        foreach ($usernames as $username) {
            $failed = ! $this->deleteProject($username, $forced) || $failed;
        }

        return $failed ? 1 : 0;
    }

    /**
     * One question for the whole set rather than one per project: an operator
     * answering "yes" forty times stopped reading at three (uninstall.sh).
     */
    private function confirmAll(int $count): bool
    {
        return $this->option('force')
            || $this->confirm("All {$count} projects will be completely deleted from this server, are you sure?");
    }

    /** Delete one project after confirming; false when it could not be deleted. */
    private function deleteProject(string $username, bool $forced): bool
    {
        $user = User::findByUsername($username);
        if (! $user) {
            $this->failLine("Project `{$username}` not found in database.");

            return false;
        }

        if (! $forced && ! $this->confirm("Project `{$username}` will be completely deleted from server, are you sure?")) {
            return true;
        }

        try {
            app(ProjectDeleter::class)->delete($user);
            $this->info("Project `{$username}` deleted.");

            return true;
        } catch (ProjectBusyException $e) {
            $this->failLine($e->getMessage());

            return false;
        } catch (ProblemException $e) {
            $this->failLine((string) $e->validator->errors()->first());

            return false;
        } catch (\Throwable $e) {
            Log::warning(
                "Could not delete project '{$user->username}': ".$e->getMessage(),
                ['exception' => $e],
            );
            $this->failLine("ERROR deleting project `{$username}`: ".$e->getMessage());

            return false;
        }
    }

    /** A reason the operator can act on goes to stderr, as the handler prints one that propagates. */
    private function failLine(string $message): void
    {
        $out = $this->output->getOutput();
        $err = $out instanceof ConsoleOutputInterface ? $out->getErrorOutput() : $out;
        $err->writeln('<error>'.OutputFormatter::escape(trim($message)).'</error>');
    }
}
