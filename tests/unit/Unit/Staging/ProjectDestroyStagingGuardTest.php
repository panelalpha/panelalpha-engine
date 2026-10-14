<?php

namespace Tests\Unit\Staging;

use App\Models\User;
use App\System;
use Exception;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Mockery;
use Tests\TestCase;

class ProjectDestroyStagingGuardTest extends TestCase
{
    public function test_destroy_throws_when_staging_user_exists(): void
    {
        $stagingUser = new User();
        $stagingUser->username = 'stgapp';

        $relation = Mockery::mock(HasOne::class);
        $relation->shouldReceive('exists')->once()->andReturn(true);

        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'liveapp';
        $user->setRelation('stagingUser', $stagingUser);
        $user->shouldReceive('stagingUser')->once()->andReturn($relation);
        $user->shouldReceive('hasGitProject')->andReturn(false);
        $user->shouldReceive('getTemplate')->andReturn('default');

        $project = (new System())->project($user);

        try {
            $project->destroy();
            $this->fail('Expected destroy guard to throw before teardown runs.');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertStringContainsString('Delete the staging project first.', $e->getMessage());
        }
    }
}
