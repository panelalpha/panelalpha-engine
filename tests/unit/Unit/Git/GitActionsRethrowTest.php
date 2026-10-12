<?php

namespace Tests\Unit\Git;

use App\Lib\DeployHook\DeployHooks;
use App\Lib\Git\GitActions;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Validation\ValidationException;
use Tests\Unit\DeployHook\DeployHookTestCase;

/**
 * A git refusal the layer did not mark 422 is rethrown with its status, its
 * problem code and the task it names, so the endpoint can still answer with
 * `problem_code` and `task_id`.
 */
class GitActionsRethrowTest extends DeployHookTestCase
{
    public function test_a_non_422_refusal_keeps_its_problem_code_and_task(): void
    {
        try {
            $this->actions()->onCheckout($this->user(), null, function (): void {
                throw new GitException('A deploy of this project is running', 409, 'git_busy', 77);
            });
            $this->fail('The refusal was not rethrown.');
        } catch (GitException $e) {
            $this->assertSame(409, $e->httpStatus);
            $this->assertSame('git_busy', $e->problemCode);
            $this->assertSame(77, $e->taskId);
        }
    }

    public function test_a_422_refusal_is_still_a_validation_error_under_git(): void
    {
        $this->expectException(ValidationException::class);

        $this->actions()->onCheckout($this->user(), null, function (): void {
            throw new GitException('bad ref', 422);
        });
    }

    private function actions(): GitActions
    {
        return new GitActions(new DeployHooks());
    }
}
