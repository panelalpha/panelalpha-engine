<?php

namespace App\System\Project\PhpHosting;

use App\Models\Domain;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\PhpHosting;
use Illuminate\Support\Str;

final class FpmApacheStack implements PhpStack
{
    use FpmPoolSettings;

    public function __construct(
        private System $system,
        private ModelsUser $model,
    ) {
    }

    public function dockerfileTemplateName(): string
    {
        return 'Dockerfile-fpm-apache';
    }

    public function composeTemplateName(): string
    {
        return 'docker-compose.yml-fpm-apache';
    }

    public function applySettings(PhpHosting $project): void
    {
        $this->applyPhpFpmSettings($project);
        (new EnvironmentSetup())->applyRedisSettings($project);
    }

    public function entrypointInitScripts(PhpHosting $project): array
    {
        $scripts = [];
        $scripts['10-remoteip.sh'] = 'a2enmod remoteip 2>/dev/null || true';
        $scripts['20-apache.sh'] = 'apache2ctl start';

        return $scripts;
    }

    public function entrypointBackgroundScripts(PhpHosting $project): array
    {
        return (new FpmStack($this->system, $this->model))->entrypointBackgroundScripts($project);
    }

    public function waitForAllRunning(PhpHosting $project, int $tries = 12, int $intervalSeconds = 5): void
    {
        if ($intervalSeconds < 0 || $intervalSeconds > PHP_INT_MAX) {
            throw new \Exception('Invalid interval');
        }

        do {
            if ($this->confirmApacheRunning($project)) {
                return;
            }
            sleep($intervalSeconds);
        } while (--$tries);

        throw new \Exception('Waited too long for all services to start');
    }

    public function restartPhpHandler(PhpHosting $project, string $phpVersion): void
    {
        (new FpmStack($this->system, $this->model))->restartPhpHandler($project, $phpVersion);
    }

    public function isApacheRunning(PhpHosting $project): bool
    {
        try {
            $result = $project->system()->exec(
                "sudo docker compose -f {$project->composeFilePath()} exec -T php service apache2 status"
            );
        } catch (\Exception $e) {
            return false;
        }

        return Str::contains($result, 'apache2 is running');
    }

    public function reloadWebserver(PhpHosting $project): void
    {
        if ($this->isApacheRunning($project)) {
            $this->reloadApache($project);
        }
    }

    public function rebuildDomains(PhpHosting $project): void
    {
        foreach ($this->model->getDomains() as $domain) {
            $this->rebuildDomain($project, $domain);
        }
    }

    public function rebuildDomain(PhpHosting $project, Domain $domain): void
    {
        $projectDomain = $project->project()->domain($domain);
        if ($domain->sslEnabled() && !$projectDomain->hasSslCertificate()) {
            $projectDomain->generateCertificate();
        }
        $this->createDomainConfig($project, $domain);

        $phpVersion = $domain->getPhpVersion();
        if ($phpVersion) {
            // Through the runner, never `service ... restart`: that starts a
            // master the runner cannot see, and every later INI change missed it.
            try {
                $this->restartPhpHandler($project, $phpVersion);
            } catch (PhpHandlerNotRunning) {
                // A PHP version switch lands here before the runner scripts are
                // written; the runner's `sync --all` that follows starts it.
            }
        }

        $this->reloadWebserver($project);
    }

    public function createDomainConfig(PhpHosting $project, Domain $domain): void
    {
        $domainName = $domain->domain;

        $templateVars = [
            'user' => $this->model->username,
            'domain' => $domainName,
            'aliases' => $domain->getVhostAltNames(),
            'php_version' => $domain->getPhpVersion(),
        ];
        if ($domain->sslEnabled()) {
            $certDir = '/etc/apache2/ssl-certs';
            $templateVars['ssl_enabled'] = true;
            $templateVars['ssl_cert_file'] = "{$certDir}/{$domainName}.crt";
            $templateVars['ssl_cert_key_file'] = "{$certDir}/{$domainName}.key";
            $templateVars['ssl_cert_ca_file'] = "{$certDir}/{$domainName}.ca";
        }

        $templatesDir = $project->system()->templatesDirPath();
        $templateVars['relative_document_root'] = $domain->getDocumentRoot();
        $domainConfigFile = $project->system()->projectDirPath($project->username()) . "/apache-sites/{$domainName}.conf";
        $templateConfigFile = "{$templatesDir}/virtualHost-apache-user.blade.php";
        $project->system()->filesystem()->makeFileFromTemplate($domainConfigFile, $templateConfigFile, $templateVars);
    }

    /**
     * @return array<string>
     */
    public function listDomains(PhpHosting $project): array
    {
        $dir = $project->system()->projectDirPath($project->username()) . '/apache-sites';
        $domains = [];
        foreach ((new \FilesystemIterator($dir)) as $file) {
            if (!($file instanceof \SplFileInfo)) {
                continue;
            }
            if ($file->getExtension() === 'conf') {
                $domains[] = substr($file->getFilename(), 0, -5);
            }
        }

        return $domains;
    }

    public function deleteAllDomainsConfigs(PhpHosting $project): void
    {
        $domainConfigsDir = $project->system()->projectDirPath($project->username()) . '/apache-sites';
        $project->system()->runProcess("sudo rm -rf {$domainConfigsDir}/* {$domainConfigsDir}/.*");
    }

    /**
     * @param array<string> $domainNames
     */
    public function deleteDomainsConfigs(PhpHosting $project, array $domainNames): void
    {
        foreach ($domainNames as $domainName) {
            $this->deleteDomainConfig($project, $domainName);
        }
    }

    public function deleteDomainConfig(PhpHosting $project, string $domainName): void
    {
        $dir = $project->system()->projectDirPath($project->username()) . '/apache-sites';
        $configFile = $dir . '/' . $domainName . '.conf';
        $project->system()->exec("sudo rm -f {$configFile}");
    }

    public function reloadApache(PhpHosting $project): void
    {
        $project->system()->exec(
            "sudo docker compose -f {$project->composeFilePath()} exec -T php service apache2 reload"
        );
    }

    public function enableApacheMod(PhpHosting $project, string $mod): void
    {
        $project->system()->exec("sudo docker compose -f {$project->composeFilePath()} exec -T php a2enmod {$mod}");
    }

    public function disableApacheMod(PhpHosting $project, string $mod): void
    {
        $project->system()->exec("sudo docker compose -f {$project->composeFilePath()} exec -T php a2dismod {$mod}");
    }

    private function confirmApacheRunning(PhpHosting $project): bool
    {
        return $this->isApacheRunning($project);
    }
}
