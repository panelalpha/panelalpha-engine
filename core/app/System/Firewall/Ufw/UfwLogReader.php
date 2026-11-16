<?php

namespace App\System\Firewall\Ufw;

use App\System\Firewall\FirewallLogEntry;
use App\System\Firewall\FirewallRule;
use App\System\Privileged;
use App\System\ProcessRunner;

/** What ufw blocked and fail2ban banned or released, read from the host's logs, newest first. */
final class UfwLogReader
{
    /** @param  int  $scan  how far back each source is read, in lines */
    public function __construct(private ProcessRunner $system, private int $scan) {}

    /** @return list<FirewallLogEntry> */
    public function read(int $limit, ?string $type, ?string $address): array
    {
        $entries = [];
        if ($type === null || $type === FirewallLogEntry::BLOCKED) {
            $entries = UfwLogs::parseKernel($this->kernelLog());
        }
        if ($type !== FirewallLogEntry::BLOCKED) {
            $entries = [...$entries, ...$this->banLog()];
        }

        $address = $address === null ? null : FirewallRule::normalizeAddress($address);
        $entries = array_filter($entries, static fn (FirewallLogEntry $e): bool => ($type === null || $e->type === $type)
            && ($address === null || $e->address === $address));
        usort($entries, static fn (FirewallLogEntry $a, FirewallLogEntry $b): int => $b->time <=> $a->time);

        return array_slice($entries, 0, $limit);
    }

    /** @return list<FirewallLogEntry> fail2ban's bans and unbans, read in the host's time zone */
    private function banLog(): array
    {
        $offset = trim($this->system->runProcessOnHost(Privileged::firewall()->timezoneOffset())->getOutput());
        $bans = $this->system->runProcessOnHost(Privileged::firewall()->fail2banLog($this->scan));

        return UfwLogs::parseFail2ban($bans->isSuccessful() ? $bans->getOutput() : '', $offset ?: '+00:00');
    }

    /** ufw's blocked packets, from the journal, or /var/log/ufw.log where rsyslog keeps it. */
    private function kernelLog(): string
    {
        $journal = $this->system->runProcessOnHost(Privileged::firewall()->kernelBlockLog($this->scan));
        if ($journal->isSuccessful() && trim($journal->getOutput()) !== '') {
            return $journal->getOutput();
        }
        $file = $this->system->runProcessOnHost(Privileged::firewall()->ufwLog($this->scan));

        return $file->isSuccessful() ? $file->getOutput() : '';
    }
}
