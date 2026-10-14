<?php

namespace App\System\Firewall;

/** ufw, fail2ban or the trusted-list write failed on the host: a 502, not the caller's 422. */
class FirewallCommandFailed extends FirewallException
{
}
