<?php

namespace App\System\Services\Webserver;

use App\Lib\Helpers\Config;
use App\Models\Domain;
use App\Models\User;
use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Project\Domain as ProjectDomain;
use Illuminate\Support\Str;

abstract class AbstractWebserver implements WebserverInterface
{
    protected EngineSystem $system;

    public function __construct(EngineSystem $system)
    {
        $this->system = $system;
    }

    protected function projectDomain(Domain $domain): ProjectDomain
    {
        return $this->system->project($domain->getUser())->domain($domain);
    }

    protected function phpPortForVersion(?string $phpVersion): int
    {
        if (! $phpVersion || ! Str::contains($phpVersion, '.')) {
            return 9083;
        }

        [$major, $minor] = explode('.', $phpVersion);

        return (int) "90{$major}{$minor}";
    }

    /**
     * @return array{
     *   name: string,
     *   version: string,
     *   slug: string,
     *   metadata: array,
     * }
     */
    public function getDetails(): array
    {
        return [
            'name' => 'Unknown',
            'version' => 'Unknown',
            'slug' => 'unknown',
            'metadata' => [],
        ];
    }

    protected function getFallbackVars(): array
    {
        $default = [
            'fallback_proxy' => false,
            'fallback_proxy_host' => 'localhost',
            'fallback_proxy_port' => 8080,
        ];

        if (! config('env.APP_LITE_PROXY_ENABLED')) {
            return $default;
        }

        if (config('env.APP_LITE_CA_PORT') || config('env.APP_LITE_CA_DOMAIN')) {
            return $default;
        }

        return [
            'fallback_proxy' => true,
            'fallback_proxy_host' => config('env.APP_LITE_PROXY_CA_HOST'),
            'fallback_proxy_port' => config('env.APP_LITE_PROXY_CA_PORT'),
        ];
    }

    /** @return array{ssl_cert_file: string, ssl_cert_key_file: string} */
    protected function getSslCertVars(): array
    {
        return $this->defaultServerCertificate()->templateVars();
    }

    protected function defaultServerCertificate(): DefaultServerCertificate
    {
        return new DefaultServerCertificate($this->system);
    }

    /**
     * @return array{http_acme_challenges_enabled: bool, http_acme_challenges_dir: string}
     */
    protected function httpAcmeChallengeTemplateVars(Domain $domain): array
    {
        $dir = $this->system->engineDirPath().'/data/webserver/acme-challenges/'.$domain->domain;

        return [
            'http_acme_challenges_enabled' => $this->httpAcmeChallengesEnabled($dir),
            'http_acme_challenges_dir' => $dir,
        ];
    }

    private function httpAcmeChallengesEnabled(string $dir): bool
    {
        $fs = $this->system->filesystem();
        if (! $fs->directoryExists($dir)) {
            return false;
        }

        $process = $this->system->runProcess(Privileged::files()->listAllNames($dir));
        if ($process->getExitCode() !== 0) {
            return false;
        }

        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $name) {
            if ($name !== '' && $name !== '.' && $name !== '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,string>
     */
    protected function getAppProxyVars(): array
    {
        return [...self::clientAreaProxyVars(), ...self::adminAreaProxyVars(), ...self::websocketProxyVars()];
    }

    /**
     * @return array<string,string>
     */
    private static function clientAreaProxyVars(): array
    {
        $caPort = Config::getIntValue('env.APP_LITE_CA_PORT');
        $caServerName = '_';
        $caDomain = Config::getStringValue('env.APP_LITE_CA_DOMAIN');
        if (! empty($caDomain)) {
            $caServerName = $caDomain;
        }
        $caEnabled = $caPort || $caDomain;
        $caProxyHost = Config::getStringValue('env.APP_LITE_PROXY_CA_HOST');
        $caProxyPort = Config::getIntValue('env.APP_LITE_PROXY_CA_PORT');

        return [
            'client_area' => (string) $caEnabled,
            'ca_port' => (string) $caPort,
            'ca_server_name' => $caServerName,
            'ca_proxy_host' => (string) $caProxyHost,
            'ca_proxy_port' => (string) $caProxyPort,
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function adminAreaProxyVars(): array
    {
        $aaPort = Config::getIntValue('env.APP_LITE_AA_PORT');
        $aaServerName = '_';
        $aaDomain = Config::getStringValue('env.APP_LITE_AA_DOMAIN');
        if (! empty($aaDomain)) {
            $aaServerName = $aaDomain;
        }
        $aaEnabled = $aaPort || $aaDomain;

        return [
            'admin_area' => (string) $aaEnabled,
            'aa_port' => (string) $aaPort,
            'aa_server_name' => $aaServerName,
            'aa_proxy_host' => (string) Config::getStringValue('env.APP_LITE_PROXY_AA_HOST'),
            'aa_proxy_port' => (string) Config::getIntValue('env.APP_LITE_PROXY_AA_PORT'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function websocketProxyVars(): array
    {
        return [
            'ws_proxy_host' => (string) Config::getStringValue('env.APP_LITE_PROXY_WS_HOST'),
            'ws_proxy_port' => (string) Config::getIntValue('env.APP_LITE_PROXY_WS_PORT'),
            'ws_rewrite_proxy_host' => (string) Config::getStringValue('env.APP_LITE_PROXY_WS_REWRITE_HOST'),
            'ws_rewrite_proxy_port' => (string) Config::getIntValue('env.APP_LITE_PROXY_WS_REWRITE_PORT'),
        ];
    }

    /**
     * The addresses every generated config listens on, seeded with the engine's own so a fresh install never
     * listens on a wildcard: nginx cannot rebind from one to an address on reload, and every vhost stays unloaded.
     */
    public function getAllIpsVars(): array
    {
        $defaults = User::defaultBindIpAddresses();
        $ipsv4 = $defaults['ipv4'];
        $ipsv6 = $defaults['ipv6'];
        foreach (User::getAll() as $user) {
            $userIps = $user->getBindIpAddresses();
            $ipsv4 = [...$ipsv4, ...$userIps['ipv4']];
            $ipsv6 = [...$ipsv6, ...$userIps['ipv6']];
        }
        $ipsv4 = array_unique($ipsv4);
        $ipsv6 = array_unique($ipsv6);
        $vars = [
            'ips_v4' => $ipsv4,
            'ips_v6' => $ipsv6,
        ];

        return $vars;
    }

    public function resetWebPanelPassword(): string
    {
        throw new \Exception('Operation not supported on current webserver');
    }

    public function updateConfig(string $name, string $value): void
    {
        throw new \Exception('Unsupported config entry for current webserver');
    }

    /** $command is a shell line for sites-http's own shell, never the host's. */
    protected function reloadNginx(): void
    {
        $this->system->exec(Privileged::engineServices($this->system->composeFilePath())->nginxReload());
    }

    public function logsDirPath(): string
    {
        $webserver = $this->system->webserver()->getCurrentWebserver();

        return $this->system->engineDirPath().'/webserver-logs/'.$webserver;
    }

    public function domainsConfigsDirPath(): string
    {
        $webserver = $this->system->webserver()->getCurrentWebserver();

        return $this->system->engineDirPath().'/webserver-config/'.$webserver.'/vhosts';
    }

    public function rebuildDomainConfig(Domain $domain): void
    {
        throw new \Exception('Method '.__METHOD__.' not implemented for class '.get_called_class());
    }

    /**
     * @param  ?array<Domain>  $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        throw new \Exception('Method '.__METHOD__.' not implemented for class '.get_called_class());
    }

    /**
     * Whether new address-specific listen directives need a container restart rather than a graceful reload;
     * only the nginx variants override this.
     */
    public function needsIpListenerRebind(): bool
    {
        return false;
    }

    public function reload(bool $rebindIpListeners = false): void
    {
        throw new \Exception('Method '.__METHOD__.' not implemented for class '.get_called_class());
    }

    /**
     * For webservers whose reload never restarts them; nginx-proxy's can, so it overrides this.
     */
    public function reloadRouting(): void
    {
        $this->reload(false);
    }

    public function restart(): void
    {
        throw new \Exception('Method '.__METHOD__.' not implemented for class '.get_called_class());
    }

    public function addDomain(Domain $domain): void
    {
        throw new \Exception('Method '.__METHOD__.' not implemented for class '.get_called_class());
    }

    /**
     * Every webserver writes one vhost per domain, each its own way; declared here because
     * {@see rebuildDomainConfigs()} calls it.
     */
    public function createDomainConfig(Domain $domain): void
    {
        throw new \Exception('Method '.__METHOD__.' not implemented for class '.get_called_class());
    }

    /**
     * Write the vhost for every domain, defaulting to all of them: the tail every rebuildConfig() shares except
     * the LiteSpeed pair's, which regenerate one whole config file.
     *
     * @param  ?array<Domain>  $domains
     */
    protected function rebuildDomainConfigs(?array $domains = null): void
    {
        foreach ($domains ?? Domain::getAll() as $domain) {
            $this->createDomainConfig($domain);
        }

        $this->pruneDomainConfigs();
    }

    /**
     * Remove per-domain vhosts whose domain is not in the database; `$liveDomains` stands in for
     * `Domain::getAll()` so the rule can be tested without one.
     *
     * @param  ?list<string>  $liveDomains  the domains still in the database
     * @return list<string> the domain names pruned
     */
    public function pruneDomainConfigs(?array $liveDomains = null): array
    {
        $dir = $this->domainsConfigsDirPath();
        if (! is_dir($dir)) {
            return [];
        }

        $known = self::knownDomains($liveDomains);
        $pruned = [];
        foreach (glob($dir.'/*.conf') ?: [] as $file) {
            $name = basename($file, '.conf');
            if (self::isEngineConfig($name)) {
                continue;
            }
            if (! isset($known[$name])) {
                $this->deleteDomainConfig($name);
                $pruned[] = $name;
            }
        }

        return $pruned;
    }

    /**
     * @param  ?list<string>  $liveDomains
     * @return array<string, true>
     */
    private static function knownDomains(?array $liveDomains): array
    {
        $known = [];
        foreach ($liveDomains ?? array_map(
            static fn (Domain $d): string => (string) $d->domain,
            Domain::getAll()
        ) as $name) {
            $known[(string) $name] = true;
        }

        return $known;
    }

    private static function isEngineConfig(string $name): bool
    {
        return $name === '' || $name === 'app-lite' || str_starts_with($name, 'proxy-rule-')
            || $name === 'stream' || $name === 'nginx';
    }

    /**
     * @return array<string>
     */
    public function listDomains(): array
    {
        throw new \Exception('Method '.__METHOD__.' not implemented for class '.get_called_class());
    }

    public function deleteDomainConfig(string $domainName): void
    {
        throw new \Exception('Method '.__METHOD__.' not implemented for class '.get_called_class());
    }

    public function deleteDomainsConfigs(array $domainNames): void
    {
        throw new \Exception('Method '.__METHOD__.' not implemented for class '.get_called_class());
    }

    public function deleteDomainLogsDir(string $domainName): void
    {
        $slug = $this->getDetails()['slug'];
        $dir = $this->system->engineDirPath()."/webserver-logs/{$slug}";
        $this->system->runProcess(Privileged::files()->removeTree("{$dir}/{$domainName}"));
    }

    public function deleteDomainsLogsDirs(array $domainNames): void
    {
        foreach ($domainNames as $domainName) {
            $this->deleteDomainLogsDir($domainName);
        }
    }

    public function toggleModsecurity(): void {}

    public function toggleLscache(): void {}
}
