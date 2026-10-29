<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\ProxyRule;

/**
 * A project's rule answers for no name or for its own domains and aliases: another
 * project's name puts the rule into that project's vhost. System rules are not limited,
 * except that no rule may take a name whose domain's own vhost answers for it without
 * rendering the rule.
 */
final class ProxyRuleServerName
{
    private const HTTPS_PORT = 443;

    private const CATCH_ALL_WARNING = "No domain or alias on this engine is named _, so this rule on port 443 is served with the host's "
        ."default self-signed certificate and clients will warn. Name a container project's domain to use its certificate.";

    /**
     * Why clients will not get a live HTTP rule on 443 under a certificate for its name, or null. A domain's
     * own vhost answers for its name and aliases, and serves only a container project's rule named after the
     * domain, on 443 only with SSL on; any other name takes the server's trusted certificate covering it, or the host's default.
     */
    public static function defaultCertificateWarning(ProxyRule $rule): ?string
    {
        if (! $rule->enabled || $rule->transport !== 'http' || (int) $rule->listen_port !== self::HTTPS_PORT) {
            return null;
        }
        $name = trim((string) $rule->server_name);
        $domain = self::domainAnswering($name);
        if ($domain !== null) {
            return self::domainNameWarning($domain, $name);
        }
        if (app(ProxyRuleCertificate::class)->covering([$name]) !== null) {
            return null;
        }

        return in_array($name, ['', '_'], true) ? self::CATCH_ALL_WARNING : self::uncoveredNameWarning($name);
    }

    private static function uncoveredNameWarning(string $name): string
    {
        return "No domain or alias on this engine is named {$name} and no trusted certificate on the server covers it, so this rule "
            ."on port 443 is served with the host's default self-signed certificate and clients will warn. Name a container "
            ."project's domain, or put a publicly trusted certificate for {$name} in /etc/letsencrypt/live on the server.";
    }

    private static function domainNameWarning(Domain $domain, string $name): ?string
    {
        if (! self::rendersRulesNamed($domain, $name)) {
            return self::unservedNameWarning($domain, $name);
        }

        return $domain->sslEnabled() ? null : "{$domain->domain} was added without SSL, so its site has no server on port 443 "
            ."and this rule is not served: clients get the host's default self-signed certificate and will warn.";
    }

    private static function unservedNameWarning(Domain $domain, string $name): string
    {
        return $domain->user?->getTemplate() === 'dind'
            ? "{$name} is an alias of {$domain->domain}, whose own site answers for it, so this rule on port 443 is never "
                ."served. Name the rule {$domain->domain}: a rule for the domain serves its aliases too."
            : "{$name} belongs to the traditional PHP project {$domain->user?->username}, whose own site answers for it, "
                .'so this rule on port 443 is never served.';
    }

    /** Why this owner may not use the server name, or null when it may. */
    public static function refusal(?string $ownerScope, ?string $username, ?string $serverName): ?string
    {
        return self::foreignNameRefusal($ownerScope, $username, $serverName)
            ?? self::unservedNameRefusal(trim((string) $serverName));
    }

    private static function foreignNameRefusal(?string $ownerScope, ?string $username, ?string $serverName): ?string
    {
        if (self::unlimited($ownerScope, $serverName)
            || ($username !== null && in_array(strtolower((string) $serverName), self::namesOf($username), true))) {
            return null;
        }

        return "The server name must be empty or one of the project's own domains or aliases: "
            ."a project's rule cannot answer for another project's site.";
    }

    /**
     * A domain's own vhost answers for its main name and aliases, and renders a rule only when the
     * domain is a container project's and the rule takes the main name: any other would never be served.
     */
    private static function unservedNameRefusal(string $name): ?string
    {
        $domain = self::domainAnswering($name);
        if ($domain === null || self::rendersRulesNamed($domain, $name)) {
            return null;
        }

        return $domain->user?->getTemplate() === 'dind'
            ? "{$name} is an alias of {$domain->domain}, whose own site answers for it, so a rule named after it would "
                ."never be served. Name the rule {$domain->domain}: a rule for the domain serves its aliases too."
            : "{$name} belongs to the traditional PHP project {$domain->user?->username}, whose own site answers for it, "
                .'so a rule named after it would never be served.';
    }

    /**
     * Whether a stored rule may be served, given every domain's owner from owners().
     *
     * @param  array<string, string>  $owners
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

    /** The domain whose vhost answers for $name, under its main name or an alias. */
    private static function domainAnswering(string $name): ?Domain
    {
        $name = strtolower($name);
        foreach (Domain::query()->with('user')->get() as $domain) {
            $names = array_map('strtolower', [$domain->domain, ...$domain->getAliases()]);
            if ($domain->user !== null && in_array($name, $names, true)) {
                return $domain;
            }
        }

        return null;
    }

    private static function rendersRulesNamed(Domain $domain, string $name): bool
    {
        return $domain->user?->getTemplate() === 'dind' && strtolower($domain->domain) === strtolower($name);
    }
}
