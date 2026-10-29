<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\FailureCleanup;

/**
 * Substitute failure cleanup: records the failed steps, touches no source site.
 */
final class NoFailureCleanup implements FailureCleanup
{
    /** @var list<string> */
    public array $failedSteps = [];

    public function afterFailure(WordpressMigration $migration, string $failedStep): void
    {
        $this->failedSteps[] = $failedStep;
    }
}
