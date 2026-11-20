<?php

namespace Tests\Integration\Usage;

use App\Integrations\Statistics\Statistics;
use App\Lib\Usage\ProjectUsage;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\FileManager;
use App\System\Project\Runtime;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** The usage summary: disk from the background measurement or `du`, logs only for DinD, counts beside limits. */
class ProjectUsageSummaryTest extends TestCase
{
    use InMemoryDatabase;

    private FileManager&MockInterface $files;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
        $this->files = Mockery::mock(FileManager::class);
    }

    public function test_a_measured_dind_project_reads_disk_and_logs_and_counts_only_its_own_rows(): void
    {
        $user = $this->makeUser('shop', [
            'disk_space_limit' => 2048,
            'bandwidth_limit' => 10,
            'addon_domains_limit' => 5,
            'subdomains_limit' => -1,
            'ftp_accounts_limit' => 3,
            'mysql_databases_limit' => 2,
        ]);
        $other = $this->makeUser('blog');
        $this->rows($user, addon: 2, sub: 1, ftp: 3, sftp: 1, mysql: 2);
        $this->rows($other, addon: 1, sub: 2, ftp: 1, sftp: 2, mysql: 1);
        DB::table('project_disk_usage')->insert([
            ['timestamp' => '2026-10-01 00:00:00', 'username' => 'shop', 'files_bytes' => 1, 'docker_bytes' => 1],
            ['timestamp' => '2026-10-02 00:00:00', 'username' => 'shop', 'files_bytes' => 5 * 1048576 + 1, 'docker_bytes' => 999],
        ]);
        $this->files->shouldNotReceive('diskUsage');
        $this->files->shouldReceive('containerLogBytes')->once()->andReturn(4096);

        $summary = $this->usage(['shop-0.example.test', 'shop-1.example.test', 'shop-sub-0.example.test'], 123456)
            ->summary($user, $this->system($user, Mockery::mock(Dind::class)));

        $this->assertSame([
            'storage' => ['usage' => 6, 'maximum' => 2048],
            'logs' => ['usage' => 4096, 'maximum' => null],
            'docker' => ['usage' => 999, 'maximum' => null],
            'bandwidth' => ['usage' => 123456, 'maximum' => 10 * 1024 * 1024],
            'addon_domains' => ['usage' => 2, 'maximum' => 5],
            'subdomains' => ['usage' => 1, 'maximum' => -1],
            'ftp_accounts' => ['usage' => 3, 'maximum' => 3],
            'sftp_accounts' => ['usage' => 1, 'maximum' => null],
            'mysql_databases' => ['usage' => 2, 'maximum' => 2],
        ], $summary);
    }

    public function test_an_unmeasured_project_runs_du_and_another_runtime_has_no_logs(): void
    {
        $user = $this->makeUser('shop', ['bandwidth_limit' => -1]);
        $this->files->shouldReceive('diskUsage')->once()->andReturn(77);
        $this->files->shouldNotReceive('containerLogBytes');

        $summary = $this->usage([], 0)->summary($user, $this->system($user, Mockery::mock(Runtime::class)));

        $this->assertSame([
            'storage' => ['usage' => 77, 'maximum' => null],
            'logs' => ['usage' => 0, 'maximum' => null],
            'docker' => ['usage' => null, 'maximum' => null],
            'bandwidth' => ['usage' => 0, 'maximum' => -1],
            'addon_domains' => ['usage' => 0, 'maximum' => null],
            'subdomains' => ['usage' => 0, 'maximum' => null],
            'ftp_accounts' => ['usage' => 0, 'maximum' => null],
            'sftp_accounts' => ['usage' => 0, 'maximum' => null],
            'mysql_databases' => ['usage' => 0, 'maximum' => null],
        ], $summary);
    }

    public function test_a_measurement_without_files_still_runs_du_and_keeps_its_docker_bytes(): void
    {
        $user = $this->makeUser('shop');
        DB::table('project_disk_usage')->insert(
            ['timestamp' => '2026-10-02 00:00:00', 'username' => 'shop', 'files_bytes' => null, 'docker_bytes' => 5]
        );
        $this->files->shouldReceive('diskUsage')->once()->andReturn(12);
        $this->files->shouldReceive('containerLogBytes')->once()->andReturn(0);

        $summary = $this->usage([], 0)->summary($user, $this->system($user, Mockery::mock(Dind::class)));

        $this->assertSame(['usage' => 12, 'maximum' => null], $summary['storage']);
        $this->assertSame(['usage' => 5, 'maximum' => null], $summary['docker']);
    }

    /** @param list<string> $domains */
    private function usage(array $domains, int $bytes): ProjectUsage
    {
        $statistics = Mockery::mock(Statistics::class);
        $statistics->shouldReceive('projectCalendarMonthBytes')->once()
            ->withArgs(static function (array $names) use ($domains): bool {
                sort($names);

                return $names === $domains;
            })
            ->andReturn($bytes);

        return new ProjectUsage($statistics);
    }

    private function system(User $user, Runtime $runtime): System
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runtime')->andReturn($runtime);
        $project->shouldReceive('fileManager')->andReturn($this->files);

        $system = Mockery::mock(System::class);
        $system->shouldReceive('project')->once()->with($user)->andReturn($project);

        return $system;
    }

    private function rows(User $user, int $addon, int $sub, int $ftp, int $sftp, int $mysql): void
    {
        $name = (string) $user->username;
        $domains = [];
        foreach (['addon' => $addon, 'sub' => $sub] as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $domain = $type === 'addon' ? "{$name}-{$i}.example.test" : "{$name}-sub-{$i}.example.test";
                $domains[] = ['user_id' => $user->id, 'domain' => $domain, 'type' => $type, 'details' => '{}'];
            }
        }
        DB::table('domains')->insert($domains);
        for ($i = 0; $i < $ftp; $i++) {
            DB::table('ftp_accounts')->insert(['user_id' => $user->id, 'user' => "{$name}_ftp{$i}", 'directory' => '/']);
        }
        for ($i = 0; $i < $sftp; $i++) {
            DB::table('sftp_accounts')->insert(['user_id' => $user->id, 'username' => "{$name}_sftp{$i}", 'auth_method' => 'password']);
        }
        for ($i = 0; $i < $mysql; $i++) {
            DB::table('mysql_databases')->insert(['user_id' => $user->id, 'database' => "{$name}_db{$i}"]);
        }
    }
}
