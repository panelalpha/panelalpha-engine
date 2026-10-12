<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project\Deployment\DeploymentWorkflow;
use Tests\TestCase;

/**
 * A cancel in the first moments of a redeploy must not read it as a first
 * deploy: the running version is recorded before the deploy touches the account.
 */
class RedeployVersionRecordTest extends TestCase
{
    public function test_every_redeploy_records_the_running_version_before_it_touches_the_account(): void
    {
        $logger = $this->stubLogger();
        $paths = [
            'checkout' => fn (DeploymentWorkflow $w) => $w->rebuildFromCheckout($logger),
            'source' => fn (DeploymentWorkflow $w) => $w->rebuildFromSource($logger, null),
            'archive' => fn (DeploymentWorkflow $w) => $w->deployFromArchive($logger, '/project/app.zip'),
        ];
        foreach ($paths as $name => $run) {
            $mechanics = new RecordingMechanics($this->model(), new DomainModel);

            $run(DeploymentWorkflow::forMechanics($mechanics, new RecordingDisposition));

            $this->assertSame('record', $mechanics->runningVersionOrder[0] ?? null, $name);
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
        $lock = new DeployLock(new DeployLogPaths('redeploy-version-record'));
        (new \ReflectionClass(DeployLogger::class))->getProperty('lock')->setValue($logger, $lock);

        return $logger;
    }
}
