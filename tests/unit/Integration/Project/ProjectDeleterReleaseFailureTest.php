<?php

namespace Tests\Integration\Project;

use App\Lib\Project\ApiAccess;
use App\Lib\Project\ProjectDeleter;
use App\Models\User;
use ArrayObject;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingFirewall;
use Tests\TestCase;

/**
 * A project whose Engine API release fails is still deleted when no allowance is left behind for its name: the
 * failure is logged, not thrown.
 */
class ProjectDeleterReleaseFailureTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Queue::fake();
    }

    public function test_a_failed_release_with_nothing_left_behind_is_logged_and_the_delete_goes_on(): void
    {
        $name = 'delrel'.bin2hex(random_bytes(3));
        $user = $this->makeUser($name, ['template' => 'dind']);
        $destroyed = new ArrayObject;
        Log::spy();

        $this->deleter($destroyed)->delete($user);

        $this->assertSame([$name], $destroyed->getArrayCopy());
        Log::shouldHaveReceived('warning')->with("Could not remove the Engine API access of '{$name}': the firewall is busy")->once();
    }

    private function deleter(ArrayObject $destroyed): ProjectDeleter
    {
        $access = new class (new RecordingFirewall) extends ApiAccess {
            public function __construct(RecordingFirewall $firewall)
            {
                parent::__construct(firewall: $firewall);
            }

            public function deny(User|string $project, bool $apply = true): bool
            {
                throw new RuntimeException('the firewall is busy');
            }
        };

        return new class ($access, $destroyed) extends ProjectDeleter {
            public function __construct(ApiAccess $access, private ArrayObject $destroyed)
            {
                parent::__construct(apiAccess: $access);
            }

            protected function destroy(User $user): void
            {
                $this->destroyed->append($user->username);
            }
        };
    }
}
