<?php

namespace App\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLock;
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
            $done = self::settleIfIdle($runtime);
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
            // Moved aside before a pull that has not taken the lock yet.
            $checkout = (new GenerationState($project->username()))->get(GenerationState::CHECKOUT);
            if ($checkout !== null && GenerationState::ownedElsewhere($checkout)) {
                return null;
            }
            $done = self::settleNext($project) ? ['second generation removed'] : [];
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
        } finally {
            $lock->release();
        }
        if ($done !== []) {
            Log::info("Settled what a redeploy of {$project->username()} left: " . implode(', ', $done));
        }

        return $done;
    }

    /** The caller holds the deploy lock. */
    public static function settleNext(DindProject $project): bool
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
            if ($rules !== [] && $routes !== []) {
                (new RouteSwitch($project->system(), $project->username()))->move(ZeroDowntimeRedeploy::inverse($routes), $rules);
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
}
