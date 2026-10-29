<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Dind\InnerBuildLimit;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * An image build in the account's own daemon -- a Dockerfile, compose or
 * Railpack build -- held to the server's host build ceiling, never above the
 * project's own limit, and first in line for the OOM killer while it runs
 * ({@see InnerBuildLimit}). The project's runtime limit is not touched.
 *
 * Never fails a deploy: a limit that cannot be set leaves the build as it was.
 */
final class InnerBuildGuard
{
    private const EXEC_TIMEOUT_SECONDS = 30;

    private int $depth = 0;

    private ?string $token = null;

    public function __construct(
        private DindProject $project,
    ) {}

    /**
     * Run $work with the build held; nested calls share one guard.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function during(callable $work): mixed
    {
        if ($this->depth === 0) {
            $this->token = $this->apply();
        }
        $this->depth++;
        try {
            return $work();
        } finally {
            $this->depth--;
            if ($this->depth === 0) {
                $this->release();
            }
        }
    }

    public function megabytes(): int
    {
        return InnerBuildLimit::megabytes(
            $this->project->engine()->hostBuilder()->memoryLimitMb(),
            $this->project->userModel()->effectiveMemoryLimit()
        );
    }

    public static function announcement(int $megabytes): string
    {
        return 'Image build memory: '.$megabytes.' MB, the server\'s build memory held to the project\'s memory '
            .'limit; the build is the first thing the kernel kills when memory runs out';
    }

    private function apply(): ?string
    {
        $token = bin2hex(random_bytes(8));
        try {
            $megabytes = $this->megabytes();
            $this->exec(InnerBuildLimit::applyCommand($megabytes, $token));
        } catch (\Throwable $e) {
            Log::warning("Could not limit image builds for {$this->project->username()}: ".$e->getMessage());
            $this->project->shell()->logger()?->warn('Could not limit the image build\'s memory; it runs within the project\'s limit only');

            return null;
        }
        $this->project->shell()->logger()?->info(self::announcement($megabytes));

        return $token;
    }

    private function release(): void
    {
        if ($this->token === null) {
            return;
        }
        try {
            $this->exec(InnerBuildLimit::releaseCommand($this->token));
        } catch (\Throwable $e) {
            Log::warning("Could not stop the image build guard for {$this->project->username()}: ".$e->getMessage());
        } finally {
            $this->token = null;
        }
    }

    /** @param  list<string>  $command */
    private function exec(array $command): void
    {
        $this->project->shell()->execQuiet($command, [], self::EXEC_TIMEOUT_SECONDS);
    }
}
