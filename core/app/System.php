<?php

namespace App;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User as ModelsUser;
use App\System\EnginePaths;
use App\System\EngineUpdate;
use App\System\Filesystem;
use App\System\HostProcess;
use App\System\Network;
use App\System\Project as SystemProject;
use App\System\ProcessRunner;
use App\System\Projects;
use App\System\UsernamePolicy;
use App\System\Services\Exim;
use App\System\Services\Modsec;
use App\System\Services\Mysql;
use App\System\Services\Php;
use App\System\Services\PureFtpd;
use App\System\Services\Sftp;
use App\System\Services\Webserver;
use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class System implements ProcessRunner
{
    /**
     * The two roots every other path derives from. Kept as methods rather than
     * constants because 44 test files subclass System to redirect them at a
     * temporary tree, and every derived path below has to follow.
     */
    public function homesDirPath(): string
    {
        return EnginePaths::HOMES_DIR;
    }

    public function engineDirPath(): string
    {
        return EnginePaths::ENGINE_DIR;
    }

    /** Built from this instance's roots, so a subclass's override still wins. */
    public function paths(): EnginePaths
    {
        return new EnginePaths($this->engineDirPath(), $this->homesDirPath());
    }

    public function projectsDirPath(): string
    {
        return $this->paths()->projectsDir();
    }

    public function templatesDirPath(): string
    {
        return $this->paths()->templatesDir();
    }

    public function projectFilesTemplateDirPath(?string $template = null): string
    {
        return $this->paths()->projectFilesTemplateDir($template);
    }

    public function projectDomainTemplateDirPath(?string $template = null): string
    {
        return $this->paths()->projectDomainTemplateDir($template);
    }

    public function projectHomeTemplateDirPath(?string $template = null): string
    {
        return $this->paths()->projectHomeTemplateDir($template);
    }

    public function composeFilePath(): string
    {
        return $this->paths()->composeFile();
    }

    public function projectDirPath(string $username): string
    {
        return $this->paths()->projectDir($username);
    }

    public function projectHomeDirPath(string $username): string
    {
        return $this->paths()->projectHomeDir($username);
    }

    public function filesystem(): Filesystem
    {
        return new Filesystem($this);
    }

    public function directoryExists(string $path): bool
    {
        return $this->filesystem()->directoryExists($path);
    }

    public function fileExists(string $path): bool
    {
        return $this->filesystem()->fileExists($path);
    }

    public function fileGetContents(string $path): string
    {
        return $this->filesystem()->fileGetContents($path);
    }

    public function filePutContents(
        string $path,
        string $contents,
        ?string $chown = null,
        ?string $chmod = null,
    ): void {
        $this->filesystem()->filePutContents($path, $contents, $chown, $chmod);
    }

    public function makeDirWithParents(string $target, ?string $chown = null): void
    {
        $this->filesystem()->makeDirWithParents($target, $chown);
    }

    public function network(): Network
    {
        return new Network($this);
    }

    public function php(): Php
    {
        return new Php($this);
    }

    public function webserver(): Webserver
    {
        return new Webserver($this);
    }

    public function modsec(): Modsec
    {
        return new Modsec($this);
    }

    public function mysql(): Mysql
    {
        return new Mysql($this);
    }

    public function ftp(): PureFtpd
    {
        return new PureFtpd($this);
    }

    public function sftp(): Sftp
    {
        return new Sftp($this);
    }

    public function exim(): Exim
    {
        return new Exim($this);
    }

    /**
     * @return ?array{
     *   started_at: ?int,
     *   finished_at: ?int,
     *   pid: ?int,
     *   exit_code: ?int,
     *   tail_stdout: ?string,
     *   tail_stderr: ?string,
     *   from_version: ?string,
     *   to_version: ?string,
     *   logs_path: string
     * }
     */
    public function getLatestChangeWebserverInfo(): ?array
    {
        return $this->webserver()->getLatestChangeWebserverInfo();
    }

    public function runChangeWebserverScript(string $newWebserver, ?string $serialNumber = null): void
    {
        $this->webserver()->runChangeWebserverScript($newWebserver, $serialNumber);
    }

    public function isChangeWebserverScriptRunning(): bool
    {
        return $this->webserver()->isChangeWebserverScriptRunning();
    }

    /**
     * Render every domain's config and apply it, reporting whether the reload
     * reached the webserver.
     *
     * @return bool false when the proxy was down and the reload is pending in the background
     */
    public function rebuildDomains(): bool
    {
        $this->webserver()->rebuildConfig();

        return $this->reloadWebserver();
    }

    /**
     * Reload the webserver, deferring when its container is not running. The config
     * on disk is already rendered, so a proxy that comes back loads it anyway.
     *
     * @return bool false when the reload was scheduled in the background instead
     */
    public function reloadWebserver(): bool
    {
        try {
            $this->webserver()->reload();
            return true;
        } catch (DockerErrorException $e) {
            if (!$e->isContainerUnavailable()) {
                throw $e;
            }
            Log::warning('Webserver container is not running; reload scheduled in the background', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->webserver()->scheduleWebserverReloadInBackground();
        } catch (\Throwable $scheduleError) {
            Log::warning('Could not schedule a background webserver reload: ' . $scheduleError->getMessage());
        }

        return false;
    }

    public function project(ModelsUser $model): SystemProject
    {
        return new SystemProject($this, $model);
    }

    public function projects(): Projects
    {
        return new Projects($this);
    }

    /**
     * Whether this host could take a project by that name: the name itself has
     * to be acceptable ({@see UsernamePolicy}) and nothing here may hold it
     * already -- an OS user, a home directory, or a project directory.
     */
    public function isUsernameAvailable(string $username): bool
    {
        return UsernamePolicy::isAcceptable($username)
            && !$this->isUidExists($username)
            && !is_dir($this->projectHomeDirPath($username))
            && !is_dir($this->projectDirPath($username))
            && !$this->isContainerNameTaken($username);
    }

    /**
     * A host container of that name, in any state. The account's DinD container
     * is named after the account, so `compose up` would fail on the conflict.
     */
    public function isContainerNameTaken(string $name): bool
    {
        $result = $this->runProcess([
            'sudo', 'docker', 'ps', '-a', '--filter', 'name=^/' . preg_quote($name, null) . '$', '--format', '{{.Names}}',
        ]);

        return $result->isSuccessful() && trim($result->getOutput()) !== '';
    }

    public function isUidExists(string $username): bool
    {
        $result = $this->runProcessOnHost([
            'id',
            '-u',
            $username,
        ]);

        return $result->getExitCode() === 0;
    }

    public function isComposeServiceRunning(string $service): bool
    {
        $command = "sudo docker compose -f {$this->composeFilePath()} ps --services --filter status=running";
        $result = $this->exec($command);
        $result = trim($result);
        $services = explode("\n", $result);
        if (in_array($service, $services)) {
            return true;
        }
        return false;
    }

    // --- HOST PROCESSES ---

    public function processes(): HostProcess
    {
        return new HostProcess($this);
    }

    /**
     * Run to completion and return stdout, or throw.
     *
     * These five stay on System, and keep calling each other through $this,
     * because about thirty test files override one or another of them to keep a
     * test from running a real command -- some override only exec(), some only
     * runProcess(). {@see HostProcess}
     *
     * @param string|list<string> $cmd
     * @throws DockerErrorException
     * @throws Exception
     */
    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        return $this->processes()->exec($cmd, $env, $timeout);
    }

    /**
     * @param string|list<string> $cmd
     * @throws DockerErrorException
     */
    public function execOnHost(string|array $cmd, array $env = []): string
    {
        return $this->exec(HostProcess::onHost($cmd), $env);
    }

    /**
     * @param string|list<string> $cmd
     */
    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return $this->processes()->run($cmd, $env, $timeout);
    }

    /**
     * @param string|list<string> $cmd
     */
    public function runProcessWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?StepWatchdog $watchdog = null
    ): Process {
        return $this->processes()->runWithCallbacks($cmd, $env, $timeout, $onStart, $onOutput, $watchdog);
    }

    /**
     * @param string|list<string> $cmd
     */
    public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return $this->runProcess(HostProcess::onHost($cmd), $env, $timeout);
    }

    public function getEnv(): array
    {
        $lines = file($this->engineDirPath() . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (strpos($line, '=') !== false) {
                /** @psalm-suppress PossiblyUndefinedArrayOffset */
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                $env[$key] = $value;
            }
        }

        return $env;
    }

    // --- UPDATE ENGINE ---

    public function update(): EngineUpdate
    {
        return new EngineUpdate($this);
    }

    /**
     * @return ?array{
     *   started_at: ?int,
     *   finished_at: ?int,
     *   pid: ?int,
     *   exit_code: ?int,
     *   tail_stdout: ?string,
     *   tail_stderr: ?string,
     *   from_version: ?string,
     *   to_version: ?string,
     *   logs_path: string
     * }
     */
    public function getLatestUpdateInfo(): ?array
    {
        return $this->update()->latest();
    }

    public function runUpdateScript(?string $licenseKey = null): void
    {
        $this->update()->run($licenseKey);
    }

    public function isUpdateScriptRunning(): bool
    {
        return $this->update()->isRunning();
    }
}
