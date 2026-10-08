<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System\Project;
use ReflectionMethod;
use Tests\TestCase;

final class UserProjectSeamTest extends TestCase
{
    public function test_project_returns_app_system_project_for_users_row(): void
    {
        $user = new User([
            'id' => 42,
            'username' => 'alice',
            'domain' => 'alice.example',
        ]);

        $project = $user->project();

        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame($user, $project->model());
        $this->assertSame('alice', $project->username());
    }

    public function test_connect_seam_is_removed_after_cutover(): void
    {
        $this->assertFalse(method_exists(User::class, 'connect'));
        $this->assertSame(
            Project::class,
            (new ReflectionMethod(User::class, 'project'))->getReturnType()?->getName(),
        );
    }
}
