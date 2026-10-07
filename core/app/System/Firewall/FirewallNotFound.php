<?php

namespace App\System\Firewall;

class FirewallNotFound extends \RuntimeException
{
    public static function rule(string $id): self
    {
        return new self("Firewall rule {$id} not found");
    }

    public static function trustedAddress(string $id): self
    {
        return new self("Trusted address {$id} not found");
    }
}
