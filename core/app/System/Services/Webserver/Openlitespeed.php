<?php

namespace App\System\Services\Webserver;

use App\Lib\Helpers\Config;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System\Privileged;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

class Openlitespeed extends AbstractWebserver implements WebserverInterface
{
    use LiteSpeedTrait;

    public function getDetails(): array
    {
        return [
            'name' => 'OpenLiteSpeed',
            'version' => $this->getVersion(),
            'slug' => $this->getSlug(),
            'metadata' => [
                'web_panel_port' => '7080',
                'web_panel_username' => 'admin',
            ],
        ];
    }

    private function getSlug(): string
    {
        return 'openlitespeed';
    }

    public function addDomain(Domain $domain): void
    {
        $this->rebuildConfig();
    }

    public function deleteDomainConfig(string $domainName): void
    {
        $domains = Domain::getAll();

        $filteredDomains = [];
        foreach ($domains as $domain) {
            if ($domain->domain == $domainName) {
                continue;
            }
            $filteredDomains[] = $domain;
        }

        if (count($domains) != count($filteredDomains)) {
            $this->rebuildConfig($filteredDomains);
        }
    }

    public function rebuildDomainConfig(Domain $domain): void
    {
        $domains = Domain::getAll();
        foreach ($domains as $key => $storedDomain) {
            if ($domain->id == $storedDomain->id) {
                $domains[$key] = $domain;
                break;
            }
        }
        $this->rebuildConfig($domains);
    }

    /**
     * @param  ?array<Domain>  $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        $currentConfigPath = $this->system->engineDirPath().'/webserver-config/openlitespeed/httpd_config.conf';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);
        $listenerTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath().'/listener-openlitespeed.blade.php');
        $virtualHostTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath().'/virtualHost-openlitespeed.blade.php');
        $virtualHostConfigTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath().'/virtualHostConfig-openlitespeed.blade.php');

        [$listeners, $allDomains] = $this->listenersByIp(User::getAll(), $domains);
        [$otherListenerMaps, $otherListeners, $otherVhosts] = $this->appLiteListeners();
        $listenersString = $this->listenersString($listenerTemplate, $listeners, $otherListenerMaps, $otherListeners);
        $virtualHosts = $this->domainVirtualHosts($allDomains, $virtualHostTemplate, $virtualHostConfigTemplate);
        $virtualHostsString = $this->virtualHostsString($virtualHosts, $otherVhosts);

        $newConfig = $this->removeBlocks($currentConfig, 'listener');
        $newConfig = $this->removeBlocks($newConfig, 'virtualhost');

        $newConfig = trim($newConfig)."\n\n".trim($listenersString)."\n\n".trim($virtualHostsString);
        $newConfig = preg_replace('/\R{3,}/', "\n\n", $newConfig);

        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->applyCloudflareRealIpConfig();
    }

    /** @return array<string> listeners of their own for the app proxy areas on a port other than 80 and 443 */
    private function appLitePortListeners(string $caDomain, string $aaDomain): array
    {
        $otherListeners = [];
        $appLiteCaPort = Config::getIntValue('env.APP_LITE_CA_PORT');
        if (
            $appLiteCaPort
            && ! in_array($appLiteCaPort, [80, 443])
        ) {
            $otherListeners[] = $this->getListenerString('listener-app-lite-ca', (string) $appLiteCaPort, 'app-lite-ca', [$caDomain]);
        }
        $appLiteAaPort = Config::getIntValue('env.APP_LITE_AA_PORT');
        if (
            $appLiteAaPort
            && ! in_array($appLiteAaPort, [80, 443])
        ) {
            $otherListeners[] = $this->getListenerString('listener-app-lite-aa', (string) $appLiteAaPort, 'app-lite-aa', [$aaDomain]);
        }

        return $otherListeners;
    }

    private function domainVirtualHost(Domain $domain, bool $cacheEnabled, string $virtualHostTemplate, string $virtualHostConfigTemplate): string
    {
        $user = $domain->getUser();
        $cacheStorePath = $this->prepareCacheStore($user, $domain, $cacheEnabled);
        $templateVars = $this->domainTemplateVars($domain, $user, $cacheEnabled, $cacheStorePath);
        $virtualHostConfig = Blade::render($virtualHostConfigTemplate, $templateVars);
        $virtualHostConfigPath = $this->system->engineDirPath().'/webserver-config/openlitespeed/vhosts/'.$domain->domain.'.conf';
        $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');

        $this->system->exec(Privileged::files()->makeDirectories($this->system->engineDirPath().'/webserver-logs/openlitespeed/'.$domain->domain));

        return Blade::render($virtualHostTemplate, $templateVars);
    }

    /** @return array<string, mixed> */
    private function domainTemplateVars(Domain $domain, User $user, bool $cacheEnabled, string $cacheStorePath): array
    {
        $connection = $this->projectDomain($domain);
        $project = $connection->project();

        $templateVars = [
            'domain' => $domain->domain,
            'aliases' => $domain->getVhostAltNames(),
            'relative_document_root' => $domain->getDocumentRoot(),
            'user' => $user->username,
            'suspended' => $user->status === 'suspended',
            'php_port' => $this->phpPortForVersion($domain->getPhpVersion()),
            'lsphp_max_conns' => $user->getLsPhpMaxConns(),
            'cache_enabled' => $cacheEnabled ? '1' : '0',
            'cache_store_path' => $cacheStorePath,
        ];
        $templateVars = array_merge($templateVars, $this->httpAcmeChallengeTemplateVars($domain));
        $templateVars = array_merge($templateVars, LiteSpeedCookieIsolation::templateVars(CookieScope::forDomain($domain, $user, false), false));
        if ($connection->hasServableCertificate()) {
            $templateVars = $this->withServableCertificate($templateVars, $project->projectDirPath().'/ssl-certs', $domain->domain);
        }

        return $templateVars;
    }

    private function fallbackVirtualHost(): string
    {
        $vhRoot = $this->system->engineDirPath().'/webserver-config/document-root';

        return <<<CONFIG
virtualhost fallback {
  vhRoot $vhRoot
  configFile \$SERVER_ROOT/conf/vhosts/fallback.conf
  allowSymbolLink         1
  enableScript            0
  restrained              1
}
CONFIG;
    }

    private function rebuildFallbackVirtualHostConfig(): void
    {
        $docRoot = $this->system->engineDirPath().'/webserver-config/document-root';
        $logDir = $this->system->engineDirPath().'/webserver-logs/openlitespeed/fallback';
        $virtualHostConfig = $this->fallbackVirtualHostConfig($docRoot, $logDir);

        $virtualHostConfigPath = $this->system->engineDirPath().'/webserver-config/openlitespeed/vhosts/fallback.conf';
        $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');
        $this->system->exec(Privileged::files()->makeDirectories($logDir));
    }

    private function fallbackVirtualHostConfig(string $docRoot, string $logDir): string
    {
        return <<<CONFIG
docRoot $docRoot
errorpage 404 {
  url /404.html
}
errorlog $logDir/error.log {
  logLevel                WARNING
  useServer               0
  rollingSize             100M
  keepDays                30
  compressArchive         1
}
accesslog $logDir/access.log {
  useServer               0
  rollingSize             100M
  keepDays                30
  compressArchive         1
}
rewrite  {
  enable                  1
  RewriteCond %{REQUEST_URI} !^/404\.html$
  RewriteRule .* /404.html [L]
}
CONFIG;
    }

    private function rebuildAppProxyVirtualHostConfig(): void
    {
        $docRoot = $this->system->engineDirPath().'/webserver-config/document-root';
        $appProxyVars = $this->getAppProxyVars();

        $this->writeAppProxyVirtualHostConfig('app-lite-ca', $docRoot, $appProxyVars['ca_proxy_host'], $appProxyVars['ca_proxy_port'], $appProxyVars);
        $this->writeAppProxyVirtualHostConfig('app-lite-aa', $docRoot, $appProxyVars['aa_proxy_host'], $appProxyVars['aa_proxy_port'], $appProxyVars);
    }

    /**
     * @param  array<string>  $domains
     */
    private function getListenerString(string $name, string $port, string $vhost, array $domains): string
    {
        $mapDomains = implode(', ', $domains);
        $cert = $this->getSslCertVars();

        return <<<CONFIG
listener $name {
  address                 *:$port
  secure                  1
  keyFile                 {$cert['ssl_cert_key_file']}
  certFile                {$cert['ssl_cert_file']}
  map $vhost $mapDomains
}
CONFIG;
    }

    private function getVhostString(string $name): string
    {
        $docRoot = $this->system->engineDirPath().'/webserver-config/document-root';

        return <<<CONFIG
virtualhost $name {
  vhRoot $docRoot
  configFile \$SERVER_ROOT/conf/vhosts/$name.conf
  allowSymbolLink         1
  enableScript            0
  restrained              1
}
CONFIG;
    }

    private function getProxyVhostConfigString(string $name, string $docRoot, string $logDir, string $proxyHost, string $proxyPort, string $wsRewriteProxyHost, string $wsRewriteProxyPort): string
    {
        return <<<CONFIG
docRoot $docRoot
errorpage 404 {
  url /404.html
}
errorlog $logDir/error.log {
  logLevel                WARNING
  useServer               0
  rollingSize             100M
  keepDays                30
  compressArchive         1
}
accesslog $logDir/access.log {
  useServer               0
  rollingSize             100M
  keepDays                30
  compressArchive         1
}
extprocessor $name {
  type                    proxy
  address                 http://$proxyHost:$proxyPort
  maxConns                10
  initTimeout             5
  retryTimeout            5
  respBuffer              0
}
context / {
  type                    proxy
  handler                 $name
}
websocket /ws/ {
  address                 $wsRewriteProxyHost:$wsRewriteProxyPort
}
CONFIG;
    }

    private function removeBlocks(string $config, string $blockName): string
    {
        $offset = 0;
        $from = stripos($config, $blockName.' ', $offset);
        while ($from !== false) {
            $to = strpos($config, '}', $from);
            if ($to === false) {
                throw new \Exception("Cannot find closing tag for {$blockName} block");
            }
            $to++;
            if (! Str::contains(substr($config, $from, $to - $from), '{')) {
                $from = $to;

                continue;
            }
            $before = substr($config, 0, $from);
            $after = substr($config, $to);
            $config = "{$before}{$after}";
            $offset = $from;
            $from = strpos($config, $blockName.' ', $offset);
        }

        return $config;
    }

    public function reload(bool $rebindIpListeners = false): void
    {
        $this->system->runProcess(Privileged::engineServices($this->system->composeFilePath())->litespeedControl('condrestart'));
    }

    public function restart(): void
    {
        $this->system->runProcess(Privileged::engineServices($this->system->composeFilePath())->litespeedControl('fullrestart'));
    }

    public function listDomains(): array
    {
        $dir = $this->system->engineDirPath().'/webserver-config/openlitespeed/vhosts';
        $domains = [];
        foreach ((new \FilesystemIterator($dir)) as $file) {
            if (! ($file instanceof \SplFileInfo)) {
                continue;
            }
            if ($file->getExtension() == 'conf') {
                $domains[] = substr($file->getFilename(), 0, -5);
            }
        }

        return $domains;
    }

    /** The glob stays a glob; the name is a positional argument, never shell. */
    public function deleteDomainsConfigs(array $domainNames): void
    {
        $dir = $this->system->engineDirPath().'/webserver-config/openlitespeed/vhosts';
        foreach ($domainNames as $domainName) {
            $configFile = $dir.'/'.$domainName.'.conf';
            $this->system->exec(Privileged::scripts()->removeStartingWith($configFile));
        }
    }

    /** OpenLiteSpeed has no serial number, so the LiteSpeed serial write it would inherit is refused. */
    public function updateConfig(string $name, string $value): void
    {
        if ($name === 'serial_number') {
            throw new \Exception('OpenLiteSpeed has no serial number to update');
        }

        parent::updateConfig($name, $value);
    }

    public function toggleModsecurity(): void
    {
        $config = Setting::getModsecConfig();

        $currentConfigPath = $this->system->engineDirPath().'/webserver-config/openlitespeed/httpd_config.conf';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);

        $blockExists = stripos($currentConfig, 'module mod_security') !== false;

        if ($config['mode'] === 'off' && ! $blockExists) {
            return;
        }

        $newConfig = $this->removeBlocks($currentConfig, 'module mod_security');

        if ($config['mode'] === 'off') {
            $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
            $this->restart();

            return;
        }

        $modsecString = "module mod_security {\n";
        $modsecString .= "  modsecurity on\n";
        $modsecString .= "  modsecurity_rules_file /opt/modsecurity/main.conf\n";
        $modsecString .= "}\n";

        $newConfig = trim($newConfig)."\n\n".$modsecString;
        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->restart();
    }

    public function toggleLscache(): void
    {
        $disabled = ! empty(Setting::get('disable-lscache'));
        $cacheStorePath = $this->system->engineDirPath().'/data/lscache';
        $cacheLsEnabled = $disabled ? '0' : '1';
        $this->system->runProcess(Privileged::files()->makeDirectories($cacheStorePath));
        $this->system->runProcess(Privileged::files()->changeOwnerRecursively('nobody:www-data', $cacheStorePath));

        $currentConfigPath = $this->system->engineDirPath().'/webserver-config/openlitespeed/httpd_config.conf';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);

        $blockExists = stripos($currentConfig, 'module cache') !== false;
        if ($disabled && ! $blockExists) {
            return;
        }

        $newConfig = $this->removeBlocks($currentConfig, 'module cache');
        if ($disabled) {
            $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
            $this->reload();

            return;
        }

        $configString = $this->cacheModuleBlock($cacheStorePath, $cacheLsEnabled);

        $newConfig = trim($newConfig)."\n\n".$configString;
        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->rebuildConfig();
        $this->reload();
    }

    private function cacheModuleBlock(string $cacheStorePath, string $cacheLsEnabled): string
    {
        $configString = '';
        $configString .= "module cache {\n";
        $configString .= "  internal 1\n";
        $configString .= "  checkPrivateCache 1\n";
        $configString .= "  checkPublicCache 1\n";
        $configString .= "  maxCacheObjSize 10000000\n";
        $configString .= "  maxStaleAge 200\n";
        $configString .= "  qsCache 1\n";
        $configString .= "  reqCookieCache 1\n";
        $configString .= "  respCookieCache 1\n";
        $configString .= "  ignoreReqCacheCtrl \n";
        $configString .= "  ignoreRespCacheCtrl \n";
        $configString .= "  enableCache 0\n";
        $configString .= "  expireInSeconds 43200\n";
        $configString .= "  enablePrivateCache \n";
        $configString .= "  privateExpireInSeconds \n";
        $configString .= "  storagePath {$cacheStorePath}\n";
        $configString .= "  ls_enabled {$cacheLsEnabled}\n";
        $configString .= "}\n";

        return $configString;
    }
}
