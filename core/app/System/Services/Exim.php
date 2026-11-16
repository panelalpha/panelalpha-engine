<?php

namespace App\System\Services;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Dind\TenantNetwork;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Lib\Mail\MailDnsRecords;
use App\Lib\Mail\SenderDomainGate;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Project\Dind\TenantEgressGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class Exim
{
    /** Docker's stock docker0 address; used only when the bridge cannot be inspected. */
    public const FALLBACK_BRIDGE_GATEWAY = '172.17.0.1';

    /** The port exim takes mail on, and the one {@see TenantEgressGuard::HOST_PORTS} admits for accounts. */
    public const RELAY_PORT = '25';

    /** Where the rewrite rule reads the sender map, on the host and inside the mail container. */
    public const SENDER_MAP_DIR = 'config/exim/panelalpha';

    public const SENDER_MAP_FILE = 'senders';

    /** The map's key for the name a sender that owns no domain here leaves as. */
    public const SENDER_NAME_KEY = 'server';

    /** What the map says about one project's address and one domain of it. */
    public const SENDER_ALLOWED = 'allowed';

    /**
     * The name the shipped rule falls back to, which the written rule replaces with the server's, and the one a server
     * with no name of its own sends as: reserved (RFC 2606), owned by no one, and never the mail container's own name.
     */
    public const UNNAMED_SENDER = 'panelalpha-sender.invalid';

    /** How long a domain's A and AAAA answer is reused: the map is rewritten every minute, DNS is asked far less. */
    public const SENDER_DNS_TTL_SECONDS = 600;

    private const SENDER_DNS_CACHE_PREFIX = 'exim-sender-dns:';

    /** The bases whose names are the operator's: PanelAlpha Online's zone is shared by every engine. */
    private const OWN_DOMAIN_SOURCES = [DomainPlan::SOURCE_SITES_BASE_DOMAIN, DomainPlan::SOURCE_PANELALPHA_DIRECT];

    public function __construct(
        private EngineSystem $system,
    ) {}

    public function rebuildEximConfig(): void
    {
        $config = $this->eximConfig();

        $this->writeUpdateConf($config);
        $this->writePasswdClient($config);
        $this->writeRewriteConf();
        $this->writeTransportConf($config);
        $this->refreshSenderMap();

        $this->applyConfig();
    }

    /**
     * @param  array<string, string>  $config
     */
    private function writeUpdateConf(array $config): void
    {
        $updateConfPath = $this->system->engineDirPath().'/config/exim/update-exim4.conf.conf';
        $updateConfTemplatePath = $this->system->engineDirPath().'/templates/config/exim-update-conf.blade.php';
        $updateConfTemplate = $this->system->filesystem()->fileGetContents($updateConfTemplatePath);

        [$type, $smarthost] = $this->smarthostRoute($config);

        $templateVars = [
            'dc_readhost' => $config['sender_domain'],
            'dc_eximconfig_configtype' => $type,
            'dc_smarthost' => $smarthost,
            ...$this->networkSettings(),
        ];
        $updateConf = Blade::render($updateConfTemplate, $templateVars);
        $this->writeConfigFile($updateConfPath, $updateConf);
    }

    /**
     * @param  array<string, string>  $config
     * @return array{0: string, 1: string}
     */
    private function smarthostRoute(array $config): array
    {
        $type = 'internet';
        $smarthost = '';
        switch ($config['smarthost_provider']) {
            case 'sendgrid':
                $type = 'smarthost';
                $smarthost = 'smtp.sendgrid.net::587';
                break;
            case 'mailchannels':
                $type = 'smarthost';
                $smarthost = 'smtp.mailchannels.net::25';
                break;
            case 'amazon_ses':
                $type = 'smarthost';
                $smarthost = self::smarthost($config['amazon_ses_smtp_endpoint'], $config['amazon_ses_starttls_port']);
                break;
            case 'smtp':
                $type = 'smarthost';
                $smarthost = self::smarthost($config['smtp_host'], $config['smtp_port']);
                break;
        }

        return [$type, $smarthost];
    }

    /**
     * @param  array<string, string>  $config
     */
    private function writePasswdClient(array $config): void
    {
        $passwdPath = $this->system->engineDirPath().'/config/exim/passwd.client';
        $passwdTemplatePath = $this->system->engineDirPath().'/templates/config/exim-passwd.blade.php';
        $passwdTemplate = $this->system->filesystem()->fileGetContents($passwdTemplatePath);

        [$username, $password] = $this->smarthostLogin($config);

        $templateVars = [
            'username' => $username,
            'password' => $password,
        ];
        $passwd = Blade::render($passwdTemplate, $templateVars);
        $this->writeConfigFile($passwdPath, $passwd);
    }

    /**
     * @param  array<string, string>  $config
     * @return array{0: string, 1: string}
     */
    private function smarthostLogin(array $config): array
    {
        $username = '';
        $password = '';
        switch ($config['smarthost_provider']) {
            case 'sendgrid':
                $username = 'apikey';
                $password = $config['sendgrid_api_token'];
                break;
            case 'mailchannels':
                $username = $config['mailchannels_username'];
                $password = $config['mailchannels_password'];
                break;
            case 'amazon_ses':
                $username = $config['amazon_ses_smtp_username'];
                $password = $config['amazon_ses_smtp_password'];
                break;
            case 'smtp':
                $username = $config['smtp_username'];
                $password = $config['smtp_password'];
                break;
        }

        return [$username, $password];
    }

    /**
     * The rewrite rule reads the sender map instead of carrying its values, so the shipped rule is the whole of it and
     * an install that still carries an earlier one is given this one here. It names the server only as its fallback, for
     * a map that is missing or names no one.
     */
    private function writeRewriteConf(): void
    {
        $rewritePath = $this->system->engineDirPath().'/config/exim/rewrite.conf';

        $this->writeConfigFile($rewritePath, $this->rewriteConf());
    }

    private function rewriteConf(): string
    {
        $shipped = $this->system->filesystem()->fileGetContents($this->system->engineDirPath().'/templates/config/exim/rewrite.conf');

        return str_replace(self::UNNAMED_SENDER, $this->fallbackSenderName(), $shipped);
    }

    /** The name the server sends as, else the reserved one: never `$primary_hostname`, the mail container's own. */
    private function fallbackSenderName(): string
    {
        $name = $this->senderName();
        if (preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $name) === 1) {
            return $name;
        }
        Log::warning('Exim has no name to send mail as, so a sender that owns no domain here leaves as '.self::UNNAMED_SENDER
            .': set the sending domain in the mail settings, or the engine\'s certificate domain.');

        return self::UNNAMED_SENDER;
    }

    /**
     * @param  array<string, string>  $config
     */
    private function writeTransportConf(array $config): void
    {
        $transportPath = $this->system->engineDirPath().'/config/exim/transport.conf';
        $transportTemplatePath = $this->system->engineDirPath().'/templates/config/exim-transport.blade.php';
        $transportTemplate = $this->system->filesystem()->fileGetContents($transportTemplatePath);

        $templateVars = [
            'smtp_implicit_tls' => ! empty($config['smtp_implicit_tls']),
        ];
        $transport = Blade::render($transportTemplate, $templateVars);
        $this->writeConfigFile($transportPath, $transport);
    }

    /** Writes the sender map, which the rewrite rule reads per message: no restart follows. */
    public function refreshSenderMap(): void
    {
        $this->system->filesystem()->makeDirWithParents($this->system->engineDirPath().'/'.self::SENDER_MAP_DIR);
        $this->writeConfigFile($this->senderMapPath(), $this->senderMap());
    }

    /**
     * `<address>/<domain> allowed` for every domain a project may send as, from the address its account container
     * reaches the relay with, and `server <name>` for every sender that owns none. A domain or alias is listed only
     * when {@see SenderDomainGate} finds it is this server's; attaching one checks nothing but its format.
     */
    public function senderMap(): string
    {
        $lines = [];
        $name = $this->senderName();
        if ($name !== '') {
            $lines[] = self::SENDER_NAME_KEY.' '.$name;
        }
        $users = User::query()->with('domains.tunnels')->orderBy('username')->get();
        $gate = $this->senderDomainGate($users);
        foreach ($users as $user) {
            $address = $this->accountAddress($user->username);
            if ($address === null) {
                continue;
            }
            foreach ($user->domains->sortBy('domain') as $domain) {
                $held = $domain->tunnels->pluck('hostname')->map(fn ($hostname): string => (string) $hostname)->all();
                foreach ([$domain->domain, ...$domain->getAliases()] as $sender) {
                    if ($gate->allows($sender, $held)) {
                        $lines[] = $address.'/'.strtolower($sender).' '.self::SENDER_ALLOWED;
                    }
                }
            }
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /** @param Collection<int, User> $users */
    private function senderDomainGate(Collection $users): SenderDomainGate
    {
        return new SenderDomainGate($this->ownDomainBases(), $this->serverAddresses($users), $this->cachedAddresses(...));
    }

    /** @return list<string> */
    private function ownDomainBases(): array
    {
        $own = array_filter(
            DomainAllocator::projectDomainBases(),
            static fn (array $base): bool => in_array($base['source'], self::OWN_DOMAIN_SOURCES, true)
        );

        return array_values(array_map(static fn (array $base): string => $base['base'], $own));
    }

    /**
     * The engine's default addresses as recorded and as the internet sees them through the NAT map, and every
     * address a project is given.
     *
     * @param  Collection<int, User>  $users
     * @return list<string>
     */
    private function serverAddresses(Collection $users): array
    {
        $ipv4 = trim((string) Setting::get('default_ipv4'));
        $ipv6 = trim((string) Setting::get('default_ipv6'));
        $addresses = [$ipv4, $ipv6, ...MailDnsRecords::hostAddresses($ipv4, $ipv6, Ipv4NatMap::getLocalToPublicMap())];
        foreach ($users as $user) {
            $assigned = $user->getIpAddresses();
            array_push($addresses, ...$assigned['ipv4'], ...$assigned['ipv6']);
        }

        return array_values(array_unique(array_filter($addresses, static fn (string $address): bool => $address !== '')));
    }

    /** @return list<string> */
    private function cachedAddresses(string $domain): array
    {
        return Cache::remember(
            self::SENDER_DNS_CACHE_PREFIX.strtolower($domain),
            self::SENDER_DNS_TTL_SECONDS,
            fn (): array => $this->resolveAddresses($domain)
        );
    }

    /**
     * A domain's IPv4 and IPv6 addresses, CNAMEs followed; none when it does not resolve.
     *
     * @return list<string>
     */
    protected function resolveAddresses(string $domain): array
    {
        $ipv4 = gethostbynamel($domain.'.');
        $ipv6 = @dns_get_record($domain.'.', DNS_AAAA);
        $ipv6 = is_array($ipv6) ? array_column($ipv6, 'ipv6') : [];

        return array_map('strval', [...($ipv4 === false ? [] : $ipv4), ...$ipv6]);
    }

    /**
     * The name a sender that is not one of the project's own domains leaves as: the sending domain the operator
     * configured, else the name the engine's own certificate is issued for, else the address zone it defaults to.
     */
    public function senderName(): string
    {
        $configured = trim($this->eximConfig()['sender_domain']);
        if ($configured !== '') {
            return strtolower($configured);
        }
        $certified = strtolower(trim((string) Setting::get('cert_domain')));
        if ($certified !== '') {
            return $certified;
        }
        $address = trim((string) Setting::get('default_ipv4'));
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return '';
        }

        return str_replace('.', '-', $address).'.'.DomainPlan::PANELALPHA_DIRECT;
    }

    /**
     * The address a project's mail arrives from, which is the one its account container holds; null while that
     * container is not running, so an account that is down lends its address to no one.
     */
    protected function accountAddress(string $account): ?string
    {
        $process = $this->system->runProcess(Privileged::docker()->containerNetworks($account), [], 30);
        if ($process->getExitCode() !== 0) {
            return null;
        }

        return self::senderAddress($process->getOutput());
    }

    /** An account container's own IPv4 on the tenant network, else on any network; null when it holds none. */
    public static function senderAddress(string $networks): ?string
    {
        $decoded = json_decode(trim($networks), true);
        if (! is_array($decoded)) {
            return null;
        }

        return self::endpointAddress($decoded[TenantNetwork::NAME] ?? null)
            ?? self::endpointAddress($decoded[TenantNetwork::LEGACY_NAME] ?? null)
            ?? self::firstAddress($decoded);
    }

    /** The first IPv4 any of the container's networks gives it, so an account on an unknown bridge still sends. */
    private static function firstAddress(array $networks): ?string
    {
        foreach ($networks as $endpoint) {
            $address = self::endpointAddress($endpoint);
            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }

    /** @param mixed $endpoint one of Docker's `NetworkSettings.Networks` entries */
    private static function endpointAddress(mixed $endpoint): ?string
    {
        $address = is_array($endpoint) ? ($endpoint['IPAddress'] ?? null) : null;

        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? $address
            : null;
    }

    private function senderMapPath(): string
    {
        return $this->system->engineDirPath().'/'.self::SENDER_MAP_DIR.'/'.self::SENDER_MAP_FILE;
    }

    /**
     * `dc_smarthost` for one host: `<host>::<port>`. Exim reads it as a colon-separated list, so an IPv6
     * address goes in brackets with its colons doubled; written plain, `2001:db8::25` became host `2001`.
     */
    public static function smarthost(string $host, string $port): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '['.str_replace(':', '::', $host).']';
        }

        return $host.'::'.$port;
    }

    /**
     * Rewrites the lines the engine decides in the existing update-exim4.conf.conf -- where Exim listens and relays,
     * and the name it hides the mailname behind -- leaving the rest as installed or saved, and writes the rule and the
     * sender map, which an install or a deploy of an older engine lacks.
     */
    public function rebuildNetworks(): void
    {
        $path = $this->system->engineDirPath().'/config/exim/update-exim4.conf.conf';
        $current = $this->system->filesystem()->fileGetContents($path);

        $lines = array_filter(
            explode("\n", rtrim($current, "\n")),
            fn (string $line) => ! preg_match('/^(dc_local_interfaces|dc_relay_nets|dc_readhost)=/', $line)
        );
        foreach ([...$this->networkSettings(), 'dc_readhost' => $this->eximConfig()['sender_domain']] as $key => $value) {
            $lines[] = "{$key}='{$value}'";
        }
        $this->writeConfigFile($path, implode("\n", $lines)."\n");
        $this->writeRewriteConf();
        $this->refreshSenderMap();

        $this->applyConfig();
    }

    /**
     * An account sends mail to host.docker.internal:25, which Docker resolves to docker0's gateway, and a container
     * project's application to the tenant network's gateway ({@see relayEnvironment()}); on pash-tenants both arrive
     * from that network's subnet.
     *
     * @return array{dc_local_interfaces: string, dc_relay_nets: string}
     */
    public function networkSettings(): array
    {
        $gateway = $this->networkAddresses('bridge')['gateway'] ?? self::FALLBACK_BRIDGE_GATEWAY;
        $tenants = $this->networkAddresses(TenantNetwork::NAME);

        $interfaces = ['127.0.0.1', $gateway];
        $relay = ['127.0.0.0/8', '172.16.0.0/12'];
        if (isset($tenants['gateway'])) {
            $interfaces[] = $tenants['gateway'];
        }
        if (isset($tenants['subnet'])) {
            $relay[] = $tenants['subnet'];
        }

        return [
            'dc_local_interfaces' => implode(' ; ', $interfaces),
            'dc_relay_nets' => implode(' ; ', $relay),
        ];
    }

    /**
     * The relay as a container project reaches it: the tenant network's gateway, which exim listens on
     * ({@see networkSettings()}), and its port. Not docker0's gateway: the account's own Docker daemon gives its
     * docker0 172.17.0.1 too, so on a host whose docker0 has Docker's default address that one is the account itself.
     * Nothing when the gateway cannot be read, since a literal would be another install's.
     *
     * @return array<string, string> `PA_SMTP_HOST`, `PA_SMTP_PORT`
     */
    public function relayEnvironment(): array
    {
        $gateway = $this->networkAddresses(TenantNetwork::NAME)['gateway'] ?? null;
        if ($gateway === null) {
            return [];
        }

        return [ComposeEnvironment::SMTP_HOST => $gateway, ComposeEnvironment::SMTP_PORT => self::RELAY_PORT];
    }

    /**
     * The first IPv4 subnet and gateway of a Docker network; empty when it
     * does not exist or cannot be inspected.
     *
     * @return array{subnet?: string, gateway?: string}
     */
    protected function networkAddresses(string $network): array
    {
        $process = $this->system->runProcess(Privileged::docker()->networkSubnetsAndGateways($network), [], 30);
        if ($process->getExitCode() !== 0) {
            return [];
        }

        return self::parseNetworkAddresses($process->getOutput());
    }

    /**
     * @return array{subnet?: string, gateway?: string}
     */
    public static function parseNetworkAddresses(string $output): array
    {
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (! preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)(?:\s+(\S+))?$#', trim($line), $m)
                || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || (int) $m[2] > 32) {
                continue;
            }
            $found = ['subnet' => $m[1].'/'.$m[2]];
            if (filter_var($m[3] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $found['gateway'] = $m[3];
            }

            return $found;
        }

        return [];
    }

    /**
     * The mail container bind-mounts each file, and Docker leaves a directory where one was missing;
     * `cp` would write into it and report success, so a directory there is refused.
     */
    private function writeConfigFile(string $path, string $contents): void
    {
        if ($this->system->filesystem()->directoryExists($path)) {
            throw new \RuntimeException("Cannot write the Exim config {$path}: it is a directory, not a file.");
        }
        $this->system->filesystem()->filePutContents($path, $contents);
    }

    /**
     * A reload only signals a running daemon; one that abandoned a bind it could not make stays down,
     * so it is started afresh.
     */
    private function applyConfig(): void
    {
        $mail = Privileged::engineServices($this->system->composeFilePath());
        $this->system->exec($mail->eximUpdateConfig());
        $this->system->exec($mail->eximRestart());
    }

    /**
     * The address is checked as it goes into the To: header; the From: domain is reserved (RFC 2606), owned by no one.
     * A null envelope sender means a failed test bounces nowhere, and exim never rewrites an empty sender into From:.
     */
    public function sendTestEmail(string $email): array
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid test email address.');
        }

        $process = $this->system->runProcess(Privileged::engineServices($this->system->composeFilePath())->eximSendTestEmail($email));

        return $this->testEmailResult($process);
    }

    private function testEmailResult(Process $process): array
    {
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        return [
            ...self::deliveryOutcome($stdout."\n".$stderr, $exitCode),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * What happened to the message, read from the log lines `exim -v` prints. Exit code 0 only says exim
     * accepted it: a deferral (`==`) leaves it on the queue for a retry, and a bounce (`**`) exits 0 as well.
     *
     * @return array{status: string, delivered: bool, reason: ?string}
     */
    public static function deliveryOutcome(string $output, ?int $exitCode): array
    {
        preg_match_all(
            '/^\s*(?:\d{4}-\d\d-\d\d [\d:.]+ (?:\[\d+\] )?(?:[\w-]+ )?)?(=>|->|==|\*\*) \S+ ?(.*)$/m',
            $output,
            $lines,
            PREG_SET_ORDER
        );
        $first = [];
        foreach ($lines as [, $flag, $rest]) {
            $first[$flag] ??= trim($rest);
        }

        [$status, $reason] = match (true) {
            isset($first['**']) => ['failed', $first['**']],
            isset($first['==']) => ['deferred', $first['==']],
            isset($first['=>']) || isset($first['->']) => ['delivered', null],
            default => [$exitCode === 0 ? 'unknown' : 'not_sent', null],
        };

        return [
            'status' => $status,
            'delivered' => $status === 'delivered',
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eximConfig(): array
    {
        return Setting::getEximConfig();
    }
}
