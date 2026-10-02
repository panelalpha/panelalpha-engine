<?php

namespace App\System\Firewall;

/**
 * The host's firewall, whatever runs it. The API, the MCP tools and the CLI
 * talk to this and never to a provider; {@see FirewallFactory} picks the one
 * `FIREWALL_PROVIDER` names. ufw is the only one that ships today.
 */
interface Firewall
{
    /** Stable identifier, as used by FirewallFactory and `FIREWALL_PROVIDER`. */
    public function name(): string;

    public function status(): FirewallStatus;

    /** @return list<FirewallRule> in the order the firewall evaluates them */
    public function rules(): array;

    /** @throws FirewallNotFound */
    public function rule(string $id): FirewallRule;

    /** Deny rules go before every allow, so they win; allow rules go last. */
    public function addRule(FirewallRule $rule): FirewallRule;

    /** @throws FirewallNotFound */
    public function updateRule(string $id, FirewallRule $rule): FirewallRule;

    /** @throws FirewallNotFound */
    public function deleteRule(string $id): FirewallRule;

    public function enable(): void;

    public function disable(): void;

    /** Re-apply the configured rules. */
    public function reload(): void;

    /**
     * What the firewall blocked, and the addresses banned and released, newest
     * first. `$type` is one of FirewallLogEntry's types; `$address` keeps
     * only entries for that address.
     *
     * @return list<FirewallLogEntry>
     */
    public function logs(int $limit = 100, ?string $type = null, ?string $address = null): array;

    /**
     * Addresses the login protection never bans.
     *
     * @return list<TrustedAddress>
     */
    public function trustedAddresses(): array;

    /** Never ban the address again, and lift a ban it has now. */
    public function trust(TrustedAddress $address): TrustedAddress;

    /** @throws FirewallNotFound */
    public function untrust(string $id): TrustedAddress;
}
