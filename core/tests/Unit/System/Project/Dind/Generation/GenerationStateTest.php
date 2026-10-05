<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System\Project\Dind\Generation\GenerationState;
use Tests\TestCase;

/**
 * engine#33: what a redeploy kept beside the running app is written down
 * next to its deploy lock, so a sweep can find it after the deploy died.
 */
class GenerationStateTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'gen-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_entries_are_kept_until_forgotten(): void
    {
        $state = new GenerationState($this->username);
        $state->put(GenerationState::NEXT, ['project' => 'project-next', 'routes' => [3000 => 32771], 'rules' => [7]]);
        $state->put(GenerationState::CHECKOUT, ['path' => '/home/x/.project-prev', 'containers' => ['c0ffee']]);

        $again = new GenerationState($this->username);
        $this->assertSame('project-next', $again->get(GenerationState::NEXT)['project']);
        $this->assertSame(['c0ffee'], $again->get(GenerationState::CHECKOUT)['containers']);
        $this->assertContains($this->username, GenerationState::usernames());

        $again->forget(GenerationState::NEXT);
        $this->assertNull($again->get(GenerationState::NEXT));
        $this->assertNotNull($again->get(GenerationState::CHECKOUT));

        $again->forget(GenerationState::CHECKOUT);
        $this->assertFileDoesNotExist(GenerationState::path($this->username));
        $this->assertNotContains($this->username, GenerationState::usernames());
    }

    public function test_an_unsafe_name_is_refused(): void
    {
        $this->expectException(\Throwable::class);
        new GenerationState('../etc');
    }

    /** A checkout moved aside before a pull takes the lock is not the sweep's yet. */
    public function test_an_entry_belongs_to_the_live_process_that_wrote_it_for_a_while(): void
    {
        $this->assertFalse(GenerationState::ownedElsewhere(['owner' => GenerationState::owner()]), 'this process');
        $this->assertFalse(GenerationState::ownedElsewhere([]), 'no owner noted');

        $parent = posix_getppid();
        $alive = ['owner' => ['pid' => $parent, 'start' => \App\Lib\Deploy\DeployLog\ProcessIdentity::startTime($parent), 'at' => time()]];
        $this->assertTrue(GenerationState::ownedElsewhere($alive));
        $this->assertFalse(GenerationState::ownedElsewhere($alive, time() + GenerationState::OWNER_GRACE_SECONDS + 1), 'too old to be a pull in progress');

        $gone = ['owner' => ['pid' => $parent, 'start' => 'not-its-start-time', 'at' => time()]];
        $this->assertFalse(GenerationState::ownedElsewhere($gone), 'the pid was reused');
    }
}
