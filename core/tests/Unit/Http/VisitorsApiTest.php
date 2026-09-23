<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Integrations\Statistics\Statistics;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Unit\Integrations\Statistics\FakeStatistics;

class VisitorsApiTest extends TestCase
{
    private FakeStatistics $statistics;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('domain')->index();
            $table->string('type');
            $table->json('details')->nullable();
            $table->timestamps();
        });

        $this->statistics = new FakeStatistics();
        $this->statistics->visitors['example.com'] = [
            'unique' => 10,
            'total' => 63,
            'visits' => [
                'records' => [
                    '2026-09-01' => 5,
                    '2026-09-02' => 6,
                ],
                'total' => 11,
            ],
            'visits_length' => [
                '0s-30s' => 8,
            ],
        ];
        $this->statistics->breakdowns['example.com:pages'] = [
            ['label' => '/', 'visits' => 20],
        ];
        $this->app->instance(Statistics::class, $this->statistics);

        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_domain_visitors_overview(): void
    {
        $user = $this->makeProject();

        $response = $this->getJson(
            "/api/projects/{$user->username}/domains/example.com/visitors?start=2026-09-01&end=2026-09-30"
        );

        $response->assertOk();
        $response->assertExactJson($this->statistics->visitors['example.com']);
    }

    public function test_legacy_users_prefix_serves_the_same_overview(): void
    {
        $user = $this->makeProject();

        $this->getJson(
            "/api/users/{$user->username}/domains/example.com/visitors?start=2026-09-01&end=2026-09-30"
        )->assertOk()->assertJsonPath('unique', 10);
    }

    public function test_domain_visitors_breakdown(): void
    {
        $user = $this->makeProject();

        $this->getJson(
            "/api/projects/{$user->username}/domains/example.com/visitors/pages?start=2026-09-01&end=2026-09-30"
        )->assertOk()->assertExactJson([
            ['label' => '/', 'visits' => 20],
        ]);
    }

    public function test_missing_visitor_data_is_zeros_not_errors(): void
    {
        $user = $this->makeProject();

        $this->getJson(
            "/api/projects/{$user->username}/domains/other.com/visitors?start=2026-09-01&end=2026-09-30"
        )->assertOk()->assertExactJson([
            'unique' => 0,
            'total' => 0,
            'visits' => ['records' => [], 'total' => 0],
            'visits_length' => [],
        ]);

        $this->getJson(
            "/api/projects/{$user->username}/domains/other.com/visitors/countries?start=2026-09-01&end=2026-09-30"
        )->assertOk()->assertExactJson([]);
    }

    public function test_unknown_dimension_is_a_validation_error(): void
    {
        $user = $this->makeProject();

        $this->getJson(
            "/api/projects/{$user->username}/domains/example.com/visitors/devices?start=2026-09-01&end=2026-09-30"
        )->assertStatus(422);
    }

    public function test_unknown_domain_is_not_found(): void
    {
        $user = $this->makeProject();

        $this->getJson(
            "/api/projects/{$user->username}/domains/missing.com/visitors?start=2026-09-01&end=2026-09-30"
        )->assertStatus(404);
    }

    private function makeProject(): User
    {
        $user = User::query()->create([
            'username' => 'alice',
            'domain' => 'alice.test',
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'status' => 'active',
            'details' => [],
        ]);
        Domain::query()->create([
            'user_id' => $user->id,
            'domain' => 'example.com',
            'type' => 'main',
        ]);
        Domain::query()->create([
            'user_id' => $user->id,
            'domain' => 'other.com',
            'type' => 'addon',
        ]);

        return $user;
    }
}
