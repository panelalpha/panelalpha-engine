<?php

namespace App\System\Firewall\Ufw;

use App\System\Firewall\ExemptsApiCallers;
use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallBusy;
use App\System\Firewall\FirewallClash;
use App\System\Firewall\FirewallCommandFailed;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\FirewallNotFound;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\FirewallStatus;
use App\System\Firewall\TrustedAddress;
use App\System\Privileged;
use App\System\Privileged\RootFirewall;
use App\System\ProcessRunner;
use Symfony\Component\Process\Process;

/**
 * ufw on the engine host, set up by scripts/firewall/ufw.sh; it reloads only its own chains, so Docker's survive.
 * Ports Docker publishes reach ufw's route rules (scope `published`) through the host hook in DOCKER-USER.
 */
class UfwFirewall implements ExemptsApiCallers, Firewall
{
    private const RULES = RootFirewall::RULES;

    /** The rule files as they were before a write, kept there while it holds {@see self::LOCK}. */
    public const SNAPSHOT = RootFirewall::SNAPSHOT;

    /** How far back the logs are read: lines, per source. */
    private const SCAN = 5000;

    /** fail2ban's ignore list, which scripts/firewall/ufw.sh turns into jail config. */
    private const TRUSTED = RootFirewall::TRUSTED_LISTS[0];

    /** The same, read by the engine-api jail alone: where PanelAlpha Connect calls from. */
    private const API_TRUSTED = RootFirewall::TRUSTED_LISTS[1];

    /**
     * ufw rewrites user.rules whole, so two writers at once lose or resurrect each other's rules.
     * Every ufw write on the host takes this lock: the engine's, scripts/firewall/ufw.sh's and fail2ban's ban action.
     */
    public const LOCK = RootFirewall::LOCKS[0];

    /**
     * Serialises read-modify-writes of the trusted list, here and in ufw.sh's CSF import.
     * Not {@see self::LOCK}: saving the list reloads fail2ban, whose ban action waits for that one.
     */
    public const TRUSTED_LOCK = RootFirewall::LOCKS[1];

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
        $version = $this->system->runProcessOnHost(Privileged::firewall()->version());
        if (! $version->isSuccessful()) {
            return new FirewallStatus($this->name(), null, error: trim($version->getErrorOutput() ?: $version->getOutput()) ?: 'ufw is not installed');
        }
        $status = $this->system->runProcessOnHost(Privileged::firewall()->status());
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
        $check = $this->system->runProcessOnHost(Privileged::firewall()->refusedRules());
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
        $v4 = $this->system->runProcessOnHost(Privileged::firewall()->rules());
        if (! $v4->isSuccessful()) {
            throw new FirewallCommandFailed('Could not read '.self::RULES.': '.trim($v4->getErrorOutput()));
        }
        $v6 = $this->system->runProcessOnHost(Privileged::firewall()->rules6());

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
     * ufw would write over a rule differing from this one only in action or comment, or skip this one for it:
     * a managed rule would lose its prefix, a deny would turn into an allow, or half a rule would go in.
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
            $this->system->runProcessOnHost(Privileged::firewall()->ufw(self::withVerb(['delete'], $half)));
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
     * ufw cannot hold the new rule beside the old one, so the old goes first; one with the old match can only pair.
     * A ban's new rule waits for a lock of its own: fail2ban's unban, run once this one is released, would take it too.
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
     * Rules that came in between an edit's two locks are checked by toArray(), not id: fail2ban's new ban, usually
     * with its old comment, can carry the old rule's id. One made only of ufw rules $rule has too is no clash.
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
        $this->afterUnlock[] = fn () => $this->system->runProcessOnHost(Privileged::firewall()->unban($source));
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
        return (new UfwLogReader($this->system, self::SCAN))->read($limit, $type, $address);
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
        $file = $this->system->runProcessOnHost(Privileged::firewall()->readTrustedList($path));

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
            $this->system->runProcessOnHost(Privileged::firewall()->unban($address->address));

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
        $write = $this->system->runProcessOnHost(Privileged::firewall()->writeTrustedList($path, TrustedAddress::formatList($list)));
        if (! $write->isSuccessful()) {
            throw new FirewallCommandFailed('Could not write '.$path.': '.trim($write->getErrorOutput()));
        }
        $apply = $this->system->runProcessOnHost(Privileged::firewall()->applyTrustedLists());
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
            $this->system->runProcessOnHost(Privileged::firewall()->ensureLockFile($file));
            $lock = @fopen($file, 'r');
        }
        if ($lock === false) {
            throw new FirewallCommandFailed('Could not open the firewall lock '.$file);
        }

        return $lock;
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
        $copy = $this->system->runProcessOnHost(Privileged::firewall()->snapshotRules());
        if (! $copy->isSuccessful()) {
            throw new FirewallCommandFailed('Could not copy ufw\'s rules aside before changing them, so nothing was changed: '.trim($copy->getErrorOutput() ?: $copy->getOutput()));
        }

        return trim($copy->getOutput(), " \t\n\r\"'") === 'yes';
    }

    /**
     * After a failed write, the old rule files loaded again, or ufw turned off: empty user chains lock the host out.
     * A ufw that was off is stopped again, which clears the chains a failed start left.
     *
     * @return string|null what the host was left with, or null when it is as it was
     */
    private function rollBack(): ?string
    {
        $put = $this->system->runProcessOnHost(Privileged::firewall()->restoreRules());
        if (! $put->isSuccessful()) {
            return $this->turnOffAfterRollBack('could not put the rule files back: '.trim($put->getErrorOutput() ?: $put->getOutput()));
        }
        if (! $this->snapshot) {
            $this->system->runProcessOnHost(Privileged::firewall()->ufw(['disable']));

            return null;
        }

        $reload = $this->system->runProcessOnHost(Privileged::firewall()->ufw(['reload']));
        $why = trim($reload->getOutput()."\n".$reload->getErrorOutput());
        if ($reload->isSuccessful() && preg_match('/^ERROR/mi', $why) !== 1) {
            return null;
        }

        return $this->turnOffAfterRollBack($why);
    }

    private function turnOffAfterRollBack(string $why): string
    {
        $this->system->runProcessOnHost(Privileged::firewall()->ufw(['disable']));

        return 'ufw could not load its rules again, so it was turned off to keep the host reachable: '.$why
            .'. Fix the rules in '.self::RULES.' and enable the firewall again.';
    }

    /**
     * Run ufw, copying the rule files aside first when guarded. Some refusals exit 0: iptables refusing
     * a stored rule prints its error and then "Rule added", as ufw's "Invalid syntax" style refusals do.
     *
     * @param  list<string>  $args
     */
    private function ufw(array $args, bool $rule = false, bool $guarded = true): string
    {
        if ($guarded) {
            $this->snapshot ??= $this->snapshot();
        }
        $process = $this->system->runProcessOnHost(Privileged::firewall()->ufw($args));
        $out = trim($process->getOutput()."\n".$process->getErrorOutput());
        preg_match_all('/^ip6?tables.*$/mi', $out, $refusals);
        if ($process->isSuccessful() && $refusals[0] === [] && preg_match('/^(ERROR|Invalid|Bad)/mi', $out) !== 1) {
            return $out;
        }

        $this->fail($process, $args, $out, $refusals[0], $rule, $guarded);
    }

    /**
     * ufw refusing the rule itself (a bad port, mixed IP versions) is the request's fault (422) and wrote nothing. Failing
     * on the host (502) first rolls back what ufw may have written: what is live may differ from the files, or be nothing.
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
     * ufw's error, without its "ERROR:" prefixes. Of a usage page or traceback only what comes before is kept:
     * the ERROR line and, for ufw-init, the line that says why.
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
