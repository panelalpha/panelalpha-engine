<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;

trait ReadsProjectOption
{
    private function projectOption(): User
    {
        $username = trim((string) $this->option('project'));
        if ($username === '') {
            throw new \InvalidArgumentException('--project is required.');
        }

        return User::findByUsername($username)
            ?? throw new \InvalidArgumentException("Project '{$username}' not found.");
    }
}
