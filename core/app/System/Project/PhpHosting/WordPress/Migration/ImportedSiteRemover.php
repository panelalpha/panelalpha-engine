<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\User;

/**
 * Removes what an import put on this engine, for a cancel.
 */
interface ImportedSiteRemover
{
    public function deleteProject(User $user): void;

    /** Everything under the install path but the placeholder page the project had. */
    public function removeFiles(User $user, string $installPath): void;

    public function dropDatabase(User $user, string $database, ?string $databaseUser): void;
}
