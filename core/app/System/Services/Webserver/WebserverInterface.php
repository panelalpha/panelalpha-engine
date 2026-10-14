<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;

interface WebserverInterface
{
    public function getDetails(): array;

    public function resetWebPanelPassword(): string;

    public function updateConfig(string $name, string $value): void;

    public function rebuildDomainConfig(Domain $domain): void;

    /**
     * @param ?array<Domain> $domains
     */
    public function rebuildConfig(?array $domains = null): void;

    public function reload(bool $rebindIpListeners = false): void;

    /**
     * Reload a vhost/upstream change without ever restarting the webserver; the traffic switch uses it.
     */
    public function reloadRouting(): void;

    public function needsIpListenerRebind(): bool;

    public function restart(): void;

    public function addDomain(Domain $domain): void;

    /**
     * @return array<string>
     */
    public function listDomains(): array;

    public function deleteDomainConfig(string $domainName): void;

    /**
     * @param array<string> $domainNames
     */
    public function deleteDomainsConfigs(array $domainNames): void;

    public function deleteDomainLogsDir(string $domainName): void;

    /**
     * @param array<string> $domainNames
     */
    public function deleteDomainsLogsDirs(array $domainNames): void;

    public function toggleModsecurity(): void;

    public function toggleLscache(): void;
}
