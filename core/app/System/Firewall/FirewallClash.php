<?php

namespace App\System\Firewall;

/** Refused: a rule already there matches the same traffic, and is left as it is. */
class FirewallClash extends FirewallException
{
}
