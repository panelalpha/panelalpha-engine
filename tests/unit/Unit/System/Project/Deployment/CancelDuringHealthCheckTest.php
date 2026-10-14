<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project\Deployment\DeploymentWorkflow;
use Tests\TestCase;

/**
 * A cancel accepted after `docker compose up` returned, while the engine waits
 * for the app's health check, used to be ignored and the deploy finished partial.
 */
class CancelDuringHealthCheckTest extends TestCase
{
    public function test_a_cancel_accepted_after_compose_up_ends_the_deploy_cancelled(): void
    {
        $paths = [
            'checkout' => fn (DeploymentWorkflow $w, DeployLogger $l) => $w->rebuildFromCheckout($l),
            'source' => fn (DeploymentWorkflow $w, DeployLogger $l) => $w->rebuildFromSource($l, null),
            'archive' => fn (DeploymentWorkflow $w, DeployLogger $l) => $w->deployFromArchive($l, '/project/app.zip'),
            'first' => fn (DeploymentWorkflow $w, DeployLogger $l) => $w->run($l),
        ];
        foreach ($paths as $name => $run) {
            $mechanics = new RecordingMechanics($this->model(), new DomainModel);
            $logger = $this->stubLogger();
            $logger->method('throwIfCancelled')->willThrowException(new DeployCancelledException('Deployment cancelled by user'));
            $finished = [];
            $logger->method('finish')->willReturnCallback(function (string $status) use (&$finished): void {
                $finished[] = $status;
            });

            try {
                $run(DeploymentWorkflow::forMechanics($mechanics, new RecordingDisposition), $logger);
                $this->fail("Expected a cancel from {$name}");
            } catch (ProblemException $e) {
                $this->assertSame('deploy_cancelled', $e->problems[0]['code'] ?? null, $name);
            } catch (DeployCancelledException) {
            }

            $this->assertSame([], array_diff($finished, [DeployLogger::STATUS_CANCELLED]), $name);
        }
    }

    private function model(): ModelsUser
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->template = 'dind';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);

        return $model;
    }

    private function stubLogger(): DeployLogger
    {
        $logger = $this->createStub(DeployLogger::class);
        $lock = new DeployLock(new DeployLogPaths('cancel-health-check'));
        (new \ReflectionClass(DeployLogger::class))->getProperty('lock')->setValue($logger, $lock);

        return $logger;
    }
}
