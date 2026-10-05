<?php

namespace App\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System\ProcessRunner;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\Services\Service;
use App\System\Project\PhpHosting\Services\ServiceManager;

final class FpmStack implements PhpStack
{
    use FpmPoolSettings;

    /** restartScript() exit status: the account has no service for this version. */
    public const EXIT_NOT_MANAGED = ServiceManager::EXIT_NOT_MANAGED;

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

    public function services(PhpHosting $project): array
    {
        return $this->phpServices();
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
            self::restartScript($project->services(), $phpVersion),
        ]);
        if ($process->getExitCode() === self::EXIT_NOT_MANAGED) {
            throw new PhpHandlerNotRunning("php-fpm{$phpVersion} is not a service of {$project->username()}");
        }
        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException("Could not restart php-fpm{$phpVersion}: {$message}");
        }
        // A restart prints only the stray master it had to stop.
        $output = $process->isStarted() ? trim($process->getOutput()) : '';
        if ($output !== '') {
            \Illuminate\Support\Facades\Log::warning("{$project->username()}: {$output}");
        }
    }

    /** Inside the account: restart php-fpm$phpVersion through $services, then wait for its master. */
    public static function restartScript(ServiceManager $services, string $phpVersion): string
    {
        $name = 'php-fpm' . self::version($phpVersion);

        return $services->restartScript($name, self::masterPattern($phpVersion)) . "\n" . self::waitForMaster($phpVersion);
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
            if (!in_array($ver, $usedPhpVersions, true)) {
                $usedPhpVersions[] = $ver;
            }
        }
        foreach ($usedPhpVersions as $phpVersion) {
            $services[] = new Service("php-fpm{$phpVersion}", "exec php-fpm{$phpVersion} -F");
        }

        return $services;
    }
}
