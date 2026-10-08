<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Source\GitUrl;
use App\System\Project\Git\Exception as GitException;

/**
 * Runs git through a fake instead of the host or the DinD shell, and skips the
 * two steps that touch a real filesystem. Shared by both work-tree doubles.
 *
 * @internal
 */
trait FakeGitExecution
{
    /** @var (callable(list<string>, ?string, int): string)|FakeGitRunner */
    private $execute;

    /**
     * @param list<string> $gitCommand
     */
    protected function execute(array $gitCommand, ?string $token = null, int $timeout = 600): string
    {
        try {
            if ($this->execute instanceof FakeGitRunner) {
                return $this->execute->run($gitCommand, $token, $timeout);
            }

            return ($this->execute)($gitCommand, $token, $timeout);
        } catch (GitException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw new GitException($e->getMessage(), 422);
        } catch (\Throwable $e) {
            throw new GitException(GitUrl::sanitize($e->getMessage()), 400);
        }
    }

    /** Never reached: execute() is faked, so there is no work tree to make. */
    protected function runCommand(array $cmd, int $timeout): string
    {
        throw new \LogicException('runCommand() must not be reached when execute() is faked.');
    }

    protected function ensureWorkTreeDirectory(): void
    {
    }

    protected function removeCreatedGitDir(): void
    {
    }
}
