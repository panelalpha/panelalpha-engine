<?php

namespace App\System\Services\Webserver;

use App\Exceptions\DockerErrorException;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System\Privileged;
use App\System\Privileged\RootEngineServices;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait LiteSpeedTrait
{
    public function createDomainConfig(Domain $domain): void
    {
        $this->rebuildConfig();
    }

    private function getVersion(): string
    {
        try {
            $ver = $this->system->exec(Privileged::engineServices($this->system->composeFilePath())->litespeedVersion());

            return trim($ver);
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    public function resetWebPanelPassword(): string
    {
        $newPassword = Str::random(16);

        $this->system->exec(
            Privileged::engineServices($this->system->composeFilePath())->litespeedResetAdminPassword(),
            [RootEngineServices::LITESPEED_PASSWORD => $newPassword],
        );

        return $newPassword;
    }

    public function validateSerialNumberForConfig(string $serialNumber): void
    {
        $this->validateSerialNumber($serialNumber);
    }

    public function updateConfig(string $name, string $value): void
    {
        if ($name !== 'serial_number') {
            throw new \Exception('Unsupported config entry for current webserver');
        }

        $this->validateSerialNumber($value);
        $this->updateSerialNumber($value);
    }

    private function getSerialNumber(): string
    {
        $filePath = $this->system->engineDirPath().'/webserver-config/'.$this->getSlug().'/serial.no';
        if (! $this->system->filesystem()->fileExists($filePath)) {
            return '';
        }

        return $this->system->filesystem()->fileGetContents($filePath);
    }

    private function validateSerialNumber(string $serialNumber): void
    {
        $configDir = $this->system->engineDirPath().'/webserver-config/'.$this->getSlug();
        $this->system->filesystem()->filePutContents($configDir.'/serial.no.tmp', $serialNumber, '994:994');
        try {
            $this->system->exec(Privileged::docker()->checkLiteSpeedSerial("{$configDir}/serial.no.tmp"));
        } catch (DockerErrorException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('LiteSpeed serial number validation failed', [
                'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'serial_number' => 'The LiteSpeed serial number is invalid.',
            ]);
        }
    }

    private function updateSerialNumber(string $serialNumber): void
    {
        $configDir = $this->system->engineDirPath().'/webserver-config/'.$this->getSlug();
        $configFiles = $this->system->filesystem()->ls($configDir);

        if (! in_array('serial.no', $configFiles)) {
            $this->system->filesystem()->filePutContents($configDir.'/serial.no', $serialNumber, '994:994');
            $this->reload();

            return;
        }

        $this->backUpSerialFiles($configDir);

        $this->system->filesystem()->filePutContents($configDir.'/serial.no', $serialNumber, '994:994');
        $this->system->exec(Privileged::engineServices($this->system->composeFilePath())->litespeedConfigTest());
        $this->reload();

        $this->pruneSerialBackups($configDir);
    }

    private function backUpSerialFiles(string $configDir): void
    {
        $backupFileSuffix = '.bak'.time();
        $moveFiles = [
            "{$configDir}/serial.no" => "{$configDir}/serial.no{$backupFileSuffix}",
            "{$configDir}/license.key" => "{$configDir}/license.key{$backupFileSuffix}",
            "{$configDir}/trial.key" => "{$configDir}/trial.key{$backupFileSuffix}",
        ];

        foreach ($moveFiles as $from => $to) {
            $this->system->runProcess(Privileged::files()->move($from, $to));
        }
    }

    /** Removes the serial.no backups older than the newest ten; license and trial key backups are kept. */
    private function pruneSerialBackups(string $configDir): void
    {
        foreach ($this->serialBackupsToRemove($this->system->filesystem()->ls($configDir)) as $removeSuffix) {
            $this->system->runProcess(Privileged::files()->remove("{$configDir}/serial.no.bak{$removeSuffix}"));
        }
    }

    /**
     * The timestamp suffixes of the serial.no backups after the newest ten.
     *
     * @param  array<string>  $configFiles
     * @return list<string>
     */
    private function serialBackupsToRemove(array $configFiles): array
    {
        $suffixes = [];
        foreach ($configFiles as $filename) {
            if (preg_match('/^serial\.no\.bak(\d+)$/', $filename, $match)) {
                $suffixes[] = $match[1];
            }
        }
        rsort($suffixes, SORT_NUMERIC);

        return array_slice($suffixes, 10);
    }

    /**
     * Each bind address's domains with their alternative names, and every domain, in user order.
     *
     * @param  array<User>  $users
     * @param  ?array<Domain>  $domains  the domains to serve, or null for every domain the users have
     * @return array{0: array<string, array<string, array<string>>>, 1: array<Domain>}
     */
    private function listenersByIp(array $users, ?array $domains): array
    {
        $allDomains = [];

        $listeners = [];
        foreach ($users as $user) {
            $aliasesByDomain = [];
            foreach ($this->domainsOf($user, $domains) as $domain) {
                $aliasesByDomain[$domain->domain] = $domain->getVhostAltNames();
                $allDomains[] = $domain;
            }
            $listeners = $this->withListenerDomains($listeners, $user->getBindIpAddresses(), $aliasesByDomain);
        }

        return [$listeners, $allDomains];
    }

    /**
     * @param  ?array<Domain>  $domains
     * @return array<Domain>
     */
    private function domainsOf(User $user, ?array $domains): array
    {
        if ($domains === null) {
            return $user->getDomains();
        }

        return array_filter($domains, fn (Domain $domain) => (int) $domain->user_id === (int) $user->id);
    }

    /**
     * @param  array<string, array<string, array<string>>>  $listeners
     * @param  array{ipv4: array<string>, ipv6: array<string>}  $ips
     * @param  array<string, array<string>>  $aliasesByDomain
     * @return array<string, array<string, array<string>>>
     */
    private function withListenerDomains(array $listeners, array $ips, array $aliasesByDomain): array
    {
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

        return $listeners;
    }

    /**
     * The app proxy's listener maps, its own listeners and its virtual hosts, empty while it is off.
     *
     * @return array{0: array<string, array<string>>, 1: array<string>, 2: array<string>}
     */
    private function appLiteListeners(): array
    {
        if (! config('env.APP_LITE_PROXY_ENABLED')) {
            return [[], [], []];
        }

        [$caDomain, $aaDomain] = $this->appLiteDomains();
        $otherListenerMaps = $this->appLiteListenerMaps($caDomain, $aaDomain);
        $otherListeners = $this->appLitePortListeners($caDomain, $aaDomain);
        $otherVhosts = [];
        $otherVhosts[] = $this->getVhostString('app-lite-ca');
        $otherVhosts[] = $this->getVhostString('app-lite-aa');
        $this->rebuildAppProxyVirtualHostConfig();

        return [$otherListenerMaps, $otherListeners, $otherVhosts];
    }

    /** @return array{0: string, 1: string} the client and admin area domains, `*` when unset */
    private function appLiteDomains(): array
    {
        /** @var mixed */
        $caDomain = config('env.APP_LITE_CA_DOMAIN');
        if (empty($caDomain) || ! is_string($caDomain)) {
            $caDomain = '*';
        }
        /** @var mixed */
        $aaDomain = config('env.APP_LITE_AA_DOMAIN');
        if (empty($aaDomain) || ! is_string($aaDomain)) {
            $aaDomain = '*';
        }

        return [$caDomain, $aaDomain];
    }

    /** @return array<string, array<string>> the app proxy vhosts served on the IP listeners, by name */
    private function appLiteListenerMaps(string $caDomain, string $aaDomain): array
    {
        $otherListenerMaps = [];
        if (! config('env.APP_LITE_CA_PORT')) {
            $otherListenerMaps['app-lite-ca'] = [$caDomain];
        }
        if (! config('env.APP_LITE_AA_PORT')) {
            $otherListenerMaps['app-lite-aa'] = [$aaDomain];
        }

        return $otherListenerMaps;
    }

    /**
     * @param  array<string, array<string, array<string>>>  $listeners
     * @param  array<string, array<string>>  $otherListenerMaps
     * @param  array<string>  $otherListeners
     */
    private function listenersString(string $listenerTemplate, array $listeners, array $otherListenerMaps, array $otherListeners): string
    {
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
        foreach ($otherListeners as $otherListener) {
            $listenersStrings[] = $otherListener;
        }

        return implode("\n", $listenersStrings);
    }

    /**
     * @param  array<Domain>  $allDomains
     * @return array<string>
     */
    private function domainVirtualHosts(array $allDomains, string $virtualHostTemplate, string $virtualHostConfigTemplate): array
    {
        $cacheEnabled = empty(Setting::get('disable-lscache'));
        $virtualHosts = [];
        foreach ($allDomains as $domain) {
            $virtualHosts[] = $this->domainVirtualHost($domain, $cacheEnabled, $virtualHostTemplate, $virtualHostConfigTemplate);
        }

        return $virtualHosts;
    }

    /** The domain's own LSCache store, created for the account while the cache is on. */
    private function prepareCacheStore(User $user, Domain $domain, bool $cacheEnabled): string
    {
        $cacheStorePath = $this->system->projectHomeDirPath($user->username).'/'.$domain->domain.'/.lscache';
        if ($cacheEnabled) {
            $this->system->runProcess(Privileged::files()->makeDirectories($cacheStorePath));
            $this->system->runProcess(Privileged::files()->changeOwnerRecursively("nobody:{$user->username}", $cacheStorePath));
        }

        return $cacheStorePath;
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

    /**
     * The domains' virtual hosts, then the fallback, written first, and the app proxy's.
     *
     * @param  array<string>  $virtualHosts
     * @param  array<string>  $otherVhosts
     */
    private function virtualHostsString(array $virtualHosts, array $otherVhosts): string
    {
        $this->rebuildFallbackVirtualHostConfig();
        $virtualHosts[] = $this->fallbackVirtualHost();

        foreach ($otherVhosts as $otherVhost) {
            $virtualHosts[] = $otherVhost;
        }

        return implode("\n", $virtualHosts);
    }

    /** @param  array<string, string>  $appProxyVars */
    private function writeAppProxyVirtualHostConfig(string $name, string $docRoot, string $proxyHost, string $proxyPort, array $appProxyVars): void
    {
        $logDir = $this->system->engineDirPath().'/webserver-logs/'.$this->getSlug().'/'.$name;
        $virtualHostConfigPath = $this->system->engineDirPath().'/webserver-config/'.$this->getSlug().'/vhosts/'.$name.'.conf';
        $virtualHostConfig = $this->getProxyVhostConfigString(
            'proxy-'.$name,
            $docRoot,
            $logDir,
            $proxyHost,
            $proxyPort,
            $appProxyVars['ws_rewrite_proxy_host'],
            $appProxyVars['ws_rewrite_proxy_port'],
        );
        $this->system->filesystem()->filePutContents($virtualHostConfigPath, $virtualHostConfig, '994:994');
        $this->system->exec(Privileged::files()->makeDirectories($logDir));
    }

    public function applyCloudflareRealIpConfig(): void
    {
        if ($this instanceof Openlitespeed) {
            $this->applyOpenlitespeedCloudflareRealIpConfig();

            return;
        }
        if ($this instanceof Litespeed) {
            $this->applyLitespeedCloudflareRealIpConfig();
        }
    }

    private function applyOpenlitespeedCloudflareRealIpConfig(): void
    {
        $snippetPath = $this->system->engineDirPath().'/webserver-config/openlitespeed/cloudflare-realip.conf';
        if (! $this->system->filesystem()->fileExists($snippetPath)) {
            return;
        }

        $snippet = trim($this->system->filesystem()->fileGetContents($snippetPath));
        $configPath = $this->system->engineDirPath().'/webserver-config/openlitespeed/httpd_config.conf';
        $config = $this->system->filesystem()->fileGetContents($configPath);
        $config = preg_replace(
            '/# panelalpha-cloudflare-realip-begin.*?# panelalpha-cloudflare-realip-end\s*/s',
            '',
            $config
        ) ?? $config;
        $config = trim($config)."\n\n".$snippet."\n";
        $this->system->filesystem()->filePutContents($configPath, $config);
    }

    private function applyLitespeedCloudflareRealIpConfig(): void
    {
        $snippetPath = $this->system->engineDirPath().'/webserver-config/litespeed/cloudflare-realip.xml';
        if (! $this->system->filesystem()->fileExists($snippetPath)) {
            return;
        }

        $snippet = $this->system->filesystem()->fileGetContents($snippetPath);
        $innerSnippet = preg_replace(
            '/<!-- panelalpha-cloudflare-realip-begin -->|<!-- panelalpha-cloudflare-realip-end -->/s',
            '',
            $snippet
        );
        if ($innerSnippet === null) {
            return;
        }
        $innerSnippet = trim($innerSnippet);

        $configPath = $this->system->engineDirPath().'/webserver-config/litespeed/httpd_config.xml';
        $config = $this->system->filesystem()->fileGetContents($configPath);
        $config = preg_replace(
            '/<!-- panelalpha-cloudflare-realip-begin -->.*?<!-- panelalpha-cloudflare-realip-end -->\s*/s',
            '',
            $config
        ) ?? $config;

        $replacement = "<!-- panelalpha-cloudflare-realip-begin -->\n{$innerSnippet}\n<!-- panelalpha-cloudflare-realip-end -->\n";
        $newConfig = preg_replace(
            '/(<httpServerConfig>\s*)/',
            '$1'.$replacement,
            $config,
            1
        );
        if ($newConfig === null) {
            throw new \Exception('Could not inject Cloudflare real IP config into LiteSpeed httpd_config.xml');
        }

        $this->system->filesystem()->filePutContents($configPath, $newConfig);
    }
}
