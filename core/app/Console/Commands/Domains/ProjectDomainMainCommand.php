<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\ReadsProjectOption;
use App\Exceptions\DeployBusyException;
use App\Exceptions\ProblemException;
use App\Exceptions\ProjectBusyException;
use App\Lib\Domains\DomainAddressChange;
use App\Models\Domain;
use Illuminate\Console\Command;

class ProjectDomainMainCommand extends Command
{
    use ReadsProjectOption;

    protected $signature = 'project:domain:main
                            {domain : An addon domain of the project}
                            {--project= : Project username}';

    protected $description = 'Make a container project\'s addon domain its main domain; the old main domain stays as an addon';

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
        $before = (string) $project->domain;

        try {
            $task = $address->apply($project, $domain, ['main' => true]);
        } catch (ProblemException|DeployBusyException|ProjectBusyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($before === $domain->domain) {
            $this->info("{$domain->domain} is already the main domain of {$project->username}.");

            return self::SUCCESS;
        }
        $this->info("{$domain->domain} is the main domain of {$project->username} now; {$before} stays as an addon domain.");
        $this->line($task === null
            ? 'The project has not been deployed, so nothing is redeployed.'
            : "Redeploying so the app is told its new address (task {$task->id}).");

        return self::SUCCESS;
    }
}
