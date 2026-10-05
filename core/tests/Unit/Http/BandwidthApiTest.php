<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Integrations\Statistics\Statistics;
use App\Models\Domain;
use App\Models\User;
use App\System;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\TestCase;
use Tests\Unit\Integrations\Statistics\FakeStatistics;

class BandwidthApiTest extends TestCase
{
    private FakeStatistics $statistics;

    private System $system;

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
        Schema::create('ftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('user');
            $table->string('directory');
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Schema::create('sftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('username');
            $table->string('auth_method');
            $table->text('password')->nullable();
            $table->text('public_key')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Schema::create('mysql_databases', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('database');
            $table->json('details')->nullable();
            $table->timestamps();
        });

        $this->statistics = new FakeStatistics();
        $this->statistics->domainSeries = [
            'example.com' => [
                '2026-09-01' => 1000,
                '2026-09-02' => 2500,
            ],
            'other.com' => [
                '2026-09-01' => 500,
            ],
        ];
        $this->app->instance(Statistics::class, $this->statistics);
        // /usage measures the home with `du` and the container logs with `find`, both
        // through sudo; answer them here instead of on this machine.
        $this->system = new class extends System {
            /** @var list<list<string>> */
            public array $ran = [];

            public string $logSizes = '';

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = (array) $cmd;
                if (in_array('du', (array) $cmd, true)) {
                    return FakeProcess::ok("12\t/home/alice\n");
                }

                return in_array('find', (array) $cmd, true) ? FakeProcess::ok($this->logSizes) : FakeProcess::forCommand($cmd);
            }
        };
        $this->app->instance(System::class, $this->system);

        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_project_bandwidth_series_is_the_sum_of_domains(): void
    {
        $user = $this->makeProject();

        $response = $this->getJson(
            "/api/projects/{$user->username}/bandwidth?start=2026-09-01&end=2026-09-02&group_by=day"
        );

        $response->assertOk();
        $response->assertExactJson([
            '2026-09-01' => 1500,
            '2026-09-02' => 2500,
        ]);
    }

    public function test_legacy_users_prefix_serves_the_same_project_series(): void
    {
        $user = $this->makeProject();

        $response = $this->getJson(
            "/api/users/{$user->username}/bandwidth?start=2026-09-01&end=2026-09-02&group_by=day"
        );

        $response->assertOk();
        $response->assertJsonPath('2026-09-01', 1500);
    }

    public function test_domain_bandwidth_is_scoped_to_that_domain(): void
    {
        $user = $this->makeProject();

        $response = $this->getJson(
            "/api/projects/{$user->username}/domains/example.com/bandwidth?start=2026-09-01&end=2026-09-02&group_by=day"
        );

        $response->assertOk();
        $response->assertExactJson([
            '2026-09-01' => 1000,
            '2026-09-02' => 2500,
        ]);
    }

    public function test_unknown_domain_is_not_found(): void
    {
        $user = $this->makeProject();

        $this->getJson(
            "/api/projects/{$user->username}/domains/missing.com/bandwidth?start=2026-09-01&end=2026-09-02&group_by=day"
        )->assertStatus(404);
    }

    public function test_usage_includes_calendar_month_bandwidth_and_limit_in_bytes(): void
    {
        $user = $this->makeProject(['bandwidth_limit' => 10]);

        $response = $this->getJson("/api/projects/{$user->username}/usage");

        $response->assertOk();
        $response->assertJsonPath('storage.usage', 12);
        $response->assertJsonPath('bandwidth.usage', 4000);
        $response->assertJsonPath('bandwidth.maximum', 10 * 1024 * 1024);
    }

    public function test_usage_reports_null_maximum_when_unlimited(): void
    {
        $user = $this->makeProject();

        $response = $this->getJson("/api/projects/{$user->username}/usage");

        $response->assertOk();
        $response->assertJsonStructure(['bandwidth' => ['usage', 'maximum']]);
        $response->assertJsonPath('bandwidth.maximum', null);
    }

    public function test_usage_sums_a_dind_projects_container_logs_in_bytes(): void
    {
        $user = $this->makeProject(['template' => 'dind']);
        $this->system->logSizes = "10485760\n10485760\n524288\n";

        $response = $this->getJson("/api/projects/{$user->username}/usage");

        $response->assertOk();
        $response->assertJsonPath('logs.usage', 10485760 * 2 + 524288);
        $response->assertJsonPath('logs.maximum', null);
        $response->assertJsonPath('storage.usage', 12);
        $find = array_values(array_filter($this->system->ran, static fn (array $cmd): bool => in_array('find', $cmd, true)));
        $this->assertSame([[
            'sudo', 'find', $this->system->projectHomeDirPath('alice') . '/docker/containers',
            '-mindepth', '2', '-maxdepth', '2', '-type', 'f', '-name', '*-json.log*', '-printf', '%s\n',
        ]], $find);
    }

    public function test_usage_logs_are_zero_without_a_containers_directory_or_a_dind_runtime(): void
    {
        $user = $this->makeProject(['template' => 'dind']);
        $this->system->logSizes = '';
        $this->getJson("/api/projects/{$user->username}/usage")->assertOk()->assertJsonPath('logs.usage', 0);

        $user->setDetails(['template' => null]);
        $user->save();
        $this->system->ran = [];
        $this->system->logSizes = "999\n";
        $this->getJson("/api/projects/{$user->username}/usage")->assertOk()->assertJsonPath('logs.usage', 0);
        $this->assertSame([], array_filter($this->system->ran, static fn (array $cmd): bool => in_array('find', $cmd, true)));
    }

    public function test_empty_statistics_are_zeros_not_errors(): void
    {
        $user = $this->makeProject();
        $this->statistics->domainSeries = [];
        $this->statistics->monthBytes = 0;

        $series = $this->getJson(
            "/api/projects/{$user->username}/bandwidth?start=2026-09-01&end=2026-09-30&group_by=day"
        );
        $series->assertOk();
        $series->assertExactJson([]);

        $usage = $this->getJson("/api/projects/{$user->username}/usage");
        $usage->assertOk();
        $usage->assertJsonPath('bandwidth.usage', 0);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function makeProject(array $details = []): User
    {
        $user = User::query()->create([
            'username' => 'alice',
            'domain' => 'alice.test',
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'status' => 'active',
            'details' => $details,
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
