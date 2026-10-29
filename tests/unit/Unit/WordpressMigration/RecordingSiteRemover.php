<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\User;
use App\System\Project\PhpHosting\WordPress\Migration\ImportedSiteRemover;

/**
 * Substitute remover: records what a cancel would remove from this engine.
 */
final class RecordingSiteRemover implements ImportedSiteRemover
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function deleteProject(User $user): void
    {
        $this->calls[] = ['deleteProject', $user->username];
    }

    public function removeFiles(User $user, string $installPath): void
    {
        $this->calls[] = ['removeFiles', $user->username, $installPath];
    }

    public function dropDatabase(User $user, string $database, ?string $databaseUser): void
    {
        $this->calls[] = ['dropDatabase', $user->username, $database, $databaseUser];
    }
}
