<?php

namespace App\Lib;

use App\Lib\Deploy\HostingUsername;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System;

class Helper
{
    public static function generateUsername(string $repo): ?string
    {
        $base = HostingUsername::fromGitRepo($repo);
        if ($base === null) {
            return null;
        }

        return self::generateUsernameFrom($base);
    }

    /**
     * The name itself when it is free, else the same stem with a random
     * suffix. Null when ten suffixes were all taken.
     *
     * @param  (callable(string): bool)|null  $available  defaults to {@see usernameAvailable()}
     */
    public static function generateUsernameFrom(string $base, ?callable $available = null): ?string
    {
        $available ??= self::usernameAvailable(...);
        if ($available($base)) {
            return $base;
        }
        $stem = HostingUsername::stemForSuffix($base);
        $limit = 10;
        do {
            $username = $stem.(string) rand(1000, 9999);
            if ($available($username)) {
                return $username;
            }
        } while ($limit--);

        return null;
    }

    public static function usernameAvailable(string $username): bool
    {
        if (User::existsByUsername($username)) {
            return false;
        }
        if ((new System)->isUsernameAvailable($username)) {
            return true;
        }

        return false;
    }

    public static function generateDomainName(string $username): ?string
    {
        $parentDomain = 'local';
        if ($wildcardDomain = Setting::get('default_wildcard_domain')) {
            $parentDomain = $wildcardDomain;
        }

        $domainName = $username.'.'.$parentDomain;
        $limit = 10;
        do {
            if (self::domainNameAvailable($domainName)) {
                return $domainName;
            }
            $domainName = $username.rand(1000, 9999).'.'.$parentDomain;
        } while ($limit--);

        return null;
    }

    public static function domainNameAvailable(string $domainName): bool
    {
        if (Domain::domainOrAliasExists($domainName)) {
            return false;
        }

        return true;
    }

    public static function generateCloneUsername(string $sourceUsername): ?string
    {
        $stem = HostingUsername::stemForSuffix($sourceUsername);
        $limit = 10;
        do {
            $candidate = $stem.(string) rand(1000, 9999);
            if (self::usernameAvailable($candidate)) {
                return $candidate;
            }
        } while ($limit--);

        return null;
    }

    public static function generateCloneDomain(string $sourceDomain): ?string
    {
        // Try staging.{sourceDomain} first, then staging{rand4}.{sourceDomain}
        $candidate = 'staging.'.$sourceDomain;
        if (self::domainNameAvailable($candidate)) {
            return $candidate;
        }
        $limit = 10;
        do {
            $candidate = 'staging'.rand(1000, 9999).'.'.$sourceDomain;
            if (self::domainNameAvailable($candidate)) {
                return $candidate;
            }
        } while ($limit--);

        return null;
    }
}
