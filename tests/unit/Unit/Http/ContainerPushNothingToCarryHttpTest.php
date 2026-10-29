<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\PushState;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Without its files a container project pushes only the application database
 * the engine made. A source without one was accepted, carried nothing, and
 * still stopped and restarted both apps.
 */
class ContainerPushNothingToCarryHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    public function test_a_database_only_push_from_a_source_without_an_app_database_is_422_nothing_to_push(): void
    {
        [$from, $to] = $this->containerPair(withAppDatabase: false);

        $response = $this->postJson("/api/projects/{$from->username}/push", [
            'target' => $to->username,
            'files' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'databases');
        $response->assertJsonPath('problems.0.code', 'nothing_to_push');
        Queue::assertNothingPushed();
        $this->assertNull($to->refresh()->asyncStatus()['push'] ?? null);
    }

    public function test_a_database_only_push_from_a_source_with_an_app_database_is_accepted(): void
    {
        [$from, $to] = $this->containerPair(withAppDatabase: true);

        $response = $this->postJson("/api/projects/{$from->username}/push", [
            'target' => $to->username,
            'files' => false,
        ]);

        $response->assertStatus(202);
        Queue::assertPushed(PushState::class, static fn (PushState $job): bool => ! $job->files && $job->databases);
    }

    public function test_a_push_with_files_from_a_source_without_an_app_database_is_accepted(): void
    {
        [$from, $to] = $this->containerPair(withAppDatabase: false);

        $response = $this->postJson("/api/projects/{$from->username}/push", [
            'target' => $to->username,
            'databases' => false,
        ]);

        $response->assertStatus(202);
        Queue::assertPushed(PushState::class);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function containerPair(bool $withAppDatabase): array
    {
        $from = $this->makeUser('cargo-stg', ['template' => 'dind', 'mysql_prefix' => 'cargostg_']);
        $to = $this->makeUser('cargo-live', ['template' => 'dind', 'mysql_prefix' => 'cargolive_']);
        $from->staging = $to->id;
        $from->save();
        if ($withAppDatabase) {
            $from->mysqlDatabases()->create(['database' => 'cargostg_app']);
        }

        return [$from->refresh(), $to->refresh()];
    }
}
