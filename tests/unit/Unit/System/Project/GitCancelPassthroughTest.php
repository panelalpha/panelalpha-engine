<?php

namespace Tests\Unit\System\Project;

use App\Exceptions\DeployCancelledException;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\Git\WorkTree;
use PHPUnit\Framework\TestCase;

/**
 * A cancel during the clone reached the deploy as a git failure, so the task
 * failed instead of ending cancelled. The work tree lets the cancel through.
 */
class GitCancelPassthroughTest extends TestCase
{
    public function test_a_cancelled_command_is_not_wrapped_as_a_git_failure(): void
    {
        $tree = new CancellingWorkTree;

        $this->expectException(DeployCancelledException::class);
        $tree->assertHeadReadable();
    }
}

/** @internal */
final class CancellingWorkTree extends WorkTree
{
    public function __construct()
    {
        $this->absolutePath = '/tmp/pa-cancel-tree/project';
        $this->pathKey = 'project';
    }

    protected function user(): ModelsUser
    {
        return new ModelsUser;
    }

    protected function system(): System
    {
        return new class extends System
        {
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }
        };
    }

    protected function homeDirPath(): string
    {
        return '/tmp/pa-cancel-tree';
    }

    protected function runCommand(array $cmd, int $timeout): string
    {
        throw new DeployCancelledException('Deployment cancelled by user');
    }

    protected function runQuietCommand(array $cmd, int $timeout): string
    {
        throw new DeployCancelledException('Deployment cancelled by user');
    }

    protected function fetchAndSyncFreshInit(string $branch, ?string $token): ?string
    {
        return null;
    }
}
