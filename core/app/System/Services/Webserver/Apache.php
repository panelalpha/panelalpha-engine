<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\Setting;
use App\System\Privileged;
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
     * @param  ?array<Domain>  $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        $this->rebuildMainConfig();
        $this->rebuildAppProxyConfig();
        $this->rebuildDomainConfigs($domains);
    }

    private function rebuildMainConfig(): void
    {
        $confPath = $this->system->engineDirPath().'/webserver-config/apache/httpd.conf';
        $confTemplatePath = $this->system->engineDirPath().'/templates/webserver-apache.blade.php';
        $confTemplate = $this->system->filesystem()->fileGetContents($confTemplatePath);

        $modseConfig = Setting::getModsecConfig();

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
        $confPath = $this->system->engineDirPath().'/webserver-config/apache/vhosts/app-lite.conf';
        $appLiteProxyEnabled = (bool) config('env.APP_LITE_PROXY_ENABLED');
        if (! $appLiteProxyEnabled) {
            $this->system->runProcess(Privileged::files()->removeIfExists($confPath));

            return;
        }

        $confTemplatePath = $this->system->engineDirPath().'/templates/virtualHost-apache-app-lite.blade.php';
        $confTemplate = $this->system->filesystem()->fileGetContents($confTemplatePath);

        $templateVars = [
            ...$this->getAppProxyVars(),
            ...$this->getAllIpsVars(),
            ...$this->getSslCertVars(),
        ];

        $virtualHost = Blade::render($confTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($confPath, $virtualHost);

        $this->system->exec(Privileged::files()->makeDirectories($this->system->engineDirPath().'/webserver-logs/apache/app-lite'));
    }

    public function reload(bool $rebindIpListeners = false): void
    {
        $this->system->exec(Privileged::engineServices($this->system->composeFilePath())->apacheGraceful());
    }

    public function restart(): void
    {
        $this->system->exec(Privileged::engineServices($this->system->composeFilePath())->apacheRestart());
    }

    public function createDomainConfig(Domain $domain): void
    {
        $virtualHostTemplate = file_get_contents($this->system->templatesDirPath().'/virtualHost-apache.blade.php');

        $templateVars = $this->domainTemplateVars($domain);
        $virtualHost = Blade::render($virtualHostTemplate, $templateVars);
        $virtualHostPath = $this->system->engineDirPath().'/webserver-config/apache/vhosts/'.$domain->domain.'.conf';
        $this->system->filesystem()->filePutContents($virtualHostPath, $virtualHost);

        $this->system->exec(Privileged::files()->makeDirectories($this->system->engineDirPath().'/webserver-logs/apache/'.$domain->domain));
    }

    /** @return array<string, mixed> */
    private function domainTemplateVars(Domain $domain): array
    {
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
        $templateVars = array_merge(
            $templateVars,
            $this->httpAcmeChallengeTemplateVars($domain),
            ApacheCookieIsolation::templateVars(CookieScope::forDomain($domain, $user, false)),
        );
        if ($connection->hasSslCertificate()) {
            $templateVars = $this->withSslCertificate($templateVars, $project->projectDirPath().'/ssl-certs', $domain->domain);
        }

        return $templateVars;
    }

    /**
     * @param  array<string, mixed>  $templateVars
     * @return array<string, mixed>
     */
    private function withSslCertificate(array $templateVars, string $certDir, string $domainName): array
    {
        $keyFile = "{$certDir}/{$domainName}.key";
        $crtFile = "{$certDir}/{$domainName}.crt";
        $caFile = "{$certDir}/{$domainName}.ca";
        $templateVars['ssl_enabled'] = true;
        $templateVars['ssl_cert_file'] = $crtFile;
        $templateVars['ssl_cert_key_file'] = $keyFile;
        $templateVars['ssl_cert_ca_file'] = $caFile;

        return $templateVars;
    }

    public function rebuildDomainConfig(Domain $domain): void
    {
        $this->createDomainConfig($domain);
    }

    public function deleteDomainConfig(string $domainName): void
    {
        $dir = $this->system->engineDirPath().'/webserver-config/apache/vhosts';
        $configFile = $dir.'/'.$domainName.'.conf';
        $this->system->exec(Privileged::files()->removeIfExists($configFile));
    }

    /** The glob stays a glob; the name is a positional argument, never shell. */
    public function deleteDomainsConfigs(array $domainNames): void
    {
        $dir = $this->system->engineDirPath().'/webserver-config/apache/vhosts';
        foreach ($domainNames as $domainName) {
            $configFile = $dir.'/'.$domainName.'.conf';
            $this->system->exec(Privileged::scripts()->removeStartingWith($configFile));
        }
    }

    public function toggleModsecurity(): void
    {
        $this->rebuildMainConfig();
        $this->restart();
    }
}
