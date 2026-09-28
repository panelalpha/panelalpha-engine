<?php

namespace App\Lib\Project;

use App\Models\Domain;
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

    private function system(): System
    {
        return $this->system ??= new System();
    }
}
