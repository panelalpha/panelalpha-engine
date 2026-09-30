<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * App-level management (users, roles, install, info) for a deployed DinD project.
 *
 * Delegates to overrides/app.sh via the app config CLI contract.
 */
class AppManager
{
    public function __construct(
        private DindProject $dind,
    ) {
    }

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

    public function deleteUser(string $userId): void
    {
        $this->run('users:delete', $userId);
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
     * @return string[]
     */
    public function info(): array
    {
        return json_decode($this->run('info'), true) ?? [];
    }

    /**
     * @return string[]
     */
    public function listRoles(): array
    {
        return json_decode($this->run('roles:list'), true) ?? [];
    }

    private function scriptPath(): string
    {
        return $this->dind->userAppDirPath() . '/' . AppConfig::APP_SCRIPT;
    }

    /**
     * The script as the account runs it. Recipe scripts call a bare
     * `docker compose`, which on its own finds no file: the engine's run file
     * is docker-compose.panelalpha.yml, so hand them the deploy's own files.
     *
     * @return list<string>
     */
    public function scriptCommand(string ...$args): array
    {
        $env = [];
        foreach ($this->dind->userAppComposeEnv() as $name => $value) {
            $env[] = $name . '=' . $value;
        }

        return ['env', ...$env, 'bash', $this->scriptPath(), ...$args];
    }

    private function run(string ...$args): string
    {
        $scriptPath = $this->scriptPath();
        $shell = $this->dind->shell();
        $command = $this->scriptCommand(...$args);

        $process = $shell->runProcessAsUser($command);

        if (
            $process->getExitCode() !== 0
            && Str::contains($process->getErrorOutput(), $scriptPath . ': no such file', true)
        ) {
            $this->installScript($scriptPath);
            $process = $shell->runProcessAsUser($command);
        }

        if (
            $process->getExitCode() !== 0
            && Str::contains($process->getErrorOutput(), 'MISSING_SNIPPET', true)
        ) {
            $this->dind->installFileSnippets();
            $process = $shell->runProcessAsUser($command);
        }

        if ($process->getExitCode() !== 0) {
            $output = $process->getErrorOutput() ?: $process->getOutput();
            if (self::scriptError($output) === null) {
                // Arguments may carry passwords: log the action only.
                Log::warning(sprintf(
                    'App management %s failed for %s: %s',
                    $args[0] ?? '',
                    $this->dind->username(),
                    trim($output)
                ));
            }
            throw new \Exception(self::failureMessage($output));
        }

        return $process->getOutput();
    }

    /**
     * What the caller is told. The script contract reports errors as
     * {"error": "..."}; anything else is docker or shell output, which stays
     * in the log.
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
            throw new \Exception('App management is not supported for this application');
        }
        $this->dind->system()->filesystem()->filePutContents(
            $scriptPath,
            $script,
            $this->dind->userModel()->getChownString(),
            '700'
        );
    }
}
