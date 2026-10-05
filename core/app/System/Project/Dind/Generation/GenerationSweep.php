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

    /**
     * @return list<string> one line per account settled
     */
    public static function run(): array
    {
        $settled = [];
        foreach (GenerationState::usernames() as $username) {
            $user = User::query()->where('username', $username)->first();
            $runtime = $user?->project()->runtime();
            if (!$runtime instanceof DindProject) {
                @unlink(GenerationState::path($username));
                continue;
            }
            $done = self::settleInterrupted($runtime);
            if ($done !== null && $done !== []) {
                $settled[] = $username . ': ' . implode(', ', $done);
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
        $lock = new DeployLock(new DeployLogPaths($project->username()));
        try {
            $lock->acquire();
        } catch (\Throwable) {
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
     * A redeploy whose process is gone: its log is closed first, then the
     * version that serves is chosen from what it recorded ({@see decide()}),
     * and its log says which one and why.
     *
     * @return ?list<string>
     */
    public static function settleInterrupted(DindProject $project): ?array
    {
        // Failed as interrupted, unless it wrote its verdict before it died.
        DeployLogger::settleOrphaned($project->username());
        $lock = new DeployLock(new DeployLogPaths($project->username()));
        try {
            $lock->acquire();
        } catch (\Throwable) {
            return null;
        }

        $line = null;
        try {
            if (self::checkoutOwnedElsewhere($project)) {
                return null;
            }
            $snapshot = new RoutingSnapshot($project);
            $entry = $snapshot->entry();
            $verdict = $snapshot->verdict();
            if ($entry === null || $verdict === true) {
                $done = self::settleHeld($project, $verdict);
            } else {
                $recovered = self::recover($project, $entry);
                if ($recovered === null) {
                    return null;
                }
                [$done, $line] = $recovered;
            }
        } finally {
            $lock->release();
        }
        self::logSettled($project, $done);
        if ($line !== null && is_string($entry['deploy'] ?? null)) {
            try {
                DeployLogger::forDeploy($project->username(), $entry['deploy'])->warn($line);
            } catch (\Throwable) {
            }
        }

        return $done;
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

    /** The caller holds the deploy lock. $forward settles traffic on the new version's port instead. */
    public static function settleNext(DindProject $project, bool $forward = false): bool
    {
        $state = new GenerationState($project->username());
        $next = $state->get(GenerationState::NEXT);
        if ($next === null) {
            return false;
        }

        try {
            $rules = array_values(array_map('intval', (array) ($next['rules'] ?? [])));
            $routes = [];
            foreach ((array) ($next['routes'] ?? []) as $from => $to) {
                $routes[(int) $from] = (int) $to;
            }
            $map = ZeroDowntimeRedeploy::inverse($routes);
            $routed = $next['routed'] ?? null;
            if ($forward && $routed !== null) {
                $map = array_map(static fn (): int => (int) $routed, $map);
            }
            if ($rules !== [] && $map !== []) {
                (new RouteSwitch($project->system(), $project->username()))->move($map, $rules);
            }
            $name = (string) ($next['project'] ?? '');
            if (str_ends_with($name, NextGeneration::PROJECT_SUFFIX)) {
                ZeroDowntimeRedeploy::discardProject($project, $name);
            }
        } catch (\Throwable $e) {
            Log::warning("Could not settle the second generation of {$project->username()}: " . $e->getMessage());

            return false;
        }
        $state->forget(GenerationState::NEXT);

        return true;
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
        $checkout = (new CheckoutAside($project))->settle($succeeded);
        if ($checkout !== null) {
            $done[] = "checkout {$checkout}";
        }

        return $done;
    }

    /**
     * What serves after a redeploy that did not finish as a success, and the
     * line its log gets. Null when the account could not be asked.
     *
     * @param array<string, mixed> $entry {@see RoutingSnapshot::entry()}
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
        if ($gated || !$restorable) {
            $silent = ZeroDowntimeRedeploy::answersWithin($project, $port, self::$newAnswerSeconds);
        }

        if (self::decide(false, $gated, $restorable, $gated || !$restorable ? $silent === null : null) === self::RESTORE) {
            $failure = $previousVersion->start();
            $done = self::settleNext($project) ? ['second generation removed'] : [];
            (new RoutingSnapshot($project))->routeBack();
            array_unshift($done, 'previous version started again');
            $why = $gated
                ? "the new version passed its health check but no longer answers ({$silent})"
                : 'the new version had not passed its health check';

            return [$done, "The redeploy stopped before it finished, after the new version replaced the running one: "
                . "the {$previous} was started again on port {$served}, because {$why}"
                . ($failure === null ? '; it answers' : "; it does not answer: {$failure}")];
        }

        $new = self::name('new', $project->userModel()->getDetails()['git_commit'] ?? null, self::imageOn($project, $port));
        $why = $gated
            ? 'it passed its health check before the deploy stopped, and the previous one had already been replaced'
            : 'the previous one had been replaced, and its checkout or images are no longer there to start it again';

        return [self::settleHeld($project, null, forward: true), "The redeploy stopped before it finished: the {$new} serves on port {$port}, because {$why}"
            . ($silent === null ? '; it answers' : "; it does not answer: {$silent}")];
    }

    private static function name(string $which, mixed $commit, ?string $image): string
    {
        if (is_string($commit) && $commit !== '') {
            return "{$which} version (commit " . substr($commit, 0, 7) . ')';
        }

        return $image === null ? "{$which} version" : "{$which} version (image " . substr(preg_replace('/^sha256:/', '', $image) ?? '', 0, 12) . ')';
    }

    /** The image the app itself ran: one a build named, rather than a sidecar's from a registry. */
    private static function previousImage(DindProject $project): ?string
    {
        $noted = (new ServingImages($project))->noted();
        foreach ($noted as $container) {
            if (!str_contains($container['ref'], '/') && !str_contains($container['ref'], ':')) {
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
            Log::info("Settled what a redeploy of {$project->username()} left: " . implode(', ', $done));
        }
    }
}
