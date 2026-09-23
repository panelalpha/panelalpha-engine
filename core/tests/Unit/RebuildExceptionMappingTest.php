<?php

namespace Tests\Unit;

use App\Exceptions\ProblemException;
use App\Http\Controllers\UserController;
use App\Lib\Deploy\DeployLog\DeployLogger;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `rebuild()` was the one deploy entry point with no handler around its
 * pipeline call. `clone()` and `deployArchive()` both catch and translate
 * through `deployProblem()`; the two `runProjectRebuild()` calls did not, so
 * a rebuild whose compose dependency failed answered
 *
 *   HTTP 500
 *   {"message":"Server Error"}
 *
 * with no `problems[].code`, no `stage` and no `deploy_log_offset` — while
 * the deploy log for the same run already carried `status: failed` and
 * `dependency failed to start: container project-db-1`. The information
 * existed and never reached the response.
 *
 * A bare Server Error also reads as an engine fault, so the reasonable thing
 * to do on seeing it is report an outage rather than look at your own compose
 * file.
 */
class RebuildExceptionMappingTest extends TestCase
{
    private function translate(\Exception $e): ProblemException
    {
        $method = new ReflectionMethod(UserController::class, 'rebuildFailure');

        return $method->invoke(new UserController(), $e, null);
    }

    public function test_a_failed_rebuild_is_a_problem_response_not_a_raw_500(): void
    {
        $problem = $this->translate(
            new \RuntimeException('dependency failed to start: container project-db-1 exited (1)')
        );

        $this->assertInstanceOf(ProblemException::class, $problem);
        $this->assertInstanceOf(ValidationException::class, $problem);
        $this->assertSame(422, $problem->status);
        $this->assertSame('deploy', $problem->problems[0]['field']);
        $this->assertArrayHasKey('deploy_log_offset', $problem->problems[0]);
    }

    /** Nothing recognised still gets a code a client can branch on. */
    public function test_an_unrecognised_failure_still_carries_a_code(): void
    {
        $problem = $this->translate(new \RuntimeException('something nobody has seen before'));

        $this->assertSame('rebuild_failed', $problem->problems[0]['code']);
        $this->assertSame('something nobody has seen before', $problem->problems[0]['message']);
    }

    /**
     * A failure the explainer knows keeps its slug, so the rebuild path names
     * a failure exactly as the create path does.
     */
    public function test_a_recognised_failure_keeps_the_explainers_slug(): void
    {
        $problem = $this->translate(
            new \RuntimeException("gyp ERR! stack Error: Could not find any Python installation to use")
        );

        $this->assertSame('native-build-toolchain-missing', $problem->problems[0]['code']);
        $this->assertStringContainsString('compiled during install', $problem->problems[0]['message']);
    }

    /** engine#272: the stage the deploy log was in reaches the problem. */
    public function test_a_failure_names_the_stage_it_happened_in(): void
    {
        $username = 'rbstage' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $problem = (new ReflectionMethod(UserController::class, 'rebuildFailure'))
            ->invoke(new UserController(), new \RuntimeException('exited with code 1'), $logger);

        $this->assertSame('running', $problem->problems[0]['stage']);
    }

    /**
     * The plain JSON rebuild used to get no logger (only the stream opened
     * one), so its failures could not say where they happened.
     */
    public function test_the_logger_does_not_depend_on_streaming(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Http/Controllers/UserController.php');
        $this->assertIsString($source);

        $body = substr($source, (int) strpos($source, 'public function rebuild(string $username'));
        $body = substr($body, 0, (int) strpos($body, '$rebuild = function'));

        $this->assertStringContainsString("if (\$user->getTemplate() === 'dind') {", $body);
        $this->assertStringNotContainsString("=== 'dind' && \$this->wantsDeployStream", $body);
    }

    /**
     * The gap itself: both call sites now go through one closure, so the
     * streamed and the plain response cannot answer differently again.
     */
    public function test_both_rebuild_paths_go_through_the_handler(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Http/Controllers/UserController.php');
        $this->assertIsString($source);

        $body = substr($source, (int) strpos($source, 'public function rebuild(string $username'));
        $body = substr($body, 0, (int) strpos($body, 'private function rebuildFailure'));

        $this->assertSame(
            1,
            substr_count($body, '$this->runProjectRebuild('),
            'a second unguarded rebuild call is how this regressed the first time'
        );
        $this->assertStringContainsString('$this->rebuildFailure(', $body);
    }
}
