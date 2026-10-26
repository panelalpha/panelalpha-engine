<?php

namespace App\System\Services\Webserver;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Setting;
use App\Models\User;
use App\System\Project\Domain as ProjectDomain;
use App\System\Project\SitePasswordProtection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;

/**
 * @psalm-import-type HttpRule from \App\System\Services\Webserver\RoutingCompiler
 * @psalm-import-type StreamRule from \App\System\Services\Webserver\RoutingCompiler
 */
class NginxProxy extends AbstractWebserver implements WebserverInterface
{
    /**
     * Ports whose listeners have to match the rendered config for it to be live.
     */
    private const LISTEN_PORTS = [80, 443];

    /**
     * A LISTEN (st 0A) row of /proc/net/tcp: `sl local_address rem_address st ...`.
     */
    private const PROC_NET_TCP_LISTEN_ROW = '/^\s*\d+:\s+([0-9A-F]{8}):([0-9A-F]{4})\s+[0-9A-F]{8}:[0-9A-F]{4}\s+0A\b/';

    public function getDetails(): array
    {
        return [
            'name' => 'Nginx + Apache',
            'version' => 'Unknown',
            'slug' => 'nginx-proxy',
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

        $domains ??= Domain::getAll();

        $compiler = new RoutingCompiler($this->system);
        $this->renderCustomProxyRules($compiler->compile($domains));

        $this->rebuildDomainConfigs($domains);
    }

    /**
     * Render the persisted, non-generated proxy rules. A `*` rule takes IPv6 too
     * where the main config listens on it; an IPv4-only host cannot bind [::].
     *
     * @param  array{http: list<HttpRule>, stream: list<StreamRule>}  $compiledRules
     */
    private function renderCustomProxyRules(array $compiledRules): void
    {
        $ips = $this->getAllIpsVars();
        $listenIpv6 = $ips['ips_v6'] !== [];
        $this->renderProxyRuleVhosts($compiledRules['http'], $listenIpv6, [...$ips['ips_v4'], ...$ips['ips_v6']]);
        $this->renderStreamRules($compiledRules['stream'], $listenIpv6);
        $this->syncProxyRulePorts([...$compiledRules['http'], ...$compiledRules['stream']]);
    }

    private function rebuildMainConfig(): void
    {
        $confPath = $this->mainConfigPath();
        $confTemplatePath = $this->system->engineDirPath().'/templates/webserver-nginx-proxy.blade.php';
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
        $appLiteProxyEnabled = (bool) config('env.APP_LITE_PROXY_ENABLED');
        if (! $appLiteProxyEnabled) {
            $this->system->runProcess([
                'sudo',
                'rm',
                '-f',
                $this->appLiteConfigPath(),
            ]);

            return;
        }

        $this->renderAppLiteFromLegacyTemplate();

        $this->system->exec([
            'sudo',
            'mkdir',
            '-p',
            $this->system->engineDirPath().'/webserver-logs/nginx-proxy/app-lite',
        ]);
    }

    /**
     * The app-lite vhost from its template, when that still ships; the persisted
     * system proxy rules the RoutingCompiler renders are meant to replace it.
     */
    private function renderAppLiteFromLegacyTemplate(): void
    {
        $confTemplatePath = $this->system->engineDirPath().'/templates/virtualHost-nginx-proxy-app-lite.blade.php';
        if (! $this->system->filesystem()->fileExists($confTemplatePath)) {
            return;
        }

        $confTemplate = $this->system->filesystem()->fileGetContents($confTemplatePath);
        $templateVars = [
            ...$this->getAppProxyVars(),
            ...$this->getAllIpsVars(),
            ...$this->getSslCertVars(),
        ];
        $virtualHost = Blade::render($confTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($this->appLiteConfigPath(), $virtualHost);
    }

    private function appLiteConfigPath(): string
    {
        return $this->system->engineDirPath().'/webserver-config/nginx-proxy/vhosts/app-lite.conf';
    }

    /**
     * True when main nginx.conf still uses wildcard default listens. Adding
     * address-specific vhost listens then needs a full container restart —
     * reload fails with EADDRINUSE against the old wildcard sockets.
     * Once default_server is already IP-bound, a normal reload is enough.
     */
    public function needsIpListenerRebind(): bool
    {
        $confPath = $this->mainConfigPath();
        try {
            $contents = $this->system->filesystem()->fileGetContents($confPath);
        } catch (\Throwable $e) {
            return true;
        }

        return (bool) preg_match('/^\s*listen\s+80(\s+default_server)?\s*;/m', $contents)
            || (bool) preg_match('/^\s*listen\s+443\s+ssl(\s+default_server)?\s*;/m', $contents);
    }

    /**
     * A rebind restarts the container from the host: `/etc/init.d/nginx restart`
     * inside it exits PID 1 and loops the webserver (see Modsec::restartWebserver).
     */
    public function reload(bool $rebindIpListeners = false): void
    {
        if ($rebindIpListeners) {
            $this->system->webserver()->restartWebserverContainerFromHost();

            return;
        }
        $this->exec('/etc/init.d/nginx reload');
        $this->ensureReloadApplied();
    }

    /**
     * Reload for a vhost-only change, which never moves a listener: never restarts the
     * container, since a restart closes :80/:443 for the whole host; a reload that did not take is logged.
     */
    public function reloadRouting(): void
    {
        $this->exec('/etc/init.d/nginx reload');
        $this->ensureReloadApplied(restartIfNeeded: false);
    }

    /**
     * Restart the webserver when the reload it was just asked for did not take.
     *
     * A reload exits 0 even when the master cannot bind its new listeners and keeps
     * serving the old config; the listeners are the only observable that tells.
     * A routing-only reload passes false: its config cannot move a listener, so it only logs.
     */
    private function ensureReloadApplied(bool $restartIfNeeded = true): void
    {
        if ($this->listenersSettle()) {
            return;
        }

        if (! $restartIfNeeded) {
            Log::warning(
                'nginx-proxy routing reload did not apply: the running listeners do not match '
                .'the rendered config. Not restarting the webserver, because a routing change '
                .'does not alter the listen addresses; the container restart would drop live '
                .'connections without addressing the mismatch.'
            );

            return;
        }

        Log::warning(
            'nginx-proxy reload did not apply: the running listeners do not match '
            .'the rendered config, so the webserver is still serving the '
            .'configuration it booted with. Restarting the container.'
        );
        try {
            $this->system->webserver()->restartWebserverContainerFromHost();
        } catch (\Throwable $e) {
            Log::error('nginx-proxy restart after a failed reload did not run: '.$e->getMessage());

            return;
        }

        if (! $this->listenersSettle()) {
            Log::error(
                'nginx-proxy still does not listen where the rendered config says after a restart; '
                .'sites added since it started are not being served.'
            );
        }
    }

    /**
     * nginx retries a failed bind five times at 500ms, so recheck a few times first.
     */
    private function listenersSettle(): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                $this->pauseBeforeRecheck();
            }
            if ($this->listenersMatchConfig()) {
                return true;
            }
        }

        return false;
    }

    protected function pauseBeforeRecheck(): void
    {
        sleep(2);
    }

    /**
     * Whether every IPv4 address the rendered main config declares is bound; every vhost
     * derives its addresses from the same list. A check that cannot run reads as matching.
     * Extra listeners are fine: with `network_mode: host` /proc/net/tcp is the whole host's table.
     */
    public function listenersMatchConfig(): bool
    {
        try {
            $configured = $this->configuredListenAddresses();
            $bound = $this->boundListenAddresses();
        } catch (\Throwable $e) {
            Log::warning('nginx-proxy listener check skipped: '.$e->getMessage());

            return true;
        }

        foreach (self::LISTEN_PORTS as $port) {
            if (empty($configured[$port])) {
                continue;
            }
            if (array_diff($configured[$port], $bound[$port] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * IPv4 listen addresses the rendered main config declares, by port; a bare
     * `listen 80` is the wildcard. Bracketed IPv6 lines never match, deliberately.
     *
     * @return array<int, list<string>>
     */
    private function configuredListenAddresses(): array
    {
        $conf = $this->system->filesystem()->fileGetContents($this->mainConfigPath());

        $found = [];
        preg_match_all('/^\s*listen\s+([0-9.]+:)?(\d+)\b/m', $conf, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $port = (int) $match[2];
            if (! in_array($port, self::LISTEN_PORTS, true)) {
                continue;
            }
            $found[$port][] = $match[1] === '' ? '0.0.0.0' : rtrim($match[1], ':');
        }

        return self::sortedUniqueByPort($found);
    }

    /**
     * IPv4 addresses nginx is actually listening on, by port.
     *
     * Read from /proc/net/tcp rather than `ss` or `netstat`, neither of which
     * the webserver image ships.
     *
     * @return array<int, list<string>>
     */
    private function boundListenAddresses(): array
    {
        $composeFile = $this->system->composeFilePath();
        $raw = $this->system->exec(
            "sudo docker compose -f {$composeFile} exec -T sites-http cat /proc/net/tcp"
        );

        $bound = [];
        foreach (explode("\n", $raw) as $line) {
            if (! preg_match(self::PROC_NET_TCP_LISTEN_ROW, $line, $match)) {
                continue;
            }
            $port = (int) hexdec($match[2]);
            if (! in_array($port, self::LISTEN_PORTS, true)) {
                continue;
            }
            $bound[$port][] = self::hexToIpv4($match[1]);
        }

        return self::sortedUniqueByPort($bound);
    }

    /**
     * @param  array<int, list<string>>  $addressesByPort
     * @return array<int, list<string>>
     */
    private static function sortedUniqueByPort(array $addressesByPort): array
    {
        return array_map(static function (array $addresses): array {
            $addresses = array_values(array_unique($addresses));
            sort($addresses);

            return $addresses;
        }, $addressesByPort);
    }

    /** /proc/net/tcp stores the local address as little-endian hex. */
    private static function hexToIpv4(string $hex): string
    {
        $bytes = array_map('hexdec', str_split($hex, 2));

        return implode('.', array_reverse($bytes));
    }

    private function mainConfigPath(): string
    {
        return $this->system->engineDirPath().'/webserver-config/nginx-proxy/nginx.conf';
    }

    public function restart(): void
    {
        $this->system->webserver()->restartWebserverContainerFromHost();
    }

    public function listDomains(): array
    {
        $dir = $this->domainsConfigsDirPath();
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

    /**
     * The nginx-proxy vhost directory, whatever webserver is current.
     */
    public function domainsConfigsDirPath(): string
    {
        return $this->system->engineDirPath().'/webserver-config/nginx-proxy/vhosts';
    }

    public function domainConfigExists(string $domainName): bool
    {
        return file_exists($this->domainsConfigsDirPath().'/'.$domainName.'.conf');
    }

    public function createDomainConfig(Domain $domain): void
    {
        $domainName = $domain->domain;
        $user = $domain->getUser();

        $templateVars = array_merge(
            $this->domainTemplateVars($domain, $user),
            SitePasswordProtection::nginxTemplateVars($user),
            $this->httpAcmeChallengeTemplateVars($domain),
            $this->sslTemplateVars($this->system->project($user)->domain($domain)),
        );

        $isDind = $user->getTemplate() === 'dind';
        if ($isDind) {
            $templateVars = array_merge($templateVars, $this->dindProxyTemplateVars($user, $domainName));
        }

        $domainLogsDir = "{$this->logsDirPath()}/{$domainName}";
        $this->system->exec(['sudo', 'mkdir', '-p', $domainLogsDir]);

        $domainConfigFile = "{$this->domainsConfigsDirPath()}/{$domainName}.conf";
        $this->system->filesystem()->makeFileFromTemplate($domainConfigFile, $this->domainTemplatePath($isDind), $templateVars);
    }

    /**
     * @return array<string, mixed>
     */
    private function domainTemplateVars(Domain $domain, User $user): array
    {
        $ips = $user->getBindIpAddresses();

        return [
            'user' => $user->username,
            'suspended' => $user->status === 'suspended',
            'ips_v4' => $ips['ipv4'],
            'ips_v6' => $ips['ipv6'],
            'domain' => $domain->domain,
            'aliases' => $domain->getVhostAltNames(),
            'redirect_url' => $this->safeRedirectUrl($domain->getRedirectUrl()),
            'force_https_redirect' => $domain->forceHttpsRedirectEnabled(),
            'relative_document_root' => $domain->getDocumentRoot(),
            'app_port' => $user->getAppPort() ?? 80,
            'app_ssl_port' => $user->getAppPort() ?? 443,
            'trusted_fronts' => PanelAlphaConnect::trustedFrontsFor($domain),
        ];
    }

    private function domainTemplatePath(bool $isDind): string
    {
        $templatesDir = $this->system->templatesDirPath();

        return $isDind
            ? "{$templatesDir}/dind/virtualHost-nginx-proxy.blade.php"
            : "{$templatesDir}/virtualHost-nginx-proxy.blade.php";
    }

    /**
     * Drops a stored redirect URL holding nginx metacharacters (rows older than
     * `App\Rules\RedirectUrl`): one such URL fails the reload for every site on the host.
     */
    private function safeRedirectUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (preg_match("/[\s\"'\\$;{}`]/", $url) === 1) {
            Log::warning(
                'Refusing to render a redirect URL with nginx metacharacters for domain; '
                .'clear or re-enter it in the panel.'
            );

            return null;
        }

        return $url;
    }

    /**
     * The TLS block's vars, only once its cert and key exist (self-signed if need
     * be): one vhost naming a missing file breaks every reload on the host.
     *
     * @return array<string, mixed>
     */
    public function sslTemplateVars(ProjectDomain $connection): array
    {
        $domain = $connection->model();
        if (! $domain->sslEnabled() || ! $connection->ensureServableCertificate()) {
            return [];
        }

        $certDir = "{$connection->project()->projectDirPath()}/ssl-certs";

        return [
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => "{$certDir}/{$domain->domain}.pem",
            'ssl_cert_key_file' => "{$certDir}/{$domain->domain}.key",
        ];
    }

    /**
     * Build proxy_http / proxy_https / proxy_extra for the DinD vhost template.
     * Routing is driven only by ProxyRule rows for this domain (no app_port fallback).
     * Each entry carries ips_v4/ips_v6 resolved from ProxyRule.listen_ip (* = all account IPs).
     *
     * @return array{
     *   proxy_http: ?array{host: string, port: int, protocol: string, ips_v4: list<string>, ips_v6: list<string>},
     *   proxy_https: ?array{host: string, port: int, protocol: string, ips_v4: list<string>, ips_v6: list<string>},
     *   proxy_extra: list<array{listen_port: int, host: string, port: int, protocol: string, ips_v4: list<string>, ips_v6: list<string>}>
     * }
     */
    private function dindProxyTemplateVars(User $user, string $domainName): array
    {
        $accountIps = $user->getBindIpAddresses();
        $rules = $this->httpRulesNamedAfter($domainName);

        $vars = [
            'proxy_http' => null,
            'proxy_https' => null,
            'proxy_extra' => [],
        ];
        foreach ($rules as $rule) {
            if (ProxyRuleUpstream::serves($rule) && ProxyRuleServerName::answersFor($rule, $user->username)) {
                $vars = self::withDindUpstream($vars, (int) $rule->listen_port, $this->dindRuleUpstream($rule, $accountIps));
            }
        }

        return $vars;
    }

    /**
     * Port 80 and 443 each take one upstream, the last rule winning; any other port is an extra.
     *
     * @param  array{proxy_http: ?array, proxy_https: ?array, proxy_extra: list<array>}  $vars
     * @param  array<string, mixed>  $upstream
     * @return array{proxy_http: ?array, proxy_https: ?array, proxy_extra: list<array>}
     */
    private static function withDindUpstream(array $vars, int $listenPort, array $upstream): array
    {
        if ($listenPort === 80) {
            $vars['proxy_http'] = $upstream;
        } elseif ($listenPort === 443) {
            $vars['proxy_https'] = $upstream;
        } else {
            $vars['proxy_extra'][] = array_merge([
                'listen_port' => $listenPort,
            ], $upstream);
        }

        return $vars;
    }

    /**
     * Every enabled HTTP rule whose server_name is this domain, system-scoped CLI rules
     * without a username included; another project's rule is filtered out by the caller.
     *
     * @return iterable<ProxyRule>
     */
    private function httpRulesNamedAfter(string $domainName): iterable
    {
        return ProxyRule::query()
            ->where('transport', 'http')
            ->where('enabled', true)
            ->where('server_name', $domainName)
            ->get();
    }

    /**
     * @param  array{ipv4: list<string>, ipv6: list<string>}  $accountIps
     * @return array{host: string, port: int, protocol: string, ips_v4: list<string>, ips_v6: list<string>}
     */
    private function dindRuleUpstream(ProxyRule $rule, array $accountIps): array
    {
        $listenIps = $this->resolveProxyRuleListenIps($rule->listen_ip, $accountIps);

        return [
            'host' => $rule->upstream_host,
            'port' => (int) $rule->upstream_port,
            'protocol' => $rule->upstream_protocol ?: 'http',
            'ips_v4' => $listenIps['ipv4'],
            'ips_v6' => $listenIps['ipv6'],
        ];
    }

    /**
     * Map ProxyRule.listen_ip onto concrete listen addresses.
     * "*" / null / empty → all account bind IPs; a specific IP → only that address
     * (IPv4 or IPv6), even if it is not currently in the account bind list.
     *
     * @param  array{ipv4: list<string>, ipv6: list<string>}  $accountIps
     * @return array{ipv4: list<string>, ipv6: list<string>}
     */
    private function resolveProxyRuleListenIps(?string $listenIp, array $accountIps): array
    {
        $listenIp = $listenIp !== null ? trim($listenIp) : '';
        if ($listenIp === '' || $listenIp === '*') {
            return [
                'ipv4' => array_values($accountIps['ipv4'] ?? []),
                'ipv6' => array_values($accountIps['ipv6'] ?? []),
            ];
        }

        if (filter_var($listenIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ['ipv4' => [], 'ipv6' => [$listenIp]];
        }

        return ['ipv4' => [$listenIp], 'ipv6' => []];
    }

    public function rebuildDomainConfig(Domain $domain): void
    {
        $this->createDomainConfig($domain);
    }

    public function deleteDomainConfig(string $domainName): void
    {
        $configFile = $this->domainsConfigsDirPath().'/'.$domainName.'.conf';
        $this->system->exec(['sudo', 'rm', '-f', $configFile]);
    }

    public function deleteDomainsConfigs(array $domainNames): void
    {
        foreach ($domainNames as $domainName) {
            $this->deleteDomainConfig($domainName);
        }
    }

    public function toggleModsecurity(): void
    {
        $this->rebuildMainConfig();
        $this->restart();
    }

    /**
     * Render persisted HTTP proxy rules to vhost config files, one per port and server name.
     *
     * @param  array<array>  $httpRules  HTTP rules from RoutingCompiler
     *
     * @psalm-param list<HttpRule> $httpRules
     */
    protected function renderProxyRuleVhosts(array $httpRules, bool $listenIpv6, array $hostIps = []): void
    {
        $vhostsDir = $this->domainsConfigsDirPath();
        $this->system->exec("sudo mkdir -p {$vhostsDir}");
        $this->system->exec("sudo rm -f {$vhostsDir}/proxy-rule-*.conf");

        foreach ($this->groupStandaloneProxyRules($httpRules) as $identifier => $ruleGroup) {
            $configPath = "{$vhostsDir}/proxy-rule-{$identifier}.conf";
            $vhostConfig = self::renderHttpProxyRule(self::proxyRuleTemplateVars($ruleGroup), $listenIpv6, $hostIps);
            $this->system->filesystem()->filePutContents($configPath, $vhostConfig);
        }
    }

    /**
     * The rules that need their own vhost, grouped by port and server name.
     *
     * @psalm-param list<HttpRule> $httpRules
     *
     * @return array<string, array<string, mixed>>
     */
    private function groupStandaloneProxyRules(array $httpRules): array
    {
        $domainOwnedNames = array_keys(ProxyRuleServerName::owners());

        $rulesByIdentifier = [];
        foreach ($httpRules as $rule) {
            if (! self::isStandaloneProxyRule($rule, $domainOwnedNames)) {
                continue;
            }
            $identifier = self::proxyRuleIdentifier($rule);
            if (! isset($rulesByIdentifier[$identifier])) {
                $rulesByIdentifier[$identifier] = self::proxyRuleGroup($rule);
            } elseif (! empty($rule['server_name']) && ! in_array($rule['server_name'], $rulesByIdentifier[$identifier]['server_names'])) {
                $rulesByIdentifier[$identifier]['server_names'][] = $rule['server_name'];
            }
        }

        return $rulesByIdentifier;
    }

    /**
     * A rule named exactly after a domain or alias is rendered into that domain's vhost
     * by the DinD template, and a generated one never stands alone; wildcards do.
     *
     * @param  list<string>  $domainOwnedNames
     */
    private static function isStandaloneProxyRule(array $rule, array $domainOwnedNames): bool
    {
        $serverName = (string) ($rule['server_name'] ?? '');
        $ownedByDomain = $serverName !== '' && $serverName !== '_'
            && in_array(strtolower($serverName), $domainOwnedNames, true);

        return ! $ownedByDomain && ($rule['is_generated'] ?? false) !== true;
    }

    private static function proxyRuleIdentifier(array $rule): string
    {
        $identifier = $rule['listen_port'].'_'.($rule['server_name'] ?? 'wildcard');

        return preg_replace('/[^a-z0-9_-]/', '_', strtolower((string) $identifier));
    }

    /**
     * @return array<string, mixed>
     */
    private static function proxyRuleGroup(array $rule): array
    {
        return [
            'id' => $rule['id'],
            'listen_port' => $rule['listen_port'],
            'listen_ip' => $rule['listen_ip'],
            'server_names' => [$rule['server_name'] ?? '_'],
            'upstream_host' => $rule['upstream_host'],
            'upstream_port' => $rule['upstream_port'],
            'upstream_protocol' => $rule['upstream_protocol'],
            'ssl_enabled' => $rule['ssl_enabled'] ?? false,
            'ssl_cert_pem_file' => $rule['ssl_cert_pem_file'] ?? null,
            'ssl_cert_key_file' => $rule['ssl_cert_key_file'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $ruleGroup
     * @return array<string, mixed>
     */
    private static function proxyRuleTemplateVars(array $ruleGroup): array
    {
        $templateVars = [
            'id' => $ruleGroup['id'],
            'listen_ip' => $ruleGroup['listen_ip'],
            'listen_port' => $ruleGroup['listen_port'],
            'server_names' => $ruleGroup['server_names'],
            'upstream_protocol' => $ruleGroup['upstream_protocol'],
            'upstream_host' => $ruleGroup['upstream_host'],
            'upstream_port' => $ruleGroup['upstream_port'],
            'ssl_enabled' => $ruleGroup['ssl_enabled'],
        ];

        if ($ruleGroup['ssl_enabled']) {
            $templateVars['ssl_cert_pem_file'] = $ruleGroup['ssl_cert_pem_file'];
            $templateVars['ssl_cert_key_file'] = $ruleGroup['ssl_cert_key_file'];
        }

        return $templateVars;
    }

    /**
     * Generate nginx HTTP vhost config for a proxy rule. Log names keep the bare
     * address; only `listen` needs IPv6 brackets.
     *
     * @param array{
     *   id: int,
     *   listen_ip: string,
     *   listen_port: int,
     *   server_names: array<string>,
     *   upstream_protocol: string,
     *   upstream_host: string,
     *   upstream_port: int,
     *   ssl_enabled: bool,
     *   ssl_cert_pem_file?: ?string,
     *   ssl_cert_key_file?: ?string
     * } $vars
     */
    public static function renderHttpProxyRule(array $vars, bool $listenIpv6 = false, array $hostIps = []): string
    {
        $serverNames = implode(' ', $vars['server_names']);
        $ip = $vars['listen_ip'];
        $logAddress = $ip === '*' ? (string) $vars['listen_port'] : "{$ip}:{$vars['listen_port']}";
        $sslConfig = self::proxyRuleSslDirectives($vars);

        $upstreamProtocol = $vars['upstream_protocol'] ?? 'http';
        $scheme = $upstreamProtocol === 'https' ? 'https' : 'http';

        $listen = self::proxyRuleListen($vars, $listenIpv6, $hostIps);

        return <<<NGINX
upstream upstream-{$vars['id']}-{$vars['upstream_host']}-{$vars['upstream_port']} {
  resolver 127.0.0.54 valid=30s;
  zone upstreams 64k;
  server {$vars['upstream_host']}:{$vars['upstream_port']} resolve;
}
server {
    {$listen}
    server_name {$serverNames};

    access_log /var/log/nginx/proxy-rule-{$logAddress}.access.log;
    error_log /var/log/nginx/proxy-rule-{$logAddress}.error.log;
{$sslConfig}

    location / {
        proxy_pass {$scheme}://upstream-{$vars['id']}-{$vars['upstream_host']}-{$vars['upstream_port']};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$server_name;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_pass_header X-Accel-Buffering;
    }
}
NGINX;
    }

    private static function proxyRuleSslDirectives(array $vars): string
    {
        if (
            ! $vars['ssl_enabled']
            || empty($vars['ssl_cert_pem_file'])
            || empty($vars['ssl_cert_key_file'])
        ) {
            return '';
        }

        return <<<NGINX
    ssl_certificate {$vars['ssl_cert_pem_file']};
    ssl_certificate_key {$vars['ssl_cert_key_file']};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
NGINX;
    }

    /**
     * On 80/443 a `*` rule takes the main config's per-address binds instead: a
     * wildcard socket beside them fails every reload with EADDRINUSE.
     */
    private static function proxyRuleListen(array $vars, bool $listenIpv6, array $hostIps): string
    {
        $ssl = $vars['ssl_enabled'] ? ' ssl' : '';
        if ($vars['listen_ip'] === '*' && $hostIps !== [] && in_array((int) $vars['listen_port'], self::LISTEN_PORTS, true)) {
            return implode("\n    ", array_map(
                static fn (string $a): string => 'listen '.(str_contains($a, ':') ? "[{$a}]" : $a).":{$vars['listen_port']}{$ssl};",
                array_values(array_unique($hostIps)),
            ));
        }

        return self::listenDirectives($vars['listen_ip'], $vars['listen_port'], $ssl, $listenIpv6);
    }

    /**
     * `listen` for one address, plus `[::]` for a `*` rule on an IPv6 host: nginx binds
     * [::] ipv6only by default, so it does not clash with the IPv4 wildcard.
     */
    private static function listenDirectives(string $ip, int|string $port, string $parameters, bool $listenIpv6): string
    {
        $listen = 'listen '.self::listenAddress($ip, $port)."{$parameters};";
        if ($ip === '*' && $listenIpv6) {
            $listen .= "\n    listen [::]:{$port}{$parameters};";
        }

        return $listen;
    }

    private static function listenAddress(string $ip, int|string $port): string
    {
        return match (true) {
            $ip === '*' => (string) $port,
            str_contains($ip, ':') => "[{$ip}]:{$port}",
            default => "{$ip}:{$port}",
        };
    }

    /**
     * Render stream rules to stream.conf, always: an empty file clears old rules.
     *
     * @psalm-param list<StreamRule> $streamRules
     */
    private function renderStreamRules(array $streamRules, bool $listenIpv6): void
    {
        $streamConfPath = $this->system->engineDirPath().'/webserver-config/nginx-proxy/stream.conf';
        $this->system->filesystem()->filePutContents($streamConfPath, self::streamConfig($streamRules, $listenIpv6));
    }

    /**
     * Keep the host firewall open for the ports the custom rules listen on.
     *
     * Only a host that has or had such a port touches the firewall: the
     * marker lists the ports the last successful sync was asked to open.
     *
     * @psalm-param list<HttpRule|StreamRule> $rules
     */
    public function syncProxyRulePorts(array $rules): void
    {
        $markerPath = $this->system->engineDirPath().'/webserver-config/nginx-proxy/proxy-rule-ports';
        $wanted = array_keys(ProxyRulePorts::allows($rules));
        sort($wanted);
        try {
            $previous = trim($this->system->filesystem()->fileGetContents($markerPath));
        } catch (\Throwable $e) {
            $previous = '';
        }
        if ($wanted === [] && $previous === '') {
            return;
        }

        if ($this->proxyRulePorts()->sync($rules)) {
            $this->system->filesystem()->filePutContents($markerPath, implode("\n", $wanted)."\n");
        }
    }

    protected function proxyRulePorts(): ProxyRulePorts
    {
        return new ProxyRulePorts;
    }

    /**
     * stream {} content for the custom (non-generated) tcp/udp rules.
     *
     * The upstream is resolved at run time, as the http rules' are: a name
     * resolved at load (an account that is not running) makes nginx refuse
     * the whole config, and an account's address changes when it is recreated.
     *
     * @psalm-param list<StreamRule> $streamRules
     */
    public static function streamConfig(array $streamRules, bool $listenIpv6 = false): string
    {
        $config = "# Stream (TCP/UDP) rules\n";

        foreach ($streamRules as $rule) {
            if ($rule['is_generated'] !== false) {
                continue;
            }
            $config .= self::streamServer($rule, $listenIpv6);
        }

        return $config;
    }

    /**
     * tcp is nginx's default; `udp` is the only transport parameter `listen` takes.
     *
     * @psalm-param StreamRule $rule
     */
    private static function streamServer(array $rule, bool $listenIpv6): string
    {
        $udp = $rule['transport'] === 'udp' ? ' udp' : '';
        $listen = self::listenDirectives($rule['listen_ip'], $rule['listen_port'], $udp, $listenIpv6);

        return <<<NGINX

upstream stream_proxy_{$rule['id']} {
    zone stream_upstreams 64k;
    resolver 127.0.0.54 valid=30s;
    server {$rule['upstream_host']}:{$rule['upstream_port']} resolve;
}

server {
    {$listen}
    proxy_pass stream_proxy_{$rule['id']};
}

NGINX;
    }
}
