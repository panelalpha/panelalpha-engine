<?php

namespace App\System\Services\Webserver;

use App\Lib\Helpers\Config;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
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
                'serial_number' => $this->getSerialNumber(),
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
     * @param ?array<Domain> $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        $currentConfigPath = $this->system->engineDirPath() . '/webserver-config/openlitespeed/httpd_config.conf';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);
        $listenerTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath() . '/listener-openlitespeed.blade.php');
        $virtualHostTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath() . '/virtualHost-openlitespeed.blade.php');
        $virtualHostConfigTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath() . '/virtualHostConfig-openlitespeed.blade.php');

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
            $appLiteCaPort = Config::getIntValue('env.APP_LITE_CA_PORT');
            if (
                $appLiteCaPort
                && !in_array($appLiteCaPort, [80, 443])
            ) {
                $otherListeners[] = $this->getListenerString('listener-app-lite-ca', (string)$appLiteCaPort, 'app-lite-ca', [$caDomain]);
            }
            $appLiteAaPort = Config::getIntValue('env.APP_LITE_AA_PORT');
            if (
                $appLiteAaPort
                && !in_array($appLiteAaPort, [80, 443])
            ) {
                $otherListeners[] = $this->getListenerString('listener-app-lite-aa', (string)$appLiteAaPort, 'app-lite-aa', [$aaDomain]);
            }
            $otherVhosts[] = $this->getVhostString('app-lite-ca');
            $otherVhosts[] = $this->getVhostString('app-lite-aa');
            $this->rebuildAppProxyVirtualHostConfig();
        }

        $listenersStrings = [];
        foreach ($listeners as $ip => $listenerDomains) {
            $isv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? true : false;
            $templateVars = [
                'ip' => $ip,
                'listen_address' => $isv6 ? "[{$ip}]" : $ip,
                'domains' => $listenerDomains,
                'other_listener_maps' => $otherListenerMaps,
            ];
            $listenersStrings[] = Blade::render($listenerTemplate, $templateVars);
        }
        foreach($otherListeners as $otherListener) {
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
                'cache_enabled' => $cacheEnabled ? "1" : "0",
                'cache_store_path' => $cacheStorePath,
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
            $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/openlitespeed/vhosts/' . $domain->domain . '.conf';
            $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');

            $this->system->exec([
                'sudo',
                'mkdir',
                '-p',
                $this->system->engineDirPath() . '/webserver-logs/openlitespeed/' . $domain->domain,
            ]);

            $virtualHosts[] = Blade::render($virtualHostTemplate, $templateVars);
        }

        $this->rebuildFallbackVirtualHostConfig();
        $virtualHosts[] = $this->fallbackVirtualHost();

        foreach($otherVhosts as $otherVhost) {
            $virtualHosts[] = $otherVhost;
        }

        $virtualHostsString = implode("\n", $virtualHosts);

        $newConfig = $this->removeBlocks($currentConfig, 'listener');
        $newConfig = $this->removeBlocks($newConfig, 'virtualhost');

        $newConfig = trim($newConfig) . "\n\n" . trim($listenersString) . "\n\n" . trim($virtualHostsString);
        $newConfig = preg_replace('/\R{3,}/', "\n\n", $newConfig);

        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->applyCloudflareRealIpConfig();
    }

    private function fallbackVirtualHost(): string
    {
        $vhRoot = $this->system->engineDirPath() . '/webserver-config/document-root';
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
        $docRoot = $this->system->engineDirPath() . '/webserver-config/document-root';
        $logDir = $this->system->engineDirPath() . '/webserver-logs/openlitespeed/fallback';
        $virtualHostConfig = <<<CONFIG
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

        $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/openlitespeed/vhosts/fallback.conf';
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
        $logDir = $this->system->engineDirPath() . '/webserver-logs/openlitespeed/app-lite-ca';
        $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/openlitespeed/vhosts/app-lite-ca.conf';
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
        $logDir = $this->system->engineDirPath() . '/webserver-logs/openlitespeed/app-lite-aa';
        $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/openlitespeed/vhosts/app-lite-aa.conf';
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

    /**
     * @param array<string> $domains
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
        $docRoot = $this->system->engineDirPath() . '/webserver-config/document-root';
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
        $from = stripos($config, $blockName . " ", $offset);
        while ($from !== false) {
            $to = strpos($config, "}", $from);
            if ($to === false) {
                throw new \Exception("Cannot find closing tag for {$blockName} block");
            }
            $to++;
            if (!Str::contains(substr($config, $from, $to - $from), '{')) {
                $from = $to;
                continue;
            }
            $before = substr($config, 0, $from);
            $after = substr($config, $to);
            $config = "{$before}{$after}";
            $offset = $from;
            $from = strpos($config, $blockName . " ", $offset);
        }

        return $config;
    }

    // /**
    //  * @param ?array<Domain> $domains
    //  */
    // public function rebuildConfig(?array $domains = null): void
    // {
    //     if ($domains === null) {
    //         /** @var Collection<array-key, Domain> */
    //         $domains = Domain::get();
    //     }

    //     $currentConfigPath = $this->system->engineDirPath() . '/webserver-config/openlitespeed/httpd_config.conf';
    //     $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);
    //     $virtualHostTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath() . '/virtualHost-openlitespeed.blade.php');
    //     $virtualHostConfigTemplate = $this->system->filesystem()->fileGetContents($this->system->templatesDirPath() . '/virtualHostConfig-openlitespeed.blade.php');

    //     $virtualHosts = [];
    //     $mapDirectives = [];

    //     foreach ($domains as $domain) {

    //         $templateVars = [
    //             'domain' => $domain->domain,
    //             'aliases' => $domain->getAliases(),
    //             'relative_document_root' => $domain->getDocumentRoot(),
    //             'user' => $domain->user?->username,
    //             'php_port' => $domain->projectDomain()->project()->getPhpPort($domain->getPhpVersion()),
    //         ];
    //         if ($domain->projectDomain()->hasSslCertificate()) {
    //             $certDir = $domain->projectDomain()->project()->projectSslCertsDirPath() . "/ssl-certs";
    //             $keyFile = "{$certDir}/{$domain->domain}.key";
    //             $pemFile = "{$certDir}/{$domain->domain}.pem";
    //             $templateVars['ssl_enabled'] = true;
    //             $templateVars['ssl_cert_pem_file'] = $pemFile;
    //             $templateVars['ssl_cert_key_file'] = $keyFile;
    //         }
    //         $virtualHostConfig = Blade::render($virtualHostConfigTemplate, $templateVars);
    //         $virtualHostConfigPath = $this->system->engineDirPath() . '/webserver-config/openlitespeed/vhosts/' . $domain->domain . '.conf';
    //         $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');

    //         $this->system->exec([
    //             'sudo',
    //             'mkdir',
    //             '-p',
    //             $this->system->engineDirPath() . '/webserver-logs/openlitespeed/' . $domain->domain,
    //         ]);

    //         $virtualHosts[] = Blade::render($virtualHostTemplate, $templateVars);
    //         $mapHosts = [
    //             $domain->domain,
    //             ...$domain->getAliases(),
    //         ];
    //         $mapDirectives[] = "map {$domain->domain} " . implode(', ', $mapHosts);
    //     }

    //     $virtualHostsString = implode("\n", $virtualHosts);
    //     $newConfig = $this->replaceVhostBlocks($currentConfig, $virtualHostsString);
    //     $newConfig = $this->replaceMapsDirectives($newConfig, $mapDirectives);

    //     $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
    // }

    private function replaceVhostBlocks(string $config, string $blocks): string
    {
        $offset = 0;
        $from = strpos($config, "virtualhost ", $offset);
        while ($from !== false) {
            $to = strpos($config, "}", $from);
            if ($to === false) {
                throw new \Exception('Cannot find closing tag for virtualhost block');
            }
            $to++;
            $before = substr($config, 0, $from);
            $after = substr($config, $to);
            $config = "{$before}{$after}";
            $offset = $from;
            $from = strpos($config, "virtualhost", $offset);
        }

        $config = trim($config) . "\n" . $blocks;

        return $config;
    }

    /**
     * @param array<string> $mapDirectives
     */
    private function replaceMapsDirectives(string $config, array $mapDirectives): string
    {
        $offset = 0;
        $from = stripos($config, "listener", $offset);
        while ($from !== false) {
            $to = stripos($config, '}', $from);
            if ($to === false) {
                throw new \Exception('Cannot find closing tag for virtualhost block');
            }
            $to++;

            $before = substr($config, 0, $from);
            $listenerBlock = substr($config, $from, $to - $from);
            $after = substr($config, $to);

            $lines = explode("\n", $listenerBlock);
            $newLines = [];
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if (substr($trimmedLine, 0, 4) == "map ") {
                    continue;
                }
                $newLines[] = $line;
            }

            $lastLine = array_pop($newLines);
            $newLines = array_merge($newLines, $mapDirectives);
            $newLines[] = $lastLine;

            $newListenerBlock = implode("\n", $newLines);
            $config = "{$before}{$newListenerBlock}{$after}";

            $offset = $from + strlen($newListenerBlock);
            $from = stripos($config, "listener", $offset);
        }

        return $config;
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
            // 'service',
            // 'lsws',
            // 'reload',
            '/usr/local/lsws/bin/lswsctrl',
            'condrestart',
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

    public function listDomains(): array
    {
        $dir = $this->system->engineDirPath() . '/webserver-config/openlitespeed/vhosts';
        $domains = [];
        foreach ((new \FilesystemIterator($dir)) as $file) {
            if (!($file instanceof \SplFileInfo)) {
                continue;
            }
            if ($file->getExtension() == 'conf') {
                $domains[] = substr($file->getFilename(), 0, -5);
            }
        }
        return $domains;
    }

    public function deleteDomainsConfigs(array $domainNames): void
    {
        $dir = $this->system->engineDirPath() . '/webserver-config/openlitespeed/vhosts';
        foreach ($domainNames as $domainName) {
            $configFile = $dir . '/' . $domainName . '.conf';
            // The glob stays a glob; the name is a positional argument, never shell.
            $this->system->exec(['sudo', 'sh', '-c', 'rm -f -- "$1"*', 'sh', $configFile]);
        }
    }

    /**
     * TODO method for testing, remove later
     */
    public function updateConfig(string $name, string $value): void
    {
        if ($name !== 'serial_number') {
            throw new \Exception('Unsupported config entry for current webserver');
        }

        $this->validateSerialNumber($value);
        $this->updateSerialNumber($value);
    }

    /**
     * TODO method for testing, remove later
     */
    private function updateSerialNumber(string $serialNumber): void
    {
        $configDir = $this->system->engineDirPath() . '/webserver-config/' . $this->getSlug();
        $configFiles = $this->system->filesystem()->ls($configDir);

        if (!in_array('serial.no', $configFiles)) {
            $this->system->filesystem()->filePutContents($configDir . '/serial.no', $serialNumber, '994:994');
            // $this->reload();
            return;
        }

        $backupFileSuffix = ".bak" . time();
        $moveFiles = [
            "{$configDir}/serial.no" => "{$configDir}/serial.no{$backupFileSuffix}",
            "{$configDir}/license.key" => "{$configDir}/license.key{$backupFileSuffix}",
            "{$configDir}/trial.key" => "{$configDir}/trial.key{$backupFileSuffix}",
        ];

        foreach ($moveFiles as $from => $to) {
            $this->system->runProcess([
                'sudo',
                'mv',
                $from,
                $to,
            ]);
        }

        $this->system->filesystem()->filePutContents($configDir . '/serial.no', $serialNumber, '994:994');
        // $this->system->exec([
        //     'sudo',
        //     'docker',
        //     'compose',
        //     '-f',
        //     $this->system->engineDirPath() . '/docker-compose.yml',
        //     'exec',
        //     'sites-http',
        //     '/usr/local/lsws/bin/lshttpd',
        //     '-r'
        // ]);
        // $this->reload();

        $keepBackups = [];
        $removeBackups = [];
        foreach ($configFiles as $filename) {

            if (!Str::startsWith($filename, [
                'serial.no.bak',
                'license.key.bak',
                'trial.key.bak'
            ])) {
                continue;
            }
            $suffix = Str::afterLast($filename, 'serial.no.bak');
            if (!$suffix) {
                continue;
            }
            if (count($keepBackups) < 10) {
                if (!in_array($suffix, $keepBackups)) {
                    $keepBackups[] = $suffix;
                }
                continue;
            }
            $removeBackups[] = $suffix;
        }
        foreach ($removeBackups as $removeSuffix) {
            $this->system->runProcess(["sudo", "rm", "{$configDir}/serial.no.bak{$removeSuffix}"]);
            $this->system->runProcess(["sudo", "rm", "{$configDir}/license.key.bak{$removeSuffix}"]);
            $this->system->runProcess(["sudo", "rm", "{$configDir}/trial.key.bak{$removeSuffix}"]);
        }
    }

    private function replaceModsecurity(string $config, string $blocks): string
    {
        $offset = 0;
        $from = strpos($config, "virtualhost ", $offset);
        while ($from !== false) {
            $to = strpos($config, "}", $from);
            if ($to === false) {
                throw new \Exception('Cannot find closing tag for virtualhost block');
            }
            $to++;
            $before = substr($config, 0, $from);
            $after = substr($config, $to);
            $config = "{$before}{$after}";
            $offset = $from;
            $from = strpos($config, "virtualhost", $offset);
        }

        $config = trim($config) . "\n" . $blocks;

        return $config;
    }


    public function toggleModsecurity(): void
    {
        $config = Setting::getModsecConfig();

        $currentConfigPath = $this->system->engineDirPath() . '/webserver-config/openlitespeed/httpd_config.conf';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);

        $blockExists = stripos($currentConfig, 'module mod_security') !== false;

        if ($config['mode'] === 'off' && !$blockExists) {
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

        $newConfig = trim($newConfig) . "\n\n" . $modsecString;
        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->restart();
    }

    public function toggleLscache(): void
    {
        $disabled = !empty(Setting::get('disable-lscache'));
        $cacheStorePath = $this->system->engineDirPath() . '/data/lscache';
        $cacheLsEnabled = $disabled ? "0" : "1";
        $this->system->runProcess(["sudo", "mkdir", "-p", $cacheStorePath]);
        $this->system->runProcess(["sudo", "chown", "-R", "nobody:www-data", $cacheStorePath]);

        $currentConfigPath = $this->system->engineDirPath() . '/webserver-config/openlitespeed/httpd_config.conf';
        $currentConfig = $this->system->filesystem()->fileGetContents($currentConfigPath);

        $blockExists = stripos($currentConfig, 'module cache') !== false;
        if ($disabled && !$blockExists) {
            return;
        }

        $newConfig = $this->removeBlocks($currentConfig, 'module cache');
        if ($disabled) {
            $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
            $this->reload();
            return;
        }

        $configString = "";
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

        $newConfig = trim($newConfig) . "\n\n" . $configString;
        $this->system->filesystem()->filePutContents($currentConfigPath, $newConfig);
        $this->rebuildConfig();
        $this->reload();
    }
}
