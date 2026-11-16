<?php

namespace App\Lib\Project;

use App\System;

/**
 * What {@see ProvisionChecks} has to ask the engine about; everything else is in the request,
 * so the checks run without a database or a host.
 */
interface ProvisionEnvironment
{
    public function usernameRowExists(string $username): bool;

    /** Whether the host has no home directory or OS user by that name. */
    public function usernameAvailableOnHost(string $username): bool;

    /** @return list<string> what holds the name on the host, as {@see System::usernameHolders()} lists it */
    public function usernameHolders(string $username): array;

    public function templateExists(string $template): bool;

    public function domainOrAliasExists(string $domain): bool;

    /** Whether a non-shared subnet of that family (4 or 6) has an address to give. */
    public function freeDedicatedIpExists(int $family): bool;
}
