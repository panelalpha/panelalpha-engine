<?php

namespace App\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Models\User;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Settles what an interrupted redeploy left: traffic back on the app's port,
 * the second copy removed, the checkout restored or removed. Under the deploy lock.
 */
final class GenerationSweep
{
    /** {@see decide()}: the previous version's containers still run; everything goes back to them. */
    public const PREVIOUS = 'previous';

    /** {@see decide()}: they were replaced; the previous version is started again from what was kept. */
    public const RESTORE = 'restore';

    /** {@see decide()}: the new version stays. */
    public const NEW = 'new';

    /** A new version that passed its health check answers at once, or not at all. Tests shorten it. */
    public static int $newAnswerSeconds = 20;

    /** How long the port traffic would go back to gets, before a second copy that serves is kept. */
    public static int $backAnswerSeconds = 10;

    /**
     * @return list<string> one line per account settled
     */
    public static function run(): array
    {
        $settled = [];
        foreach (GenerationState::usernames() as $username) {
            $user = User::query()->where('username', $username)->first();
            $runtime = $user?->project()->runtime();
            if (! $runtime instanceof DindProject) {
                @unlink(GenerationState::path($username));

                continue;
            }
            $done = self::settleInterrupted($runtime);
            if ($done !== null && $done !== []) {
                $settled[] = $username.': '.implode(', ', $done);
            }
        }

        return $settled;
    }

    /**
     * Null when a deploy holds the account's lock. $succeeded is the verdict
     * of the deploy that just ended, null when it is not known.
     *
     * @return ?list<string>
     */
    public static function settleIfIdle(DindProject $project, ?bool $succeeded = null): ?array
    {
        $lock = self::acquireLock($project);
        if ($lock === null) {
            return null;
        }

        try {
            if (self::checkoutOwnedElsewhere($project)) {
                return null;
            }
            $done = self::settleHeld($project, $succeeded);
        } finally {
            $lock->release();
        }
        self::logSettled($project, $done);

        return $done;
    }

    /**
     * A redeploy whose process is gone: its log is closed first, failed as interrupted unless it wrote its
     * verdict before it died. Then the version that serves is chosen from what it recorded ({@see decide()}),
     * and its log says which one and why.
     *
     * @return ?list<string>
     */
    public static function settleInterrupted(DindProject $project): ?array
    {
        DeployLogger::settleOrphaned($project->username());
        $lock = self::acquireLock($project);
        if ($lock === null) {
            return null;
        }

        try {
            if (self::checkoutOwnedElsewhere($project)) {
                return null;
            }
            $snapshot = new RoutingSnapshot($project);
            $entry = $snapshot->entry();
            $settled = self::settleRecorded($project, $entry, $snapshot->verdict());
        } finally {
            $lock->release();
        }
        if ($settled === null) {
            return null;
        }
        [$done, $line] = $settled;
        self::logSettled($project, $done);
        if ($line !== null && is_string($entry['deploy'] ?? null)) {
            self::warnInDeployLog($project, $entry['deploy'], $line);
        }

        return $done;
    }

    private static function acquireLock(DindProject $project): ?DeployLock
    {
        $lock = new DeployLock(new DeployLogPaths($project->username()));
        try {
            $lock->acquire();
        } catch (\Throwable) {
            return null;
        }

        return $lock;
    }

    /**
     * What was settled and the line the deploy's log gets, if any; null when the account could not be asked.
     *
     * @param  ?array<string, mixed>  $entry  {@see RoutingSnapshot::entry()}
     * @return ?array{0: list<string>, 1: ?string}
     */
    private static function settleRecorded(DindProject $project, ?array $entry, ?bool $verdict): ?array
    {
        if ($entry === null || $verdict === true) {
            return [self::settleHeld($project, $verdict), null];
        }

        return self::recover($project, $entry);
    }

    private static function warnInDeployLog(DindProject $project, string $deploy, string $line): void
    {
        try {
            DeployLogger::forDeploy($project->username(), $deploy)->warn($line);
        } catch (\Throwable) {
        }
    }

    /**
     * Which version serves after a redeploy died. One whose containers still
     * run is the previous one, as the deploy had not replaced it. Once they
     * are gone, a new version that passed its health check and still answers
     * stays; otherwise the previous one is started again while what it needs
     * is kept, and only when it is not does the new one stay.
     */
    public static function decide(bool $previousRunning, bool $gated, bool $restorable, ?bool $newAnswers): string
    {
        if ($previousRunning) {
            return self::PREVIOUS;
        }
        if ($gated && $newAnswers === true) {
            return self::NEW;
        }

        return $restorable ? self::RESTORE : self::NEW;
    }

    /**
     * The caller holds the deploy lock. $forward settles traffic on the new version's port instead, $to on
     * that port. A copy that serves the site while that port does not answer stays, with the port noted
     * for the next call: the site never moves to nothing. False when it stays or could not be settled.
     */
    public static function settleNext(DindProject $project, bool $forward = false, ?int $to = null, ?int $wait = null): bool
    {
        $state = new GenerationState($project->username());
        $next = $state->get(GenerationState::NEXT);
        if ($next === null) {
            return false;
        }

        try {
            if (! self::moveTrafficOffTheCopy($project, $state, $next, self::backPort($next, $forward, $to), $wait)) {
                return false;
            }
            self::discardCopy($project, $next);
        } catch (\Throwable $e) {
            Log::warning("Could not settle the second generation of {$project->username()}: ".$e->getMessage());

            return false;
        }
        $state->forget(GenerationState::NEXT);

        return true;
    }

    /**
     * The port all of the copy's traffic goes to: $to, else the one noted, else on $forward the
     * routed port. Null to send each of the copy's ports back where it came from.
     *
     * @param  array<string, mixed>  $next  the NEXT entry
     */
    private static function backPort(array $next, bool $forward, ?int $to): ?int
    {
        $routed = $next['routed'] ?? null;

        return $to ?? (isset($next['back']) ? (int) $next['back'] : ($forward && $routed !== null ? (int) $routed : null));
    }

    /**
     * False when the copy stays, as it serves while the port its traffic would go to does not answer;
     * that port is then noted for the next call.
     *
     * @param  array<string, mixed>  $next  the NEXT entry
     */
    private static function moveTrafficOffTheCopy(DindProject $project, GenerationState $state, array $next, ?int $back, ?int $wait): bool
    {
        $rules = array_values(array_map('intval', (array) ($next['rules'] ?? [])));
        $map = self::trafficMap($next, $back);
        if ($rules === [] || $map === []) {
            return true;
        }
        $silent = self::silentWhileTheCopyServes($project, $map, $wait ?? self::$backAnswerSeconds);
        if ($silent !== null) {
            if ($back !== null && ($next['back'] ?? null) !== $back) {
                $state->put(GenerationState::NEXT, ['back' => $back] + $next);
            }
            Log::info("Kept the second generation of {$project->username()}: it serves the site, and port {$silent} does not answer");

            return false;
        }
        (new RouteSwitch($project->system(), $project->username()))->move($map, $rules);

        return true;
    }

    /**
     * @param  array<string, mixed>  $next  the NEXT entry
     * @return array<int, int> the copy's port => where its traffic goes
     */
    private static function trafficMap(array $next, ?int $back): array
    {
        $routes = [];
        foreach ((array) ($next['routes'] ?? []) as $from => $copy) {
            $routes[(int) $from] = (int) $copy;
        }
        $map = ZeroDowntimeRedeploy::inverse($routes);

        return $back === null ? $map : array_map(static fn (): int => $back, $map);
    }

    /** @param array<string, mixed> $next the NEXT entry */
    private static function discardCopy(DindProject $project, array $next): void
    {
        $name = (string) ($next['project'] ?? '');
        if (str_ends_with($name, NextGeneration::PROJECT_SUFFIX)) {
            ZeroDowntimeRedeploy::discardProject($project, $name);
        }
    }

    /**
     * The port traffic would go to, when it does not answer while one of the copy's does; else null and
     * traffic moves. A copy that answers nothing either holds nothing worth keeping.
     *
     * @param  array<int, int>  $map  the copy's port => where its traffic would go
     */
    private static function silentWhileTheCopyServes(DindProject $project, array $map, int $wait): ?int
    {
        foreach (array_values(array_unique($map)) as $port) {
            if (ZeroDowntimeRedeploy::answersWithin($project, $port, $wait) === null) {
                continue;
            }
            foreach (array_keys($map) as $copy) {
                if (ZeroDowntimeRedeploy::answersWithin($project, $copy, 0) === null) {
                    return $port;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function settleHeld(DindProject $project, ?bool $succeeded, bool $forward = false): array
    {
        $done = self::settleNext($project, $forward) ? ['second generation removed'] : [];
        $retagged = (new ServingImages($project))->settle();
        if ($retagged !== null && $retagged > 0) {
            $done[] = "{$retagged} image tag(s) back on the running version";
        }
        $routing = (new RoutingSnapshot($project))->settle($succeeded);
        if ($routing !== null) {
            $done[] = $routing;
        }

        return [...$done, ...self::settleCheckout($project, $succeeded)];
    }

    /**
     * A stranded, unrecorded checkout is put back before the recorded one is settled.
     *
     * @return list<string>
     */
    private static function settleCheckout(DindProject $project, ?bool $succeeded): array
    {
        $done = [];
        $checkout = new CheckoutAside($project);
        $stranded = $checkout->reconcileStranded();
        if ($stranded !== null) {
            $done[] = "checkout {$stranded}";
        }
        $settled = $checkout->settle($succeeded);
        if ($settled !== null) {
            $done[] = "checkout {$settled}";
        }

        return $done;
    }

    /**
     * What serves after a redeploy that did not finish as a success, and the
     * line its log gets. Null when the account could not be asked.
     *
     * @param  array<string, mixed>  $entry  {@see RoutingSnapshot::entry()}
     * @return ?array{0: list<string>, 1: string}
     */
    private static function recover(DindProject $project, array $entry): ?array
    {
        $running = CheckoutAside::allRunning($project, array_values(array_filter((array) ($entry['containers'] ?? []), 'is_string')));
        if ($running === null) {
            return null;
        }
        $details = (array) ($entry['details'] ?? []);
        $served = (int) ($details['app_port'] ?? 0);
        $previous = self::name('previous', $details['git_commit'] ?? null, self::previousImage($project));
        if ($running) {
            return [
                self::settleHeld($project, null),
                "The redeploy stopped before it finished: the {$previous} still serves on port {$served}, as the new one had not replaced it yet",
            ];
        }

        $gated = ($entry['gated'] ?? false) === true;
        $previousVersion = new PreviousVersion($project);
        $restorable = $previousVersion->restorable();
        $port = $project->userModel()->getAppPort() ?? $served;
        $silent = null;
        if ($gated || ! $restorable) {
            $silent = ZeroDowntimeRedeploy::answersWithin($project, $port, self::$newAnswerSeconds);
        }

        if (self::decide(false, $gated, $restorable, $gated || ! $restorable ? $silent === null : null) === self::RESTORE) {
            return self::restartPrevious($project, $previousVersion, $gated, $silent, $previous, $served);
        }

        return self::keepNew($project, $gated, $silent, $port);
    }

    /** @return array{0: list<string>, 1: string} */
    private static function restartPrevious(DindProject $project, PreviousVersion $previousVersion, bool $gated, ?string $silent, string $previous, int $served): array
    {
        $failure = $previousVersion->start();
        $done = self::settleNext($project) ? ['second generation removed'] : [];
        $kept = ZeroDowntimeRedeploy::copyHeld($project->username());
        (new RoutingSnapshot($project))->routeBack();
        array_unshift($done, 'previous version started again');
        $why = $gated
            ? "the new version passed its health check but no longer answers ({$silent})"
            : 'the new version had not passed its health check';

        return [$done, 'The redeploy stopped before it finished, after the new version replaced the running one: '
            ."the {$previous} was started again on port {$served}, because {$why}"
            .($failure === null ? '; it answers' : "; it does not answer: {$failure}")
            .($kept ? '. The new version\'s second copy keeps serving the site until it does' : '')];
    }

    /**
     * The new version stays. Its port is asked once more: the site moved once it answered, which it may
     * have begun to do while the sweep waited.
     *
     * @return array{0: list<string>, 1: string}
     */
    private static function keepNew(DindProject $project, bool $gated, ?string $silent, int $port): array
    {
        $new = self::name('new', $project->userModel()->getDetails()['git_commit'] ?? null, self::imageOn($project, $port));
        $why = $gated
            ? 'it passed its health check before the deploy stopped, and the previous one had already been replaced'
            : 'the previous one had been replaced, and its checkout or images are no longer there to start it again';

        $done = self::settleHeld($project, null, forward: true);
        if (ZeroDowntimeRedeploy::copyHeld($project->username())) {
            return [$done, "The redeploy stopped before it finished: the {$new} does not answer on port {$port}".($silent === null ? '' : " ({$silent})").', and the previous one had been replaced '
                .'and cannot be started again, so the new version\'s second copy keeps serving the site until the app answers'];
        }
        if ($silent !== null && ZeroDowntimeRedeploy::answersWithin($project, $port, 0) === null) {
            $silent = null;
        }

        return [$done, "The redeploy stopped before it finished: the {$new} serves on port {$port}, because {$why}"
            .($silent === null ? '; it answers' : "; it does not answer: {$silent}")];
    }

    private static function name(string $which, mixed $commit, ?string $image): string
    {
        if (is_string($commit) && $commit !== '') {
            return "{$which} version (commit ".substr($commit, 0, 7).')';
        }

        return $image === null ? "{$which} version" : "{$which} version (image ".substr(preg_replace('/^sha256:/', '', $image) ?? '', 0, 12).')';
    }

    /** The image the app itself ran: one a build named, rather than a sidecar's from a registry. */
    private static function previousImage(DindProject $project): ?string
    {
        $noted = (new ServingImages($project))->noted();
        foreach ($noted as $container) {
            if (! str_contains($container['ref'], '/') && ! str_contains($container['ref'], ':')) {
                return $container['image'];
            }
        }

        return $noted[0]['image'] ?? null;
    }

    /** The image of the container that publishes $port. */
    private static function imageOn(DindProject $project, int $port): ?string
    {
        $script = 'id=$(docker ps -q --filter "publish=$1" | head -n1); [ -z "$id" ] || docker inspect --format "{{.Image}}" "$id"';
        try {
            $image = trim($project->shell()->execQuiet(['bash', '-c', $script, 'image', (string) $port], [], 30));
        } catch (\Throwable) {
            return null;
        }

        return $image === '' ? null : $image;
    }

    /** Moved aside before a pull that has not taken the lock yet. */
    private static function checkoutOwnedElsewhere(DindProject $project): bool
    {
        $checkout = (new GenerationState($project->username()))->get(GenerationState::CHECKOUT);

        return $checkout !== null && GenerationState::ownedElsewhere($checkout);
    }

    /** @param list<string> $done */
    private static function logSettled(DindProject $project, array $done): void
    {
        if ($done !== []) {
            Log::info("Settled what a redeploy of {$project->username()} left: ".implode(', ', $done));
        }
    }
}
