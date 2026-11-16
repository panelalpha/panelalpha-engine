<?php

namespace App\Lib\Mail;

use App\Lib\Domains\DomainPlan;
use Closure;

/**
 * Whether the engine can show that a domain is this server's, so a project may send mail as it. Attaching a domain
 * checks only its format, so attaching proves nothing: a name counts when it is below one of the engine's own project
 * domain bases, when a tunnel of the project holds it, or when its A or AAAA records name one of this server's addresses.
 */
final class SenderDomainGate
{
    /**
     * @param  list<string>  $bases  the zones the engine names projects under, whose names are the operator's
     * @param  list<string>  $addresses  this server's own addresses
     * @param  Closure(string): list<string>  $resolve  a name's A and AAAA addresses
     */
    public function __construct(
        private readonly array $bases,
        private readonly array $addresses,
        private readonly Closure $resolve,
    ) {}

    /**
     * @param  list<string>  $held  the hostnames the project's tunnels registered: a PanelAlpha Online label is held by
     *                              one engine only, and a Cloudflare one was put in a zone the project's token controls
     */
    public function allows(string $domain, array $held = []): bool
    {
        $domain = self::normalised($domain);
        if ($domain === '') {
            return false;
        }
        if (in_array($domain, array_map(self::normalised(...), $held), true)) {
            return true;
        }

        return ! DomainPlan::inOnlineZone($domain) && ($this->isBelowABase($domain) || $this->resolvesHere($domain));
    }

    /** A base itself is not below it: the engine's own name is not a project's to send as. */
    private function isBelowABase(string $domain): bool
    {
        foreach ($this->bases as $base) {
            $base = self::normalised($base);
            if ($base !== '' && str_ends_with($domain, '.'.$base)) {
                return true;
            }
        }

        return false;
    }

    private function resolvesHere(string $domain): bool
    {
        $own = array_filter(array_map(self::packed(...), $this->addresses));

        return array_intersect(array_filter(array_map(self::packed(...), ($this->resolve)($domain))), $own) !== [];
    }

    /** The binary form, so `2001:db8::1` and `2001:0db8:0:0::1` are the same address. */
    private static function packed(string $address): string
    {
        return (string) @inet_pton(trim($address));
    }

    private static function normalised(string $name): string
    {
        return strtolower(trim($name, " \t\n\r\0\x0B."));
    }
}
