<?php

namespace App\System\Services;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\Setting;
use App\System as EngineSystem;
use App\System\Privileged;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Process\Process;

class Exim
{
    /** Docker's stock docker0 address; used only when the bridge cannot be inspected. */
    public const FALLBACK_BRIDGE_GATEWAY = '172.17.0.1';

    public function __construct(
        private EngineSystem $system,
    ) {}

    public function rebuildEximConfig(): void
    {
        $config = $this->eximConfig();

        $this->writeUpdateConf($config);
        $this->writePasswdClient($config);
        $this->writeRewriteConf($config);
        $this->writeTransportConf($config);

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
     * @param  array<string, string>  $config
     */
    private function writeRewriteConf(array $config): void
    {
        $rewritePath = $this->system->engineDirPath().'/config/exim/rewrite.conf';
        $rewriteTemplatePath = $this->system->engineDirPath().'/templates/config/exim-rewrite.blade.php';
        $rewriteTemplate = $this->system->filesystem()->fileGetContents($rewriteTemplatePath);

        $templateVars = [
            'sender_domain' => $config['sender_domain'],
        ];
        $rewrite = Blade::render($rewriteTemplate, $templateVars);
        $this->writeConfigFile($rewritePath, $rewrite);
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
     * Rewrites only the listen and relay lines of the existing
     * update-exim4.conf.conf, leaving the rest as installed or saved.
     */
    public function rebuildNetworks(): void
    {
        $path = $this->system->engineDirPath().'/config/exim/update-exim4.conf.conf';
        $current = $this->system->filesystem()->fileGetContents($path);

        $lines = array_filter(
            explode("\n", rtrim($current, "\n")),
            fn (string $line) => ! preg_match('/^(dc_local_interfaces|dc_relay_nets)=/', $line)
        );
        foreach ($this->networkSettings() as $key => $value) {
            $lines[] = "{$key}='{$value}'";
        }
        $this->writeConfigFile($path, implode("\n", $lines)."\n");

        $this->applyConfig();
    }

    /**
     * Accounts send mail to host.docker.internal:25, which Docker resolves to
     * docker0's gateway; on pash-tenants they arrive from that network's subnet.
     *
     * @return array{dc_local_interfaces: string, dc_relay_nets: string}
     */
    public function networkSettings(): array
    {
        $gateway = $this->networkAddresses('bridge')['gateway'] ?? self::FALLBACK_BRIDGE_GATEWAY;

        $relay = ['127.0.0.0/8', '172.16.0.0/12'];
        $tenants = $this->networkAddresses(TenantNetwork::NAME)['subnet'] ?? null;
        if ($tenants !== null) {
            $relay[] = $tenants;
        }

        return [
            'dc_local_interfaces' => '127.0.0.1 ; '.$gateway,
            'dc_relay_nets' => implode(' ; ', $relay),
        ];
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
