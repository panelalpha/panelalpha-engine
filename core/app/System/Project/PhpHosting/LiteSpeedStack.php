<?php

namespace App\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System\ProcessRunner;
use App\System\Project\PhpHosting;

final class LiteSpeedStack implements PhpStack
{
    public function __construct(
        private ProcessRunner $system,
        private ModelsUser $model,
    ) {
    }

    public function dockerfileTemplateName(): string
    {
        return 'Dockerfile-ls';
    }

    public function composeTemplateName(): string
    {
        return 'docker-compose.yml-ls';
    }

    public function applySettings(PhpHosting $project): void
    {
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
        $phpVersion = str_replace('.', '', $phpVersion);
        if (S6Services::manages($project)) {
            $this->system->runProcess($project->execArgv([
                'sh', '-c', S6Services::restartScript("lsphp{$phpVersion}", FpmStack::EXIT_NOT_MANAGED),
            ]));

            return;
        }
        $this->system->runProcess([
            'sudo',
            'docker',
            'compose',
            '-f',
            $project->composeFilePath(),
            'exec',
            '-T',
            'php',
            'bash',
            '/entrypoint-runner.sh',
            'restart',
            "lsphp{$phpVersion}",
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getEntrypointPhpScripts(): array
    {
        $scriptFiles = [];
        $settings = $this->model->getLsPhpSettings();
        $children = $settings['PHP_LSAPI_CHILDREN'];
        $maxRequests = $settings['PHP_LSAPI_MAX_REQUESTS'];
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
            $phpVersionShort = str_replace('.', '', $phpVersion);
            $command = "exec runuser -u {$this->model->username} -- env LSPHP_ENABLE_USER_INI=on PHP_LSAPI_CHILDREN={$children} PHP_LSAPI_MAX_REQUESTS={$maxRequests} /usr/local/lsws/lsphp{$phpVersionShort}/bin/lsphp -b *:90{$phpVersionShort}";
            $scriptFiles["lsphp{$phpVersionShort}.sh"] = $command;
        }

        return $scriptFiles;
    }
}
