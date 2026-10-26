<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;

/**
 * Substitute step for sequence tests. Marks the named step completed and does nothing else.
 */
final class NoOpStep implements MigrationStep
{
    public function __construct(private readonly string $stepName)
    {
    }

    public function name(): string
    {
        return $this->stepName;
    }

    public function run(WordpressMigration $migration): void
    {
        $migration->markStepCompleted($this->stepName);
    }
}
