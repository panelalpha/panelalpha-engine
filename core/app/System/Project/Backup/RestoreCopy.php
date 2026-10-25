<?php

namespace App\System\Project\Backup;

use App\System\Project as UserProject;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DefinerMap;

/**
 * A backup restored into a new project rather than the one that owns it: the databases take the new
 * project's prefix, and the progress belongs to the new project while the backup records nothing.
 */
final readonly class RestoreCopy
{
    public function __construct(
        private UserProject $source,
        private UserProject $target,
    ) {}

    /**
     * A copy is complete only once its caller has started it, so this records the run and a failure
     * with its reason, never completion.
     */
    public function saveStatus(string $status, ?string $error = null): void
    {
        $target = $this->target->model();
        if ($error !== null) {
            $target->markRestoreFailed($error);

            return;
        }

        if ($status === 'completed') {
            return;
        }

        $target->mergeAsyncStatus(['restore' => $status]);
        $target->save();
    }

    public function databaseName(string $name): string
    {
        return DatabaseCopier::mapName(
            $name,
            $this->source->model()->getMysqlPrefix(),
            $this->target->model()->getMysqlPrefix(),
        );
    }

    /** Who owns the views, triggers and routines a copied dump recreates, as the new project is now. */
    public function definers(): DefinerMap
    {
        return DefinerMap::between($this->source->model(), $this->target->model());
    }
}
