<?php

namespace App\System\Firewall\Ufw;

use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallClash;
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
 * scripts/firewall/ufw.sh sends them through ufw's route rules from
 * DOCKER-USER, which are the rules here with the scope `published`.
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
    /**
     * ufw rewrites user.rules whole on every call, so two writers at once lose
     * or resurrect each other's rules. Every ufw write on the host takes this
     * lock: the engine's, scripts/firewall/ufw.sh's and fail2ban's ban action.
     */
    public const LOCK = '/opt/panelalpha/shared-hosting/data/ufw.lock';
    private const LOCK_WAIT = 30;

    /** @var resource|null the lock, while a write holds it */
    private $held = null;
    /** @var list<callable(): void> */
    private array $afterUnlock = [];

    public function __construct(
        private ProcessRunner $system,
        private string $lockFile = self::LOCK,
        private int $lockWait = self::LOCK_WAIT,
    ) {
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
        // Checked under the lock, so no other writer (a fail2ban ban) adds the clashing rule in between.
        return $this->locked(function () use ($rule): FirewallRule {
            self::refuseClash($rule, $this->rules());

            return $this->add($rule);
        });
    }

    /**
     * ufw would write over a rule that differs from this one only in its
     * action or comment, or skip this one for it: a managed rule would lose
     * its prefix, a deny would turn into an allow, or half a rule would go in.
     *
     * @param list<FirewallRule> $rules
     */
    private static function refuseClash(FirewallRule $rule, array $rules): void
    {
        $clash = UfwRules::clash($rule, $rules);
        if ($clash === null) {
            self::refusePair($rule, $rules);

            return;
        }

        throw new FirewallClash(sprintf(
            'Rule %s (%s) already matches this traffic and is left as it is; %s.',
            $clash->id(),
            self::describe($clash),
            $clash->managed() ? 'the engine opened it and needs it' : 'change or delete that rule instead',
        ));
    }

    /**
     * The rule would be listed as one with a rule already there, under an id
     * neither has: the API would answer that it does not exist.
     *
     * @param list<FirewallRule> $rules
     */
    private static function refusePair(FirewallRule $rule, array $rules): void
    {
        $pair = UfwRules::pairsWith($rule, $rules);
        if ($pair === null) {
            return;
        }

        throw new FirewallClash(sprintf(
            'Rule %s (%s) and this one would be listed as a single rule %s: ufw keeps nothing that tells the two apart. It is left as it is; change that rule instead.',
            $pair->id(),
            self::describe($pair),
            in_array(FirewallRule::PUBLISHED, [$pair->scope, $rule->scope], true) ? 'on host and published ports' : 'both ways',
        ));
    }

    private static function describe(FirewallRule $rule): string
    {
        return $rule->raw ?? implode(' ', array_filter([
            $rule->action, $rule->direction, $rule->protocol, $rule->port,
            $rule->source === null ? null : 'from ' . $rule->source,
            $rule->destination === null ? null : 'to ' . $rule->destination,
            match ($rule->scope) {
                FirewallRule::PUBLISHED => 'on published ports',
                FirewallRule::BOTH => 'on host and published ports',
                default => null,
            },
        ]));
    }

    private function add(FirewallRule $rule): FirewallRule
    {
        // ufw takes the first rule that matches: a deny goes above the allows.
        $position = $rule->action === FirewallRule::DENY ? ['prepend'] : [];
        $halves = UfwRules::specs($rule, withComment: false);
        foreach (UfwRules::specs($rule) as $i => $spec) {
            try {
                $this->ufw(self::withVerb($position, $spec));
            } catch (FirewallException $e) {
                // Half of a two-way rule is not the rule that was asked for.
                for ($j = 0; $j < $i; $j++) {
                    $this->system->runProcessOnHost(['ufw', ...self::withVerb(['delete'], $halves[$j])]);
                }
                throw $e;
            }
        }

        return $this->rule($rule->id());
    }

    public function updateRule(string $id, FirewallRule $rule): FirewallRule
    {
        // The old rule is read under the lock: read before it, another edit of the
        // rule could land first, and this one would act on and put back a stale copy.
        [$old, $new, $seen] = $this->locked(function () use ($id, $rule): array {
            $old = $this->rule($id);
            $others = $this->others($id);
            $sameMatch = $old->sameMatch($rule);
            if (!$sameMatch && UfwRules::clash($rule, [$old]) === null && UfwRules::pairsWith($rule, [$old]) === null) {
                self::refuseClash($rule, $others);
                $new = $this->add($rule);
                $this->delete($old);

                return [$old, $new, null];
            }

            // ufw cannot hold the new rule beside the old one (a new comment, a shared
            // ufw rule, or one the two would be listed as), so the old one goes first.
            // A new comment shares no ufw rule with another, but can pair it with one.
            if ($sameMatch) {
                self::refusePair($rule, $others);
            } else {
                self::refuseClash($rule, $others);
            }
            $this->delete($old);
            // A ban's new rule goes in under a lock of its own: fail2ban's unban, run
            // once this one is released, would take it too.
            if (self::isBan($old)) {
                return [$old, null, $others];
            }

            return [$old, $this->replace($old, $rule), null];
        });

        return $new ?? $this->locked(fn (): FirewallRule => $this->replace($old, $rule, $seen));
    }

    /**
     * Adds $rule in place of $old, which is out already, or puts $old back when it cannot go in.
     *
     * @param list<FirewallRule>|null $seen the other rules when $old went out, if fail2ban has had its turn since
     */
    private function replace(FirewallRule $old, FirewallRule $rule, ?array $seen = null): FirewallRule
    {
        try {
            if ($seen !== null) {
                $this->refuseNewBan($rule, $seen);
            }

            return $this->add($rule);
        } catch (FirewallException $e) {
            try {
                $this->add($old);
            } catch (FirewallNotFound) {
                // fail2ban's new ban holds part of the old rule's place, so the old one cannot read back whole.
            }
            throw $e;
        }
    }

    /**
     * fail2ban may have banned the address again between an edit's two locks,
     * also when the edit keeps what the ban matched. The rules that came in
     * since are checked against, a ban with the old rule's id included. One
     * made only of ufw rules $rule has too, action and comment alike, is no
     * clash: ufw skips those and writes the rest. fail2ban usually bans again
     * with the comment it banned with before.
     *
     * @param list<FirewallRule> $seen
     */
    private function refuseNewBan(FirewallRule $rule, array $seen): void
    {
        $known = array_map(static fn (FirewallRule $r): array => $r->toArray(), $seen);
        $rules = $this->rules();
        $parts = UfwRules::partsOf($rule, $rules);
        self::refuseClash($rule, array_values(array_filter(
            $rules,
            static fn (FirewallRule $r): bool => !in_array($r->toArray(), $known, true) && !in_array($r, $parts, true),
        )));
    }

    /** @return list<FirewallRule> every rule but $id */
    private function others(string $id): array
    {
        return array_values(array_filter($this->rules(), static fn (FirewallRule $r): bool => $r->id() !== $id));
    }

    public function deleteRule(string $id): FirewallRule
    {
        return $this->locked(fn (): FirewallRule => $this->delete($this->rule($id)));
    }

    private function delete(FirewallRule $rule): FirewallRule
    {
        foreach (UfwRules::specs($rule, withComment: false) as $spec) {
            $this->ufw(self::withVerb(['delete'], $spec));
        }
        // A fail2ban ban: lift it in fail2ban too, or its ban database writes
        // the rule back the next time fail2ban starts. Only once the lock is
        // released: fail2ban's unban action waits for it.
        if (self::isBan($rule)) {
            $source = (string) $rule->source;
            $this->afterUnlock[] = fn () => $this->system->runProcessOnHost(['fail2ban-client', 'unban', $source]);
        }

        return $rule;
    }

    public function enable(): void
    {
        $this->locked(fn () => $this->ufw(['--force', 'enable']));
    }

    public function disable(): void
    {
        $this->locked(fn () => $this->ufw(['disable']));
    }

    public function reload(): void
    {
        $this->locked(fn () => $this->ufw(['reload']));
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
        // The jails are reloaded, not restarted, so their bans stay. fail2ban
        // lifts every ban inside a range. Not banned is not an error: the
        // answer is only "was it?".
        $this->system->runProcessOnHost(['fail2ban-client', 'unban', $address->address]);

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

    private static function isBan(FirewallRule $rule): bool
    {
        // fail2ban writes only denies. An allow made from a ban keeps its comment; handled as a
        // ban, its edit could put it back over a new ban, and fail2ban would be told to lift that one.
        return $rule->action === FirewallRule::DENY && $rule->source !== null && str_starts_with((string) $rule->comment, 'by Fail2Ban');
    }

    /**
     * Runs $write holding the host-wide ufw lock, for its whole read-modify-write.
     *
     * @template T
     * @param callable(): T $write
     * @return T
     */
    private function locked(callable $write): mixed
    {
        if ($this->held !== null) {
            return $write();
        }
        $this->held = $this->acquireLock();
        try {
            return $write();
        } finally {
            flock($this->held, LOCK_UN);
            fclose($this->held);
            $this->held = null;
            $after = $this->afterUnlock;
            $this->afterUnlock = [];
            foreach ($after as $task) {
                $task();
            }
        }
    }

    /** @return resource */
    private function acquireLock()
    {
        $lock = @fopen($this->lockFile, 'r');
        if ($lock === false) {
            // scripts/firewall/ufw.sh makes it; core may get here first, or find it unreadable to www-data.
            $this->system->runProcessOnHost(['sh', '-c', 'mkdir -p "$(dirname "$0")" && touch "$0" && chmod 0644 "$0"', $this->lockFile]);
            $lock = @fopen($this->lockFile, 'r');
        }
        if ($lock === false) {
            throw new FirewallException('Could not open the firewall lock ' . $this->lockFile);
        }
        // Polled: PHP's flock() cannot time out on its own.
        $deadline = microtime(true) + $this->lockWait;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);
                throw new FirewallException(sprintf('Another firewall change held %s for over %d s; nothing was changed', $this->lockFile, $this->lockWait));
            }
            usleep(10000);
        }

        return $lock;
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

    /**
     * `ufw <verb> allow ...`, or `ufw route <verb> allow ...` for a route rule.
     *
     * @param list<string> $verb
     * @param list<string> $spec
     * @return list<string>
     */
    private static function withVerb(array $verb, array $spec): array
    {
        return ($spec[0] ?? null) === 'route' ? ['route', ...$verb, ...array_slice($spec, 1)] : [...$verb, ...$spec];
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
