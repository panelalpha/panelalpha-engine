<?php

namespace App\System\Services;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\Setting;
use App\System as EngineSystem;
use Illuminate\Support\Facades\Blade;

class Exim
{
    /** Docker's stock docker0 address; used only when the bridge cannot be inspected. */
    public const FALLBACK_BRIDGE_GATEWAY = '172.17.0.1';

    /**
     * `service exim4 restart`, plus stopping every `exim4 -bd` the init script
     * missed: a daemon still retrying a bind has written no pid file, kept
     * 127.0.0.1:25, and left the new one waiting minutes for the port.
     */
    public const RESTART_SCRIPT = <<<'SH'
        service exim4 stop || true
        daemons() {
            for p in /proc/[0-9]*; do
                [ "$(cat "$p/comm" 2>/dev/null)" = exim4 ] || continue
                case " $(tr '\0' ' ' < "$p/cmdline" 2>/dev/null)" in
                    *" -bd"*) echo "${p#/proc/}" ;;
                esac
            done
        }
        left=$(daemons)
        [ -n "$left" ] && kill $left 2>/dev/null
        for _ in $(seq 20); do [ -z "$(daemons)" ] && break; sleep 0.5; done
        left=$(daemons)
        [ -n "$left" ] && kill -9 $left 2>/dev/null
        service exim4 start
        SH;

    public function __construct(
        private EngineSystem $system,
    ) {
    }

    public function rebuildEximConfig(): void
    {
        $config = $this->eximConfig();

        // update-exim4.conf.conf
        $updateConfPath = $this->system->engineDirPath() . '/config/exim/update-exim4.conf.conf';
        $updateConfTemplatePath = $this->system->engineDirPath() . '/templates/config/exim-update-conf.blade.php';
        $updateConfTemplate = $this->system->filesystem()->fileGetContents($updateConfTemplatePath);

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
                $smarthost = $config['amazon_ses_smtp_endpoint'] . '::' . $config['amazon_ses_starttls_port'];
                break;
            case 'smtp':
                $type = 'smarthost';
                $smarthost = $config['smtp_host'] . '::' . $config['smtp_port'];
                break;
        }

        $templateVars = [
            'dc_readhost' => $config['sender_domain'],
            'dc_eximconfig_configtype' => $type,
            'dc_smarthost' => $smarthost,
            ...$this->networkSettings(),
        ];
        $updateConf = Blade::render($updateConfTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($updateConfPath, $updateConf);

        // passwd.client
        $passwdPath = $this->system->engineDirPath() . '/config/exim/passwd.client';
        $passwdTemplatePath = $this->system->engineDirPath() . '/templates/config/exim-passwd.blade.php';
        $passwdTemplate = $this->system->filesystem()->fileGetContents($passwdTemplatePath);

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

        $templateVars = [
            'username' => $username,
            'password' => $password,
        ];
        $passwd = Blade::render($passwdTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($passwdPath, $passwd);

        // rewrite.conf
        $rewritePath = $this->system->engineDirPath() . '/config/exim/rewrite.conf';
        $rewriteTemplatePath = $this->system->engineDirPath() . '/templates/config/exim-rewrite.blade.php';
        $rewriteTemplate = $this->system->filesystem()->fileGetContents($rewriteTemplatePath);

        $templateVars = [
            'sender_domain' => $config['sender_domain'],
        ];
        $rewrite = Blade::render($rewriteTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($rewritePath, $rewrite);

        $transportPath = $this->system->engineDirPath() . '/config/exim/transport.conf';
        $transportTemplatePath = $this->system->engineDirPath() . '/templates/config/exim-transport.blade.php';
        $transportTemplate = $this->system->filesystem()->fileGetContents($transportTemplatePath);

        $templateVars = [
            'smtp_implicit_tls' => !empty($config['smtp_implicit_tls']),
        ];
        $transport = Blade::render($transportTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($transportPath, $transport);

        $this->applyConfig();
    }

    /**
     * Rewrites only the listen and relay lines of the existing
     * update-exim4.conf.conf, leaving the rest as installed or saved.
     */
    public function rebuildNetworks(): void
    {
        $path = $this->system->engineDirPath() . '/config/exim/update-exim4.conf.conf';
        $current = $this->system->filesystem()->fileGetContents($path);

        $lines = array_filter(
            explode("\n", rtrim($current, "\n")),
            fn (string $line) => !preg_match('/^(dc_local_interfaces|dc_relay_nets)=/', $line)
        );
        foreach ($this->networkSettings() as $key => $value) {
            $lines[] = "{$key}='{$value}'";
        }
        $this->system->filesystem()->filePutContents($path, implode("\n", $lines) . "\n");

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
            'dc_local_interfaces' => '127.0.0.1 ; ' . $gateway,
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
        $process = $this->system->runProcess([
            'sudo', 'docker', 'network', 'inspect', $network,
            '--format', '{{range .IPAM.Config}}{{.Subnet}} {{.Gateway}}{{"\n"}}{{end}}',
        ], [], 30);
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
            if (!preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)(?:\s+(\S+))?$#', trim($line), $m)
                || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || (int) $m[2] > 32) {
                continue;
            }
            $found = ['subnet' => $m[1] . '/' . $m[2]];
            if (filter_var($m[3] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $found['gateway'] = $m[3];
            }

            return $found;
        }

        return [];
    }

    private function applyConfig(): void
    {
        $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'mail',
            'update-exim4.conf',
        ]);

        $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'mail',
            // A reload only signals a running daemon; one that abandoned a
            // bind it could not make stays down, so start it afresh.
            'sh',
            '-c',
            self::RESTART_SCRIPT,
        ]);
    }

    public function sendTestEmail(string $email): array
    {
        // Interpolated into the To: header below.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid test email address.');
        }

        // example.invalid is reserved (RFC 2606), so no third party owns it.
        $message = ""
            . "From: PanelAlpha Engine <noreply@example.invalid>\n"
            . "To: {$email}\n"
            . "Subject: Test email from PanelAlpha Engine\n\n"
            . "This is a test email sent from PanelAlpha Engine.\n";

        $to = escapeshellarg($email);
        $process = $this->system->runProcess([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'mail',
            'bash',
            '-c',
            // Null envelope sender: a failed test generates no bounce, and exim
            // never rewrites an empty sender into the From: address.
            "echo " . escapeshellarg($message) . " | exim4 -v -odf -f '<>' $to"
        ]);

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        return [
            ...self::deliveryOutcome($stdout . "\n" . $stderr, $exitCode),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * What happened to the message, read from the log lines `exim -v` prints.
     * Exit code 0 only says exim accepted it: a deferral (`==`) leaves it on
     * the queue for a retry, and a bounce (`**`) exits 0 as well.
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
