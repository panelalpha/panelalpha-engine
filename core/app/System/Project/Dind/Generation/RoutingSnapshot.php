<?php

namespace App\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ProjectBindMounts;
use Illuminate\Support\Facades\Log;

/**
 * The port the site is routed to, and the deploy details a later down/up
 * reads, as they were before a redeploy prepared the new version. The
 * proxy rules stay on that port until the new version takes traffic; a
 * failed redeploy puts the details back, a successful one routes to the
 * port the new version answers on.
 */
final class RoutingSnapshot
{
    /** Written by the prepare step and read again by stop/start, health and routing. */
    private const DETAILS = [
        'app_port', 'app_port_scheme', 'git_commit',
        'deploy_source', 'deploy_strategy', 'deploy_label', 'deploy_port', 'deploy_runtime',
        'deploy_platform', 'deploy_checks_dir', 'deploy_image', AppHealth::DETAIL_START_PERIOD,
        SidecarPasswords::DETAILS_KEY,
    ];

    public function __construct(private readonly DindProject $project)
    {
    }

    /** Before a redeploy prepares anything; nothing when no app is running. */
    public function take(): void
    {
        $state = new GenerationState($this->project->username());
        $read = (new ProjectBindMounts($this->project))->read();
        if ($read === null || $read['running'] === []) {
            $state->forget(GenerationState::ROUTES);

            return;
        }
        $details = $this->project->userModel()->getDetails();
        $served = $details['app_port'] ?? null;
        $state->put(GenerationState::ROUTES, [
            'details' => self::pick($details),
            'containers' => $read['running'],
            // Whichever way the redeploy moves them, a restore puts these back.
            'hand' => $served === null ? [] : (new RouteSwitch($this->project->system(), $this->project->username()))->handRulesTo((int) $served),
            'applied' => false,
            'gated' => false,
            'deploy' => $this->project->shell()->logger()?->getDeployId(),
            'owner' => GenerationState::owner(),
        ]);
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public static function pick(array $details): array
    {
        $picked = [];
        foreach (self::DETAILS as $key) {
            $picked[$key] = $details[$key] ?? null;
        }

        return $picked;
    }

    /** The port the running version serves on while this process redeploys it, else null. */
    public static function servedPort(string $username): ?int
    {
        $entry = self::ownEntry($username);
        $port = $entry['details']['app_port'] ?? null;

        return $entry === null || $port === null ? null : (int) $port;
    }

    /** The new version answered its health check in this process's redeploy: a sweep keeps it if it still does. */
    public static function markGated(string $username): void
    {
        $entry = self::ownEntry($username);
        if ($entry !== null) {
            (new GenerationState($username))->put(GenerationState::ROUTES, ['gated' => true] + $entry);
        }
    }

    /** @return ?array<string, mixed> what {@see take()} noted, whichever process noted it */
    public function entry(): ?array
    {
        return (new GenerationState($this->project->username()))->get(GenerationState::ROUTES);
    }

    /**
     * True when the deploy that took this snapshot closed as a success, false
     * when it closed otherwise, null when that is not known.
     */
    public function verdict(): ?bool
    {
        $id = $this->entry()['deploy'] ?? null;
        $latest = is_string($id) ? DeployLogger::readLatestFor($this->project->username()) : null;
        if ($latest === null || ($latest['id'] ?? null) !== $id) {
            return null;
        }
        $status = $latest['status'] ?? null;
        if (in_array($status, [DeployLogger::STATUS_SUCCESS, DeployLogger::STATUS_PARTIAL], true)) {
            return true;
        }

        return $status === DeployLogger::STATUS_RUNNING ? null : false;
    }

    /** Whether the proxy rules wait for the new version instead of following the detected port now. */
    public static function defers(string $username): bool
    {
        $entry = self::ownEntry($username);

        return $entry !== null && ($entry['applied'] ?? false) !== true;
    }

    /** The new version takes traffic: route to the port it answers on, the operator's rules to it too. */
    public function apply(): void
    {
        $state = new GenerationState($this->project->username());
        $entry = $state->get(GenerationState::ROUTES);
        if ($entry === null || ($entry['applied'] ?? false) === true) {
            return;
        }
        $state->put(GenerationState::ROUTES, ['applied' => true] + $entry);
        $user = $this->project->userModel();
        $port = $user->getAppPort();
        if ($port === null) {
            return;
        }
        $served = $entry['details']['app_port'] ?? null;
        if ($served !== null) {
            (new RouteSwitch($this->project->system(), $this->project->username()))->follow((int) $served, $port);
        }
        $this->project->networking()->applyRoutes($user, $port);
    }

    /**
     * After the deploy: the details back when it failed and its containers
     * still run, otherwise the routes on the new version's port. Null when
     * nothing was taken, or the containers could not be asked about.
     */
    public function settle(?bool $succeeded): ?string
    {
        $state = new GenerationState($this->project->username());
        $entry = $state->get(GenerationState::ROUTES);
        if ($entry === null) {
            return null;
        }
        $running = $succeeded === true
            ? false
            : CheckoutAside::allRunning($this->project, array_values(array_filter((array) ($entry['containers'] ?? []), 'is_string')));
        if ($running === null) {
            return null;
        }

        try {
            if (CheckoutAside::outcome($succeeded, $running) === 'restored') {
                $user = $this->project->userModel();
                $user->setDetails((array) ($entry['details'] ?? []));
                $user->save();
                $outcome = 'deploy details restored';
            } else {
                $this->apply();
                $outcome = null;
            }
        } catch (\Throwable $e) {
            Log::warning("Could not settle the routing of {$this->project->username()}: " . $e->getMessage());

            return null;
        }
        $state->forget(GenerationState::ROUTES);

        return $outcome;
    }

    /** The details noted before the redeploy, back for a start of the previous version; the port it answers on. */
    public function restoreDetails(): ?int
    {
        $state = new GenerationState($this->project->username());
        $entry = $state->get(GenerationState::ROUTES);
        if ($entry === null) {
            return null;
        }
        $user = $this->project->userModel();
        $state->put(GenerationState::ROUTES, ['left' => $user->getAppPort()] + $entry);
        $user->setDetails((array) ($entry['details'] ?? []));
        $user->save();
        $port = $entry['details']['app_port'] ?? null;

        return $port === null ? null : (int) $port;
    }

    /** The previous version is back: the site, and the operator's rules the redeploy moved, on its port. */
    public function routeBack(): void
    {
        $state = new GenerationState($this->project->username());
        $entry = $state->get(GenerationState::ROUTES);
        if ($entry === null) {
            return;
        }
        $served = $entry['details']['app_port'] ?? null;
        if ($served !== null) {
            try {
                // The new version's port, and a second copy's should its routes not have gone back yet.
                $from = [(int) ($entry['left'] ?? $this->project->userModel()->getAppPort())];
                foreach ((array) ($state->get(GenerationState::NEXT)['routes'] ?? []) as $copy) {
                    $from[] = (int) $copy;
                }
                $this->putHandRulesBack($entry, $from);
                $this->project->networking()->applyRoutes($this->project->userModel(), (int) $served);
            } catch (\Throwable $e) {
                Log::warning("Could not route {$this->project->username()} back to its previous version: " . $e->getMessage());
            }
        }
        $state->forget(GenerationState::ROUTES);
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<int> $from
     */
    private function putHandRulesBack(array $entry, array $from): void
    {
        $recorded = [];
        foreach ((array) ($entry['hand'] ?? []) as $rule) {
            if (is_array($rule) && isset($rule['id'], $rule['port'])) {
                $recorded[] = ['id' => (int) $rule['id'], 'port' => (int) $rule['port']];
            }
        }
        if ($recorded !== []) {
            (new RouteSwitch($this->project->system(), $this->project->username()))->putBack($recorded, $from);
        }
    }

    /** @return ?array<string, mixed> */
    private static function ownEntry(string $username): ?array
    {
        $entry = (new GenerationState($username))->get(GenerationState::ROUTES);

        return ($entry['owner']['pid'] ?? null) === (int) getmypid() ? $entry : null;
    }
}
