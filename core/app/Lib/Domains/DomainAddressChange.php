<?php

namespace App\Lib\Domains;

use App\Exceptions\DeployBusyException;
use App\Exceptions\ProblemException;
use App\Exceptions\ProjectBusyException;
use App\Lib\Project\ProjectRebuild;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;

/**
 * A domain made the main one, or moved to other HTTPS ports: refused before anything changes, applied, and the project
 * redeployed when the URL its app is told changes.
 */
class DomainAddressChange
{
    public const MAIN_PORT_REMOVED = 'https_port_main_removed';

    public function __construct(
        private readonly HttpsPortsChange $ports,
        private readonly ProjectRebuild $rebuild,
    ) {}

    /**
     * The redeploy's task, when one was queued.
     *
     * @param  array{main?: bool, https_ports?: list<int>, https_port?: int}  $params
     *
     * @throws ProblemException
     * @throws DeployBusyException|ProjectBusyException while a deploy or a backup runs, when the address would change
     */
    public function apply(User $user, Domain $domain, array $params): ?Task
    {
        $makeMain = ($params['main'] ?? false) === true && $domain->type !== 'main';
        $portsAsked = array_key_exists(HttpsPorts::PORTS_KEY, $params) || array_key_exists(HttpsPorts::MAIN_KEY, $params);
        if (! $makeMain && ! $portsAsked) {
            return null;
        }

        $mainBefore = HttpsPorts::main($domain);
        $ports = array_values(array_unique($params[HttpsPorts::PORTS_KEY] ?? HttpsPorts::of($domain)));
        $main = $params[HttpsPorts::MAIN_KEY] ?? $mainBefore;
        if ($portsAsked) {
            $this->assertPorts($domain, $ports, $main, array_key_exists(HttpsPorts::MAIN_KEY, $params));
        }
        if ($makeMain) {
            $refusal = MainDomainSwitch::refusal($user, $domain);
            if ($refusal !== null) {
                throw ProblemException::one('main', $refusal['code'], $refusal['message']);
            }
        }

        $change = fn () => $this->change($user, $domain, $makeMain, $portsAsked, $ports, $main);
        $urlChanges = $makeMain || ($domain->type === 'main' && $main !== $mainBefore);
        if (! $urlChanges || $user->getAppPort() === null) {
            $change();

            return null;
        }

        return $this->rebuild->whileIdle($user, function () use ($change, $user): Task {
            $change();

            return $this->rebuild->queue($user, ProjectRebuild::REBUILD, null, null, null);
        });
    }

    /**
     * @param  list<int>  $ports
     *
     * @throws ProblemException
     */
    private function assertPorts(Domain $domain, array $ports, int $main, bool $mainSent): void
    {
        $mainBefore = HttpsPorts::main($domain);
        if (! $mainSent && ! in_array($mainBefore, $ports, true)) {
            throw ProblemException::one(
                HttpsPorts::PORTS_KEY,
                self::MAIN_PORT_REMOVED,
                "Port {$mainBefore} is the domain's main HTTPS port: make another port the main one before removing it."
            );
        }
        $this->ports->assertAllowed($domain, $ports, $main);
    }

    /** @param list<int> $ports */
    private function change(User $user, Domain $domain, bool $makeMain, bool $portsAsked, array $ports, int $main): void
    {
        if ($portsAsked) {
            $this->ports->apply($domain, $ports, $main);
        }
        if ($makeMain) {
            MainDomainSwitch::apply($user, $domain);
        }
    }
}
