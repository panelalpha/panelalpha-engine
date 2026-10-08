<?php

namespace Tests\Unit;

use App\Exceptions\ProblemException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The archive deploy used to re-throw a bare \Exception on any failure that
 * wasn't already an \InvalidArgumentException, which propagated as a raw,
 * message-less 500 instead of the ProblemException the rest of the deploy
 * pipeline uses. This exercises the one helper every deploy path builds it with.
 */
class DeployArchiveExceptionMappingTest extends TestCase
{
    public function test_deploy_problem_is_a_validation_exception_not_a_raw_500(): void
    {
        $problem = ProblemException::deploy('deploy_failed', 'compose stop failed: no such file or directory', 'running');

        $this->assertInstanceOf(ProblemException::class, $problem);
        $this->assertInstanceOf(ValidationException::class, $problem);
        $this->assertSame(422, $problem->status);
        $this->assertSame('deploy_failed', $problem->problems[0]['code']);
        $this->assertSame('running', $problem->problems[0]['stage']);
        $this->assertSame('compose stop failed: no such file or directory', $problem->problems[0]['message']);
    }

    /** Three deploy paths each kept a copy of this helper; one is left. */
    public function test_no_deploy_path_keeps_its_own_copy(): void
    {
        foreach (['Http/Controllers/UserController.php', 'System/Project/Deployment/DeploymentWorkflow.php', 'Lib/Project/ProjectRebuild.php'] as $file) {
            $this->assertStringNotContainsString("ProblemException::one('deploy'", (string) file_get_contents(app_path($file)), $file);
        }
    }
}
