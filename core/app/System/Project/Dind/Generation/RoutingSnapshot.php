<?php

namespace App\System\Project\Dind\Generation;

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
        $state->put(GenerationState::ROUTES, [
            'details' => self::pick($details),
            'containers' => $read['running'],
            'applied' => false,
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

    /** Whether the proxy rules wait for the new version instead of following the detected port now. */
    public static function defers(string $username): bool
    {
        $entry = self::ownEntry($username);

        return $entry !== null && ($entry['applied'] ?? false) !== true;
    }

    /** The new version takes traffic: route to the port it answers on. */
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
        if ($port !== null) {
            $this->project->networking()->applyRoutes($user, $port);
        }
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

    /** @return ?array<string, mixed> */
    private static function ownEntry(string $username): ?array
    {
        $entry = (new GenerationState($username))->get(GenerationState::ROUTES);

        return ($entry['owner']['pid'] ?? null) === (int) getmypid() ? $entry : null;
    }
}
