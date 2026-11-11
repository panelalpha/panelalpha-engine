<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\PublicRoutingKinds;
use App\Lib\Project\PublicRouting\HostDomain;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Lib\Project\PublicRouting\PublicPortRules;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Lets a project's `panelalpha.yaml` open host ports, take the server's own name, or both.
 */
class PublicRoutingAllowCommand extends Command
{
    protected $signature = 'system:public-routing:allow
        {project : The project to allow}
        {--ports : The host ports its public_ports: lists}
        {--host-domain : The server\'s own name, for domain: host}';

    protected $description = "Let a project's panelalpha.yaml open public ports or take the server's own name";

    public function handle(ProjectPublicRouting $routing): int
    {
        $user = User::findByUsername((string) $this->argument('project'));
        if ($user === null) {
            $this->error("There is no project '{$this->argument('project')}' on this engine.");

            return 1;
        }
        $kinds = PublicRoutingKinds::fromOptions((bool) $this->option('ports'), (bool) $this->option('host-domain'));
        if ($kinds === []) {
            $this->error('Name what to allow: --ports, --host-domain, or both.');

            return 1;
        }

        $result = $routing->allow($user, $kinds);
        $this->info("Allowed {$user->username}: ".PublicRoutingKinds::label($result['allowed']).'.');
        if (in_array(PublicRoutingGrants::PORTS, $kinds, true)) {
            $this->reportPorts($result['ports']);
        }
        if (in_array(PublicRoutingGrants::HOST_DOMAIN, $kinds, true)) {
            $this->reportHostDomain();
        }

        return 0;
    }

    /** @param list<array<string, mixed>> $ports */
    private function reportPorts(array $ports): void
    {
        if ($ports === []) {
            $this->line('Its last deploy declared no public ports; the next one opens what its panelalpha.yaml lists.');

            return;
        }
        foreach ($ports as $port) {
            $line = "{$port['transport']} {$port['listen']} → {$port['to']}: ";
            $port['state'] === PublicPortRules::OPEN
                ? $this->line($line.'open')
                : $this->warn($line.'not opened, '.($port['reason'] ?? $port['state']));
        }
    }

    private function reportHostDomain(): void
    {
        $host = HostDomain::name();
        if ($host === null) {
            $this->warn('This engine has no name of its own (cert_domain is not set), so domain: host has nothing to take.');

            return;
        }
        $this->line("Its next deploy moves it to {$host} if its panelalpha.yaml says domain: host.");
    }
}
