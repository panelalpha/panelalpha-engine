<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\System\AccountContainers;
use App\System\Project\Dind as DindProject;

/**
 * Outer account-container compose up/down/teardown on the host.
 */
final class OuterLifecycle
{
    public function __construct(
        private DindProject $project,
    ) {
    }

    public function up(): void
    {
        $this->prepareTenantNetwork();
        $path = $this->project->composeFilePath();
        $this->project->system()->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $path,
            'up',
            '-d',
            '--remove-orphans',
        ]);
        // A port is bound to its container's address only once the
        // container exists, and until then the bridge drops its frames.
        $this->prepareTenantNetwork();
    }

    /**
     * The account joins pash-tenants, which compose cannot start without, and
     * whose firewall a reboot drops. One that
     * cannot be applied is a warning, not an account left down: with no rules
     * enable_icc still drops traffic between the network's members.
     */
    private function prepareTenantNetwork(): void
    {
        try {
            $this->project->system()->exec(TenantNetwork::firewallArgv(), [], 60);
        } catch (\Exception $e) {
            $this->project->shell()->logger()?->warn(
                'The tenant network firewall could not be applied: ' . trim($e->getMessage())
            );
        }
    }

    public function down(): void
    {
        $path = $this->project->composeFilePath();
        $this->project->system()->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $path,
            'down',
        ]);
    }

    public function tearDown(): void
    {
        if (!$this->project->exists()) {
            throw new \Exception(
                "Project of the user '{$this->project->username()}' doesn't exist"
            );
        }

        $this->deleteOuterStack();
    }

    /**
     * Outer compose down (when present) and force-remove the account's DinD container.
     * Safe when compose was never written or was already removed.
     */
    public function deleteOuterStack(): void
    {
        if ($this->project->exists()) {
            $path = $this->project->composeFilePath();
            $this->project->system()->runProcess([
                'sudo',
                'docker',
                'compose',
                '-f',
                $path,
                'down',
                '-v',
                '--remove-orphans',
            ]);
        }

        // Only the account's own container: a foreign one may share the name.
        $username = $this->project->username();
        if ((new AccountContainers($this->project->system()))->ownsContainerNamed($username)) {
            $this->project->system()->runProcess(['sudo', 'docker', 'rm', '-f', $username]);
        }
    }
}
