<?php

namespace Tests\Unit\Hub;

use App\Jobs\DeployProject;
use App\Lib\Host\ProjectStats;
use App\Lib\Project\ProjectOverview;
use App\Models\Task;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The project list Connect polls every minute reads its `app` and `usage` blocks once per table, not per project. */
class ProjectListQueriesTest extends HubTestCase
{
    private const PER_PROJECT_TABLES = ['tasks', 'tunnels', 'domains', 'project_metrics'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_app_and_usage_blocks_cost_the_same_queries_for_two_projects_or_six(): void
    {
        $this->assertSame($this->listQueries(2), $this->listQueries(6));
    }

    public function test_each_project_still_gets_its_own_app_and_usage(): void
    {
        Carbon::setTestNow('2026-10-26 12:00:00');
        ProjectOverview::fakeRunning(['shop', 'blog', 'docs']);
        $shop = $this->project('shop', ['deploy_strategy' => 'php', 'deployment_status' => 'success']);
        $blog = $this->project('blog', ['deploy_strategy' => 'php', 'deployment_status' => 'success']);
        $this->project('docs', ['deploy_strategy' => 'php', 'deployment_status' => 'success']);
        $this->project('queued', ['deploy_strategy' => 'php', 'deployment_status' => 'success']);
        $domain = $this->domain($shop->id, 'shop.example.com');
        $this->domain($blog->id, 'blog.example.com', ['ssl_disabled' => true]);
        DB::table('tunnels')->insert(['domain_id' => $domain, 'user_id' => $shop->id, 'provider' => 'panelalpha', 'hostname' => 'shop-a1.panelalpha.online']);
        $this->queuedDeploy('queued');
        $this->metrics('shop', '-90 seconds', 4.0);
        $this->metrics('shop', '-30 seconds', 6.5);
        $this->metrics('blog', '-10 minutes', 9.0);

        $projects = collect($this->withToken($this->adminToken())->getJson('/api/projects/all')->assertOk()->json('data'))->keyBy('username');

        $this->assertSame('https://shop-a1.panelalpha.online', $projects['shop']['app']['public_url']);
        $this->assertSame('http://blog.example.com', $projects['blog']['app']['public_url']);
        $this->assertSame('https://docs.example.com', $projects['docs']['app']['public_url']);
        $this->assertSame('building', $projects['queued']['app']['status']);
        $this->assertSame('live', $projects['shop']['app']['status']);
        $this->assertSame(6.5, $projects['shop']['usage']['cpu_percent']);
        $this->assertNull($projects['blog']['usage'], 'stale samples are not "now"');

        $single = $this->withToken($this->adminToken())->getJson('/api/projects/shop')->assertOk()->json('data');
        $this->assertSame($projects['shop']['app'], $single['app']);
        $this->assertSame($projects['shop']['usage'], $single['usage']);
    }

    public function test_latest_of_takes_each_projects_newest_recent_sample(): void
    {
        $now = Carbon::parse('2026-10-26 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $this->metrics('shop', '-2 minutes', 1.0);
        $this->metrics('shop', '-1 minute', 2.0);
        $this->metrics('blog', '-6 minutes', 3.0);

        $latest = ProjectStats::latestOf(['shop', 'blog', 'none'], $now);

        $this->assertSame(['shop'], array_keys($latest));
        $this->assertSame(2.0, $latest['shop']['cpu_percent']);
        $this->assertSame([], ProjectStats::latestOf([], $now));
    }

    /** @return array<string, int> queries per table while listing $count projects */
    private function listQueries(int $count): array
    {
        foreach (['tunnels', 'domains', 'project_metrics', 'users'] as $table) {
            DB::table($table)->delete();
        }
        foreach (range(1, $count) as $n) {
            $user = $this->project("p{$n}", ['deploy_strategy' => 'php', 'deployment_status' => 'success']);
            $domain = $this->domain($user->id, "p{$n}.example.com");
            DB::table('tunnels')->insert(['domain_id' => $domain, 'user_id' => $user->id, 'provider' => 'panelalpha', 'hostname' => "p{$n}-{$count}.panelalpha.online"]);
            $this->metrics("p{$n}", '-30 seconds', 1.0);
        }
        $token = $this->adminToken();

        $queries = array_fill_keys(self::PER_PROJECT_TABLES, 0);
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            foreach (self::PER_PROJECT_TABLES as $table) {
                if (preg_match('/\bfrom "'.$table.'"/', $query->sql) === 1) {
                    $queries[$table]++;
                }
            }
        });
        $this->withToken($token)->getJson('/api/projects/all')->assertOk();
        DB::getEventDispatcher()?->forget(QueryExecuted::class);

        return $queries;
    }

    /** @param array<string, mixed> $details */
    private function domain(int $userId, string $name, array $details = []): int
    {
        return (int) DB::table('domains')->insertGetId(['user_id' => $userId, 'domain' => $name, 'type' => 'main', 'details' => json_encode($details)]);
    }

    private function queuedDeploy(string $username): void
    {
        DB::table('tasks')->insert([
            'username' => $username,
            'queue' => 'default',
            'job_type' => DeployProject::class,
            'status' => Task::STATUS_QUEUED,
            'queued_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    private function metrics(string $username, string $ago, float $cpu): void
    {
        DB::table('project_metrics')->insert([
            'timestamp' => Carbon::now('UTC')->modify($ago)->format('Y-m-d H:i:s'),
            'username' => $username,
            'cpu_percent' => $cpu,
            'memory_bytes' => 1000,
            'memory_limit_bytes' => 4000,
        ]);
    }
}
