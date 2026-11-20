<?php

namespace Tests\Integration\Console;

use App\Lib\Project\ApiAccess;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingFirewall;
use Tests\TestCase;

/**
 * `pae api:token:list` shows the rows that exist: an Engine API token a project no
 * longer uses -- replaced, denied, or its project deleted -- is not on it.
 */
class ApiTokensListCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        Queue::fake();
        $this->makeUser('alice', ['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_replaced_and_then_denied_project_token_leaves_the_list(): void
    {
        $access = new ApiAccess(firewall: new RecordingFirewall());
        $alice = User::findByUsername('alice');
        $this->assertNotNull($alice);

        $access->allow($alice, ApiAccess::SCOPE_SELF, false);
        $access->rotate($alice);
        $access->confirmDelivery('alice', $access->envFor($alice));

        $this->assertCount(1, $this->listedRows(), 'only the token in use is on the list');

        $access->deny($alice);

        $this->assertSame([], $this->listedRows(), 'a denied project leaves no row behind');
    }

    /**
     * @return list<string>
     */
    private function listedRows(): array
    {
        Artisan::call('api:token:list');

        return array_values(array_filter(
            explode("\n", Artisan::output()),
            static fn (string $line): bool => str_contains($line, 'api-access:alice')
        ));
    }
}
