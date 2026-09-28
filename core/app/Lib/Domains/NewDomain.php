<?php

namespace App\Lib\Domains;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The rules an addon or subdomain has to pass before it is created, shared by
 * `POST /projects/{project}/domains` and `domain:create`. Each caller still
 * decides the order it asks in and how it words a refusal.
 */
final class NewDomain
{
    public const INVALID_DOMAIN = 'invalid_domain';
    public const DOMAIN_EXISTS = 'domain_exists';
    public const INVALID_ALIAS = 'invalid_alias';
    public const ALIAS_EXISTS = 'alias_exists';

    /**
     * `www.example.com` is created as `example.com`, with the www name kept
     * as one of its aliases.
     *
     * @param  array<mixed> $aliases
     * @return array{0: string, 1: array<mixed>}
     */
    public static function withoutWww(string $domain, array $aliases): array
    {
        if (Str::startsWith($domain, 'www.')) {
            if (!in_array($domain, $aliases)) {
                $aliases[] = $domain;
            }
            $domain = Str::after($domain, 'www.');
        }

        return [$domain, $aliases];
    }

    /** The limit this project has already used up for the type, or null when there is room. */
    public static function reachedLimit(User $user, string $type): ?int
    {
        $limit = match ($type) {
            'addon' => $user->getAddonDomainsLimit(),
            'sub' => $user->getSubdomainsLimit(),
            default => null,
        };
        if ($limit === null) {
            return null;
        }

        /** @var int $count */
        $count = $user->domains()->getQuery()->where('type', $type)->count();

        return $limit <= $count ? $limit : null;
    }

    /**
     * The first problem with the names, as [kind, name], or null. Checked in
     * the order both callers always used: the domain, then each alias.
     *
     * @param  array<mixed> $aliases
     * @return ?array{0: string, 1: mixed}
     */
    public static function nameProblem(string $domain, array $aliases): ?array
    {
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return [self::INVALID_DOMAIN, $domain];
        }
        if (Domain::domainOrAliasExists($domain)) {
            return [self::DOMAIN_EXISTS, $domain];
        }

        foreach ($aliases as $alias) {
            if (!filter_var($alias, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                return [self::INVALID_ALIAS, $alias];
            }
            if (Domain::domainOrAliasExists($alias)) {
                return [self::ALIAS_EXISTS, $alias];
            }
        }

        return null;
    }

    /**
     * The `details` a new domain starts with.
     *
     * @param  array<mixed> $aliases
     * @return array<string, mixed>
     */
    public static function details(string $domain, bool $sslDisabled, array $aliases): array
    {
        return [
            'document_root' => "/{$domain}/public_html",
            'redirect_enabled' => false,
            'redirect_url' => null,
            'force_https_redirect' => false,
            'ssl_disabled' => $sslDisabled,
            'aliases' => $aliases,
        ];
    }
}
