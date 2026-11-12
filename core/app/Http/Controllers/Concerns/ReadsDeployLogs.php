<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Deploy logs are written for container projects only, so every route that reads or
 * acts on them refuses the other projects alike instead of reading as "never deployed".
 */
trait ReadsDeployLogs
{
    private function deployLogProject(string $username): User
    {
        $user = $this->projectOr404($username);
        if ($user->getTemplate() !== 'dind') {
            abort(new JsonResponse(['message' => 'Deploy logs work only on container projects (template dind).'], 403));
        }

        return $user;
    }
}
