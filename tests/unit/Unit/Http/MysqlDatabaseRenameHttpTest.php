<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\RenameMysqlDatabase;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * PUT /projects/{username}/mysql/databases/{dbname}/rename validates the target
 * name up front, refuses a busy project, and queues the rename with a task whose
 * details name the database it moves.
 */
class MysqlDatabaseRenameHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    public function test_a_rename_is_queued_with_its_task(): void
    {
        $this->projectWithDatabase('shop', 'shop_old');

        $response = $this->putJson('/api/projects/shop/mysql/databases/shop_old/rename', ['name' => 'shop_new']);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.action', 'mysql_database_rename');
        $response->assertJsonPath('data.details.from', 'shop_old');
        $response->assertJsonPath('data.details.to', 'shop_new');
        Queue::assertPushed(RenameMysqlDatabase::class, static fn (RenameMysqlDatabase $job): bool => $job->from === 'shop_old' && $job->to === 'shop_new');
    }

    public function test_a_name_without_the_prefix_is_prefixed(): void
    {
        $this->projectWithDatabase('shop', 'shop_old');

        $this->putJson('/api/projects/shop/mysql/databases/shop_old/rename', ['name' => 'plain'])
            ->assertStatus(202)
            ->assertJsonPath('data.details.to', 'shop_plain');
    }

    public function test_a_name_with_the_prefix_is_kept(): void
    {
        $this->projectWithDatabase('shop', 'shop_old');

        $this->putJson('/api/projects/shop/mysql/databases/shop_old/rename', ['name' => 'shop_again'])
            ->assertStatus(202)
            ->assertJsonPath('data.details.to', 'shop_again');
    }

    public function test_a_taken_name_is_refused(): void
    {
        $this->projectWithDatabase('shop', 'shop_old');
        $user = User::findByUsernameOrFail('shop');
        $user->mysqlDatabases()->create(['database' => 'shop_taken']);

        $this->putJson('/api/projects/shop/mysql/databases/shop_old/rename', ['name' => 'taken'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'name_taken');

        Queue::assertNothingPushed();
    }

    public function test_a_reserved_name_is_refused(): void
    {
        $this->projectWithDatabase('shop', 'olddb', '');

        $this->putJson('/api/projects/shop/mysql/databases/olddb/rename', ['name' => 'mysql'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'reserved_name');
    }

    public function test_a_name_that_is_too_long_with_the_prefix_is_refused(): void
    {
        $this->projectWithDatabase('shop', 'shop_old');

        $this->putJson('/api/projects/shop/mysql/databases/shop_old/rename', ['name' => str_repeat('a', 60)])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'name_too_long');
    }

    public function test_the_dind_application_database_is_refused(): void
    {
        $user = User::make([
            'username' => 'shop',
            'domain' => 'shop.example.test',
            'details' => ['mysql_prefix' => 'shop_', 'template' => 'dind'],
        ]);
        $user->save();
        $user->mysqlDatabases()->create(['database' => 'shop_old']);

        $this->putJson('/api/projects/shop/mysql/databases/shop_old/rename', ['name' => 'app'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'managed_database');

        $user->mysqlDatabases()->create(['database' => 'shop_app']);
        $this->putJson('/api/projects/shop/mysql/databases/shop_app/rename', ['name' => 'elsewhere'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'managed_database');
    }

    public function test_a_project_busy_with_another_job_is_refused(): void
    {
        $this->projectWithDatabase('shop', 'shop_old');
        Task::start(jobType: 'App\\Jobs\\RebuildProject', queue: 'default', username: 'shop')
            ->markRunning();

        $this->putJson('/api/projects/shop/mysql/databases/shop_old/rename', ['name' => 'shop_new'])
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_an_unknown_database_is_not_found(): void
    {
        $this->projectWithDatabase('shop', 'shop_old');

        $this->putJson('/api/projects/shop/mysql/databases/shop_missing/rename', ['name' => 'shop_new'])
            ->assertStatus(404);
    }

    public function test_an_unknown_project_is_not_found(): void
    {
        $this->putJson('/api/projects/nobody/mysql/databases/olddb/rename', ['name' => 'newdb'])
            ->assertStatus(404);
    }

    private function projectWithDatabase(string $username, string $database, string $prefix = 'shop_'): User
    {
        $user = $this->makeUser($username, ['mysql_prefix' => $prefix]);
        $user->mysqlDatabases()->create(['database' => $database]);

        return $user->refresh();
    }
}
