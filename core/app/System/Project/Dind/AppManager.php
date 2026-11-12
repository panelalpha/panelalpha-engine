<?php

namespace App\System\Project\Dind;

use App\Exceptions\NotFoundException;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * App-level management (users, roles, install, info) for a deployed DinD project,
 * delegated to overrides/app.sh via the app config CLI contract.
 */
class AppManager
{
    /** Frozen at deploy: false when the project has an app config that is written for another stack than it ran. */
    public const DETAIL_APP_CONFIG_FITS = 'deploy_app_config_fits';

    private const UNSUPPORTED = 'App management is not supported for this application';

    /** @var list<string>|null the script's `info` answer, read once per manager. */
    private ?array $advertised = null;

    public function __construct(
        private DindProject $dind,
    ) {}

    /**
     * @return array<int, array{id: string, username: string, email: string, role: string}>
     */
    public function listUsers(): array
    {
        return json_decode($this->run('users:list'), true) ?? [];
    }

    /**
     * @return array{id: string}
     */
    public function addUser(string $login, string $email, string $password, string $role): array
    {
        return json_decode($this->run('users:add', $login, $email, $password, $role), true) ?? [];
    }

    /**
     * Delete an app user, handing `$reassign` their content; omitted, the app picks a default.
     */
    public function deleteUser(string $userId, ?string $reassign = null): void
    {
        $this->assertUserExists($userId);

        $args = [$userId];
        if ($reassign !== null && $reassign !== '') {
            $args[] = $reassign;
        }

        $this->run('users:delete', ...$args);
    }

    public function resetUserPassword(string $userId, string $password): void
    {
        $this->run('users:reset-password', $userId, $password);
    }

    /**
     * @return array<string, mixed>
     */
    public function ssoCredentials(string $userId): array
    {
        return json_decode($this->run('users:sso', $userId), true) ?? [];
    }

    public function install(
        string $url,
        string $title,
        string $adminUser,
        string $adminEmail,
        string $adminPassword
    ): void {
        $this->run('install', $url, $title, $adminUser, $adminEmail, $adminPassword);
    }

    /**
     * @return list<string>
     */
    public function info(): array
    {
        /** @var list<string> $info */
        $info = json_decode($this->run('info'), true) ?? [];

        return $info;
    }

    /**
     * @return string[]
     */
    public function listRoles(): array
    {
        return json_decode($this->run('roles:list'), true) ?? [];
    }

    /**
     * An app deletes an unknown id as if it had removed a user, so the id is checked against the
     * list first and a missing one is a 404 rather than a delete that removed nothing.
     */
    private function assertUserExists(string $userId): void
    {
        $ids = array_map('strval', array_column($this->listUsers(), 'id'));
        if (in_array($userId, $ids, true)) {
            return;
        }

        throw new NotFoundException("App user '{$userId}' not found.");
    }

    private function scriptPath(): string
    {
        return $this->dind->userAppDirPath().'/'.AppConfig::APP_SCRIPT;
    }

    /**
     * The script as the account runs it, given the deploy's own compose files: a bare `docker compose`
     * in a recipe script finds no file, since the engine's run file is docker-compose.panelalpha.yml.
     *
     * @return list<string>
     */
    public function scriptCommand(string ...$args): array
    {
        $env = [];
        foreach ($this->dind->userAppComposeEnv() as $name => $value) {
            $env[] = $name.'='.$value;
        }

        return ['env', ...$env, 'bash', $this->scriptPath(), ...$args];
    }

    /**
     * Refuses an unsupported command before it runs: refusing after it ran would report
     * a users:add or users:delete that already happened as a failure.
     */
    private function run(string ...$args): string
    {
        $this->refuseOnAnotherStack();
        if ($args !== []) {
            $this->refuseUnsupported($args[0]);
        }

        $process = $this->runRecovering($this->scriptPath(), $this->dind->shell(), $this->scriptCommand(...$args));
        if ($process->getExitCode() !== 0) {
            $this->throwFailure($args[0] ?? '', $process);
        }

        return $process->getOutput();
    }

    /**
     * Runs the script, installing it or the file snippets and running it again when either is missing.
     *
     * @param  list<string>  $command
     */
    private function runRecovering(string $scriptPath, ShellOperations $shell, array $command): Process
    {
        $process = $shell->runProcessAsUser($command);

        if (self::failedWith($process, $scriptPath.': no such file')) {
            $this->installScript($scriptPath);
            $process = $shell->runProcessAsUser($command);
        }

        if (self::failedWith($process, 'MISSING_SNIPPET')) {
            $this->dind->installFileSnippets();
            $process = $shell->runProcessAsUser($command);
        }

        return $process;
    }

    private static function failedWith(Process $process, string $errorText): bool
    {
        return $process->getExitCode() !== 0 && Str::contains($process->getErrorOutput(), $errorText, true);
    }

    /** Logs only the action, since arguments may carry passwords, and only output that is not the script's own error. */
    private function throwFailure(string $action, Process $process): never
    {
        $output = $process->getErrorOutput() ?: $process->getOutput();
        if (self::scriptError($output) === null) {
            Log::warning(sprintf(
                'App management %s failed for %s: %s',
                $action,
                $this->dind->username(),
                trim($output)
            ));
        }
        throw new \Exception(self::failureMessage($output));
    }

    /**
     * The commands the script advertised via `info`, cached for this manager.
     *
     * @return list<string>
     */
    private function advertised(): array
    {
        return $this->advertised ??= $this->readAdvertised();
    }

    /**
     * An `info` that fails or is not a list of names says nothing, which allows every command.
     *
     * @return list<string>
     */
    private function readAdvertised(): array
    {
        try {
            $info = $this->info();
        } catch (\Exception) {
            $info = [];
        }

        return array_is_list($info) && array_filter($info, 'is_string') === $info ? $info : [];
    }

    /**
     * The app script names the services of the stack its app config is written for, which a deploy pinned to another
     * recipe does not run, so on that stack no app script is run, not even one an earlier deploy left.
     */
    private function refuseOnAnotherStack(): void
    {
        if (($this->dind->userModel()->getDetails()[self::DETAIL_APP_CONFIG_FITS] ?? null) === false) {
            throw new \Exception(self::UNSUPPORTED);
        }
    }

    /** Refuse a `users:*`, `install` or `roles:list` command the script's `info` does not list. */
    private function refuseUnsupported(string $command): void
    {
        if (! str_starts_with($command, 'users:') && $command !== 'install' && $command !== 'roles:list') {
            return;
        }

        $advertised = $this->advertised();
        if ($advertised === [] || in_array($command, $advertised, true)) {
            return;
        }

        throw new \Exception(
            "The application does not support the '{$command}' command. Its app script lists: "
            .implode(', ', $advertised).'.'
        );
    }

    /**
     * What the caller is told: the script contract's {"error": "..."}; anything else is docker
     * or shell output, which stays in the log.
     */
    public static function failureMessage(string $output): string
    {
        $error = self::scriptError($output);
        if ($error !== null) {
            return $error;
        }
        if (preg_match('/no configuration file provided|is not running|no such container|no such service/i', $output)) {
            return 'The application is not running. Deploy or start the project, then try again.';
        }

        return 'The application\'s management command failed. The engine log has the details.';
    }

    /** The script's own {"error": ...}, whole or as one line among other output. */
    private static function scriptError(string $output): ?string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        foreach ([trim($output), ...array_reverse($lines)] as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded) && is_string($decoded['error'] ?? null) && $decoded['error'] !== '') {
                return $decoded['error'];
            }
        }

        return null;
    }

    private function installScript(string $scriptPath): void
    {
        $script = $this->dind
            ->appConfig($this->dind->userModel()->getGitRepo())
            ?->appScript();
        if ($script === null) {
            throw new \Exception(self::UNSUPPORTED);
        }
        $this->dind->system()->filesystem()->writeFileReplacingPath(
            $scriptPath,
            $script,
            $this->dind->userModel()->getChownString(),
            '700'
        );
    }
}
