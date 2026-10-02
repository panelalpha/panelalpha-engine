<?php

namespace App\Lib\Project;

/**
 * What {@see ProvisionChecks} has to ask the engine about. Narrow on purpose:
 * everything else the checks need is in the request itself, so they can be
 * exercised without a database or a host.
 */
interface ProvisionEnvironment
{
    public function usernameRowExists(string $username): bool;

    /** Whether the host has no home directory or OS user by that name. */
    public function usernameAvailableOnHost(string $username): bool;

    public function templateExists(string $template): bool;

    public function domainOrAliasExists(string $domain): bool;

    /** Whether a non-shared subnet of that family (4 or 6) has an address to give. */
    public function freeDedicatedIpExists(int $family): bool;
}
