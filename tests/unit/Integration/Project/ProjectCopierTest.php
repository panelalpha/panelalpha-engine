<?php

namespace Tests\Integration\Project;

use App\Exceptions\ProblemException;
use App\Lib\Project\ProjectCopier;
use App\Models\User;
use App\System;
use App\System\Project as SystemProject;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * POST /projects/{name}/clone copies in the request: whatever stops the copy,
 * the half-made copy is destroyed and the failure answers as a problem.
 */
class ProjectCopierTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_an_error_in_the_copy_destroys_the_half_made_copy_and_answers_a_problem(): void
    {
        $source = new class extends User
        {
            public function hasGitProject(): bool
            {
                throw new \Error('Call to a member function getTemplate() on null');
            }
        };
        $source->forceFill(['username' => 'alice']);
        $copy = new class extends User
        {
            public SystemProject $hosting;

            public function project(?System $system = null): SystemProject
            {
                return $this->hosting;
            }
        };
        $copy->forceFill(['username' => 'alicecopy']);
        $copy->hosting = Mockery::mock(SystemProject::class);
        $copy->hosting->shouldReceive('destroy')->once();

        try {
            (new ProjectCopier())->copy($source, $copy, true);
            $this->fail('The copy did not fail.');
        } catch (ProblemException $e) {
            $this->assertSame('clone_failed', $e->problems[0]['code'] ?? null);
            $this->assertStringContainsString('getTemplate() on null', $e->problems[0]['message'] ?? '');
        }
    }
}
