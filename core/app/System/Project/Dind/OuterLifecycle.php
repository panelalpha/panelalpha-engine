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
    ) {}

    /**
     * A container sysbox forgot (it was restarted meanwhile) either cannot start at all or starts
     * with its image unmapped; both are recreated.
     */
    public function up(): void
    {
        try {
            $this->composeUp();
        } catch (\Exception $e) {
            if (! self::isSysboxStartRefusal($e)) {
                throw $e;
            }
            (new AccountMapping($this->project))->afterRefusedStart()->recreate();

            return;
        }
        (new AccountMapping($this->project))->ensureMapped();
    }

    private static function isSysboxStartRefusal(\Exception $e): bool
    {
        return AccountRuntime::isSysbox() && AccountMapping::isStartRefusal($e->getMessage());
    }

    /** A new account container in place of the old one; {@see AccountMapping}. */
    public function recreate(): void
    {
        $this->composeUp('--force-recreate');
    }

    /**
     * The tenant firewall is applied again after up: a port is bound to its container's address
     * only once the container exists, and until then the bridge drops its frames.
     */
    private function composeUp(string ...$flags): void
    {
        $this->prepareTenantNetwork();
        $this->project->system()->exec($this->composeArgv('up', '-d', '--remove-orphans', ...$flags));
        $this->prepareTenantNetwork();
    }

    /** @return list<string> */
    private function composeArgv(string ...$args): array
    {
        return ['sudo', 'docker', 'compose', '-f', $this->project->composeFilePath(), ...$args];
    }

    /**
     * Compose cannot start the account without pash-tenants, whose firewall a reboot drops. A failure only
     * warns: with no rules, enable_icc still drops traffic between the network's members.
     */
    private function prepareTenantNetwork(): void
    {
        try {
            $this->project->system()->exec(TenantNetwork::firewallArgv(), [], 60);
        } catch (\Exception $e) {
            $this->project->shell()->logger()?->warn(
                'The tenant network firewall could not be applied: '.trim($e->getMessage())
            );
        }
    }

    public function down(): void
    {
        $this->project->system()->exec($this->composeArgv('down'));
    }

    public function tearDown(): void
    {
        if (! $this->project->exists()) {
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
            $this->project->system()->runProcess($this->composeArgv('down', '-v', '--remove-orphans'));
        }
        $this->removeAccountContainer();
    }

    /** Only the account's own container: a foreign one may share the name. */
    private function removeAccountContainer(): void
    {
        $username = $this->project->username();
        if ((new AccountContainers($this->project->system()))->ownsContainerNamed($username)) {
            $this->project->system()->runProcess(['sudo', 'docker', 'rm', '-f', $username]);
        }
    }
}
