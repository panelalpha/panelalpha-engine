<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
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
     * @param ?array<Domain> $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        $currentConfigPath = $this->system->engineDirPath() . '/webserver-config/litespeed/httpd_config.xml';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);
        $listenerTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath() . '/listener-litespeed.blade.php');
        $virtualHostTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath() . '/virtualHost-litespeed.blade.php');
        $virtualHostConfigTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath() . '/virtualHostConfig-litespeed.blade.php');

        $users = User::getAll();
        $allDomains = [];

        $listeners = [];
        foreach ($users as $user) {
            $domains = $user->getDomains();
            $aliasesByDomain = [];
            foreach ($domains as $domain) {
                $aliasesByDomain[$domain->domain] = $domain->getVhostAltNames();
                $allDomains[] = $domain;
            }
            $ips = $user->getBindIpAddresses();
            foreach ($ips['ipv4'] as $ipv4) {
                foreach ($aliasesByDomain as $domainName => $aliases) {
                    $listeners[$ipv4][$domainName] = $aliases;
                }
            }
            foreach ($ips['ipv6'] as $ipv6) {
                foreach ($aliasesByDomain as $domainName => $aliases) {
                    $listeners[$ipv6][$domainName] = $aliases;
                }
            }
        }

        $otherListenerMaps = [];
        $otherListeners = [];
        $otherVhosts = [];
        if (config('env.APP_LITE_PROXY_ENABLED')) {
            /** @var mixed */
            $caDomain = config('env.APP_LITE_CA_DOMAIN');
            if (empty($caDomain) || !is_string($caDomain)) {
                $caDomain = '*';
            }
            /** @var mixed */
            $aaDomain = config('env.APP_LITE_AA_DOMAIN');
            if (empty($aaDomain) || !is_string($aaDomain)) {
                $aaDomain = '*';
            }

            if (!config('env.APP_LITE_CA_PORT')) {
                $otherListenerMaps['app-lite-ca'] = [$caDomain];
            }
            if (!config('env.APP_LITE_AA_PORT')) {
                $otherListenerMaps['app-lite-aa'] = [$aaDomain];
            }
            /** @var mixed $appLiteCaPortConfig */
            $appLiteCaPortConfig = config('env.APP_LITE_CA_PORT');
            if (
                !empty($appLiteCaPortConfig)
                && (is_string($appLiteCaPortConfig) || is_int($appLiteCaPortConfig))
                && !in_array($appLiteCaPortConfig, [80, 443])
            ) {
                $otherListeners[] = $this->getListenerString('listener-app-lite-ca', (string)$appLiteCaPortConfig, 'app-lite-ca', [$caDomain]);
            }
            /** @var mixed */
            $appLiteAaPortConfig = config('env.APP_LITE_AA_PORT');
            if (
                $appLiteAaPortConfig
                && (is_string($appLiteAaPortConfig) || is_int($appLiteAaPortConfig))
                && !in_array($appLiteAaPortConfig, [80, 443])
            ) {
                $otherListeners[] = $this->getListenerString('listener-app-lite-aa', (string)$appLiteAaPortConfig, 'app-lite-aa', [$aaDomain]);
            }
            $otherVhosts[] = $this->getVhostString('app-lite-ca');
            $otherVhosts[] = $this->getVhostString('app-lite-aa');
            $this->rebuildAppProxyVirtualHostConfig();
        }

        $listenersStrings = [];
        foreach ($listeners as $ip => $domains) {
            $isv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? true : false;
            $templateVars = [
                'ip' => $ip,
                'listen_address' => $isv6 ? "[{$ip}]" : $ip,
                'domains' => $domains,
                'other_listener_maps' => $otherListenerMaps,
            ];
            $listenersStrings[] = Blade::render($listenerTemplate, $templateVars);
        }
        foreach ($otherListeners as $otherListener) {
            $listenersStrings[] = $otherListener;
        }

        $listenersString = implode("\n", $listenersStrings);

        $cacheEnabled = empty(Setting::get('disable-lscache'));
        $virtualHosts = [];
        foreach ($allDomains as $domain) {
            $user = $domain->getUser();
            $cacheStorePath = $this->system->projectHomeDirPath($user->username) . "/" . $domain->domain . "/.lscache";
            if ($cacheEnabled) {
                $this->system->runProcess(["sudo", "mkdir", "-p", $cacheStorePath]);
                $this->system->runProcess(["sudo", "chown", "-R", "nobody:{$user->username}", $cacheStorePath]);
            }
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
                'cache_engine' => $cacheEnabled ? "7" : "0",
                'cache_store_path' => $cacheStorePath,
                'cache_check_public' => $cacheEnabled ? "1" : "0",
                'cache_check_private' => $cacheEnabled ? "1" : "0",
            ];
            $templateVars = array_merge($templateVars, $this->httpAcmeChallengeTemplateVars($domain));
            if ($connection->hasServableCertificate()) {
                $certDir = $project->projectDirPath() . "/ssl-certs";
                $keyFile = "{$certDir}/{$domain->domain}.key";
                $pemFile = "{$certDir}/{$domain->domain}.pem";
                $templateVars['ssl_enabled'] = true;
                $templateVars['ssl_cert_pem_file'] = $pemFile;
                $templateVars['ssl_cert_key_file'] = $keyFile;
            }
            $virtualHostConfig = Blade::render($virtualHostConfigTemplate, $templateVars);
            $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/litespeed/vhosts/' . $domain->domain . '.xml';
            $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');

            $logsDir = $this->system->engineDirPath() . '/webserver-logs/litespeed/' . $domain->domain;
            $this->system->runProcess(["sudo", "mkdir", "-p", $logsDir]);
            $this->system->runProcess(["sudo", "chown", "-R", "nobody:www-data", $logsDir]);

            $virtualHosts[] = Blade::render($virtualHostTemplate, $templateVars);
        }

        $this->rebuildFallbackVirtualHostConfig();
        $virtualHosts[] = $this->fallbackVirtualHost();

        foreach ($otherVhosts as $otherVhost) {
            $virtualHosts[] = $otherVhost;
        }

        $virtualHostsString = implode("\n", $virtualHosts);
        $newConfig = $this->replaceXmlNodeContents($currentConfig, 'listenerList', $listenersString);
        $newConfig = $this->replaceXmlNodeContents($newConfig, 'virtualHostList', $virtualHostsString);

        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->applyCloudflareRealIpConfig();
    }

    /**
     * @param list<string> $domains
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
        $docRoot = $this->system->engineDirPath() . '/webserver-config/document-root';
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
        $vhRoot = $this->system->engineDirPath() . '/webserver-config/document-root';
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
        $docRoot = $this->system->engineDirPath() . '/webserver-config/document-root';
        $logDir = $this->system->engineDirPath() . '/webserver-logs/litespeed/fallback';
        $virtualHostConfig = <<<CONFIG
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

        $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/litespeed/vhosts/fallback.xml';
        $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');
        $this->system->exec([
            'sudo',
            'mkdir',
            '-p',
            $logDir,
        ]);
    }

    private function rebuildAppProxyVirtualHostConfig(): void
    {
        $docRoot = $this->system->engineDirPath() . '/webserver-config/document-root';
        $appProxyVars = $this->getAppProxyVars();

        // CA
        $logDir = $this->system->engineDirPath() . '/webserver-logs/litespeed/app-lite-ca';
        $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/litespeed/vhosts/app-lite-ca.conf';
        $virtualHostConfig = $this->getProxyVhostConfigString(
            'proxy-app-lite-ca',
            $docRoot,
            $logDir,
            $appProxyVars['ca_proxy_host'],
            $appProxyVars['ca_proxy_port'],
            $appProxyVars['ws_rewrite_proxy_host'],
            $appProxyVars['ws_rewrite_proxy_port'],
        );
        $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');
        $this->system->exec([
            'sudo',
            'mkdir',
            '-p',
            $logDir,
        ]);

        // AA
        $logDir = $this->system->engineDirPath() . '/webserver-logs/litespeed/app-lite-aa';
        $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/litespeed/vhosts/app-lite-aa.conf';
        $virtualHostConfig = $this->getProxyVhostConfigString(
            'proxy-app-lite-aa',
            $docRoot,
            $logDir,
            $appProxyVars['aa_proxy_host'],
            $appProxyVars['aa_proxy_port'],
            $appProxyVars['ws_rewrite_proxy_host'],
            $appProxyVars['ws_rewrite_proxy_port'],
        );
        $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');
        $this->system->exec([
            'sudo',
            'mkdir',
            '-p',
            $logDir,
        ]);
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

    // /**
    //  * @param ?array<Domain> $domains
    //  */
    // public function rebuildConfig(?array $domains = null): void
    // {
    //     if ($domains === null) {
    //         $domains = Domain::getAll();
    //     }

    //     $currentConfigPath = $this->system->engineDirPath() . '/webserver-config/litespeed/httpd_config.xml';
    //     $currentConfig = file_get_contents($currentConfigPath);

    //     $vhostMapTemplate = file_get_contents($this->system->templatesDirPath() . '/vhostMap-litespeed.blade.php');
    //     $virtualHostTemplate = file_get_contents($this->system->templatesDirPath() . '/virtualHost-litespeed.blade.php');
    //     $virtualHostConfigTemplate = file_get_contents($this->system->templatesDirPath() . '/virtualHostConfig-litespeed.blade.php');

    //     $vhostMaps = [];
    //     $virtualHosts = [];

    //     foreach ($domains as $domain) {

    //         $templateVars = [
    //             'domain' => $domain->domain,
    //             'aliases' => $domain->getAliases(),
    //             'relative_document_root' => $domain->getDocumentRoot(),
    //             'user' => $domain->getUser()->username,
    //         ];
    //         $virtualHostConfig = Blade::render($virtualHostConfigTemplate, $templateVars);
    //         $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/litespeed/vhosts/' . $domain->domain . '.xml';
    //         $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig);

    //         $vhostMaps[] = Blade::render($vhostMapTemplate, $templateVars);
    //         $virtualHosts[] = Blade::render($virtualHostTemplate, $templateVars);
    //     }

    //     $vhostMapsString = implode("\n", $vhostMaps);
    //     $newConfig = $this->replaceXmlNodeContents($currentConfig, 'vhostMapList', $vhostMapsString);

    //     $virtualHostsString = implode("\n", $virtualHosts);
    //     $newConfig = $this->replaceXmlNodeContents($newConfig, 'virtualHostList', $virtualHostsString);

    //     $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
    // }

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
        $this->system->runProcess([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'sites-http',
            '/usr/local/lsws/bin/lswsctrl',
            'restart',
        ]);
    }

    public function restart(): void
    {
        $this->system->runProcess([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'sites-http',
            '/usr/local/lsws/bin/lswsctrl',
            'fullrestart',
        ]);
    }

    public function toggleModsecurity(): void
    {
        $config = Setting::getModsecConfig();

        $currentConfigPath = $this->system->engineDirPath() . '/webserver-config/litespeed/httpd_config.xml';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);
        assert(!empty($currentConfig));

        $dom = new \DOMDocument();
        $dom->loadXML($currentConfig);

        $httpServerConfig = $dom->getElementsByTagName('httpServerConfig')->item(0);
        assert($httpServerConfig instanceof \DOMElement);
        $security = $httpServerConfig->getElementsByTagName('security')->item(0);
        assert($security instanceof \DOMElement);

        foreach ($security->getElementsByTagName('censorshipControl') as $node) {
            /** @var \DOMNode $node */
            $security->removeChild($node);
        }

        foreach ($security->getElementsByTagName('censorshipRuleSet') as $node) {
            /** @var \DOMNode $node */
            $security->removeChild($node);
        }

        $enabled = $config['mode'] !== 'off' ? '1' : '0';

        $censorshipControl = "<censorshipControl>\n";
        $censorshipControl .= "  <enableCensorship>{$enabled}</enableCensorship>\n";
        $censorshipControl .= "  <logLevel>0</logLevel>\n";
        $censorshipControl .= "  <defaultAction>deny,log,status:403</defaultAction>\n";
        $censorshipControl .= "  <scanPOST>1</scanPOST>\n";
        $censorshipControl .= "</censorshipControl>\n";

        $temp = new \DOMDocument();
        $temp->loadXml($censorshipControl);
        /** @var \DOMElement $censorshipControlNode */
        $censorshipControlNode = $temp->getElementsByTagName('censorshipControl')->item(0);
        $censorshipControlNode = $dom->importNode($censorshipControlNode, true);

        $security->appendChild($censorshipControlNode);

        $censorshipRuleSet = "<censorshipRuleSet>\n";
        $censorshipRuleSet .= "  <name>ModSec</name>\n";
        $censorshipRuleSet .= "  <enabled>1</enabled>\n";
        $censorshipRuleSet .= "  <ruleSet>include /opt/modsecurity/main.conf</ruleSet>\n";
        $censorshipRuleSet .= "</censorshipRuleSet>\n";

        $temp = new \DOMDocument();
        $temp->loadXml($censorshipRuleSet);
        /** @var \DOMElement $censorshipRuleSetNode */
        $censorshipRuleSetNode = $temp->getElementsByTagName('censorshipRuleSet')->item(0);
        $censorshipRuleSetNode = $dom->importNode($censorshipRuleSetNode, true);

        $security->appendChild($censorshipRuleSetNode);

        $newConfig = $dom->saveXML();
        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->restart();
    }

    public function toggleLscache(): void
    {
        $disabled = !empty(Setting::get('disable-lscache'));

        $cacheEngine = $disabled ? "0" : "7";
        $cacheStorePath = $this->system->engineDirPath() . '/data/lscache';
        $this->system->runProcess(["sudo", "mkdir", "-p", $cacheStorePath]);
        $this->system->runProcess(["sudo", "chown", "-R", "nobody:www-data", $cacheStorePath]);

        $defaultCacheConfig = "";
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

        $currentConfigPath = $this->system->engineDirPath() . '/webserver-config/litespeed/httpd_config.xml';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);
        assert(!empty($currentConfig));

        $dom = new \DOMDocument();
        $dom->loadXML($currentConfig);

        $httpServerConfig = $dom->getElementsByTagName('httpServerConfig')->item(0);
        assert($httpServerConfig instanceof \DOMElement);
        $cacheConfig = $httpServerConfig->getElementsByTagName('cache')->item(0);
        if ($cacheConfig === null) {
            $temp = new \DOMDocument();
            $temp->loadXml($defaultCacheConfig);
            $cacheNode = $temp->getElementsByTagName('cache')->item(0);
            assert($cacheNode instanceof \DOMElement);
            $cacheNode = $dom->importNode($cacheNode, true);
            $httpServerConfig->appendChild($cacheNode);
            $newConfig = $dom->saveXML();
            $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
            $this->rebuildConfig();
            $this->reload();
            return;
        }

        assert($cacheConfig instanceof \DOMElement);
        $cacheEngineConfig = $cacheConfig->getElementsByTagName('cacheEngine')->item(0);
        assert($cacheEngineConfig instanceof \DOMElement);

        $cacheEngineConfig->nodeValue = $cacheEngine;
        $newConfig = $dom->saveXML();
        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->rebuildConfig();
        $this->reload();
    }

    public function deleteDomainsConfigs(array $domainNames): void
    {
        $dir = $this->system->engineDirPath() . '/webserver-config/litespeed/vhosts';
        foreach ($domainNames as $domainName) {
            $configFile = $dir . '/' . $domainName . '.xml';
            $this->system->exec("sudo rm -f {$configFile}*");
        }
    }
}
