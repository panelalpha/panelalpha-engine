<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\ReadsProjectOption;
use App\Exceptions\DeployBusyException;
use App\Exceptions\ProblemException;
use App\Exceptions\ProjectBusyException;
use App\Lib\Domains\DomainAddressChange;
use App\Lib\Domains\HttpsPorts;
use App\Models\Domain;
use Illuminate\Console\Command;

class ProjectDomainHttpsPortsCommand extends Command
{
    use ReadsProjectOption;

    protected $signature = 'project:domain:https-ports
                            {domain : A domain of the project}
                            {--project= : Project username}
                            {--ports= : The ports it answers HTTPS on, comma-separated, e.g. 443,8443}
                            {--main= : The one the project\'s address names}';

    protected $description = 'Show or change the ports a container project\'s domain answers HTTPS on';

    public function handle(DomainAddressChange $address): int
    {
        try {
            $project = $this->projectOption();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $name = strtolower(trim((string) $this->argument('domain')));
        $domain = $project->domains()->getQuery()->where('domain', $name)->first();
        if (! $domain instanceof Domain) {
            $this->error("'{$name}' is not a domain of {$project->username}.");

            return self::FAILURE;
        }

        $params = $this->params();
        if ($params === null) {
            return self::FAILURE;
        }
        if ($params !== []) {
            try {
                $task = $address->apply($project, $domain, $params);
            } catch (ProblemException|DeployBusyException|ProjectBusyException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if ($task !== null) {
                $this->line("Redeploying so the app is told its new address (task {$task->id}).");
            }
        }

        $this->info("{$domain->domain} answers HTTPS on ".implode(', ', HttpsPorts::of($domain)).'; its main port is '.HttpsPorts::main($domain).'.');

        return self::SUCCESS;
    }

    /** @return ?array{https_ports?: list<int>, https_port?: int} null after printing what is wrong */
    private function params(): ?array
    {
        $params = [];
        $ports = trim((string) $this->option('ports'));
        if ($ports !== '') {
            $list = array_map('trim', explode(',', $ports));
            if (array_filter($list, static fn (string $port): bool => ! ctype_digit($port)) !== []) {
                $this->error('--ports takes port numbers separated by commas, e.g. 443,8443.');

                return null;
            }
            $params[HttpsPorts::PORTS_KEY] = array_values(array_unique(array_map('intval', $list)));
        }
        $main = trim((string) $this->option('main'));
        if ($main !== '') {
            if (! ctype_digit($main)) {
                $this->error('--main takes one port number.');

                return null;
            }
            $params[HttpsPorts::MAIN_KEY] = (int) $main;
        }

        return $params;
    }
}
