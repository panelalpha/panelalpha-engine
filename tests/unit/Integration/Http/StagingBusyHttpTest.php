<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A staging copy or push is refused while a backup or restore holds either
 * deploy lock, since both move the tree the copy reads or the push replaces.
 */
class StagingBusyHttpTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<string> */
    private array $usernames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        parent::tearDown();
    }

    private function dindUser(string $prefix): User
    {
        $username = $prefix . bin2hex(random_bytes(4));
        $this->usernames[] = $username;

        return $this->makeUser($username, ['template' => 'dind']);
    }

    /**
     * @return array{User, User}
     */
    private function stagingPair(): array
    {
        $live = $this->dindUser('live');
        $staging = $this->dindUser('stg');

        $live->staging = $staging->id;
        $live->save();
        $staging->staging = $live->id;
        $staging->save();

        return [$live, $staging];
    }

    public function test_a_staging_copy_is_refused_while_a_backup_holds_the_lock(): void
    {
        $live = $this->dindUser('live');
        $lock = DeployLock::acquireFor($live->username, DeployLock::HOLDER_BACKUP);

        try {
            $response = $this->postJson("/api/projects/{$live->username}/staging", []);
        } finally {
            $lock->release();
        }

        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame("Project '{$live->username}' is busy: a backup is running.", $response->json('message'));
        Queue::assertNothingPushed();
        $this->assertSame(0, Task::query()->count());
        $this->assertNull($live->refresh()->staging);
    }

    public function test_a_staging_push_is_refused_while_a_restore_holds_the_source_lock(): void
    {
        [$live, $staging] = $this->stagingPair();
        $lock = DeployLock::acquireFor($live->username, DeployLock::HOLDER_RESTORE);

        try {
            $response = $this->postJson("/api/projects/{$live->username}/push", ['target' => $staging->username]);
        } finally {
            $lock->release();
        }

        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame("Project '{$live->username}' is busy: a restore is running.", $response->json('message'));
        $this->assertNoPushStarted($staging);
    }

    public function test_a_staging_push_is_refused_while_the_target_has_a_backup(): void
    {
        [$live, $staging] = $this->stagingPair();
        $lock = DeployLock::acquireFor($staging->username, DeployLock::HOLDER_BACKUP);

        try {
            $response = $this->postJson("/api/projects/{$live->username}/push", ['target' => $staging->username]);
        } finally {
            $lock->release();
        }

        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame("Project '{$staging->username}' is busy: a backup is running.", $response->json('message'));
        $this->assertNoPushStarted($staging);
    }

    private function assertNoPushStarted(User $staging): void
    {
        Queue::assertNothingPushed();
        $this->assertSame(0, Task::query()->count());
        $this->assertNotSame('running', $staging->refresh()->asyncStatus()['push'] ?? null);
    }
}
