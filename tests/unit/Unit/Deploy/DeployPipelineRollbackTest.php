<?php

namespace Tests\Unit\Deploy;

use App\Exceptions\ProblemException;
use App\Exceptions\DeployCancelledException;
use App\System\Project as SystemProject;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Deployment\RollBackProject;
use App\System\Project\Deployment\TemplateDeployMechanics;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\Unit\Task\SqliteTaskTestCase;

/**
 * The before-rollback hook on a failed create: it sees the deploy log while it
 * still exists, and a failing hook never replaces the deploy's own error.
 */
class DeployPipelineRollbackTest extends SqliteTaskTestCase
{
    private string $username;

    protected function setUp(): void
    {
        parent::setUp();

        // No row: the rollback's account lookup finds nothing to delete.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->json('details')->nullable();
            $table->timestamps();
        });
        $this->username = 'rollback-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /** The template deploy pipeline, failing in its first step. */
    private function failingDeployment(?\Exception $thrown = null): DeploymentWorkflow
    {
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('createDirectories')
            ->andThrow($thrown ?? new \Exception('process "/bin/sh -c npm run build" did not complete successfully: exit code: 1'));

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getMainDomain')->andReturn(Mockery::mock(Domain::class));
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getTemplate')->andReturn('default');
        $user->shouldReceive('hasGitProject')->andReturn(false);
        $project->shouldReceive('runtime')->andReturn(Mockery::mock(\App\System\Project\PhpHosting::class));
        $project->shouldReceive('model')->andReturn($user);
        $user->shouldReceive('usedCustomEnvVars')->andReturn(false);
        $user->username = $this->username;

        return DeploymentWorkflow::forMechanics(
            new TemplateDeployMechanics($project),
            new RollBackProject(),
        );
    }

    public function test_hook_reads_the_deploy_log_before_the_rollback(): void
    {
        $logger = DeployLogger::start($this->username);
        $seen = null;

        try {
            $this->failingDeployment()->run(
                $logger,
                function (DeployLogger $failed) use (&$seen): void {
                    $seen = array_column($failed->tail(150), 'msg');
                },
            );
            $this->fail('Expected the failed deploy to throw');
        } catch (ProblemException $e) {
            $this->assertStringContainsString('A build step failed (exit code 1)', $e->getMessage());
        }

        $this->assertIsArray($seen);
        $this->assertStringStartsWith('Deploy failed: A build step failed', (string) end($seen));
    }

    /** The rollback deletes the log on a cancel too, so the hook keeps the tail first. */
    public function test_a_cancelled_template_deploy_keeps_the_log_tail_before_the_rollback(): void
    {
        $logger = DeployLogger::start($this->username);
        $seen = null;

        try {
            $this->failingDeployment(new DeployCancelledException('operator cancelled'))->run(
                $logger,
                function (DeployLogger $cancelled) use (&$seen): void {
                    $seen = array_column($cancelled->tail(150), 'msg');
                },
            );
            $this->fail('Expected the cancelled deploy to throw');
        } catch (ProblemException $e) {
            $this->assertSame('deploy_cancelled', $e->problems[0]['code'] ?? null);
        }

        $this->assertIsArray($seen, 'the hook did not run before the rollback');
        $this->assertStringContainsString('operator cancelled', implode("\n", $seen));
    }

    public function test_failing_hook_does_not_mask_the_deploy_error(): void
    {
        $logger = DeployLogger::start($this->username);

        try {
            $this->failingDeployment()->run(
                $logger,
                static function (): void {
                    throw new RuntimeException('tail copy exploded');
                },
            );
            $this->fail('Expected the failed deploy to throw');
        } catch (ProblemException $e) {
            $this->assertStringContainsString('A build step failed (exit code 1)', $e->getMessage());
            $this->assertStringNotContainsString('tail copy exploded', $e->getMessage());
        }
    }
}
