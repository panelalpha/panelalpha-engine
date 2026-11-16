<?php

namespace App\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System\ProcessRunner;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\Services\Service;
use App\System\Project\PhpHosting\Services\ServiceManager;
use Illuminate\Support\Facades\Log;

final class FpmStack implements PhpStack
{
    use FpmPoolSettings;

    /** restartScript() exit status: the account runs no handler of this version. */
    public const EXIT_NOT_MANAGED = ServiceManager::EXIT_NOT_MANAGED;

    public function __construct(
        private ProcessRunner $system,
        private ModelsUser $model,
    ) {}

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
        (new EnvironmentSetup)->applyRedisSettings($project);
    }

    public function entrypointInitScripts(PhpHosting $project): array
    {
        return [];
    }

    public function services(PhpHosting $project): array
    {
        return $this->phpServices();
    }

    public function waitForAllRunning(PhpHosting $project, int $tries = 12, int $intervalSeconds = 5): void {}

    /** A restart prints only the stray master it had to stop, and that is logged. */
    public function restartPhpHandler(PhpHosting $project, string $phpVersion): void
    {
        $this->runHandlerScript(
            $project,
            $phpVersion,
            self::restartScript($project->services(), $phpVersion),
            'restart'
        );
    }

    /**
     * A handler that started after its pool gained the short user INI cache reads a saved file on its own, and
     * signals nothing; one that started before it still caches the file for five minutes, so its workers are
     * told to quit until it starts again. A busy worker finishes its request first and the master replaces it,
     * so the signal is the success: a full pool does not fail the save.
     */
    public function reloadPhpHandler(PhpHosting $project, string $phpVersion): void
    {
        $this->runHandlerScript($project, $phpVersion, self::reloadScript($phpVersion), 'reload');
    }

    private function runHandlerScript(PhpHosting $project, string $phpVersion, string $script, string $action): void
    {
        $process = $this->system->runProcess($project->execArgv(['bash', '-c', $script]));
        if ($process->getExitCode() === self::EXIT_NOT_MANAGED) {
            throw new PhpHandlerNotRunning("php-fpm{$phpVersion} is not a service of {$project->username()}");
        }
        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException("Could not {$action} php-fpm{$phpVersion}: {$message}");
        }
        $output = $process->isStarted() ? trim($process->getOutput()) : '';
        if ($output !== '') {
            Log::warning("{$project->username()}: {$output}");
        }
    }

    /** Inside the account: restart php-fpm$phpVersion through $services, then wait for its master. */
    public static function restartScript(ServiceManager $services, string $phpVersion): string
    {
        $name = 'php-fpm'.self::version($phpVersion);

        return $services->restartScript($name, self::masterPattern($phpVersion))."\n".self::waitForMaster($phpVersion);
    }

    /** Inside the account: nothing to do while the running master carries the short cache, tell its workers to quit otherwise. */
    public static function reloadScript(string $phpVersion): string
    {
        $version = self::version($phpVersion);
        $master = self::masterPattern($version);
        $notManaged = self::EXIT_NOT_MANAGED;
        $pool = self::poolFile($version);
        $ttl = PhpErrorLogConfig::POOL_USER_INI_CACHE_TTL_LINE;

        return <<<BASH
            master=\$(pgrep -f '{$master}' | head -n 1)
            [ -n "\$master" ] || exit {$notManaged}
            replace_workers() {
              pgrep -P "\$master" >/dev/null || exit 0
              pkill -QUIT -P "\$master"
              [ \$? -le 1 ] && exit 0
              echo 'php-fpm{$version} could not signal its workers' >&2
              exit 1
            }
            grep -Fxq '{$ttl}' '{$pool}' 2>/dev/null || replace_workers
            age=\$(ps -o etimes= -p "\$master" 2>/dev/null | tr -d '[:space:]')
            written=\$(stat -c %Y '{$pool}' 2>/dev/null)
            [ -n "\$age" ] && [ -n "\$written" ] || replace_workers
            case "\$age\$written" in *[!0-9]*) replace_workers ;; esac
            [ \$(( \$(date +%s) - age )) -gt "\$written" ] && exit 0
            replace_workers
            BASH;
    }

    private static function poolFile(string $phpVersion): string
    {
        return '/etc/php/'.$phpVersion.'/fpm/pool.d/www.conf';
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
        return '^php-fpm: master process \\(/etc/php/'.str_replace('.', '\\.', self::version($phpVersion)).'/fpm/';
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
     * @return list<Service>
     */
    private function phpServices(): array
    {
        $services = [];
        $usedPhpVersions = [];
        foreach ($this->model->getDomains() as $domain) {
            $ver = $domain->getPhpVersion();
            if ($ver === null) {
                continue;
            }
            if (! in_array($ver, $usedPhpVersions, true)) {
                $usedPhpVersions[] = $ver;
            }
        }
        foreach ($usedPhpVersions as $phpVersion) {
            $services[] = new Service("php-fpm{$phpVersion}", "exec php-fpm{$phpVersion} -F");
        }

        return $services;
    }
}
