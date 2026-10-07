<?php

namespace App\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System\ProcessRunner;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\Services\Service;

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

    public function services(PhpHosting $project): array
    {
        return $this->phpServices();
    }

    public function waitForAllRunning(PhpHosting $project, int $tries = 12, int $intervalSeconds = 5): void
    {
    }

    public function restartPhpHandler(PhpHosting $project, string $phpVersion): void
    {
        $phpVersion = str_replace('.', '', $phpVersion);
        $this->system->runProcess($project->execArgv($project->services()->restartArgv("lsphp{$phpVersion}")));
    }

    /**
     * @return list<Service>
     */
    private function phpServices(): array
    {
        $services = [];
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
            $services[] = new Service("lsphp{$phpVersionShort}", $command);
        }

        return $services;
    }
}
