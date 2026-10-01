<?php

namespace Tests\Unit\Console;

use App\Http\Middleware\Authenticate;
use App\Integrations\Statistics\Statistics;
use App\Models\Admin;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Unit\Integrations\Statistics\FakeStatistics;

/** Pins the output and exit codes of the project:usage / bandwidth / visitors commands. */
class UsageCommandsTest extends TestCase
{
    private FakeStatistics $statistics;

    private string $shimDir;

    private string|false $path;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.debug' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2022_09_05_105857_create_admins_table.php'))->up();

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
        foreach (['ftp_accounts', 'sftp_accounts', 'mysql_databases'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->bigInteger('user_id')->index();
                $table->timestamps();
            });
        }

        $this->statistics = new FakeStatistics();
        $this->statistics->domainSeries = [
            'example.com' => ['2026-09-01' => 1000, '2026-09-02' => 2500],
            'other.com' => ['2026-09-01' => 500],
        ];
        $this->statistics->visitors['example.com'] = [
            'unique' => 10,
            'total' => 63,
            'visits' => ['records' => ['2026-09-01' => 5], 'total' => 5],
            'visits_length' => ['0s-30s' => 8],
        ];
        $this->statistics->breakdowns['example.com:pages'] = [['label' => '/', 'visits' => 20]];
        $this->app->instance(Statistics::class, $this->statistics);

        // FileManager::diskUsage() runs `sudo setpriv ... du`; answer it from a shim.
        $this->shimDir = sys_get_temp_dir() . '/usage-cmd-shim-' . bin2hex(random_bytes(4));
        mkdir($this->shimDir);
        $this->path = getenv('PATH');
        putenv('PATH=' . $this->shimDir . ':' . $this->path);
        $this->shimSudo("printf '42\\t/home/alice\\n'");

        $user = User::query()->create([
            'username' => 'alice',
            'domain' => 'alice.test',
            'status' => 'active',
            'details' => ['bandwidth_limit' => 10],
        ]);
        Domain::query()->create(['user_id' => $user->id, 'domain' => 'example.com', 'type' => 'main']);
        Domain::query()->create(['user_id' => $user->id, 'domain' => 'other.com', 'type' => 'addon']);
        DB::table('mysql_databases')->insert(['user_id' => $user->id]);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->path);
        @unlink($this->shimDir . '/sudo');
        @rmdir($this->shimDir);
        parent::tearDown();
    }

    public function test_project_usage(): void
    {
        $this->assertCommand(0, '{"storage":{"usage":42,"maximum":null},"bandwidth":{"usage":4000,"maximum":10485760},'
            . '"addon_domains":{"usage":1,"maximum":null},"subdomains":{"usage":0,"maximum":null},'
            . '"ftp_accounts":{"usage":0,"maximum":null},"sftp_accounts":{"usage":0,"maximum":null},'
            . '"mysql_databases":{"usage":1,"maximum":null}}', 'project:usage', ['project' => 'alice']);
    }

    public function test_project_usage_unknown_project(): void
    {
        $this->assertCommand(1, "HTTP 404: User not found\n", 'project:usage', ['project' => 'nobody']);
    }

    public function test_project_usage_engine_failure_is_a_server_error(): void
    {
        $this->shimSudo("echo 'du: cannot access' >&2; exit 2");
        $this->assertCommand(1, "HTTP 500: Server Error\n", 'project:usage', ['project' => 'alice']);

        config(['app.debug' => true]);
        $this->assertCommand(1, "HTTP 500: du: cannot access\n (exit code 2)\n", 'project:usage', ['project' => 'alice']);
    }

    public function test_project_bandwidth(): void
    {
        $args = ['project' => 'alice', '--start' => '2026-09-01', '--end' => '2026-09-30'];
        $this->assertCommand(0, '{"2026-09-01":1500,"2026-09-02":2500}', 'project:bandwidth', $args);
        $this->assertCommand(0, '{"2026-09-01":4000}', 'project:bandwidth', $args + ['--group-by' => 'month']);
        $this->assertCommand(0, '[]', 'project:bandwidth', ['project' => 'alice', '--start' => '2025-01-01', '--end' => '2025-01-02']);
    }

    public function test_project_bandwidth_validation_and_not_found(): void
    {
        $this->assertCommand(1, "HTTP 422: The start field is required. (and 1 more error)\n", 'project:bandwidth', ['project' => 'alice']);
        $this->assertCommand(1, "HTTP 422: The end must be a date after or equal to start.\n", 'project:bandwidth', [
            'project' => 'alice', '--start' => '2026-09-02', '--end' => '2026-09-01',
        ]);
        $this->assertCommand(1, "HTTP 422: The selected group by is invalid.\n", 'project:bandwidth', [
            'project' => 'alice', '--start' => '2026-09-01', '--end' => '2026-09-02', '--group-by' => 'week',
        ]);
        // Validation runs before the project lookup.
        $this->assertCommand(1, "HTTP 422: The start field is required. (and 1 more error)\n", 'project:bandwidth', ['project' => 'nobody']);
        $this->assertCommand(1, "HTTP 404: User not found\n", 'project:bandwidth', [
            'project' => 'nobody', '--start' => '2026-09-01', '--end' => '2026-09-02',
        ]);
    }

    public function test_domain_bandwidth(): void
    {
        $args = ['project' => 'alice', 'domain' => 'example.com', '--start' => '2026-09-01', '--end' => '2026-09-30'];
        $this->assertCommand(0, '{"2026-09-01":1000,"2026-09-02":2500}', 'project:domain:bandwidth', $args);
        $this->assertCommand(0, '{"2026-09-01":3500}', 'project:domain:bandwidth', $args + ['--group-by' => 'month']);
        $this->assertCommand(1, "HTTP 404: Not found\n", 'project:domain:bandwidth', ['domain' => 'nope.com'] + $args);
        $this->assertCommand(1, "HTTP 404: User not found\n", 'project:domain:bandwidth', ['project' => 'nobody'] + $args);
        $this->assertCommand(1, "HTTP 422: The start does not match the format Y-m-d.\n", 'project:domain:bandwidth', [
            'project' => 'nobody', 'domain' => 'nope.com', '--start' => '01-09-2026', '--end' => '2026-09-30',
        ]);
    }

    public function test_domain_visitors(): void
    {
        $args = ['project' => 'alice', 'domain' => 'example.com', '--start' => '2026-09-01', '--end' => '2026-09-30'];
        $this->assertCommand(0, '{"unique":10,"total":63,"visits":{"records":{"2026-09-01":5},"total":5},"visits_length":{"0s-30s":8}}', 'project:domain:visitors', $args);
        $this->assertCommand(0, '{"unique":0,"total":0,"visits":{"records":[],"total":0},"visits_length":[]}', 'project:domain:visitors', ['domain' => 'other.com'] + $args);
        $this->assertCommand(1, "HTTP 404: Not found\n", 'project:domain:visitors', ['domain' => 'nope.com'] + $args);
        $this->assertCommand(1, "HTTP 404: User not found\n", 'project:domain:visitors', ['project' => 'nobody'] + $args);
        $this->assertCommand(1, "HTTP 422: The end field is required.\n", 'project:domain:visitors', [
            'project' => 'nobody', 'domain' => 'nope.com', '--start' => '2026-09-01',
        ]);
    }

    public function test_domain_visitors_breakdown(): void
    {
        $args = ['project' => 'alice', 'domain' => 'example.com', 'dimension' => 'pages', '--start' => '2026-09-01', '--end' => '2026-09-30'];
        $this->assertCommand(0, '[{"label":"\/","visits":20}]', 'project:domain:visitors-breakdown', $args);
        $this->assertCommand(0, '[]', 'project:domain:visitors-breakdown', ['dimension' => 'os'] + $args);
        $this->assertCommand(1, "HTTP 404: Not found\n", 'project:domain:visitors-breakdown', ['domain' => 'nope.com'] + $args);
        $this->assertCommand(1, "HTTP 404: User not found\n", 'project:domain:visitors-breakdown', ['project' => 'nobody'] + $args);
        $this->assertCommand(1, "HTTP 422: The selected dimension is invalid.\n", 'project:domain:visitors-breakdown', [
            'project' => 'nobody', 'dimension' => 'devices',
        ] + $args);
        $this->assertCommand(1, "HTTP 422: The start field is required. (and 2 more errors)\n", 'project:domain:visitors-breakdown', [
            'project' => 'alice', 'domain' => 'example.com', 'dimension' => 'devices',
        ]);
    }

    public function test_commands_do_not_go_through_the_router(): void
    {
        $matched = 0;
        Event::listen(RouteMatched::class, function () use (&$matched) {
            $matched++;
        });

        $range = ['--start' => '2026-09-01', '--end' => '2026-09-30'];
        Artisan::call('project:usage', ['project' => 'alice']);
        Artisan::call('project:bandwidth', ['project' => 'alice'] + $range);
        Artisan::call('project:domain:bandwidth', ['project' => 'alice', 'domain' => 'example.com'] + $range);
        Artisan::call('project:domain:visitors', ['project' => 'alice', 'domain' => 'example.com'] + $range);
        Artisan::call('project:domain:visitors-breakdown', ['project' => 'alice', 'domain' => 'example.com', 'dimension' => 'pages'] + $range);

        $this->assertSame(0, $matched);
        $this->assertSame(0, Admin::query()->count());
    }

    public function test_the_api_answers_the_same_bodies(): void
    {
        $this->withoutMiddleware(Authenticate::class);
        $range = 'start=2026-09-01&end=2026-09-30';

        $this->assertSame(200, ($r = $this->get('/api/projects/alice/usage'))->getStatusCode());
        Artisan::call('project:usage', ['project' => 'alice']);
        $this->assertSame($r->getContent(), Artisan::output());

        $r = $this->get("/api/projects/alice/bandwidth?{$range}&group_by=month");
        $this->assertSame('{"2026-09-01":4000}', $r->getContent());
        $r = $this->get("/api/projects/alice/domains/example.com/bandwidth?{$range}&group_by=day");
        $this->assertSame('{"2026-09-01":1000,"2026-09-02":2500}', $r->getContent());
        $r = $this->get("/api/projects/alice/domains/example.com/visitors/pages?{$range}");
        $this->assertSame('[{"label":"\/","visits":20}]', $r->getContent());
        $r = $this->get("/api/projects/alice/domains/nope.com/visitors?{$range}");
        $this->assertSame(404, $r->getStatusCode());
        $this->assertSame('{"message":"Not found"}', $r->getContent());
        $r = $this->get("/api/projects/nobody/usage");
        $this->assertSame('{"message":"User not found"}', $r->getContent());
        $r = $this->getJson('/api/projects/alice/bandwidth');
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame('The start field is required. (and 2 more errors)', $r->json('message'));
    }

    /** @param array<string, mixed> $args */
    private function assertCommand(int $exit, string $output, string $command, array $args): void
    {
        $code = Artisan::call($command, $args);
        $this->assertSame($output, Artisan::output(), $command);
        $this->assertSame($exit, $code, $command);
    }

    private function shimSudo(string $body): void
    {
        file_put_contents($this->shimDir . '/sudo', "#!/bin/sh\n{$body}\n");
        chmod($this->shimDir . '/sudo', 0755);
    }
}
