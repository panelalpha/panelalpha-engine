<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use Throwable;

/**
 * Records each run so a sequence test can see which steps advance actually called.
 */
final class RecordingStep implements MigrationStep
{
    /** @var list<string> */
    private array $executed;

    /**
     * @param list<string> $executed
     */
    public function __construct(
        private readonly string $stepName,
        array &$executed,
        private readonly ?Throwable $throw = null,
        private readonly bool $clearSecrets = false,
    ) {
        $this->executed = &$executed;
    }

    public function name(): string
    {
        return $this->stepName;
    }

    public function run(WordpressMigration $migration): void
    {
        $this->executed[] = $this->stepName;
        if ($this->throw !== null) {
            throw $this->throw;
        }
        if ($this->clearSecrets) {
            $migration->clearSecrets();
        }
        $migration->markStepCompleted($this->stepName);
    }
}
