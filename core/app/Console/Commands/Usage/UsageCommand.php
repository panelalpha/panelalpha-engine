<?php

namespace App\Console\Commands\Usage;

use App\Lib\Usage\ProjectUsage;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** Prints usage data as JSON; a failed lookup or validation throws for artisan to print. */
abstract class UsageCommand extends Command
{
    /** @param array<mixed> $data */
    protected function printJson(array $data): int
    {
        $this->output->write(json_encode($data, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    protected function validated(array $input, array $rules): array
    {
        return Validator::make($input, $rules)->validate();
    }

    protected function project(): User
    {
        return User::findByUsernameOrFail((string) $this->argument('project'));
    }

    protected function domain(ProjectUsage $usage, User $user): Domain
    {
        return $usage->ownedDomainOrFail($user, (string) $this->argument('domain'));
    }
}
