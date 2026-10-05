<?php

namespace App\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System\ProcessRunner;
use App\System\Project\PhpHosting;

final class FpmStack implements PhpStack
{
    use FpmPoolSettings;

    /** restartFpmScript() exit status: the runner has no script for this version. */
    public const EXIT_NOT_MANAGED = 3;

    public function __construct(
        private ProcessRunner $system,
        private ModelsUser $model,
    ) {
    }

    public function dockerfileTemplateName(): string
    {
        return 'Dockerfile-fpm';
    }

    public function composeTemplateName(): string
    {
        return 'docker-compose.yml-fpm';
    }

    public function applySettings(PhpHosting $project): void
    {
        $this->applyPhpFpmSettings($project);
        (new EnvironmentSetup())->applyRedisSettings($project);
    }

    public function entrypointInitScripts(PhpHosting $project): array
    {
        return [];
    }

    public function entrypointBackgroundScripts(PhpHosting $project): array
    {
        return $this->getEntrypointPhpScripts();
    }

    public function waitForAllRunning(PhpHosting $project, int $tries = 12, int $intervalSeconds = 5): void
    {
    }

    public function restartPhpHandler(PhpHosting $project, string $phpVersion): void
    {
        $process = $this->system->runProcess([
            'sudo',
            'docker',
            'compose',
            '-f',
            $project->composeFilePath(),
            'exec',
            '-T',
            'php',
            'bash',
            '-c',
            S6Services::manages($project) ? self::restartFpmS6Script($phpVersion) : self::restartFpmScript($phpVersion),
        ]);
        if ($process->getExitCode() === self::EXIT_NOT_MANAGED) {
            throw new PhpHandlerNotRunning("php-fpm{$phpVersion} is not a service of {$project->username()}");
        }
        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException("Could not restart php-fpm{$phpVersion}: {$message}");
        }
        $output = $process->isStarted() ? trim($process->getOutput()) : '';
        if (str_contains($output, 'the runner did not start')) {
            \Illuminate\Support\Facades\Log::warning("{$project->username()}: {$output}");
        }
    }

    /**
     * The runner only kills the PID it wrote itself. `service phpX-fpm restart`
     * left a daemonised master it never saw holding the socket, so the fresh
     * `-F` instance died on "Another FPM instance seems to already listen" and
     * the old master kept the old php.ini. Nothing starts one that way any more;
     * a master still running after the runner's stop is that leftover, so it is
     * stopped by process title (these images write no FPM pid file) and named on
     * stdout, which the caller logs.
     */
    public static function restartFpmScript(string $phpVersion): string
    {
        $name = 'php-fpm' . self::version($phpVersion);
        $notManaged = self::EXIT_NOT_MANAGED;
        $master = self::masterPattern($phpVersion);
        $started = self::waitForMaster($phpVersion);

        return <<<BASH
            [ -f /entrypoint.d/{$name}.sh ] || exit {$notManaged}
            bash /entrypoint-runner.sh stop {$name} >/dev/null
            stray=\$(pgrep -d ' ' -f '{$master}')
            if [ -n "\$stray" ]; then
                echo "stopped a {$name} master the runner did not start: \$stray"
                pkill -QUIT -f '{$master}'
                for _ in \$(seq 20); do pgrep -f '{$master}' >/dev/null || break; sleep 0.5; done
                pkill -KILL -f '{$master}'
            fi
            bash /entrypoint-runner.sh start {$name} >/dev/null
            {$started}
            BASH;
    }

    /** {@see restartFpmScript()} for an account on s6, which starts nothing it does not supervise. */
    public static function restartFpmS6Script(string $phpVersion): string
    {
        $restart = S6Services::restartScript('php-fpm' . self::version($phpVersion), self::EXIT_NOT_MANAGED);

        return $restart . "\n" . self::waitForMaster($phpVersion);
    }

    private static function version(string $phpVersion): string
    {
        if (preg_match('/^\d+\.\d+$/', $phpVersion) !== 1) {
            throw new \InvalidArgumentException("Invalid PHP version: {$phpVersion}");
        }

        return $phpVersion;
    }

    /** Anchored, so it never matches the command line of the script that runs it. */
    private static function masterPattern(string $phpVersion): string
    {
        return '^php-fpm: master process \\(/etc/php/' . str_replace('.', '\\.', $phpVersion) . '/fpm/';
    }

    private static function waitForMaster(string $phpVersion): string
    {
        $master = self::masterPattern($phpVersion);

        return <<<BASH
            for _ in \$(seq 20); do pgrep -f '{$master}' >/dev/null && exit 0; sleep 0.5; done
            echo 'php-fpm{$phpVersion} did not start' >&2
            exit 1
            BASH;
    }

    /**
     * @return array<string, string>
     */
    private function getEntrypointPhpScripts(): array
    {
        $scriptFiles = [];
        $usedPhpVersions = [];
        foreach ($this->model->getDomains() as $domain) {
            $ver = $domain->getPhpVersion();
            if ($ver === null) {
                continue;
            }
            if (!in_array($ver, $usedPhpVersions, true)) {
                $usedPhpVersions[] = $ver;
            }
        }
        foreach ($usedPhpVersions as $phpVersion) {
            $scriptFiles["php-fpm{$phpVersion}.sh"] = "exec php-fpm{$phpVersion} -F";
        }

        return $scriptFiles;
    }
}
