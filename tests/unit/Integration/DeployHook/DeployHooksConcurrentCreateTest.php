<?php

namespace Tests\Integration\DeployHook;

use App\Lib\DeployHook\DeployHooks;
use App\Models\DeployHook;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\Exception as GitException;

/**
 * A create that loses the (user, checkout) slot to another create running at the same time:
 * the winner's hook comes back without a secret, and a lost slot with no winner is a 500.
 */
class DeployHooksConcurrentCreateTest extends DeployHookTestCase
{
    protected function tearDown(): void
    {
        DeployHook::flushEventListeners();
        parent::tearDown();
    }

    private function git(): ProjectGit
    {
        $git = $this->createStub(ProjectGit::class);
        $git->method('isDeployManaged')->willReturn(true);
        $git->method('isConnected')->willReturn(true);
        $git->method('pathKey')->willReturn('project');

        return $git;
    }

    /**
     * Saves another row just before the create's own insert.
     *
     * @param  callable(DeployHook): array{path_key: string, public_id: string}  $clash
     */
    private function raceWith(callable $clash): void
    {
        $raced = false;
        DeployHook::creating(function (DeployHook $hook) use (&$raced, $clash): void {
            if ($raced) {
                return;
            }
            $raced = true;
            $other = new DeployHook(['user_id' => $hook->user_id] + $clash($hook));
            $other->setSecret('the-winner-holds-this-secret');
            $other->save();
        });
    }

    public function test_the_winner_of_the_slot_is_returned_without_a_secret(): void
    {
        $user = $this->user();
        $this->raceWith(static fn (DeployHook $hook): array => [
            'path_key' => $hook->path_key,
            'public_id' => DeployHook::newPublicId(),
        ]);

        $creation = (new DeployHooks())->create($user, $this->git());

        $this->assertFalse($creation->created);
        $this->assertNull($creation->secret);
        $this->assertSame(1, DeployHook::count());
        $this->assertSame((int) DeployHook::query()->value('id'), $creation->hook->id);
        $this->assertSame('the-winner-holds-this-secret', $creation->hook->secret());
    }

    public function test_a_lost_slot_with_no_winner_to_return_is_a_500(): void
    {
        $user = $this->user();
        $this->raceWith(static fn (DeployHook $hook): array => [
            'path_key' => 'elsewhere',
            'public_id' => $hook->public_id,
        ]);

        try {
            (new DeployHooks())->create($user, $this->git());
            $this->fail('A create that lost its insert reported success.');
        } catch (GitException $e) {
            $this->assertSame(500, $e->httpStatus);
            $this->assertSame('Could not create the deploy hook.', $e->getMessage());
        }

        $this->assertSame(['elsewhere'], DeployHook::pluck('path_key')->all());
    }
}
