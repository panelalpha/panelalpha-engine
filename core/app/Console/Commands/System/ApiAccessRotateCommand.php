<?php

namespace App\Console\Commands\System;

use App\Exceptions\ProblemException;
use App\Lib\Project\ApiAccess;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Mints a new token for a project already allowed, keeping its scope. The old
 * one is revoked once the application runs on the new one, so a rotation with a
 * failing apply leaves both in place and says so.
 */
class ApiAccessRotateCommand extends Command
{
    protected $signature = 'system:api-access:rotate {project : The project whose token is replaced}';

    protected $description = 'Replace an allowed project\'s Engine API token without changing its scope';

    public function handle(): int
    {
        $username = (string) $this->argument('project');
        $user = User::findByUsername($username);
        if ($user === null) {
            $this->error("There is no project '{$username}' on this engine.");

            return 1;
        }

        try {
            $entry = (new ApiAccess())->rotate($user, $taskId);
        } catch (ProblemException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info(sprintf(
            'Minted a new Engine API token (id %s) for %s, scope %s, and queued apply task %s to hand it over. The '
            . 'previous token is revoked once the application is running on the new one; if that apply fails, both '
            . 'stay valid and the task says why.',
            (string) ($entry['token_id'] ?? '-'),
            $username,
            ApiAccess::scopeLabel($entry['scope'] ?? ApiAccess::SCOPE_SELF),
            $taskId === null ? '-' : (string) $taskId
        ));

        if (($entry['pending_revoke_token_id'] ?? null) !== null) {
            $this->line(sprintf(
                'Token %s is replaced once this apply succeeds: %s.',
                (string) $entry['pending_revoke_token_id'],
                (string) ($entry['warning'] ?? ApiAccess::pendingWarning(false))
            ));
        }

        return 0;
    }
}
