<?php

namespace App\System\Project\PhpHosting;

use App\System\Project\PhpHosting;
use Illuminate\Support\Str;

/**
 * Project-wide PHP handler / custom.ini / WP-CLI runtime (not a single Application).
 */
final class PhpRuntime
{
    public function __construct(
        private PhpHosting $project,
    ) {
    }

    public function customIniFilePath(string $phpVersion): string
    {
        return $this->project->project()->php()->customIniFilePath($phpVersion);
    }

    /**
     * @return array<string, string>
     */
    public function getCustomIniSettings(string $phpVersion): array
    {
        return $this->project->project()->php()->getCustomIniSettings($phpVersion);
    }

    /**
     * @param array<string, string> $settings
     *
     * @throws \ErrorException
     */
    public function updateCustomIniSettings(string $phpVersion, array $settings): void
    {
        $this->project->project()->php()->updateCustomIniSettings($phpVersion, $settings);
    }

    public function restartPhpHandler(string $phpVersion): void
    {
        $this->project->stackVariant()->restartPhpHandler($this->project, $phpVersion);
    }

    public function syncPhpHandlersScripts(): void
    {
        $this->project->services()->write($this->project->stackVariant()->services($this->project));
    }

    /**
     * @param list<string> $args
     *
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function runWpCli(array $args): array
    {
        $wpPath = null;
        foreach ($args as $arg) {
            if (Str::startsWith($arg, '--path=')) {
                $wpPath = Str::after($arg, '--path=');
            }
        }

        $user = $this->project->userModel();
        $uid = $user->getUid();
        $gid = $user->getGid();
        $command = [
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->project->composeFilePath(),
            'exec',
            '-u',
            "{$uid}:{$gid}",
            '-T',
            $this->project->defaultServiceName(),
            $this->phpBinaryPath($wpPath),
            '-d',
            'memory_limit=' . $this->wpCliMemoryLimit($user->effectiveMemoryLimit()),
            '/opt/wp-cli.phar',
            ...$args,
        ];

        $process = $this->project->system()->runProcess($command);
        $exitCode = $process->getExitCode();
        if ($exitCode === null) {
            throw new \Exception('Could not get exit code after running WP-CLI command: ' . implode(' ', $args));
        }

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $exitCode,
        ];
    }

    public function phpBinaryPath(?string $wpPath = null): string
    {
        $phpVersion = null;
        if ($wpPath) {
            $domain = $this->project->userModel()->findDomainByDocumentRoot($wpPath);
            if ($domain) {
                $phpVersion = $domain->getPhpVersion();
            }
        }

        if ($phpVersion === null) {
            $phpVersion = '8.3';
        }

        $webserver = $this->project->system()->webserver()->getCurrentWebserver();
        switch ($webserver) {
            case 'litespeed':
            case 'openlitespeed':
                $ver = str_replace('.', '', $phpVersion);

                return "/usr/local/lsws/lsphp{$ver}/bin/php";
            default:
                return "/usr/bin/php{$phpVersion}";
        }
    }

    public function getPhpPort(?string $phpVersion): int
    {
        if (!$phpVersion || !Str::contains($phpVersion, '.')) {
            return 9083;
        }

        [$major, $minor] = explode('.', $phpVersion);

        return (int) "90{$major}{$minor}";
    }

    protected function wpCliMemoryLimit(?int $containerMemoryLimitMb): string
    {
        if ($containerMemoryLimitMb === null || $containerMemoryLimitMb <= 0) {
            return '-1';
        }

        $limitMb = (int) floor($containerMemoryLimitMb * 0.75);
        if ($limitMb < 128) {
            $limitMb = min(128, $containerMemoryLimitMb);
        }

        return $limitMb . 'M';
    }
}
