<?php

namespace Tests\Unit\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Project\ProjectCreator;
use App\Models\User;
use App\System;
use App\System\Project;
use Tests\TestCase;

/**
 * A synchronous create holds the account's deploy lock while it deploys, so a
 * delete sent meanwhile is refused rather than tearing the account down under it.
 */
class ProjectCreatorLockTest extends TestCase
{
    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->name = 'pcl' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->name);
        parent::tearDown();
    }

    /** A user whose deploy records whether the account was locked while it ran. */
    private function user(\ArrayObject $seen, array $details): User
    {
        $user = new class extends User {
            public \ArrayObject $seen;

            public function project(?System $system = null): Project
            {
                return new class (new System(), $this, $this->seen) extends Project {
                    public function __construct(System $system, User $model, private \ArrayObject $seen)
                    {
                        parent::__construct($system, $model);
                    }

                    public function runDeployment(?DeployLogger $deployLogger = null, ?callable $beforeRollback = null): void
                    {
                        $this->seen['locked'] = DeployLogger::isLockedFor($this->username());
                    }
                };
            }
        };
        $user->seen = $seen;
        $user->username = $this->name;
        $user->setDetails($details);

        return $user;
    }

    public function test_a_plain_template_create_holds_the_lock_while_it_deploys(): void
    {
        $seen = new \ArrayObject();

        (new ProjectCreator())->deploy($this->user($seen, ['template' => 'wordpress']), null);

        $this->assertTrue($seen['locked'], 'a delete sent now is refused');
        $this->assertFalse(DeployLogger::isLockedFor($this->name), 'and released after');
    }

    public function test_a_create_with_a_deploy_log_uses_the_lock_the_log_holds(): void
    {
        $seen = new \ArrayObject();
        $logger = DeployLogger::start($this->name);

        try {
            (new ProjectCreator())->deploy($this->user($seen, ['template' => 'dind']), $logger);
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }

        $this->assertTrue($seen['locked']);
    }
}
