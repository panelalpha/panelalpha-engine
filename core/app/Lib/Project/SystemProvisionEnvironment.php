<?php

namespace App\Lib\Project;

use App\Models\Domain;
use App\Models\IpSubnet;
use App\Models\Setting;
use App\Models\User;
use App\System;

final class SystemProvisionEnvironment implements ProvisionEnvironment
{
    private ?System $system = null;

    public function usernameRowExists(string $username): bool
    {
        return User::existsByUsername($username);
    }

    public function usernameAvailableOnHost(string $username): bool
    {
        return $this->system()->isUsernameAvailable($username);
    }

    public function templateExists(string $template): bool
    {
        return is_dir($this->system()->projectFilesTemplateDirPath($template));
    }

    public function domainOrAliasExists(string $domain): bool
    {
        return Domain::domainOrAliasExists($domain);
    }

    public function freeDedicatedIpExists(int $family): bool
    {
        // The same search as ProjectIpAddresses::assignFreeDedicatedIpv4/6(), without assigning.
        $default = (string) Setting::get($family === 6 ? 'default_ipv6' : 'default_ipv4');
        $reserved = $default === '' ? [] : [$default];
        $subnets = IpSubnet::query()->where('family', $family)->where('is_shared', 0)->get();
        foreach ($subnets as $subnet) {
            if ($subnet->findFreeIp($reserved) !== null) {
                return true;
            }
        }

        return false;
    }

    private function system(): System
    {
        return $this->system ??= new System();
    }
}
