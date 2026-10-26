<?php

namespace Tests\Unit\Usage;

use App\Integrations\Statistics\Awstats;
use App\Integrations\Statistics\Statistics;
use App\Lib\Usage\ProjectBandwidth;
use App\Models\Admin;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** This month's transfer per project, measured in the background and read from the database. */
class ProjectBandwidthTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        $this->app->forgetInstance('encrypter');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        $this->dataDir = sys_get_temp_dir() . '/pa-bandwidth-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0775, true);
        foreach (glob(base_path('../tests/unit/fixtures/awstats') . '/awstats*') ?: [] as $file) {
            copy($file, $this->dataDir . '/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
        parent::tearDown();
    }

    public function test_each_project_stores_the_month_the_bandwidth_endpoint_counts(): void
    {
        $this->project('shop', ['example.com', 'other.com']);
        $this->project('blog');
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'UTC'));

        $this->assertSame(2, $this->bandwidth()->sample());

        $byMonth = $this->statistics()->projectBandwidth(['example.com', 'other.com'], '2026-09-01', '2026-09-30', 'month');
        $this->assertGreaterThan(0, $byMonth['2026-09-01']);
        $this->assertSame([
            'blog' => ['month' => '2026-09', 'used_bytes' => 0, 'measured_at' => '2026-09-20T12:00:00Z'],
            'shop' => ['month' => '2026-09', 'used_bytes' => $byMonth['2026-09-01'], 'measured_at' => '2026-09-20T12:00:00Z'],
        ], ProjectBandwidth::all());
    }

    public function test_a_new_month_starts_at_zero(): void
    {
        $this->project('shop', ['example.com']);
        $this->travelTo(Carbon::parse('2026-09-30 23:00:00', 'UTC'));
        $this->bandwidth()->sample();
        $this->travelTo(Carbon::parse('2026-10-01 00:10:00', 'UTC'));

        $this->bandwidth()->sample();

        $this->assertSame(['month' => '2026-10', 'used_bytes' => 0], array_slice(ProjectBandwidth::all()['shop'], 0, 2));
    }

    public function test_a_deleted_project_loses_its_measurement(): void
    {
        $this->project('shop', ['example.com']);
        $blog = $this->project('blog');
        $this->bandwidth()->sample();
        $blog->delete();

        $this->bandwidth()->sample();

        $this->assertSame(['shop'], array_keys(ProjectBandwidth::all()));
    }

    public function test_listing_projects_reads_the_measurements_once_and_never_the_statistics(): void
    {
        $this->project('shop', [], ['bandwidth_limit' => 1024]);
        $this->project('blog', [], ['bandwidth_limit' => -1]);
        $this->project('fresh');
        foreach (['shop' => 5000, 'blog' => 7] as $username => $bytes) {
            DB::table('project_bandwidth_usage')->insert([
                'username' => $username, 'month' => '2026-10', 'used_bytes' => $bytes, 'measured_at' => '2026-10-14 09:00:00',
            ]);
        }
        $this->mock(Statistics::class)->shouldNotReceive('projectCalendarMonthBytes', 'projectBandwidth', 'domainBandwidth');
        $token = Admin::rootAccount()->createToken('admin', ['*'])->plainTextToken;
        DB::enableQueryLog();

        $projects = collect($this->withToken($token)->getJson('/api/projects/all')->assertOk()->json('data'))->keyBy('username');

        $this->assertSame([
            'month' => '2026-10',
            'used_bytes' => 5000,
            'limit_bytes' => 1024 * 1024 * 1024,
            'measured_at' => '2026-10-14T09:00:00Z',
        ], $projects['shop']['bandwidth']);
        $this->assertSame(-1, $projects['blog']['bandwidth']['limit_bytes'], '-1 is unlimited, never scaled');
        $this->assertNull($projects['fresh']['bandwidth'], 'not measured yet');
        $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'project_bandwidth_usage'));
        $this->assertCount(1, $reads);
    }

    public function test_an_engine_not_migrated_yet_still_serves_its_projects(): void
    {
        $this->project('shop');
        DB::statement('DROP TABLE project_bandwidth_usage');
        $token = Admin::rootAccount()->createToken('admin', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/projects/all')->assertOk()->assertJsonPath('data.0.bandwidth', null);
    }

    private function statistics(): Statistics
    {
        return new Awstats($this->dataDir);
    }

    private function bandwidth(): ProjectBandwidth
    {
        return new ProjectBandwidth($this->statistics());
    }

    /**
     * @param list<string> $domains
     * @param array<string, mixed> $details
     */
    private function project(string $username, array $domains = [], array $details = []): User
    {
        $user = new User();
        $user->forceFill([
            'username' => $username,
            'domain' => $username . '.example.net',
            'details' => $details,
            'status' => 'active',
        ])->save();
        foreach ($domains as $domain) {
            Domain::query()->create(['user_id' => $user->id, 'domain' => $domain, 'type' => 'addon']);
        }

        return $user;
    }
}
