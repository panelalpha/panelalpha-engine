<?php

namespace App\Console\Commands\Deploy;

use App\Console\Commands\Concerns\FollowsDeployLog;
use App\Console\Commands\Concerns\ParsesEnvVarOptions;
use App\Exceptions\DeployAlreadyRunningException;
use App\Http\Requests\RecipeChoiceInput;
use App\Lib\Deploy\EnvVarOverrides;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Project\ArchiveDrop;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Vault\RequestVault;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The CLI twin of POST /projects/{username}/deploy-archive. The operator names
 * a local archive; it is put into the project as the account, deployed in this
 * process with the log printed as it runs, and removed afterwards. The command
 * exits when the deploy has finished, and its exit code says how it ended.
 *
 * `pae` runs inside the core container, so the archive must be a path that
 * container can read (anything under /opt/panelalpha, for instance).
 */
class DeployArchiveCommand extends Command
{
    use FollowsDeployLog;
    use ParsesEnvVarOptions;

    /** The archive formats the import reads. */
    private const EXTENSIONS = ['.tar.gz', '.tgz', '.zip'];

    protected $signature = 'project:deploy-archive
                            {--project= : Project username}
                            {--file= : Local .zip or .tar.gz archive to deploy}
                            {--recipe= : Deploy with this recipe instead of the detected one}
                            {--env-var=* : KEY=VALUE environment override, repeatable}
                            {--quiet-deploy : Do not print the deploy log while it runs}';

    protected $description = 'Deploy a local zip or tar.gz archive into a container project, as POST deploy-archive does';

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
        app(RecipeChoiceContext::class)->set(RecipeChoiceInput::parse($this->option('recipe')));

        $zipPath = $drop->put($user, $file, (string) self::extension($file));
        try {
            $rebuild->assertArchive($user, $zipPath);
            $rebuild->whileIdle($user, fn () => $this->deploy($rebuild, $user, $zipPath, $envVars));
        } finally {
            $this->removeArchive($drop, $user, $zipPath);
        }

        $this->info("Deployed {$file} to {$user->username}.");

        return 0;
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
        if (!is_file($file) || !is_readable($file)) {
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

    /** @param array<string, string> $envVars */
    private function deploy(ProjectRebuild $rebuild, User $user, string $zipPath, array $envVars): void
    {
        try {
            $logger = $rebuild->openLog($user, ProjectRebuild::DEPLOY_ARCHIVE);
        } catch (DeployAlreadyRunningException) {
            throw new \RuntimeException("A deploy of {$user->username} is already running.");
        }
        $this->storeEnvVars($user, $envVars);

        $this->followDeployLog();
        try {
            $rebuild->run($user, ProjectRebuild::DEPLOY_ARCHIVE, $logger, $zipPath);
        } finally {
            $this->stopFollowingDeployLog();
        }
    }

    /** @param array<string, string> $envVars */
    private function storeEnvVars(User $user, array $envVars): void
    {
        if ($envVars === []) {
            return;
        }

        $resolved = RequestVault::resolve('env_vars', $envVars, $user->username);
        $user->setDetails(['env_vars' => EnvVarOverrides::applyIncoming($resolved, $user->getEnvVars())]);
        $user->save();
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
