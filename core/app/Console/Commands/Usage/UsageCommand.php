<?php

namespace App\Console\Commands\Usage;

use App\Lib\Usage\ProjectUsage;
use App\Models\Domain;
use App\Models\User;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Prints what the usage API answers: the JSON body, or `HTTP <status>: <message>`
 * on stderr with exit 1, the way these commands did when they dispatched the route.
 */
abstract class UsageCommand extends Command
{
    /** @param Closure(): array<mixed> $build */
    protected function answer(Closure $build): int
    {
        try {
            $this->output->write(json_encode($build(), JSON_THROW_ON_ERROR));
            return 0;
        } catch (ValidationException $e) {
            return $this->failWith(422, $e->getMessage());
        } catch (UsageNotFound $e) {
            return $this->failWith(404, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            return $this->failWith(500, config('app.debug') ? $e->getMessage() : 'Server Error');
        }
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
        return User::findByUsername((string) $this->argument('project'))
            ?? throw new UsageNotFound('User not found');
    }

    protected function domain(ProjectUsage $usage, User $user): Domain
    {
        return $usage->ownedDomain($user, (string) $this->argument('domain'))
            ?? throw new UsageNotFound('Not found');
    }

    private function failWith(int $status, string $message): int
    {
        $this->error(sprintf('HTTP %d: %s', $status, $message));
        return 1;
    }
}
