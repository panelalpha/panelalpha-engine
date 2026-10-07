<?php

namespace App\Lib\Project;

use App\Models\User;
use App\System\Project\Dind;
use Illuminate\Validation\ValidationException;

/** One shell command in a project's container, as the API and the CLI both run it. */
final class ProjectShell
{
    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     * @throws ValidationException when the project is not a dind project
     */
    public static function run(User $user, string $command, ?string $cwd, int $timeout): array
    {
        if ($user->getTemplate() !== 'dind') {
            self::refuse();
        }

        $runtime = $user->project()->runtime();
        if (!$runtime instanceof Dind) {
            self::refuse();
        }

        return $runtime->runSshCommand($command, is_string($cwd) && $cwd !== '' ? $cwd : null, $timeout);
    }

    private static function refuse(): never
    {
        throw ValidationException::withMessages([
            'command' => 'Shell commands are only supported for dind projects.',
        ]);
    }
}
