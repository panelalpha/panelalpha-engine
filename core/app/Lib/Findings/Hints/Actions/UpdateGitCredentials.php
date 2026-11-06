<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;

/** A token that can read the repository, for the clone and its submodules. */
final class UpdateGitCredentials extends Hint
{
    public function resolves(): array
    {
        return ['repo-auth-failed', 'submodule-fetch-failed'];
    }

    public function rest(): string
    {
        return 'PUT /projects/{username}/git/update-credentials';
    }

    public function params(): array
    {
        return ['token' => null];
    }

    public function cli(): string
    {
        return 'git:update-credentials {username} --token={token}';
    }
}
