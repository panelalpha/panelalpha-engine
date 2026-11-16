<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\ProblemException;
use App\Models\BackupContainer;

/**
 * A request that names no `container` uses the store the caller already has, else the host's default one, so a
 * host without one refuses it with 422 `no_default_container`.
 */
trait ResolvesBackupContainer
{
    /** The store the caller named, else `$current`, else the default one; null when the named one does not exist. */
    public function backupContainer(?BackupContainer $current = null): ?BackupContainer
    {
        $named = $this->namedContainer();
        if ($named !== null) {
            return BackupContainer::findByIdOrName($named);
        }

        return $current ?? BackupContainer::defaultContainer() ?? throw ProblemException::one(
            'container',
            'no_default_container',
            'Name a backup container: this host has no default one.',
        );
    }

    private function namedContainer(): ?string
    {
        $value = $this->validated('container');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
