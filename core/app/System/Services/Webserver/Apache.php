<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Blade;

class Apache extends AbstractWebserver implements WebserverInterface
{
    public function getDetails(): array
    {
        return [
            'name' => 'Apache',
            'version' => 'Unknown',
            'slug' => 'apache',
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
        $confPath = $this->system->engineDirPath() . '/webserver-config/apache/httpd.conf';
        $confTemplatePath = $this->system->engineDirPath() . '/templates/webserver-apache.blade.php';
        $confTemplate = $this->system->filesystem()->fileGetContents($confTemplatePath);

        $modseConfig = Setting::getModsecConfig();
        $ipsv4 = [];
        $ipsv6 = [];
        foreach(User::getAll() as $user) {
            $userIps = $user->getIpAddresses();
            $ipsv4 = [...$ipsv4, ...$userIps['ipv4']];
            $ipsv6 = [...$ipsv6, ...$userIps['ipv6']];
        }
        $ipsv4 = array_unique($ipsv4);
        $ipsv6 = array_unique($ipsv6);

        $templateVars = [
            'modsecurity_enabled' => $modseConfig['mode'] !== 'off',
            ...$this->getAllIpsVars(),
            ...$this->getSslCertVars(),
        ];
        $conf = Blade::render($confTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($confPath, $conf);
    }

    private function rebuildAppProxyConfig(): void
    {
        $confPath = $this->system->engineDirPath() . '/webserver-config/apache/vhosts/app-lite.conf';
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

        $confTemplatePath = $this->system->engineDirPath() . '/templates/virtualHost-apache-app-lite.blade.php';
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
            $this->system->engineDirPath() . '/webserver-logs/apache/app-lite',
        ]);
    }

    public function reload(bool $rebindIpListeners = false): void
    {
        $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'sites-http',
            'apachectl',
            '-k',
            'graceful',
        ]);
    }

    public function restart(): void
    {
        $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'sites-http',
            'apachectl',
            '-k',
            'restart',
        ]);
    }

    public function createDomainConfig(Domain $domain): void
    {
        $virtualHostTemplate = file_get_contents($this->system->templatesDirPath() . '/virtualHost-apache.blade.php');

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
        if ($connection->hasSslCertificate()) {
            $certDir = $project->projectDirPath() . "/ssl-certs";
            $keyFile = "{$certDir}/{$domain->domain}.key";
            $crtFile = "{$certDir}/{$domain->domain}.crt";
            $caFile = "{$certDir}/{$domain->domain}.ca";
            $templateVars['ssl_enabled'] = true;
            $templateVars['ssl_cert_file'] = $crtFile;
            $templateVars['ssl_cert_key_file'] = $keyFile;
            $templateVars['ssl_cert_ca_file'] = $caFile;
        }
        $virtualHost = Blade::render($virtualHostTemplate, $templateVars);
        $virtualHostPath = $this->system->engineDirPath() . '/webserver-config/apache/vhosts/' . $domain->domain . '.conf';
        $this->system->filesystem()->filePutContents($virtualHostPath, $virtualHost);

        $this->system->exec([
            'sudo',
            'mkdir',
            '-p',
            $this->system->engineDirPath() . '/webserver-logs/apache/' . $domain->domain,
        ]);
    }

    public function rebuildDomainConfig(Domain $domain): void
    {
        $this->createDomainConfig($domain);
    }

    public function deleteDomainConfig(string $domainName): void
    {
        $dir = $this->system->engineDirPath() . '/webserver-config/apache/vhosts';
        $configFile = $dir . '/' . $domainName . '.conf';
        $this->system->exec(['sudo', 'rm', '-f', $configFile]);
    }

    public function deleteDomainsConfigs(array $domainNames): void
    {
        $dir = $this->system->engineDirPath() . '/webserver-config/apache/vhosts';
        foreach ($domainNames as $domainName) {
            $configFile = $dir . '/' . $domainName . '.conf';
            // The glob stays a glob; the name is a positional argument, never shell.
            $this->system->exec(['sudo', 'sh', '-c', 'rm -f -- "$1"*', 'sh', $configFile]);
        }
    }

    public function toggleModsecurity(): void
    {
        $this->rebuildMainConfig();
        $this->restart();
    }
}
