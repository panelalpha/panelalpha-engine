<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\Setting;
use App\System\Privileged;
use Illuminate\Support\Facades\Blade;

class Nginx extends AbstractWebserver implements WebserverInterface
{
    private const COOKIE_ISOLATION_IMPORT = 'js_import pa_cookie_isolation';

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
        $confPath = $this->system->engineDirPath().'/webserver-config/nginx/nginx.conf';
        $confTemplatePath = $this->system->engineDirPath().'/templates/webserver-nginx.blade.php';
        $confTemplate = $this->system->filesystem()->fileGetContents($confTemplatePath);

        $modseConfig = Setting::getModsecConfig();
        $templateVars = [
            'modsecurity_enabled' => $modseConfig['mode'] !== 'off',
            ...$this->getAllIpsVars(),
            ...$this->getFallbackVars(),
            ...$this->getSslCertVars(),
        ];
        $conf = Blade::render($confTemplate, $templateVars);
        $this->writeCookieIsolationScript();
        $this->system->filesystem()->filePutContents($confPath, $conf);
    }

    private function writeCookieIsolationScript(): void
    {
        $source = $this->system->templatesDirPath().'/webserver-config/nginx/njs/cookie-isolation.js';
        $target = $this->system->engineDirPath().'/webserver-config/nginx/njs/cookie-isolation.js';

        $this->system->filesystem()->filePutContents($target, $this->system->filesystem()->fileGetContents($source));
    }

    private function rebuildAppProxyConfig(): void
    {
        $confPath = $this->system->engineDirPath().'/webserver-config/nginx/vhosts/app-lite.conf';
        $appLiteProxyEnabled = (bool) config('env.APP_LITE_PROXY_ENABLED');
        if (! $appLiteProxyEnabled) {
            $this->system->runProcess(Privileged::files()->removeIfExists($confPath));

            return;
        }

        $confTemplatePath = $this->system->engineDirPath().'/templates/virtualHost-nginx-app-lite.blade.php';
        $confTemplate = $this->system->filesystem()->fileGetContents($confTemplatePath);

        $templateVars = [
            ...$this->getAppProxyVars(),
            ...$this->getAllIpsVars(),
            ...$this->getSslCertVars(),
        ];

        $virtualHost = Blade::render($confTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($confPath, $virtualHost);

        $this->system->exec(Privileged::files()->makeDirectories($this->system->engineDirPath().'/webserver-logs/nginx/app-lite'));
    }

    /**
     * @see NginxProxy::needsIpListenerRebind()
     */
    public function needsIpListenerRebind(): bool
    {
        $confPath = $this->system->engineDirPath().'/webserver-config/nginx/nginx.conf';
        try {
            $contents = $this->system->filesystem()->fileGetContents($confPath);
        } catch (\Throwable $e) {
            return true;
        }

        return (bool) preg_match('/^\s*listen\s+80(\s+default_server)?\s*;/m', $contents)
            || (bool) preg_match('/^\s*listen\s+443\s+ssl(\s+default_server)?\s*;/m', $contents);
    }

    /** A rebind restarts the container from the host: `/etc/init.d/nginx restart` inside it exits PID 1 and loops. */
    public function reload(bool $rebindIpListeners = false): void
    {
        if ($rebindIpListeners) {
            $this->system->webserver()->restartWebserverContainerFromHost();

            return;
        }
        $this->reloadNginx();
    }

    public function restart(): void
    {
        $this->system->webserver()->restartWebserverContainerFromHost();
    }

    public function createDomainConfig(Domain $domain): void
    {
        $virtualHostTemplate = file_get_contents($this->system->templatesDirPath().'/virtualHost-nginx.blade.php');

        $templateVars = array_merge($this->domainTemplateVars($domain), $this->cookieIsolationTemplateVars($domain));
        $this->ensureCookieIsolationMainConfig($templateVars);
        $virtualHost = Blade::render($virtualHostTemplate, $templateVars);
        $virtualHostPath = $this->system->engineDirPath().'/webserver-config/nginx/vhosts/'.$domain->domain.'.conf';
        $this->system->filesystem()->filePutContents($virtualHostPath, $virtualHost);

        $this->system->exec(Privileged::files()->makeDirectories($this->system->engineDirPath().'/webserver-logs/nginx/'.$domain->domain));
    }

    /**
     * @return array<string, mixed>
     */
    private function cookieIsolationTemplateVars(Domain $domain): array
    {
        $scope = CookieScope::forDomain($domain, $domain->getUser(), false);

        return [...CookieIsolation::directives($scope), ...NginxCookieIsolation::templateVars($scope)];
    }

    /**
     * Renders the main config first, so a vhost never names directives the config it loads does not.
     *
     * @param  array<string, mixed>  $templateVars
     */
    private function ensureCookieIsolationMainConfig(array $templateVars): void
    {
        if (! empty($templateVars['cookie_isolation_shared_parents']) && ! $this->mainConfigImportsCookieIsolation()) {
            $this->rebuildMainConfig();
        }
    }

    private function mainConfigImportsCookieIsolation(): bool
    {
        try {
            $conf = $this->system->filesystem()->fileGetContents($this->system->engineDirPath().'/webserver-config/nginx/nginx.conf');
        } catch (\Throwable) {
            return false;
        }

        return str_contains($conf, self::COOKIE_ISOLATION_IMPORT);
    }

    /**
     * TLS only with the .pem and .key nginx loads; a .crt alone is not enough.
     *
     * @return array<string, mixed>
     */
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
        $templateVars = array_merge($templateVars, $this->httpAcmeChallengeTemplateVars($domain));
        if ($connection->hasServableCertificate()) {
            $templateVars = $this->withServableCertificate($templateVars, $project->projectDirPath().'/ssl-certs', $domain->domain);
        }

        return $templateVars;
    }

    /**
     * @param  array<string, mixed>  $templateVars
     * @return array<string, mixed>
     */
    private function withServableCertificate(array $templateVars, string $certDir, string $domainName): array
    {
        $keyFile = "{$certDir}/{$domainName}.key";
        $pemFile = "{$certDir}/{$domainName}.pem";
        $templateVars['ssl_enabled'] = true;
        $templateVars['ssl_cert_pem_file'] = $pemFile;
        $templateVars['ssl_cert_key_file'] = $keyFile;

        return $templateVars;
    }

    public function rebuildDomainConfig(Domain $domain): void
    {
        $this->createDomainConfig($domain);
    }

    public function deleteDomainConfig(string $domainName): void
    {
        $dir = $this->system->engineDirPath().'/webserver-config/nginx/vhosts';
        $configFile = $dir.'/'.$domainName.'.conf';
        $this->system->exec(Privileged::files()->removeIfExists($configFile));
    }

    public function deleteDomainsConfigs(array $domainNames): void
    {
        $dir = $this->system->engineDirPath().'/webserver-config/nginx/vhosts';
        foreach ($domainNames as $domainName) {
            $configFile = $dir.'/'.$domainName.'.conf';
            $this->system->exec(Privileged::files()->removeIfExists($configFile));
        }
    }

    public function toggleModsecurity(): void
    {
        $this->rebuildMainConfig();
        $this->restart();
    }
}
