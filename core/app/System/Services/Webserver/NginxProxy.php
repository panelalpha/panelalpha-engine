<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\Setting;
use App\System\Project\Domain as ProjectDomain;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;

/**
 * @psalm-import-type HttpRule from \App\System\Services\Webserver\RoutingCompiler
 * @psalm-import-type StreamRule from \App\System\Services\Webserver\RoutingCompiler
 */
class NginxProxy extends AbstractWebserver implements WebserverInterface
{
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
        // TODO: use rebuildConfig() or createDomainConfig() ?
        $this->createDomainConfig($domain);
    }

    /**
     * @param ?array<Domain> $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        $this->rebuildMainConfig();
        $this->rebuildAppProxyConfig();

        $domains ??= Domain::getAll();

        // Compile routing rules with conflict suppression
        $compiler = new RoutingCompiler($this->system);
        $compiledRules = $compiler->compile($domains);

        // Render custom proxy rules (persisted, non-generated rules)
        $this->renderProxyRuleVhosts($compiledRules['http']);
        $this->renderStreamRules($compiledRules['stream']);

        // Render domain vhost configs (generated defaults)
        $this->rebuildDomainConfigs($domains);
    }

    private function rebuildMainConfig(): void
    {
        $confPath = $this->mainConfigPath();
        $confTemplatePath = $this->system->engineDirPath() . '/templates/webserver-nginx-proxy.blade.php';
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
        $appLiteProxyEnabled = (bool)config('env.APP_LITE_PROXY_ENABLED');
        if (!$appLiteProxyEnabled) {
            $confPath = $this->system->engineDirPath() . '/webserver-config/nginx-proxy/vhosts/app-lite.conf';
            $this->system->runProcess([
                "sudo",
                "rm",
                "-f",
                $confPath,
            ]);
            return;
        }

        // Instead of rendering from template, render persisted system proxy rules
        // This is now handled by the RoutingCompiler in rebuildConfig()
        // For now, keep the legacy template path for backward compatibility
        // TODO: Remove legacy template once migration is complete
        $confPath = $this->system->engineDirPath() . '/webserver-config/nginx-proxy/vhosts/app-lite.conf';
        $confTemplatePath = $this->system->engineDirPath() . '/templates/virtualHost-nginx-proxy-app-lite.blade.php';

        if ($this->system->filesystem()->fileExists($confTemplatePath)) {
            $confTemplate = $this->system->filesystem()->fileGetContents($confTemplatePath);
            $templateVars = [
                ...$this->getAppProxyVars(),
                ...$this->getAllIpsVars(),
                ...$this->getSslCertVars(),
            ];
            $virtualHost = Blade::render($confTemplate, $templateVars);
            $this->system->filesystem()->filePutContents($confPath, $virtualHost);
        }

        $this->system->exec([
            'sudo',
            'mkdir',
            '-p',
            $this->system->engineDirPath() . '/webserver-logs/nginx-proxy/app-lite',
        ]);
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

        return (bool)preg_match('/^\s*listen\s+80(\s+default_server)?\s*;/m', $contents)
            || (bool)preg_match('/^\s*listen\s+443\s+ssl(\s+default_server)?\s*;/m', $contents);
    }

    public function reload(bool $rebindIpListeners = false): void
    {
        if ($rebindIpListeners) {
            // Never `/etc/init.d/nginx restart` inside the container: that exits
            // PID 1 and loops the webserver (see Modsec::restartWebserver).
            // Rebind via host-level compose restart instead.
            $this->system->webserver()->restartWebserverContainerFromHost();
            return;
        }
        $this->exec("/etc/init.d/nginx reload");
        $this->ensureReloadApplied();
    }

    /**
     * Ports whose listeners have to match the rendered config for it to be live.
     */
    private const LISTEN_PORTS = [80, 443];

    /**
     * Restart the webserver when the reload it was just asked for did not take.
     *
     * A reload cannot report this itself. `nginx -s reload` exits 0 once the
     * signal is delivered, and `nginx -t` passes because the configuration is
     * valid -- the failure is a runtime bind, not a syntax error. A master that
     * cannot open its new listening sockets logs `still could not bind()` and
     * goes on serving the configuration it started with, so a caller that
     * trusts the exit code believes a config is live that never loaded. The
     * listeners are the only observable that tells the two apart.
     *
     * nginx retries a failed bind five times at 500ms, so give it a moment
     * before concluding anything.
     */
    private function ensureReloadApplied(): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($this->listenersMatchConfig()) {
                return;
            }
            sleep(2);
        }

        Log::warning(
            'nginx-proxy reload did not apply: the running listeners do not match '
            . 'the rendered config, so the webserver is still serving the '
            . 'configuration it booted with. Restarting the container.'
        );
        $this->system->webserver()->scheduleWebserverReloadInBackground(true);
    }

    /**
     * Whether nginx is listening where the rendered main config says it should.
     *
     * IPv4 only, and only the main config: it carries the `default_server`
     * blocks, and every vhost derives its addresses from the same list
     * ({@see AbstractWebserver::getAllIpsVars()}), so the main config is the
     * authoritative bind shape. A check that cannot run is never a reason to
     * fail a reload, so any error here reads as "matching".
     */
    public function listenersMatchConfig(): bool
    {
        try {
            $configured = $this->configuredListenAddresses();
            $bound = $this->boundListenAddresses();
        } catch (\Throwable $e) {
            Log::warning('nginx-proxy listener check skipped: ' . $e->getMessage());
            return true;
        }

        foreach (self::LISTEN_PORTS as $port) {
            if (empty($configured[$port])) {
                continue;
            }
            if (($bound[$port] ?? []) !== $configured[$port]) {
                return false;
            }
        }

        return true;
    }

    /**
     * IPv4 listen addresses the rendered main config declares, by port.
     *
     * A bare `listen 80` is the wildcard, which is a different socket from
     * `listen <ip>:80` and the whole reason this check exists. IPv6 lines carry
     * brackets and never match, which is deliberate: they follow the same
     * source, so IPv4 is enough to detect a config that did not load.
     *
     * @return array<int, list<string>>
     */
    private function configuredListenAddresses(): array
    {
        $conf = $this->system->filesystem()->fileGetContents($this->mainConfigPath());

        $found = [];
        preg_match_all('/^\s*listen\s+([0-9.]+:)?(\d+)\b/m', $conf, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $port = (int)$match[2];
            if (!in_array($port, self::LISTEN_PORTS, true)) {
                continue;
            }
            $found[$port][] = $match[1] === '' ? '0.0.0.0' : rtrim($match[1], ':');
        }

        return array_map(static function (array $addresses): array {
            $addresses = array_values(array_unique($addresses));
            sort($addresses);
            return $addresses;
        }, $found);
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
            // sl  local_address rem_address st ...  -- st 0A is LISTEN
            if (!preg_match('/^\s*\d+:\s+([0-9A-F]{8}):([0-9A-F]{4})\s+[0-9A-F]{8}:[0-9A-F]{4}\s+0A\b/', $line, $match)) {
                continue;
            }
            $port = (int)hexdec($match[2]);
            if (!in_array($port, self::LISTEN_PORTS, true)) {
                continue;
            }
            $bound[$port][] = self::hexToIpv4($match[1]);
        }

        return array_map(static function (array $addresses): array {
            $addresses = array_values(array_unique($addresses));
            sort($addresses);
            return $addresses;
        }, $bound);
    }

    /** /proc/net/tcp stores the local address as little-endian hex. */
    private static function hexToIpv4(string $hex): string
    {
        $bytes = array_map('hexdec', str_split($hex, 2));

        return implode('.', array_reverse($bytes));
    }

    private function mainConfigPath(): string
    {
        return $this->system->engineDirPath() . '/webserver-config/nginx-proxy/nginx.conf';
    }

    public function restart(): void
    {
        $this->system->webserver()->restartWebserverContainerFromHost();
    }

    public function listDomains(): array
    {
        $dir = $this->domainsConfigDirPath();
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

    /**
     * Hardcoded nginx-proxy vhost dir (from the former Services\NginxProxy helper).
     */
    public function domainsConfigDirPath(): string
    {
        return $this->system->engineDirPath() . '/webserver-config/nginx-proxy/vhosts';
    }

    public function domainConfigExists(string $domainName): bool
    {
        return file_exists($this->domainsConfigDirPath() . '/' . $domainName . '.conf');
    }

    public function createDomainConfig(Domain $domain): void
    {
        $domainName = $domain->domain;

        $user = $domain->getUser();
        $ips = $user->getBindIpAddresses();

        $templateVars = [
            'user' => $user->username,
            'suspended' => $user->status === 'suspended',
            'ips_v4' => $ips['ipv4'],
            'ips_v6' => $ips['ipv6'],
            'domain' => $domainName,
            'aliases' => $domain->getVhostAltNames(),
            'redirect_url' => $domain->getRedirectUrl(),
            'force_https_redirect' => $domain->forceHttpsRedirectEnabled(),
            'relative_document_root' => $domain->getDocumentRoot(),
            'app_port' => $user->getAppPort() ?? 80,
            'app_ssl_port' => $user->getAppPort() ?? 443,
        ];
        $templateVars = array_merge($templateVars, \App\System\Project\SitePasswordProtection::nginxTemplateVars($user));
        $templateVars = array_merge($templateVars, $this->httpAcmeChallengeTemplateVars($domain));
        $connection = $this->system->project($user)->domain($domain);
        $templateVars = array_merge($templateVars, $this->sslTemplateVars($connection));

        $isDind = $user->getTemplate() === 'dind';
        if ($isDind) {
            $templateVars = array_merge($templateVars, $this->dindProxyTemplateVars($user, $domainName));
        }

        $domainLogsDir = "{$this->logsDirPath()}/{$domainName}";
        $this->system->exec("sudo mkdir -p {$domainLogsDir}");

        $domainConfigFile = "{$this->domainsConfigsDirPath()}/{$domainName}.conf";
        $templatesDir = $this->system->templatesDirPath();
        $templateConfigFile = $isDind
            ? "{$templatesDir}/dind/virtualHost-nginx-proxy.blade.php"
            : "{$templatesDir}/virtualHost-nginx-proxy.blade.php";
        $this->system->filesystem()->makeFileFromTemplate($domainConfigFile, $templateConfigFile, $templateVars);
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
        if (!$domain->sslEnabled() || !$connection->ensureServableCertificate()) {
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
    private function dindProxyTemplateVars(\App\Models\User $user, string $domainName): array
    {
        $accountIps = $user->getBindIpAddresses();
        // Any HTTP rule targeting this domain's server_name belongs in {domain}.conf
        // (including system-scoped CLI rules that omitted username).
        $rules = \App\Models\ProxyRule::query()
            ->where('transport', 'http')
            ->where('enabled', true)
            ->where('server_name', $domainName)
            ->get();

        $proxyHttp = null;
        $proxyHttps = null;
        $proxyExtra = [];

        foreach ($rules as $rule) {
            $listenIps = $this->resolveProxyRuleListenIps($rule->listen_ip, $accountIps);
            $upstream = [
                'host' => $rule->upstream_host,
                'port' => (int) $rule->upstream_port,
                'protocol' => $rule->upstream_protocol ?: 'http',
                'ips_v4' => $listenIps['ipv4'],
                'ips_v6' => $listenIps['ipv6'],
            ];
            $listenPort = (int) $rule->listen_port;
            if ($listenPort === 80) {
                $proxyHttp = $upstream;
            } elseif ($listenPort === 443) {
                $proxyHttps = $upstream;
            } else {
                $proxyExtra[] = array_merge([
                    'listen_port' => $listenPort,
                ], $upstream);
            }
        }

        return [
            'proxy_http' => $proxyHttp,
            'proxy_https' => $proxyHttps,
            'proxy_extra' => $proxyExtra,
        ];
    }

    /**
     * Map ProxyRule.listen_ip onto concrete listen addresses.
     * "*" / null / empty → all account bind IPs; a specific IP → only that address
     * (IPv4 or IPv6), even if it is not currently in the account bind list.
     *
     * @param array{ipv4: list<string>, ipv6: list<string>} $accountIps
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
        $configFile = $this->domainsConfigDirPath() . '/' . $domainName . '.conf';
        $this->system->exec("sudo rm -f {$configFile}");
    }

    public function deleteDomainsConfigs(array $domainNames): void
    {
        foreach ($domainNames as $domainName) {
            $configFile = $this->domainsConfigDirPath() . '/' . $domainName . '.conf';
            $this->system->exec("sudo rm -f {$configFile}");
        }
    }

    public function toggleModsecurity(): void
    {
        $this->rebuildMainConfig();
        $this->restart();
    }

    /**
     * Render persisted HTTP proxy rules to vhost config files.
     * 
     * @param array<array> $httpRules HTTP rules from RoutingCompiler
     * @psalm-param list<HttpRule> $httpRules
     */
    private function renderProxyRuleVhosts(array $httpRules): void
    {
        $vhostsDir = $this->domainsConfigsDirPath();
        $this->system->exec("sudo mkdir -p {$vhostsDir}");

        // Clean up old proxy rule configs before rendering new ones
        $this->system->exec("sudo rm -f {$vhostsDir}/proxy-rule-*.conf");

        // Group rules by port for rendering
        $rulesByIdentifier = [];
        foreach ($httpRules as $rule) {
            // Domain-owned HTTP rules are rendered into vhosts/{domain}.conf (DinD template).
            // Only port-only / non-domain custom rules stay in proxy-rule-*.conf.
            if (!empty($rule['server_name']) && $rule['server_name'] !== '_') {
                continue;
            }
            if (($rule['is_generated'] ?? false) === true) {
                continue;
            }
                // Use port + server_name as identifier
                $identifier = $rule['listen_port'] . '_' . ($rule['server_name'] ?? 'wildcard');
                $identifier = preg_replace('/[^a-z0-9_-]/', '_', strtolower((string) $identifier));

                if (!isset($rulesByIdentifier[$identifier])) {
                    $rulesByIdentifier[$identifier] = [
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
                } else {
                    // Merge server_names
                    if (!empty($rule['server_name']) && !in_array($rule['server_name'], $rulesByIdentifier[$identifier]['server_names'])) {
                        $rulesByIdentifier[$identifier]['server_names'][] = $rule['server_name'];
                    }
                }
        }

        // Render each grouping
        foreach ($rulesByIdentifier as $identifier => $ruleGroup) {
            $filename = "proxy-rule-{$identifier}.conf";
            $configPath = "{$vhostsDir}/{$filename}";

            // Build template variables
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

            // Render using custom proxy rule template (or inline)
            $vhostConfig = $this->renderHttpProxyRule($templateVars);
            $this->system->filesystem()->filePutContents($configPath, $vhostConfig);
        }
    }

    /**
     * Generate nginx HTTP vhost config for a proxy rule.
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
    private function renderHttpProxyRule(array $vars): string
    {
        $serverNames = implode(' ', $vars['server_names']);
        $listenDirective = '';
        if ($vars['listen_ip'] !== '*') {
            $listenDirective = "{$vars['listen_ip']}:{$vars['listen_port']}";
        } else {
            $listenDirective = "{$vars['listen_port']}";
        }

        $sslConfig = '';
        if (
            $vars['ssl_enabled']
            && !empty($vars['ssl_cert_pem_file'])
            && !empty($vars['ssl_cert_key_file'])
        ) {
            $sslConfig = <<<NGINX
    ssl_certificate {$vars['ssl_cert_pem_file']};
    ssl_certificate_key {$vars['ssl_cert_key_file']};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
NGINX;
        }

        $upstreamProtocol = $vars['upstream_protocol'] ?? 'http';
        $scheme = $upstreamProtocol === 'https' ? 'https' : 'http';

        $listenWithSsl = $listenDirective . ($vars['ssl_enabled'] ? ' ssl' : '');

        return <<<NGINX
upstream upstream-{$vars['id']}-{$vars['upstream_host']}-{$vars['upstream_port']} {
  resolver 127.0.0.54 valid=30s;
  zone upstreams 64k;
  server {$vars['upstream_host']}:{$vars['upstream_port']} resolve;
}
server {
    listen {$listenWithSsl};
    server_name {$serverNames};

    access_log /var/log/nginx/proxy-rule-{$listenDirective}.access.log;
    error_log /var/log/nginx/proxy-rule-{$listenDirective}.error.log;
{$sslConfig}

    location / {
        proxy_pass {$scheme}://upstream-{$vars['id']}-{$vars['upstream_host']}-{$vars['upstream_port']};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$server_name;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
    }
}
NGINX;
    }

    /**
     * Render stream rules to stream.conf.
     * 
     * @psalm-param list<StreamRule> $streamRules
     */
    private function renderStreamRules(array $streamRules): void
    {
        $streamConfPath = $this->system->engineDirPath() . '/webserver-config/nginx-proxy/stream.conf';
        $config = "# Stream (TCP/UDP) rules\n";

        foreach ($streamRules as $rule) {
            if ($rule['is_generated'] === false) {  // Only custom rules
                $protocol = strtoupper($rule['transport']);
                $listenDirective = '';
                if ($rule['listen_ip'] !== '*') {
                    $listenDirective = "{$rule['listen_ip']}:{$rule['listen_port']}";
                } else {
                    $listenDirective = "{$rule['listen_port']}";
                }

                $config .= <<<NGINX

upstream stream_proxy_{$rule['id']} {
    server {$rule['upstream_host']}:{$rule['upstream_port']};
}

server {
    listen {$listenDirective} {$protocol};
    proxy_pass stream_proxy_{$rule['id']};
}
NGINX;
            }
        }

        // Always write the file (even if empty) to clear old rules
        $this->system->filesystem()->filePutContents($streamConfPath, $config);
    }
}
