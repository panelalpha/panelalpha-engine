<?php

namespace App\System;

use App\Auth\TokenAbilities;
use App\System;

/**
 * Whether the root broker's last run of an engine script on the host worked. run_host_script
 * (config/root-broker/services/.lib/host-script.sh) keeps the failure in the script's failed file until one does.
 */
final class HostScriptReport
{
    /** The tenant-network service re-applies it whenever Docker attaches an account container on its own. */
    public const TENANT_NETWORK_FIREWALL = '/opt/panelalpha/log/tenant-network-firewall.failed';

    /** The broker's entrypoint puts the host firewall's Docker hook back each time the broker starts. */
    public const HOST_FIREWALL = '/opt/panelalpha/log/host-firewall.failed';

    public function __construct(
        private System $system,
        private string $failedFile,
    ) {}

    /**
     * For GET /system/info; null for a project-scoped token, as the host is the operator's.
     *
     * @return ?array{failing: bool, warning: ?string}
     */
    public function reportFor(TokenAbilities $caller): ?array
    {
        return $caller->isProjectScoped() ? null : $this->report();
    }

    /** @return array{failing: bool, warning: ?string} */
    public function report(): array
    {
        $line = trim((string) $this->system->filesystem()->cat($this->failedFile));

        return ['failing' => $line !== '', 'warning' => $line !== '' ? $line : null];
    }
}
