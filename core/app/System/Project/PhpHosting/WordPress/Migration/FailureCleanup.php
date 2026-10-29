<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;

/**
 * What a run undoes once a step has failed, before the record waits for resume.
 */
interface FailureCleanup
{
    public function afterFailure(WordpressMigration $migration, string $failedStep): void;
}
