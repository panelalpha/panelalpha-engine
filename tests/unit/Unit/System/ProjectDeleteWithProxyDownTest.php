<?php

namespace Tests\Unit\System;

use App\Exceptions\DockerErrorException;
use App\Models\User;
use App\System;
use Mockery;
use Tests\TestCase;

// Deleting a project must not depend on sites-http being up.
class ProjectDeleteWithProxyDownTest extends TestCase
{
    /** @param list<string> $calls */
    private function user(array &$calls): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'goneapp';
        $user->shouldReceive('hasGitProject')->andReturn(false);
        $user->shouldReceive('getTemplate')->andReturn('default');
        $user->shouldReceive('delete')->once()->andReturnUsing(function () use (&$calls) {
            $calls[] = 'row';

            return true;
        });

        return $user;
    }

    public function test_the_row_goes_before_the_proxy_rebuild(): void
    {
        $calls = [];
        $system = Mockery::mock(System::class)->makePartial();
        $system->shouldReceive('rebuildDomains')->once()->andReturnUsing(function () use (&$calls) {
            $calls[] = 'rebuild';

            return true;
        });
        $user = $this->user($calls);

        $system->project($user)->deleteAccountRow($user);

        $this->assertSame(['row', 'rebuild'], $calls);
    }

    public function test_a_proxy_that_is_down_defers_and_the_row_is_still_deleted(): void
    {
        $calls = [];
        $system = Mockery::mock(System::class)->makePartial();
        // System::rebuildDomains() answers false when it had to defer the reload.
        $system->shouldReceive('rebuildDomains')->once()->andReturn(false);
        $user = $this->user($calls);

        $system->project($user)->deleteAccountRow($user);

        $this->assertSame(['row'], $calls);
    }

    public function test_a_failing_rebuild_does_not_fail_the_delete(): void
    {
        $calls = [];
        $system = Mockery::mock(System::class)->makePartial();
        $system->shouldReceive('rebuildDomains')->once()->andThrow(
            new DockerErrorException('Error response from daemon: Container d6e7 is restarting, wait until the container is running')
        );
        $user = $this->user($calls);

        $system->project($user)->deleteAccountRow($user);

        $this->assertSame(['row'], $calls);
    }
}
