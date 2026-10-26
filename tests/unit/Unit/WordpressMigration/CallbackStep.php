<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use Closure;

/**
 * Substitute step that hands the record to a callback, then completes.
 */
final class CallbackStep implements MigrationStep
{
    /**
     * @param Closure(WordpressMigration): void $callback
     */
    public function __construct(
        private readonly string $stepName,
        private readonly Closure $callback,
    ) {
    }

    public function name(): string
    {
        return $this->stepName;
    }

    public function run(WordpressMigration $migration): void
    {
        ($this->callback)($migration);
        $migration->markStepCompleted($this->stepName);
    }
}
