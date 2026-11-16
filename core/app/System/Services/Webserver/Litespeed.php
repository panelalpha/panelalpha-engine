<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System\Privileged;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Blade;

class Litespeed extends AbstractWebserver implements WebserverInterface
{
    use LiteSpeedTrait;

    public function getDetails(): array
    {
        return [
            'name' => 'LiteSpeed',
            'version' => $this->getVersion(),
            'slug' => $this->getSlug(),
            'metadata' => [
                'serial_number' => $this->getSerialNumber(),
                'web_panel_port' => '7080',
                'web_panel_username' => 'admin',
            ],
        ];
    }

    private function getSlug(): string
    {
        return 'litespeed';
    }

    public function addDomain(Domain $domain): void
    {
        $this->rebuildConfig();
    }

    public function deleteDomainConfig(string $domainName): void
    {
        /** @var Collection<array-key, Domain> */
        $domains = Domain::get();

        /** @var Collection<array-key, Domain> */
        $filteredDomains = $domains->filter(function (Domain $domain) use ($domainName) {
            if ($domain->domain == $domainName) {
                return false;
            }

            return true;
        });

        if ($domains->count() != $filteredDomains->count()) {
            $this->rebuildConfig($filteredDomains->all());
        }
    }

    public function rebuildDomainConfig(Domain $domain): void
    {
        $this->rebuildConfig();
    }

    /**
     * @param  ?array<Domain>  $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        $currentConfigPath = $this->system->engineDirPath().'/webserver-config/litespeed/httpd_config.xml';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);
        $listenerTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath().'/listener-litespeed.blade.php');
        $virtualHostTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath().'/virtualHost-litespeed.blade.php');
        $virtualHostConfigTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath().'/virtualHostConfig-litespeed.blade.php');

        [$listeners, $allDomains] = $this->listenersByIp(User::getAll(), $domains);
        [$otherListenerMaps, $otherListeners, $otherVhosts] = $this->appLiteListeners();
        $listenersString = $this->listenersString($listenerTemplate, $listeners, $otherListenerMaps, $otherListeners);
        $virtualHosts = $this->domainVirtualHosts($allDomains, $virtualHostTemplate, $virtualHostConfigTemplate);
        $virtualHostsString = $this->virtualHostsString($virtualHosts, $otherVhosts);

        $newConfig = $this->replaceXmlNodeContents($currentConfig, 'listenerList', $listenersString);
        $newConfig = $this->replaceXmlNodeContents($newConfig, 'virtualHostList', $virtualHostsString);

        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->applyCloudflareRealIpConfig();
    }

    /** @return array<string> listeners of their own for the app proxy areas on a port other than 80 and 443 */
    private function appLitePortListeners(string $caDomain, string $aaDomain): array
    {
        $otherListeners = [];
        /** @var mixed $appLiteCaPortConfig */
        $appLiteCaPortConfig = config('env.APP_LITE_CA_PORT');
        if (
            ! empty($appLiteCaPortConfig)
            && (is_string($appLiteCaPortConfig) || is_int($appLiteCaPortConfig))
            && ! in_array($appLiteCaPortConfig, [80, 443])
        ) {
            $otherListeners[] = $this->getListenerString('listener-app-lite-ca', (string) $appLiteCaPortConfig, 'app-lite-ca', [$caDomain]);
        }
        /** @var mixed */
        $appLiteAaPortConfig = config('env.APP_LITE_AA_PORT');
        if (
            $appLiteAaPortConfig
            && (is_string($appLiteAaPortConfig) || is_int($appLiteAaPortConfig))
            && ! in_array($appLiteAaPortConfig, [80, 443])
        ) {
            $otherListeners[] = $this->getListenerString('listener-app-lite-aa', (string) $appLiteAaPortConfig, 'app-lite-aa', [$aaDomain]);
        }

        return $otherListeners;
    }

    private function domainVirtualHost(Domain $domain, bool $cacheEnabled, string $virtualHostTemplate, string $virtualHostConfigTemplate): string
    {
        $user = $domain->getUser();
        $cacheStorePath = $this->prepareCacheStore($user, $domain, $cacheEnabled);
        $templateVars = $this->domainTemplateVars($domain, $user, $cacheEnabled, $cacheStorePath);
        $virtualHostConfig = Blade::render($virtualHostConfigTemplate, $templateVars);
        $virtualHostConfigPath = $this->system->engineDirPath().'/webserver-config/litespeed/vhosts/'.$domain->domain.'.xml';
        $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');

        $logsDir = $this->system->engineDirPath().'/webserver-logs/litespeed/'.$domain->domain;
        $this->system->runProcess(Privileged::files()->makeDirectories($logsDir));
        $this->system->runProcess(Privileged::files()->changeOwnerRecursively('nobody:www-data', $logsDir));

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
            'cache_engine' => $cacheEnabled ? '7' : '0',
            'cache_store_path' => $cacheStorePath,
            'cache_check_public' => $cacheEnabled ? '1' : '0',
            'cache_check_private' => $cacheEnabled ? '1' : '0',
        ];
        $templateVars = array_merge($templateVars, $this->httpAcmeChallengeTemplateVars($domain));
        $templateVars = array_merge($templateVars, LiteSpeedCookieIsolation::templateVars(CookieScope::forDomain($domain, $user, false), true));
        if ($connection->hasServableCertificate()) {
            $templateVars = $this->withServableCertificate($templateVars, $project->projectDirPath().'/ssl-certs', $domain->domain);
        }

        return $templateVars;
    }

    /**
     * @param  list<string>  $domains
     */
    private function getListenerString(string $name, string $port, string $vhost, array $domains): string
    {
        $mapDomains = implode(', ', $domains);
        $cert = $this->getSslCertVars();

        return <<<CONFIG
<listener>
  <name>$name</name>
  <address>*:$port</address>
  <reusePort>1</reusePort>
  <secure>1</secure>
  <keyFile>{$cert['ssl_cert_key_file']}</keyFile>
  <certFile>{$cert['ssl_cert_file']}</certFile>
  <vhostMapList>
    <vhostMap>
      <vhost>$vhost</vhost>
      <domain>$mapDomains</domain>
    </vhostMap>
  </vhostMapList>
</listener>

CONFIG;
    }

    private function getVhostString(string $name): string
    {
        $docRoot = $this->system->engineDirPath().'/webserver-config/document-root';

        return <<<CONFIG
<virtualHost>
  <name>$name</name>
  <vhRoot>$docRoot</vhRoot>
  <configFile>\$SERVER_ROOT/conf/vhosts/$name.conf</configFile>
  <allowSymbolLink>1</allowSymbolLink>
  <enableScript>0</enableScript>
  <restrained>1</restrained>
</virtualHost>
CONFIG;
    }

    private function fallbackVirtualHost(): string
    {
        $vhRoot = $this->system->engineDirPath().'/webserver-config/document-root';

        return <<<CONFIG
<virtualHost>
    <name>fallback</name>
    <vhRoot>$vhRoot</vhRoot>
    <configFile>\$SERVER_ROOT/conf/vhosts/fallback.xml</configFile>
    <allowSymbolLink>1</allowSymbolLink>
    <enableScript>0</enableScript>
    <restrained>1</restrained>
</virtualHost>
CONFIG;
    }

    private function rebuildFallbackVirtualHostConfig(): void
    {
        $docRoot = $this->system->engineDirPath().'/webserver-config/document-root';
        $logDir = $this->system->engineDirPath().'/webserver-logs/litespeed/fallback';
        $virtualHostConfig = $this->fallbackVirtualHostConfig($docRoot, $logDir);

        $virtualHostConfigPath = $this->system->engineDirPath().'/webserver-config/litespeed/vhosts/fallback.xml';
        $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');
        $this->system->exec(Privileged::files()->makeDirectories($logDir));
    }

    private function fallbackVirtualHostConfig(string $docRoot, string $logDir): string
    {
        return <<<CONFIG
<?xml version="1.0" encoding="UTF-8"?>
<virtualHostConfig>
  <docRoot>$docRoot</docRoot>
  <customErrorPages>
    <errorPage>
      <errCode>404</errCode>
      <url>/404.html</url>
    </errorPage>
  </customErrorPages>
  <logging>
    <log>
      <useServer>0</useServer>
      <fileName>$logDir/error.log</fileName>
      <logLevel>ERROR</logLevel>
      <rollingSize>100M</rollingSize>
      <keepDays>7</keepDays>
      <compressArchive>1</compressArchive>
    </log>
    <accessLog>
      <useServer>0</useServer>
      <fileName>$logDir/access.log</fileName>
      <logHeaders>3</logHeaders>
      <rollingSize>100M</rollingSize>
      <keepDays>7</keepDays>
      <compressArchive>1</compressArchive>
    </accessLog>
  </logging>
  <rewrite>
    <enable>1</enable>
    <rules>
RewriteCond %{REQUEST_URI} !^/404\.html$
RewriteRule .* /404.html [L]
    </rules>
  </rewrite>
</virtualHostConfig>
CONFIG;
    }

    private function rebuildAppProxyVirtualHostConfig(): void
    {
        $docRoot = $this->system->engineDirPath().'/webserver-config/document-root';
        $appProxyVars = $this->getAppProxyVars();

        $this->writeAppProxyVirtualHostConfig('app-lite-ca', $docRoot, $appProxyVars['ca_proxy_host'], $appProxyVars['ca_proxy_port'], $appProxyVars);
        $this->writeAppProxyVirtualHostConfig('app-lite-aa', $docRoot, $appProxyVars['aa_proxy_host'], $appProxyVars['aa_proxy_port'], $appProxyVars);
    }

    private function getProxyVhostConfigString(string $name, string $docRoot, string $logDir, string $proxyHost, string $proxyPort, string $wsRewriteProxyHost, string $wsRewriteProxyPort): string
    {
        return <<<CONFIG
<virtualHostConfig>
  <docRoot>$docRoot</docRoot>
  <logging>
    <log>
      <useServer>0</useServer>
      <fileName>$logDir/error.log</fileName>
      <logLevel>WARNING</logLevel>
      <rollingSize>100M</rollingSize>
      <keepDays>30</keepDays>
      <compressArchive>1</compressArchive>
    </log>
    <accessLog>
      <useServer>0</useServer>
      <fileName>$logDir/access.log</fileName>
      <rollingSize>100M</rollingSize>
      <keepDays>30</keepDays>
      <compressArchive>1</compressArchive>
    </accessLog>
  </logging>
  <extProcessorList>
    <extProcessor>
      <type>proxy</type>
      <name>$name</name>
      <address>http://$proxyHost:$proxyPort</address>
      <maxConns>10</maxConns>
      <initTimeout>5</initTimeout>
      <retryTimeout>5</retryTimeout>
      <respBuffer>0</respBuffer>
    </extProcessor>
  </extProcessorList>
  <contextList>
    <context>
      <uri>/</uri>
      <type>proxy</type>
      <handler>$name</handler>
    </context>
  </contextList>
  <websocketList>
    <websocket>
      <uri>/ws/</uri>
      <address>$wsRewriteProxyHost:$wsRewriteProxyPort</address>
    </websocket>
  </websocketList>
</virtualHostConfig>
CONFIG;
    }

    private function replaceXmlNodeContents(string $xml, string $node, string $nodeContents): string
    {
        $offset = 0;
        $from = strpos($xml, "<{$node}>", $offset);
        while ($from !== false) {
            $offset = $from + strlen("<{$node}>");
            $to = strpos($xml, "</{$node}>", $offset);
            if ($to === false) {
                throw new \Exception('Cannot find closing tag for XML node');
            }
            $to += strlen("</{$node}>");

            $before = substr($xml, 0, $from);
            $after = substr($xml, $to);
            $replace = "<{$node}>\n{$nodeContents}</{$node}>";
            $xml = "{$before}{$replace}{$after}";
            $offset = $from + strlen($replace);
            $from = strpos($xml, "<{$node}>", $offset);
        }

        return $xml;
    }

    public function reload(bool $rebindIpListeners = false): void
    {
        $this->system->runProcess(Privileged::engineServices($this->system->composeFilePath())->litespeedControl('restart'));
    }

    public function restart(): void
    {
        $this->system->runProcess(Privileged::engineServices($this->system->composeFilePath())->litespeedControl('fullrestart'));
    }

    public function toggleModsecurity(): void
    {
        $config = Setting::getModsecConfig();

        $currentConfigPath = $this->system->engineDirPath().'/webserver-config/litespeed/httpd_config.xml';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);
        assert(! empty($currentConfig));

        [$dom, $httpServerConfig] = $this->httpServerConfigDom($currentConfig);
        $security = $httpServerConfig->getElementsByTagName('security')->item(0);
        assert($security instanceof \DOMElement);

        $this->removeCensorshipNodes($security);

        $enabled = $config['mode'] !== 'off' ? '1' : '0';
        $security->appendChild($this->importedNode($dom, $this->censorshipControlXml($enabled), 'censorshipControl'));
        $security->appendChild($this->importedNode($dom, $this->censorshipRuleSetXml(), 'censorshipRuleSet'));

        $newConfig = $dom->saveXML();
        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->restart();
    }

    /** @return array{0: \DOMDocument, 1: \DOMElement} */
    private function httpServerConfigDom(string $currentConfig): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($currentConfig);

        $httpServerConfig = $dom->getElementsByTagName('httpServerConfig')->item(0);
        assert($httpServerConfig instanceof \DOMElement);

        return [$dom, $httpServerConfig];
    }

    private function removeCensorshipNodes(\DOMElement $security): void
    {
        foreach (iterator_to_array($security->getElementsByTagName('censorshipControl')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($security->getElementsByTagName('censorshipRuleSet')) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function censorshipControlXml(string $enabled): string
    {
        $censorshipControl = "<censorshipControl>\n";
        $censorshipControl .= "  <enableCensorship>{$enabled}</enableCensorship>\n";
        $censorshipControl .= "  <logLevel>0</logLevel>\n";
        $censorshipControl .= "  <defaultAction>deny,log,status:403</defaultAction>\n";
        $censorshipControl .= "  <scanPOST>1</scanPOST>\n";
        $censorshipControl .= "</censorshipControl>\n";

        return $censorshipControl;
    }

    private function censorshipRuleSetXml(): string
    {
        $censorshipRuleSet = "<censorshipRuleSet>\n";
        $censorshipRuleSet .= "  <name>ModSec</name>\n";
        $censorshipRuleSet .= "  <enabled>1</enabled>\n";
        $censorshipRuleSet .= "  <ruleSet>include /opt/modsecurity/main.conf</ruleSet>\n";
        $censorshipRuleSet .= "</censorshipRuleSet>\n";

        return $censorshipRuleSet;
    }

    /** The first $tag element of $xml, imported into $dom. */
    private function importedNode(\DOMDocument $dom, string $xml, string $tag): \DOMNode|false
    {
        $temp = new \DOMDocument;
        $temp->loadXml($xml);
        /** @var \DOMElement $node */
        $node = $temp->getElementsByTagName($tag)->item(0);

        return $dom->importNode($node, true);
    }

    public function toggleLscache(): void
    {
        $disabled = ! empty(Setting::get('disable-lscache'));

        $cacheEngine = $disabled ? '0' : '7';
        $cacheStorePath = $this->system->engineDirPath().'/data/lscache';
        $this->system->runProcess(Privileged::files()->makeDirectories($cacheStorePath));
        $this->system->runProcess(Privileged::files()->changeOwnerRecursively('nobody:www-data', $cacheStorePath));

        $defaultCacheConfig = $this->defaultCacheConfig($cacheEngine, $cacheStorePath);

        $currentConfigPath = $this->system->engineDirPath().'/webserver-config/litespeed/httpd_config.xml';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);
        assert(! empty($currentConfig));

        [$dom, $httpServerConfig] = $this->httpServerConfigDom($currentConfig);
        $cacheConfig = $httpServerConfig->getElementsByTagName('cache')->item(0);
        if ($cacheConfig === null) {
            $this->appendCacheNode($dom, $httpServerConfig, $defaultCacheConfig);
            $this->saveCacheConfig($dom, $currentConfigPath);

            return;
        }

        $this->setCacheEngine($cacheConfig, $cacheEngine);
        $this->saveCacheConfig($dom, $currentConfigPath);
    }

    private function defaultCacheConfig(string $cacheEngine, string $cacheStorePath): string
    {
        $defaultCacheConfig = '';
        $defaultCacheConfig .= "<cache>\n";
        $defaultCacheConfig .= "  <cacheEngine>{$cacheEngine}</cacheEngine>\n";
        $defaultCacheConfig .= "  <storage>\n";
        $defaultCacheConfig .= "    <cacheStorePath>{$cacheStorePath}</cacheStorePath>\n";
        $defaultCacheConfig .= "    <pubStoreExpireMinutes>720</pubStoreExpireMinutes>\n";
        $defaultCacheConfig .= "    <purgeNoHitTimeout>360</purgeNoHitTimeout>\n";
        $defaultCacheConfig .= "  </storage>\n";
        $defaultCacheConfig .= "  <cachePolicy>\n";
        $defaultCacheConfig .= "    <expireInSeconds>86400</expireInSeconds>\n";
        $defaultCacheConfig .= "  </cachePolicy>\n";
        $defaultCacheConfig .= "</cache>\n";

        return $defaultCacheConfig;
    }

    private function appendCacheNode(\DOMDocument $dom, \DOMElement $httpServerConfig, string $defaultCacheConfig): void
    {
        $temp = new \DOMDocument;
        $temp->loadXml($defaultCacheConfig);
        $cacheNode = $temp->getElementsByTagName('cache')->item(0);
        assert($cacheNode instanceof \DOMElement);
        $cacheNode = $dom->importNode($cacheNode, true);
        $httpServerConfig->appendChild($cacheNode);
    }

    private function setCacheEngine(\DOMNode $cacheConfig, string $cacheEngine): void
    {
        assert($cacheConfig instanceof \DOMElement);
        $cacheEngineConfig = $cacheConfig->getElementsByTagName('cacheEngine')->item(0);
        assert($cacheEngineConfig instanceof \DOMElement);

        $cacheEngineConfig->nodeValue = $cacheEngine;
    }

    private function saveCacheConfig(\DOMDocument $dom, string $currentConfigPath): void
    {
        $newConfig = $dom->saveXML();
        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->rebuildConfig();
        $this->reload();
    }

    /** The glob stays a glob; the name is a positional argument, never shell. */
    public function deleteDomainsConfigs(array $domainNames): void
    {
        $dir = $this->system->engineDirPath().'/webserver-config/litespeed/vhosts';
        foreach ($domainNames as $domainName) {
            $configFile = $dir.'/'.$domainName.'.xml';
            $this->system->exec(Privileged::scripts()->removeStartingWith($configFile));
        }
    }
}
