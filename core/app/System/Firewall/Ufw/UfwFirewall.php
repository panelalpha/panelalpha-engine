<?php

namespace App\System\Firewall\Ufw;

use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\FirewallLogEntry;
use App\System\Firewall\FirewallNotFound;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\FirewallStatus;
use App\System\Firewall\TrustedAddress;
use App\System\ProcessRunner;

/**
 * ufw on the engine host, set up by scripts/firewall/ufw.sh.
 *
 * ufw reloads only its own chains, so Docker's rules and the engine's tenant
 * and build chains survive every change made here. ufw does not see ports
 * Docker publishes (2011, FTP, SFTP) on its own; the host hook in
 * scripts/firewall/ufw.sh sends them through ufw's user rules and default
 * policy from DOCKER-USER, so the rules here hold for them too.
 */
class UfwFirewall implements Firewall
{
    private const RULES = '/etc/ufw/user.rules';
    private const RULES6 = '/etc/ufw/user6.rules';
    /** How far back the logs are read: lines, per source. */
    private const SCAN = 5000;
    /** fail2ban's ignore list, which scripts/firewall/ufw.sh turns into jail config. */
    private const TRUSTED = '/etc/fail2ban/panelalpha-ignoreip';
    private const SCRIPT = '/opt/panelalpha/shared-hosting/scripts/firewall.sh';

    public function __construct(private ProcessRunner $system)
    {
    }

    public function name(): string
    {
        return FirewallFactory::UFW;
    }

    public function status(): FirewallStatus
    {
        $version = $this->system->runProcessOnHost(['ufw', 'version']);
        if (!$version->isSuccessful()) {
            return new FirewallStatus($this->name(), null, error: trim($version->getErrorOutput() ?: $version->getOutput()) ?: 'ufw is not installed');
        }
        $status = $this->system->runProcessOnHost(['ufw', 'status', 'verbose']);
        $out = $status->getOutput();
        if (!$status->isSuccessful()) {
            return new FirewallStatus($this->name(), null, error: trim($status->getErrorOutput() ?: $out));
        }

        preg_match('/^Status:\s*(\w+)/m', $out, $state);
        preg_match('/^Default:\s*(\w+) \(incoming\),\s*(\w+) \(outgoing\)/m', $out, $defaults);

        return new FirewallStatus(
            provider: $this->name(),
            enabled: ($state[1] ?? '') === 'active',
            version: preg_match('/^ufw\s+(\S+)/m', $version->getOutput(), $v) === 1 ? $v[1] : null,
            defaultIncoming: $defaults[1] ?? null,
            defaultOutgoing: $defaults[2] ?? null,
        );
    }

    public function rules(): array
    {
        // A missing user6.rules (IPV6=no) is not an error; the cat still prints user.rules.
        $v4 = $this->system->runProcessOnHost(['cat', self::RULES]);
        if (!$v4->isSuccessful()) {
            throw new FirewallException('Could not read ' . self::RULES . ': ' . trim($v4->getErrorOutput()));
        }
        $v6 = $this->system->runProcessOnHost(['cat', self::RULES6]);

        return UfwRules::parse($v4->getOutput(), $v6->isSuccessful() ? $v6->getOutput() : '');
    }

    public function rule(string $id): FirewallRule
    {
        foreach ($this->rules() as $rule) {
            if ($rule->id() === $id) {
                return $rule;
            }
        }

        throw FirewallNotFound::rule($id);
    }

    public function addRule(FirewallRule $rule): FirewallRule
    {
        // ufw takes the first rule that matches: a deny goes above the allows.
        $position = $rule->action === FirewallRule::DENY ? ['prepend'] : [];
        $halves = UfwRules::specs($rule, withComment: false);
        foreach (UfwRules::specs($rule) as $i => $spec) {
            try {
                $this->ufw([...$position, ...$spec]);
            } catch (FirewallException $e) {
                // Half of a two-way rule is not the rule that was asked for.
                for ($j = 0; $j < $i; $j++) {
                    $this->system->runProcessOnHost(['ufw', 'delete', ...$halves[$j]]);
                }
                throw $e;
            }
        }

        return $this->rule($rule->id());
    }

    public function updateRule(string $id, FirewallRule $rule): FirewallRule
    {
        $old = $this->rule($id);
        if ($old->sameMatch($rule)) {
            // Only the comment changed, which ufw cannot edit in place.
            $this->deleteRule($id);
            try {
                return $this->addRule($rule);
            } catch (FirewallException $e) {
                $this->addRule($old);
                throw $e;
            }
        }

        $new = $this->addRule($rule);
        $this->deleteRule($id);

        return $new;
    }

    public function deleteRule(string $id): FirewallRule
    {
        $rule = $this->rule($id);
        foreach (UfwRules::specs($rule, withComment: false) as $spec) {
            $this->ufw(['delete', ...$spec]);
        }
        // A fail2ban ban: lift it in fail2ban too, or its ban database writes
        // the rule back the next time fail2ban starts.
        if ($rule->source !== null && str_starts_with((string) $rule->comment, 'by Fail2Ban')) {
            $this->system->runProcessOnHost(['fail2ban-client', 'unban', $rule->source]);
        }

        return $rule;
    }

    public function enable(): void
    {
        $this->ufw(['--force', 'enable']);
    }

    public function disable(): void
    {
        $this->ufw(['disable']);
    }

    public function reload(): void
    {
        $this->ufw(['reload']);
    }

    public function logs(int $limit = 100, ?string $type = null, ?string $address = null): array
    {
        $entries = [];
        if ($type === null || $type === FirewallLogEntry::BLOCKED) {
            $entries = UfwLogs::parseKernel($this->kernelLog());
        }
        if ($type !== FirewallLogEntry::BLOCKED) {
            $offset = trim($this->system->runProcessOnHost(['date', '+%:z'])->getOutput());
            $bans = $this->system->runProcessOnHost(['tail', '-n', (string) self::SCAN, '/var/log/fail2ban.log']);
            $entries = [...$entries, ...UfwLogs::parseFail2ban($bans->isSuccessful() ? $bans->getOutput() : '', $offset ?: '+00:00')];
        }

        $address = $address === null ? null : FirewallRule::normalizeAddress($address);
        $entries = array_filter($entries, static fn (FirewallLogEntry $e): bool => ($type === null || $e->type === $type)
            && ($address === null || $e->address === $address));
        usort($entries, static fn (FirewallLogEntry $a, FirewallLogEntry $b): int => $b->time <=> $a->time);

        return array_slice($entries, 0, $limit);
    }

    public function trustedAddresses(): array
    {
        $file = $this->system->runProcessOnHost(['cat', self::TRUSTED]);

        return TrustedAddress::parseList($file->isSuccessful() ? $file->getOutput() : '');
    }

    public function trust(TrustedAddress $address): TrustedAddress
    {
        $list = [];
        foreach ($this->trustedAddresses() as $entry) {
            $list[$entry->id()] = $entry;
        }
        $list[$address->id()] = $address;
        $this->saveTrusted(array_values($list));
        if ($address->isSingle()) {
            // Not banned is not an error: the answer is only "was it?".
            $this->system->runProcessOnHost(['fail2ban-client', 'unban', $address->address]);
        }

        return $address;
    }

    public function untrust(string $id): TrustedAddress
    {
        $list = $this->trustedAddresses();
        foreach ($list as $i => $entry) {
            if ($entry->id() === $id) {
                unset($list[$i]);
                $this->saveTrusted(array_values($list));

                return $entry;
            }
        }

        throw FirewallNotFound::trustedAddress($id);
    }

    /**
     * The list file, then fail2ban's jails rewritten from it and reloaded by
     * the same host script the installer runs.
     *
     * @param list<TrustedAddress> $list
     */
    private function saveTrusted(array $list): void
    {
        $write = $this->system->runProcessOnHost(['sh', '-c', 'mkdir -p "$(dirname "$0")" && printf "%s" "$1" > "$0"', self::TRUSTED, TrustedAddress::formatList($list)]);
        if (!$write->isSuccessful()) {
            throw new FirewallException('Could not write ' . self::TRUSTED . ': ' . trim($write->getErrorOutput()));
        }
        $apply = $this->system->runProcessOnHost(['bash', self::SCRIPT, '--fail2ban']);
        if (!$apply->isSuccessful()) {
            throw new FirewallException(trim($apply->getErrorOutput() ?: $apply->getOutput()) ?: 'fail2ban did not take the new list');
        }
    }

    /** ufw's blocked packets, from the journal, or /var/log/ufw.log where rsyslog keeps it. */
    private function kernelLog(): string
    {
        $journal = $this->system->runProcessOnHost(['journalctl', '-k', '--no-pager', '-o', 'short-iso', '-n', (string) self::SCAN, '-g', 'UFW BLOCK']);
        if ($journal->isSuccessful() && trim($journal->getOutput()) !== '') {
            return $journal->getOutput();
        }
        $file = $this->system->runProcessOnHost(['tail', '-n', (string) self::SCAN, '/var/log/ufw.log']);

        return $file->isSuccessful() ? $file->getOutput() : '';
    }

    /** @param list<string> $args */
    private function ufw(array $args): string
    {
        $process = $this->system->runProcessOnHost(['ufw', ...$args]);
        $out = trim($process->getOutput() . "\n" . $process->getErrorOutput());
        // ufw prints ERROR: and exits 1, but some refusals ("Invalid syntax") exit 0.
        if (!$process->isSuccessful() || preg_match('/^(ERROR|Invalid|Bad)/mi', $out) === 1) {
            throw new FirewallException(preg_replace('/^ERROR:\s*/m', '', $out) ?: 'ufw ' . implode(' ', $args) . ' failed');
        }

        return $out;
    }
}
