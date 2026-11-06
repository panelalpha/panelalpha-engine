<?php

namespace App\System\Project\Backup;

use App\Support\JobBudget;
use Throwable;

/** Databases put back from dumps, each behind a dump of its own state taken first, so a failure can undo them all. */
final class DatabaseRestore
{
    /** @var array<string, string> database name => pre-restore dump */
    private array $undo = [];

    private bool $undoFailed = false;

    public function __construct(
        private ProjectDatabases $databases,
        private string $directory,
    ) {}

    /** A database whose own restore fails is put back from its pre-restore dump at once and left out of rollBack(). */
    public function restore(string $name, string $sqlGzPath): void
    {
        $preRestoreDump = $this->directory.'pre-restore-'.$name.'.sql.gz';
        $this->databases->dump($name, $preRestoreDump);
        $this->undo[$name] = $preRestoreDump;
        try {
            $this->databases->restore($name, $sqlGzPath);
        } catch (Throwable $e) {
            $this->attempt(fn () => $this->databases->restore($name, $preRestoreDump, JobBudget::CLEANUP_SECONDS));
            unset($this->undo[$name]);
            throw $e;
        }
    }

    public function rollBack(): void
    {
        foreach ($this->undo as $name => $dumpPath) {
            $this->attempt(fn () => $this->databases->restore($name, $dumpPath, JobBudget::CLEANUP_SECONDS));
        }
    }

    public function undoFailed(): bool
    {
        return $this->undoFailed;
    }

    /** Run one undo step; a failure is recorded, not thrown. */
    private function attempt(callable $step): void
    {
        try {
            $step();
        } catch (Throwable) {
            $this->undoFailed = true;
        }
    }
}
