<?php

namespace App\System\Project;

use App\Models\User;
use App\System\Project;
use App\System\Services\Mysql\DatabaseCopyResult;

/**
 * One clone or staging copy of a source project into a new destination: what
 * it copies, and what it has copied so far.
 */
final class CopyAttempt
{
    public ?DatabaseCopyResult $databaseResult = null;

    /** @var list<string> */
    public array $volumeWarnings = [];

    public bool $sourcePaused = false;

    /** @var list<string> */
    public array $containerHosts = [];

    public function __construct(
        public readonly User $source,
        public readonly User $dest,
        public readonly Project $sourceProject,
        public readonly Project $destProject,
        public readonly bool $isDindDest,
        public readonly bool $asStaging,
        public readonly bool $copyDatabases,
    ) {}
}
