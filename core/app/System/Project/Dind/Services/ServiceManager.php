<?php

namespace App\System\Project\Dind\Services;

/**
 * The long-running processes a DinD account's init keeps up (dockerd, cron, cloudflared, the egress guard);
 * callers name a service, and which init runs it is this class's business.
 */
interface ServiceManager
{
    /**
     * Write whether $service runs, and its environment, into the account's project dir: in effect when the
     * account next starts, or at once through {@see apply()}.
     *
     * @param  array<string, string>  $env
     */
    public function configure(string $service, bool $enabled, array $env = []): void;

    /** Have a running account pick up {@see configure()} for $service. */
    public function apply(string $service): void;

    /**
     * Inside the account: stop $service and wait until it has.
     *
     * @return list<string>
     */
    public function stopArgv(string $service): array;

    /**
     * Inside the account: start $service.
     *
     * @return list<string>
     */
    public function startArgv(string $service): array;

    /**
     * Inside the account: send $signal (HUP, TERM, ...) to $service.
     *
     * @return list<string>
     */
    public function signalArgv(string $service, string $signal): array;
}
