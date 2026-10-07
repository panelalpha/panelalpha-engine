<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\ProxyRule;

/**
 * A project's rule answers for no name or for its own domains and aliases: another
 * project's name puts the rule into that project's vhost. System rules are not limited.
 */
final class ProxyRuleServerName
{
    /** Why this owner may not use the server name, or null when it may. */
    public static function refusal(?string $ownerScope, ?string $username, ?string $serverName): ?string
    {
        if (self::unlimited($ownerScope, $serverName)
            || ($username !== null && in_array(strtolower((string) $serverName), self::namesOf($username), true))) {
            return null;
        }

        return "The server name must be empty or one of the project's own domains or aliases: "
            . "a project's rule cannot answer for another project's site.";
    }

    /**
     * Whether a stored rule may be served, given every domain's owner from owners().
     *
     * @param array<string, string> $owners
     */
    public static function serves(ProxyRule $rule, array $owners): bool
    {
        return self::unlimited($rule->owner_scope, $rule->server_name)
            || self::same($owners[strtolower((string) $rule->server_name)] ?? null, $rule->username);
    }

    /** Whether a stored rule named after a domain may answer in the vhost of the domain's $owner. */
    public static function answersFor(ProxyRule $rule, string $owner): bool
    {
        return self::unlimited($rule->owner_scope, $rule->server_name) || self::same($owner, $rule->username);
    }

    /** @return array<string, string> project name by domain or alias */
    public static function owners(): array
    {
        $owners = [];
        foreach (Domain::query()->with('user')->get() as $domain) {
            $username = $domain->user?->username;
            if ($username !== null) {
                foreach ([$domain->domain, ...$domain->getAliases()] as $name) {
                    $owners[strtolower($name)] ??= $username;
                }
            }
        }

        return $owners;
    }

    /** @return list<string> */
    private static function namesOf(string $username): array
    {
        return array_keys(array_filter(self::owners(), static fn (string $owner): bool => self::same($owner, $username)));
    }

    private static function unlimited(?string $ownerScope, ?string $serverName): bool
    {
        return $ownerScope === 'system' || in_array(trim((string) $serverName), ['', '_'], true);
    }

    private static function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && strtolower($a) === strtolower($b);
    }
}
