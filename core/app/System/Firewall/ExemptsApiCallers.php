<?php

namespace App\System\Firewall;

/**
 * A firewall whose login protection can leave an address alone on the engine
 * API only: SSH, SFTP and FTP still ban it, unlike a {@see TrustedAddress}.
 */
interface ExemptsApiCallers
{
    /**
     * Make the API-only exemptions what $wanted returns. It is called under
     * the trusted list's lock; an unchanged list is not written or reloaded.
     *
     * @param  callable(): list<TrustedAddress>  $wanted
     *
     * @throws FirewallException
     */
    public function exemptApiCallers(callable $wanted): void;
}
