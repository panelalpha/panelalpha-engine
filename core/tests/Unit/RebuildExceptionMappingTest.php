<?php

namespace Tests\Unit;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Project\ProjectRebuild;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The rebuild was the one deploy entry point with no handler around its
 * pipeline call. `clone()` and the archive deploy both caught and translated
 * through a deploy problem (now `ProblemException::deploy()`); the two
 * rebuild calls did not, so
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
        return $this->failure($e, null);
    }

    private function failure(\Exception $e, ?DeployLogger $logger): ProblemException
    {
        return (new ReflectionMethod(ProjectRebuild::class, 'rebuildFailure'))->invoke(null, $e, $logger);
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

    /** A host build's stderr opens with docker's image pull; that is not the reason. */
    public function test_an_unexplained_host_build_failure_is_not_reported_by_its_image_pull(): void
    {
        $problem = $this->translate(new \RuntimeException(
            "Unable to find image 'node:22-bookworm' locally\n22-bookworm: Pulling from library/node\n"
            . "0c06829c34ad: Pulling fs layer\nStatus: Downloaded newer image for node:22-bookworm\n"
            . "[18:02:31] 'update-licenses' errored after 24 ms\n"
            . "[18:02:31] Error: Command `composer licenses` exited with code 127"
        ));

        $this->assertStringStartsWith("[18:02:31] 'update-licenses' errored", $problem->problems[0]['message']);
        $this->assertStringContainsString('exited with code 127', $problem->problems[0]['message']);
    }

    /** The stage the deploy log was in reaches the problem. */
    public function test_a_failure_names_the_stage_it_happened_in(): void
    {
        $username = 'rbstage' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $problem = $this->failure(new \RuntimeException('exited with code 1'), $logger);

        $this->assertSame('running', $problem->problems[0]['stage']);
    }

    /**
     * The workflow finishes the log itself when a rebuild fails. Finishing it
     * again here wrote a second "Deploy failed" line and filed a second
     * telemetry report for the same rebuild.
     */
    public function test_a_log_the_workflow_already_finished_is_not_finished_again(): void
    {
        $username = 'rbonce' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $logger->finish(DeployLogger::STATUS_FAILED, 'exited with code 33');

        $problem = $this->failure(new \RuntimeException('exited with code 33'), $logger);

        $finished = array_filter(
            $logger->entries(),
            static fn (array $line): bool => str_starts_with($line['msg'], 'Deploy failed')
        );
        $this->assertCount(1, $finished);
        $this->assertSame('failed', $logger->readLatest()['status'] ?? null);
        $this->assertSame('running', $problem->problems[0]['stage']);
    }

    /** A failure the workflow never reached still finishes the log. */
    public function test_a_log_still_running_is_finished(): void
    {
        $username = 'rbopen' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $this->failure(new \RuntimeException('exited with code 1'), $logger);

        $this->assertSame('failed', $logger->readLatest()['status'] ?? null);
    }

    /** A cancel request sets the status but finishes nothing, so it is finished here. */
    public function test_a_cancel_request_alone_does_not_count_as_finished(): void
    {
        $username = 'rbcanc' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        DeployLogger::requestCancel($username);

        $this->failure(new \RuntimeException('killed'), $logger);

        $this->assertNotNull($logger->readLatest()['finished_at'] ?? null);
        $this->assertSame('cancelled', $logger->readLatest()['status'] ?? null);
    }

    /**
     * The plain JSON rebuild used to get no logger (only the stream opened
     * one), so its failures could not say where they happened. The job and
     * the stream both open it through the same call now.
     */
    public function test_a_dind_rebuild_opens_its_deploy_log_whichever_way_it_runs(): void
    {
        $username = 'rblog' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $user = new User();
        $user->username = $username;

        $user->setDetails(['template' => 'php']);
        $this->assertNull((new ProjectRebuild())->openLog($user, ProjectRebuild::REBUILD), 'a template project has no deploy log');

        $user->setDetails(['template' => 'dind']);
        $logger = (new ProjectRebuild())->openLog($user, ProjectRebuild::REBUILD);
        $this->assertNotNull($logger);
        $this->assertTrue(DeployLogger::isLockedFor($username), 'opening it takes the deploy lock');
        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    /**
     * The gap itself: the controller no longer runs a deploy, so the streamed
     * and the queued one both go through the one handler in ProjectRebuild.
     */
    public function test_both_rebuild_paths_go_through_the_handler(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../../app/Http/Controllers/UserController.php');
        $this->assertStringNotContainsString('rebuildFromSource(', $controller);
        $this->assertStringNotContainsString('deployFromArchive(', $controller);
        $this->assertStringContainsString('$rebuild->run(', $controller);

        $job = (string) file_get_contents(__DIR__ . '/../../app/Jobs/RebuildProject.php');
        $this->assertStringContainsString('$rebuild->run(', $job);

        $service = (string) file_get_contents(__DIR__ . '/../../app/Lib/Project/ProjectRebuild.php');
        $body = substr($service, (int) strpos($service, 'private function rebuild(User'));
        $body = substr($body, 0, (int) strpos($body, 'private function deployArchive('));
        $this->assertSame(1, substr_count($body, '->rebuildFromSource('), 'a second unguarded rebuild call is how this regressed the first time');
        $this->assertStringContainsString('self::rebuildFailure(', $body);
    }
}
