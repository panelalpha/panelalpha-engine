<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Lib\Project\ProjectDeleter;
use App\Models\User;
use App\System;

/**
 * Deletes the project the import created the way the API does, or takes the
 * imported files and database back out of an existing project.
 */
final class AccountImportedSiteRemover implements ImportedSiteRemover
{
    private const PLACEHOLDER = 'index.html';

    public function deleteProject(User $user): void
    {
        (new ProjectDeleter)->delete($user);
    }

    public function removeFiles(User $user, string $installPath): void
    {
        $files = $user->project()->fileManager();
        if (! $files->exists($installPath)) {
            return;
        }

        foreach (array_column($files->list($installPath)['entries'], 'name') as $name) {
            if ($name !== self::PLACEHOLDER) {
                $files->remove(rtrim($installPath, '/').'/'.$name, true);
            }
        }
    }

    public function dropDatabase(User $user, string $database, ?string $databaseUser): void
    {
        $mysql = (new System)->mysql();
        if ($mysql->databases()->databaseExists($database)) {
            $mysql->databases()->deleteDatabase($database);
        }
        $user->mysqlDatabases()->getQuery()->where('database', $database)->delete();

        if ($databaseUser !== null && $databaseUser !== '') {
            if ($mysql->users()->userExists($databaseUser)) {
                $mysql->users()->deleteUser($databaseUser);
            }
            $user->mysqlUsers()->getQuery()->where('user', $databaseUser)->delete();
        }
    }
}
