<?php

namespace App\System\Firewall\Ufw;

use App\System\Firewall\ExemptsApiCallers;
use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallBusy;
use App\System\Firewall\FirewallClash;
use App\System\Firewall\FirewallCommandFailed;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\FirewallLogEntry;
use App\System\Firewall\FirewallNotFound;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\FirewallStatus;
use App\System\Firewall\TrustedAddress;
use App\System\ProcessRunner;
use Symfony\Component\Process\Process;

/**
 * ufw on the engine host, set up by scripts/firewall/ufw.sh.
 *
 * ufw reloads only its own chains, so Docker's rules and the engine's tenant
 * and build chains survive every change made here. ufw does not see ports
 * Docker publishes (2011, FTP, SFTP) on its own; the host hook in
 * scripts/firewall/ufw.sh sends them through ufw's route rules from
 * DOCKER-USER, which are the rules here with the scope `published`.
 */
class UfwFirewall implements ExemptsApiCallers, Firewall
{
    private const RULES = '/etc/ufw/user.rules';

    private const RULES6 = '/etc/ufw/user6.rules';

    private const CONF = '/etc/ufw/ufw.conf';

    /** The rule files as they were before a write, kept there while it holds {@see self::LOCK}. */
    public const SNAPSHOT = '/opt/panelalpha/shared-hosting/data/ufw-snapshot';

    /** How far back the logs are read: lines, per source. */
    private const SCAN = 5000;

    /** fail2ban's ignore list, which scripts/firewall/ufw.sh turns into jail config. */
    private const TRUSTED = '/etc/fail2ban/panelalpha-ignoreip';
    /** The same, read by the engine-api jail alone: where PanelAlpha Connect calls from. */
    private const API_TRUSTED = '/etc/fail2ban/panelalpha-api-ignoreip';

    private const SCRIPT = '/opt/panelalpha/shared-hosting/scripts/firewall.sh';

    /**
     * ufw rewrites user.rules whole on every call, so two writers at once lose
     * or resurrect each other's rules. Every ufw write on the host takes this
     * lock: the engine's, scripts/firewall/ufw.sh's and fail2ban's ban action.
     */
    public const LOCK = '/opt/panelalpha/shared-hosting/data/ufw.lock';

    /**
     * Serialises read-modify-writes of the trusted list, here and in ufw.sh's CSF import.
     * Not {@see self::LOCK}: saving the list reloads fail2ban, whose ban action waits for that one.
     */
    public const TRUSTED_LOCK = '/opt/panelalpha/shared-hosting/data/ufw-trusted.lock';

    private const LOCK_WAIT = 30;

    /** ufw output that means the host failed, not that the rule was refused. */
    private const HOST_FAULT = '/problem running|ufw-init|ip6?tables|Permission denied|No such file|Could not (load|update|open|read|write)|^ERROR:\s*$/mi';

    /** @var resource|null the lock, while a write holds it */
    private $held = null;

    /** @var resource|null the trusted-list lock, while a trusted write holds it */
    private $heldTrusted = null;

    /** Whether ufw was on when this hold of the lock first wrote; null before its first write. */
    private ?bool $snapshot = null;

    /** @var list<callable(): void> */
    private array $afterUnlock = [];

    public function __construct(
        private ProcessRunner $system,
        private string $lockFile = self::LOCK,
        private int $lockWait = self::LOCK_WAIT,
        private string $trustedLockFile = self::TRUSTED_LOCK,
    ) {}

    public function name(): string
    {
        return FirewallFactory::UFW;
    }

    public function status(): FirewallStatus
    {
        $version = $this->system->runProcessOnHost(['ufw', 'version']);
        if (! $version->isSuccessful()) {
            return new FirewallStatus($this->name(), null, error: trim($version->getErrorOutput() ?: $version->getOutput()) ?: 'ufw is not installed');
        }
        $status = $this->system->runProcessOnHost(['ufw', 'status', 'verbose']);
        $out = $status->getOutput();
        if (! $status->isSuccessful()) {
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
            refusedRules: $this->refusedRules(),
        );
    }

    /**
     * Stored rules iptables would refuse on ufw's next load. Until each is
     * deleted every reload fails, and a write ends with ufw turned off.
     *
     * @return list<array{id: ?string, rule: string, error: string}>
     */
    private function refusedRules(): array
    {
        $check = $this->system->runProcessOnHost(['bash', self::SCRIPT, '--refused']);
        $lines = $check->isSuccessful() ? preg_grep('/\t/', preg_split('/\R/', $check->getOutput()) ?: []) : [];
        if ($lines === [] || $lines === false) {
            return [];
        }
        $rules = $this->readableRules();

        return array_values(array_map(static fn (string $line): array => self::refusedRule($line, $rules), $lines));
    }

    /**
     * One line of `firewall.sh --refused`: the stored tuple line, or a place in a
     * rule file outside any rule, a tab, and iptables' error.
     *
     * @param  list<FirewallRule>  $rules
     * @return array{id: ?string, rule: string, error: string}
     */
    private static function refusedRule(string $line, array $rules): array
    {
        [$stored, $error] = explode("\t", $line, 2);
        $half = UfwRules::parseTuple($stored);

        return [
            'id' => $half === null ? null : UfwRules::listedAs($half, $rules)?->id(),
            'rule' => $half === null ? $stored : self::describe($half),
            'error' => trim($error),
        ];
    }

    /** @return list<FirewallRule> the rules, or none when they cannot be read: the status still answers */
    private function readableRules(): array
    {
        try {
            return $this->rules();
        } catch (FirewallException) {
            return [];
        }
    }

    /** A missing user6.rules (IPV6=no) is not an error: the IPv4 rules are still read. */
    public function rules(): array
    {
        $v4 = $this->system->runProcessOnHost(['cat', self::RULES]);
        if (! $v4->isSuccessful()) {
            throw new FirewallCommandFailed('Could not read '.self::RULES.': '.trim($v4->getErrorOutput()));
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

    /** Checked under the lock, so no other writer (a fail2ban ban) adds a clashing rule in between. */
    public function addRule(FirewallRule $rule): FirewallRule
    {
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
     * @param  list<FirewallRule>  $rules
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
     * @param  list<FirewallRule>  $rules
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
            $rule->source === null ? null : 'from '.$rule->source,
            $rule->destination === null ? null : 'to '.$rule->destination,
            match ($rule->scope) {
                FirewallRule::PUBLISHED => 'on published ports',
                FirewallRule::BOTH => 'on host and published ports',
                default => null,
            },
        ]));
    }

    /** ufw takes the first rule that matches, so a deny goes above the allows. */
    private function add(FirewallRule $rule): FirewallRule
    {
        $position = $rule->action === FirewallRule::DENY ? ['prepend'] : [];
        $halves = UfwRules::specs($rule, withComment: false);
        foreach (UfwRules::specs($rule) as $i => $spec) {
            try {
                $this->ufw(self::withVerb($position, $spec), rule: true);
            } catch (FirewallException $e) {
                $this->deleteHalves(array_slice($halves, 0, $i));
                throw $e;
            }
        }

        return $this->rule($rule->id());
    }

    /**
     * Take out the halves of a two-way rule that went in before the rest failed:
     * half of it is not the rule that was asked for.
     *
     * @param  list<list<string>>  $halves
     */
    private function deleteHalves(array $halves): void
    {
        foreach ($halves as $half) {
            $this->system->runProcessOnHost(['ufw', ...self::withVerb(['delete'], $half)]);
        }
    }

    /**
     * The old rule is read under the lock, so another edit cannot land between
     * the read and the write. A ban's replacement goes in under a second lock.
     */
    public function updateRule(string $id, FirewallRule $rule): FirewallRule
    {
        [$old, $new, $seen] = $this->locked(fn (): array => $this->updateUnderLock($id, $rule));

        return $new ?? $this->locked(fn (): FirewallRule => $this->replace($old, $rule, $seen));
    }

    /**
     * @return array{0: FirewallRule, 1: ?FirewallRule, 2: ?list<FirewallRule>} the old rule, the new one,
     *                                                                          and the other rules when the new one still waits for a lock of its own
     */
    private function updateUnderLock(string $id, FirewallRule $rule): array
    {
        $old = $this->rule($id);
        $others = $this->others($id);
        if (! self::fitsBeside($old, $rule)) {
            return $this->replaceOldFirst($old, $rule, $others);
        }

        self::refuseClash($rule, $others);
        $new = $this->add($rule);
        $this->delete($old);

        return [$old, $new, null];
    }

    /** Whether ufw can hold $rule next to $old, so it can be added before $old goes. */
    private static function fitsBeside(FirewallRule $old, FirewallRule $rule): bool
    {
        return ! $old->sameMatch($rule)
            && UfwRules::clash($rule, [$old]) === null
            && UfwRules::pairsWith($rule, [$old]) === null;
    }

    /**
     * ufw cannot hold the new rule beside the old one (a new comment, a shared
     * ufw rule, or one the two would be listed as), so the old one goes first. A
     * new comment shares no ufw rule with another, but can pair it with one. A
     * ban's new rule waits for a lock of its own: fail2ban's unban, run once this
     * one is released, would take it too.
     *
     * @param  list<FirewallRule>  $others
     * @return array{0: FirewallRule, 1: ?FirewallRule, 2: ?list<FirewallRule>}
     */
    private function replaceOldFirst(FirewallRule $old, FirewallRule $rule, array $others): array
    {
        if ($old->sameMatch($rule)) {
            self::refusePair($rule, $others);
        } else {
            self::refuseClash($rule, $others);
        }
        $this->delete($old);
        if ($old->isBan()) {
            return [$old, null, $others];
        }

        return [$old, $this->replace($old, $rule), null];
    }

    /**
     * Adds $rule in place of $old, which is out already, or puts $old back when it
     * cannot go in; a new rule that does not read back is a failed edit too.
     *
     * @param  list<FirewallRule>|null  $seen  the other rules when $old went out, if fail2ban has had its turn since
     */
    private function replace(FirewallRule $old, FirewallRule $rule, ?array $seen = null): FirewallRule
    {
        try {
            if ($seen !== null) {
                $this->refuseNewBan($rule, $seen);
            }

            return $this->add($rule);
        } catch (FirewallException|FirewallNotFound $e) {
            $this->putBack($old);
            throw $e;
        }
    }

    /**
     * Re-add a rule an edit took out. It may not read back whole when a new
     * fail2ban ban holds part of its place, which is not a further failure.
     */
    private function putBack(FirewallRule $old): void
    {
        try {
            $this->add($old);
        } catch (FirewallNotFound) {
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
     * @param  list<FirewallRule>  $seen
     */
    private function refuseNewBan(FirewallRule $rule, array $seen): void
    {
        $known = array_map(static fn (FirewallRule $r): array => $r->toArray(), $seen);
        $rules = $this->rules();
        $parts = UfwRules::partsOf($rule, $rules);
        self::refuseClash($rule, array_values(array_filter(
            $rules,
            static fn (FirewallRule $r): bool => ! in_array($r->toArray(), $known, true) && ! in_array($r, $parts, true),
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
            $this->ufw(self::withVerb(['delete'], $spec), rule: true);
        }
        if ($rule->isBan()) {
            $this->unbanAfterUnlock((string) $rule->source);
        }

        return $rule;
    }

    /**
     * Lift a deleted ban in fail2ban too, or its ban database writes the rule
     * back when fail2ban next starts. Deferred: fail2ban's unban action waits for the lock.
     */
    private function unbanAfterUnlock(string $source): void
    {
        $this->afterUnlock[] = fn () => $this->system->runProcessOnHost(['fail2ban-client', 'unban', $source]);
    }

    /**
     * A ufw already on, or one a failed start left loaded, loads nothing on
     * enable and says it worked; the reload loads the rules for real, or fails.
     */
    public function enable(): void
    {
        $this->locked(function (): void {
            $this->ufw(['--force', 'enable']);
            $this->ufw(['reload']);
        });
    }

    public function disable(): void
    {
        $this->locked(fn () => $this->ufw(['disable'], guarded: false));
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
            $entries = [...$entries, ...$this->banLog()];
        }

        $address = $address === null ? null : FirewallRule::normalizeAddress($address);
        $entries = array_filter($entries, static fn (FirewallLogEntry $e): bool => ($type === null || $e->type === $type)
            && ($address === null || $e->address === $address));
        usort($entries, static fn (FirewallLogEntry $a, FirewallLogEntry $b): int => $b->time <=> $a->time);

        return array_slice($entries, 0, $limit);
    }

    public function trustedAddresses(): array
    {
        return $this->readList(self::TRUSTED);
    }

    public function exemptApiCallers(callable $wanted): void
    {
        $this->trustedLocked(function () use ($wanted): void {
            $list = $wanted();
            if (TrustedAddress::formatList($list) !== TrustedAddress::formatList($this->readList(self::API_TRUSTED))) {
                $this->saveTrusted($list, self::API_TRUSTED);
            }
        });
    }

    /** @return list<TrustedAddress> */
    private function readList(string $path): array
    {
        $file = $this->system->runProcessOnHost(['cat', $path]);

        return TrustedAddress::parseList($file->isSuccessful() ? $file->getOutput() : '');
    }

    /**
     * Saving reloads the jails rather than restarting them, so their bans stay:
     * the address (every ban inside a range) is unbanned, and "not banned" is no error.
     */
    public function trust(TrustedAddress $address): TrustedAddress
    {
        return $this->trustedLocked(function () use ($address): TrustedAddress {
            $list = [];
            foreach ($this->trustedAddresses() as $entry) {
                $list[$entry->id()] = $entry;
            }
            $list[$address->id()] = $address;
            $this->saveTrusted(array_values($list));
            $this->system->runProcessOnHost(['fail2ban-client', 'unban', $address->address]);

            return $address;
        });
    }

    public function untrust(string $id): TrustedAddress
    {
        return $this->trustedLocked(function () use ($id): TrustedAddress {
            $list = $this->trustedAddresses();
            foreach ($list as $i => $entry) {
                if ($entry->id() === $id) {
                    unset($list[$i]);
                    $this->saveTrusted(array_values($list));

                    return $entry;
                }
            }

            throw FirewallNotFound::trustedAddress($id);
        });
    }

    /**
     * The list file, then fail2ban's jails rewritten from it and reloaded by
     * the same host script the installer runs.
     *
     * @param  list<TrustedAddress>  $list
     */
    private function saveTrusted(array $list, string $path = self::TRUSTED): void
    {
        $write = $this->system->runProcessOnHost(['sh', '-c', 'mkdir -p "$(dirname "$0")" && printf "%s" "$1" > "$0"', $path, TrustedAddress::formatList($list)]);
        if (! $write->isSuccessful()) {
            throw new FirewallCommandFailed('Could not write '.$path.': '.trim($write->getErrorOutput()));
        }
        $apply = $this->system->runProcessOnHost(['bash', self::SCRIPT, '--fail2ban']);
        if (! $apply->isSuccessful()) {
            throw new FirewallCommandFailed(trim($apply->getErrorOutput() ?: $apply->getOutput()) ?: 'fail2ban did not take the new list');
        }
    }

    /**
     * Runs $write holding the host-wide ufw lock, for its whole read-modify-write.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function locked(callable $write): mixed
    {
        if ($this->held !== null) {
            return $write();
        }
        $this->held = $this->acquireLock($this->lockFile);
        try {
            return $write();
        } finally {
            flock($this->held, LOCK_UN);
            fclose($this->held);
            $this->held = null;
            $this->snapshot = null;
            $this->runAfterUnlock();
        }
    }

    private function runAfterUnlock(): void
    {
        $after = $this->afterUnlock;
        $this->afterUnlock = [];
        foreach ($after as $task) {
            $task();
        }
    }

    /**
     * Runs $write holding the trusted list's lock, for its whole read-modify-write.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function trustedLocked(callable $write): mixed
    {
        if ($this->heldTrusted !== null) {
            return $write();
        }
        $this->heldTrusted = $this->acquireLock($this->trustedLockFile);
        try {
            return $write();
        } finally {
            flock($this->heldTrusted, LOCK_UN);
            fclose($this->heldTrusted);
            $this->heldTrusted = null;
        }
    }

    /**
     * The lock file, opened and held exclusively; polled because PHP's flock()
     * cannot time out on its own.
     *
     * @return resource
     */
    private function acquireLock(string $file)
    {
        $lock = $this->openLockFile($file);
        $deadline = microtime(true) + $this->lockWait;
        while (! flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);
                throw new FirewallBusy(sprintf('Another firewall change held %s for over %d s; nothing was changed', $file, $this->lockWait));
            }
            usleep(10000);
        }

        return $lock;
    }

    /** @return list<FirewallLogEntry> fail2ban's bans and unbans, read in the host's time zone */
    private function banLog(): array
    {
        $offset = trim($this->system->runProcessOnHost(['date', '+%:z'])->getOutput());
        $bans = $this->system->runProcessOnHost(['tail', '-n', (string) self::SCAN, '/var/log/fail2ban.log']);

        return UfwLogs::parseFail2ban($bans->isSuccessful() ? $bans->getOutput() : '', $offset ?: '+00:00');
    }

    /**
     * scripts/firewall/ufw.sh makes the lock file, but core may get here first
     * or find it unreadable to www-data, so it is created on the host once.
     *
     * @return resource
     */
    private function openLockFile(string $file)
    {
        $lock = @fopen($file, 'r');
        if ($lock === false) {
            $this->system->runProcessOnHost(['sh', '-c', 'mkdir -p "$(dirname "$0")" && touch "$0" && chmod 0644 "$0"', $file]);
            $lock = @fopen($file, 'r');
        }
        if ($lock === false) {
            throw new FirewallCommandFailed('Could not open the firewall lock '.$file);
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
     * @param  list<string>  $verb
     * @param  list<string>  $spec
     * @return list<string>
     */
    private static function withVerb(array $verb, array $spec): array
    {
        return ($spec[0] ?? null) === 'route' ? ['route', ...$verb, ...array_slice($spec, 1)] : [...$verb, ...$spec];
    }

    /**
     * Copies the rule files and ufw.conf aside before the first write under this
     * hold of the lock, so a failed one can be undone; says whether ufw was on.
     */
    private function snapshot(): bool
    {
        $copy = $this->system->runProcessOnHost(['sh', '-c', 'rm -rf "$0" && mkdir -p "$0" && for f; do [ ! -e "$f" ] || cp -p "$f" "$0/" || exit 1; done && sed -n "s/^ENABLED=//p" "$0/ufw.conf"', self::SNAPSHOT, self::RULES, self::RULES6, self::CONF]);
        if (! $copy->isSuccessful()) {
            throw new FirewallCommandFailed('Could not copy ufw\'s rules aside before changing them, so nothing was changed: '.trim($copy->getErrorOutput() ?: $copy->getOutput()));
        }

        return trim($copy->getOutput(), " \t\n\r\"'") === 'yes';
    }

    /**
     * After a failed write: the rule files as they were, loaded again. A failed
     * load leaves INPUT dropping everything with ufw's user chains empty, which
     * locks the host out, so if they do not load ufw is turned off instead. A
     * ufw that was off is stopped again, which clears the chains a failed start left.
     *
     * @return string|null what the host was left with, or null when it is as it was
     */
    private function rollBack(): ?string
    {
        $put = $this->system->runProcessOnHost(['sh', '-c', 'for f; do b="$0/${f##*/}"; [ ! -e "$b" ] || cp -p "$b" "$f" || exit 1; done', self::SNAPSHOT, self::RULES, self::RULES6, self::CONF]);
        if (! $put->isSuccessful()) {
            return $this->turnOffAfterRollBack('could not put the rule files back: '.trim($put->getErrorOutput() ?: $put->getOutput()));
        }
        if (! $this->snapshot) {
            $this->system->runProcessOnHost(['ufw', 'disable']);

            return null;
        }

        $reload = $this->system->runProcessOnHost(['ufw', 'reload']);
        $why = trim($reload->getOutput()."\n".$reload->getErrorOutput());
        if ($reload->isSuccessful() && preg_match('/^ERROR/mi', $why) !== 1) {
            return null;
        }

        return $this->turnOffAfterRollBack($why);
    }

    private function turnOffAfterRollBack(string $why): string
    {
        $this->system->runProcessOnHost(['ufw', 'disable']);

        return 'ufw could not load its rules again, so it was turned off to keep the host reachable: '.$why
            .'. Fix the rules in '.self::RULES.' and enable the firewall again.';
    }

    /**
     * Run ufw, copying the rule files aside first when guarded. Some refusals
     * exit 0: iptables refusing a stored rule prints its error, then "Rule
     * added", and ufw's "Invalid syntax" style refusals do too.
     *
     * @param  list<string>  $args
     */
    private function ufw(array $args, bool $rule = false, bool $guarded = true): string
    {
        if ($guarded) {
            $this->snapshot ??= $this->snapshot();
        }
        $process = $this->system->runProcessOnHost(['ufw', ...$args]);
        $out = trim($process->getOutput()."\n".$process->getErrorOutput());
        preg_match_all('/^ip6?tables.*$/mi', $out, $refusals);
        if ($process->isSuccessful() && $refusals[0] === [] && preg_match('/^(ERROR|Invalid|Bad)/mi', $out) !== 1) {
            return $out;
        }

        $this->fail($process, $args, $out, $refusals[0], $rule, $guarded);
    }

    /**
     * ufw refusing the rule itself (a bad port, mixed IP versions) is the
     * request's fault (422) and wrote nothing. Failing to apply it on the host
     * (502) first rolls back what ufw may have written, since what is live may
     * differ from the files, or be nothing.
     *
     * @param  list<string>  $args
     * @param  list<string>  $refusals  iptables lines in the output
     */
    private function fail(Process $process, array $args, string $out, array $refusals, bool $rule, bool $guarded): never
    {
        $message = self::errorText($process) ?: 'ufw '.implode(' ', $args).' failed';
        if ($rule && preg_match(self::HOST_FAULT, $out) !== 1) {
            throw new FirewallException($message);
        }
        if (! $guarded) {
            throw new FirewallCommandFailed($message);
        }
        $left = $this->rollBack();
        if ($left === null && $rule && $refusals !== [] && $process->isSuccessful() && preg_match('/^ERROR/mi', $out) !== 1) {
            throw new FirewallException(implode("\n", array_unique($refusals)));
        }
        throw new FirewallCommandFailed($left === null ? $message : $message."\n".$left);
    }

    /**
     * ufw's error, without its "ERROR:" prefixes. A rule it cannot parse also
     * prints the usage page or a traceback; only what comes before is kept,
     * which holds the ERROR line and, for ufw-init, the line that says why.
     */
    private static function errorText(Process $process): string
    {
        $kept = trim(self::beforeUsage($process->getOutput())."\n".self::beforeUsage($process->getErrorOutput()));

        return trim(preg_replace('/^ERROR:[ \t]*/m', '', $kept) ?? $kept);
    }

    private static function beforeUsage(string $output): string
    {
        return trim(preg_split('/^(?:Usage: ufw|Traceback \(most recent call last\))/m', $output, 2)[0] ?? '');
    }
}
