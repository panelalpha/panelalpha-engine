<?php

namespace App\Lib;

use App\Lib\Deploy\HostingUsername;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System;

class Helper
{
    private const SUFFIX_ATTEMPTS = 10;

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
        for ($attempt = 0; $attempt < self::SUFFIX_ATTEMPTS; $attempt++) {
            $username = $stem.(string) rand(1000, 9999);
            if ($available($username)) {
                return $username;
            }
        }

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

    /**
     * `<username>.<default wildcard domain, else local>` when it is free, else the username with a random suffix
     * under the same parent. Null when ten suffixes were all taken.
     *
     * @param  (callable(string): bool)|null  $available  defaults to {@see domainNameAvailable()}
     */
    public static function generateDomainName(string $username, ?callable $available = null): ?string
    {
        $available ??= self::domainNameAvailable(...);
        $parentDomain = 'local';
        if ($wildcardDomain = Setting::get('default_wildcard_domain')) {
            $parentDomain = $wildcardDomain;
        }

        $domainName = $username.'.'.$parentDomain;
        if ($available($domainName)) {
            return $domainName;
        }
        for ($attempt = 0; $attempt < self::SUFFIX_ATTEMPTS; $attempt++) {
            $domainName = $username.rand(1000, 9999).'.'.$parentDomain;
            if ($available($domainName)) {
                return $domainName;
            }
        }

        return null;
    }

    public static function domainNameAvailable(string $domainName): bool
    {
        if (Domain::domainOrAliasExists($domainName)) {
            return false;
        }

        return true;
    }

    /**
     * The source's stem with a random suffix. Null when ten suffixes were all taken.
     *
     * @param  (callable(string): bool)|null  $available  defaults to {@see usernameAvailable()}
     */
    public static function generateCloneUsername(string $sourceUsername, ?callable $available = null): ?string
    {
        $available ??= self::usernameAvailable(...);
        $stem = HostingUsername::stemForSuffix($sourceUsername);
        for ($attempt = 0; $attempt < self::SUFFIX_ATTEMPTS; $attempt++) {
            $candidate = $stem.(string) rand(1000, 9999);
            if ($available($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * `$label.<source>` when it is free, else `$label<random suffix>.<source>`. Null when ten suffixes were all taken.
     *
     * @param  (callable(string): bool)|null  $available  defaults to {@see domainNameAvailable()}
     */
    public static function generateCloneDomain(string $sourceDomain, ?callable $available = null, string $label = 'staging'): ?string
    {
        $available ??= self::domainNameAvailable(...);
        $candidate = $label.'.'.$sourceDomain;
        if ($available($candidate)) {
            return $candidate;
        }
        for ($attempt = 0; $attempt < self::SUFFIX_ATTEMPTS; $attempt++) {
            $candidate = $label.rand(1000, 9999).'.'.$sourceDomain;
            if ($available($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
