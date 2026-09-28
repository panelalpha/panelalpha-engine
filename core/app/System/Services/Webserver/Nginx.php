<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\Setting;
use Illuminate\Support\Facades\Blade;

class Nginx extends AbstractWebserver implements WebserverInterface
{
    public function getDetails(): array
    {
        return [
            'name' => 'Nginx',
            'version' => 'Unknown',
            'slug' => 'nginx',
            'metadata' => [],
        ];
    }

    public function addDomain(Domain $domain): void
    {
        $this->createDomainConfig($domain);
    }

    /**
     * @param ?array<Domain> $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        $this->rebuildMainConfig();
        $this->rebuildAppProxyConfig();
        $this->rebuildDomainConfigs($domains);
    }

    private function rebuildMainConfig(): void
    {
        $confPath = $this->system->engineDirPath() . '/webserver-config/nginx/nginx.conf';
        $confTemplatePath = $this->system->engineDirPath() . '/templates/webserver-nginx.blade.php';
        $confTemplate = $this->system->filesystem()->fileGetContents($confTemplatePath);

        $modseConfig = Setting::getModsecConfig();
        $templateVars = [
            'modsecurity_enabled' => $modseConfig['mode'] !== 'off',
            ...$this->getAllIpsVars(),
            ...$this->getFallbackVars(),
            ...$this->getSslCertVars(),
        ];
        $conf = Blade::render($confTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($confPath, $conf);
    }

    private function rebuildAppProxyConfig(): void
    {
        $confPath = $this->system->engineDirPath() . '/webserver-config/nginx/vhosts/app-lite.conf';
        $appLiteProxyEnabled = (bool)config('env.APP_LITE_PROXY_ENABLED');
        if (!$appLiteProxyEnabled) {
            $this->system->runProcess([
                "sudo",
                "rm",
                "-f",
                $confPath,
            ]);
            return;
        }

        $confTemplatePath = $this->system->engineDirPath() . '/templates/virtualHost-nginx-app-lite.blade.php';
        $confTemplate = $this->system->filesystem()->fileGetContents($confTemplatePath);

        $templateVars = [
            ...$this->getAppProxyVars(),
            ...$this->getAllIpsVars(),
            ...$this->getSslCertVars(),
        ];

        $virtualHost = Blade::render($confTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($confPath, $virtualHost);

        $this->system->exec([
            'sudo',
            'mkdir',
            '-p',
            $this->system->engineDirPath() . '/webserver-logs/nginx/app-lite',
        ]);
    }

    /**
     * @see NginxProxy::needsIpListenerRebind()
     */
    public function needsIpListenerRebind(): bool
    {
        $confPath = $this->system->engineDirPath() . '/webserver-config/nginx/nginx.conf';
        try {
            $contents = $this->system->filesystem()->fileGetContents($confPath);
        } catch (\Throwable $e) {
            return true;
        }

        return (bool)preg_match('/^\s*listen\s+80(\s+default_server)?\s*;/m', $contents)
            || (bool)preg_match('/^\s*listen\s+443\s+ssl(\s+default_server)?\s*;/m', $contents);
    }

    public function reload(bool $rebindIpListeners = false): void
    {
        if ($rebindIpListeners) {
            // Never `/etc/init.d/nginx restart` inside the container: that exits
            // PID 1 and loops the webserver (see Modsec::restartWebserver).
            $this->system->webserver()->restartWebserverContainerFromHost();
            return;
        }
        $this->exec("/etc/init.d/nginx reload");
    }

    public function restart(): void
    {
        $this->system->webserver()->restartWebserverContainerFromHost();
    }

    public function createDomainConfig(Domain $domain): void
    {
        $virtualHostTemplate = file_get_contents($this->system->templatesDirPath() . '/virtualHost-nginx.blade.php');

        $user = $domain->getUser();
        $ips = $user->getBindIpAddresses();

        $connection = $this->projectDomain($domain);
        $project = $connection->project();

        $templateVars = [
            'domain' => $domain->domain,
            'aliases' => $domain->getVhostAltNames(),
            'relative_document_root' => $domain->getDocumentRoot(),
            'user' => $user->username,
            'suspended' => $user->status === 'suspended',
            'ips_v4' => $ips['ipv4'],
            'ips_v6' => $ips['ipv6'],
            'php_port' => $this->phpPortForVersion($domain->getPhpVersion()),
        ];
        $templateVars = array_merge($templateVars, $this->httpAcmeChallengeTemplateVars($domain));

        // The .pem and .key are what nginx loads; a .crt alone passed this before.
        if ($connection->hasServableCertificate()) {
            $certDir = $project->projectDirPath() . "/ssl-certs";
            $keyFile = "{$certDir}/{$domain->domain}.key";
            $pemFile = "{$certDir}/{$domain->domain}.pem";
            $templateVars['ssl_enabled'] = true;
            $templateVars['ssl_cert_pem_file'] = $pemFile;
            $templateVars['ssl_cert_key_file'] = $keyFile;
        }
        $virtualHost = Blade::render($virtualHostTemplate, $templateVars);
        $virtualHostPath = $this->system->engineDirPath() . '/webserver-config/nginx/vhosts/' . $domain->domain . '.conf';
        $this->system->filesystem()->filePutContents($virtualHostPath, $virtualHost);

        $this->system->exec([
            'sudo',
            'mkdir',
            '-p',
            $this->system->engineDirPath() . '/webserver-logs/nginx/' . $domain->domain,
        ]);
    }

    public function rebuildDomainConfig(Domain $domain): void
    {
        $this->createDomainConfig($domain);
    }

    public function deleteDomainConfig(string $domainName): void
    {
        $dir = $this->system->engineDirPath() . '/webserver-config/nginx/vhosts';
        $configFile = $dir . '/' . $domainName . '.conf';
        $this->system->exec(['sudo', 'rm', '-f', $configFile]);
    }

    public function deleteDomainsConfigs(array $domainNames): void
    {
        $dir = $this->system->engineDirPath() . '/webserver-config/nginx/vhosts';
        foreach ($domainNames as $domainName) {
            $configFile = $dir . '/' . $domainName . '.conf';
            $this->system->exec(['sudo', 'rm', '-f', $configFile]);
        }
    }

    public function toggleModsecurity(): void
    {
        $this->rebuildMainConfig();
        $this->restart();
    }
}
