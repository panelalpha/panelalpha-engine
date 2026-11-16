<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Exceptions\NotFoundException;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Models\User;
use App\System\Project\Deployment\DeployableDindProject;
use App\System\Project\Deployment\DeployMechanics;
use App\System\Project\Deployment\DeploymentWorkflow;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A model read before its project was deleted must never rebuild, and so recreate, the account. */
class RebuildDeletedProjectTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_rebuild_from_a_model_whose_row_was_deleted_touches_nothing(): void
    {
        $stale = $this->deletedBehindItsModel('gonerebuild');
        $mechanics = $this->createMock(DeployMechanics::class);
        $mechanics->method('user')->willReturn($stale);
        $mechanics->expects($this->never())->method('recordRunningVersion');
        $mechanics->expects($this->never())->method('prepareHostingEnvironment');
        $mechanics->expects($this->never())->method('settleRedeploy');
        $logger = $this->createMock(DeployLogger::class);
        $logger->expects($this->once())->method('finish')
            ->with(DeployLogger::STATUS_CANCELLED, "Project 'gonerebuild' no longer exists. Nothing was rebuilt.");
        (new \ReflectionProperty(DeployLogger::class, 'lock'))
            ->setValue($logger, new DeployLock(new DeployLogPaths('gonerebuild')));

        try {
            (new DeploymentWorkflow($this->createStub(DeployableDindProject::class), $mechanics))
                ->rebuildFromSource($logger);
            $this->fail('The rebuild ran for a deleted project.');
        } catch (NotFoundException $e) {
            $this->assertSame("Project 'gonerebuild' no longer exists.", $e->getMessage());
        }
    }

    public function test_a_model_whose_row_is_there_passes(): void
    {
        $this->makeUser('stillhere')->assertNotDeletedElsewhere();

        $this->addToAssertionCount(1);
    }

    /** The newer project's row is not the one the model was read from. */
    public function test_a_model_whose_name_was_taken_by_a_newer_project_is_refused(): void
    {
        $stale = $this->deletedBehindItsModel('retaken');
        $this->makeUser('retaken');

        $this->expectException(NotFoundException::class);
        $stale->assertNotDeletedElsewhere();
    }

    public function test_a_model_never_saved_is_not_refused(): void
    {
        (new User(['username' => 'unsaved']))->assertNotDeletedElsewhere();

        $this->addToAssertionCount(1);
    }

    private function deletedBehindItsModel(string $username): User
    {
        $this->makeUser($username);
        $stale = User::findByUsernameOrFail($username);
        User::findByUsernameOrFail($username)->delete();

        return $stale;
    }
}
