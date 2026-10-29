<?php

namespace Tests\Unit\Host;

use App\Lib\Host\ProjectDisk;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Disk use per project, measured in the background and read from the database. */
class ProjectDiskTest extends TestCase
{
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
    }

    public function test_the_host_script_is_valid_bash_and_quotes_every_project(): void
    {
        $script = ProjectDisk::script(['shop' => '/home/shop', "o'brien" => '/home/o\'brien/']);
        $path = (string) tempnam(sys_get_temp_dir(), 'disk-');
        file_put_contents($path, $script);
        exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        @unlink($path);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString("set -- 'shop' '/home/shop' 'o'\\''brien' '/home/o'\\''brien' ", $script);
    }

    /** The script itself, on real directories: files and ~/docker apart, a missing home skipped. */
    public function test_the_host_script_measures_files_and_docker_apart(): void
    {
        exec('du --version 2>/dev/null', $version);
        if (!str_contains(implode("\n", $version), 'GNU')) {
            $this->markTestSkipped('needs GNU du, as on the host');
        }

        $home = sys_get_temp_dir() . '/disk-home-' . bin2hex(random_bytes(4));
        mkdir($home . '/docker/volumes', 0777, true);
        file_put_contents($home . '/index.php', str_repeat('a', 300_000));
        file_put_contents($home . '/docker/volumes/db', str_repeat('b', 2_000_000));

        try {
            $out = shell_exec('bash -c ' . escapeshellarg(ProjectDisk::script(['shop' => $home, 'gone' => $home . '-missing'])));
            $read = ProjectDisk::parse((string) $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($home));
        }

        $this->assertSame(['shop'], array_keys($read));
        $this->assertGreaterThanOrEqual(300_000, $read['shop']['files_bytes']);
        $this->assertLessThan(2_000_000, $read['shop']['files_bytes'], '~/docker is not counted as files');
        $this->assertGreaterThanOrEqual(2_000_000, $read['shop']['docker_bytes']);
    }

    public function test_parse_keeps_what_it_can_read(): void
    {
        $read = ProjectDisk::parse("D\tshop\t1000\t5000\nD\tstatic\t200\t\nD\tbroken\t\t\nnoise\nD\t\t1\t1\n");

        $this->assertSame([
            'shop' => ['files_bytes' => 1000, 'docker_bytes' => 5000],
            'static' => ['files_bytes' => 200, 'docker_bytes' => null],
        ], $read);
    }

    public function test_sample_stores_known_projects_and_latest_reads_the_newest(): void
    {
        $this->project('shop');
        $files = 1000;
        $disk = new ProjectDisk(function (string $script) use (&$files): string {
            return "D\tshop\t{$files}\t5000\nD\tstranger\t1\t1\n";
        });

        $this->assertNull(ProjectDisk::latest('shop'));
        $this->assertSame(1, $disk->sample(Carbon::parse('2026-10-07 09:00:00', 'UTC')));
        $files = 3000;
        $disk->sample(Carbon::parse('2026-10-07 09:15:00', 'UTC'));

        $this->assertSame(0, DB::table('project_disk_usage')->where('username', 'stranger')->count());
        $this->assertSame([
            'files_bytes' => 3000,
            'docker_bytes' => 5000,
            'total_bytes' => 8000,
            'measured_at' => '2026-10-07T09:15:00Z',
        ], ProjectDisk::latest('shop'));
    }

    public function test_a_failed_host_run_writes_nothing(): void
    {
        $this->project('shop');
        $disk = new ProjectDisk(fn (string $script): string => throw new \RuntimeException('nsenter failed'));

        $this->assertSame(0, $disk->sample());
        $this->assertSame(0, DB::table('project_disk_usage')->count());
    }

    public function test_every_project_carries_its_disk_use_without_running_du(): void
    {
        $this->project('shop', ['disk_space_limit' => 2048]);
        $this->project('blog', ['disk_space_limit' => -1]);
        $this->project('fresh');
        foreach (['shop' => [1000, 5000], 'blog' => [7, null]] as $username => [$files, $docker]) {
            DB::table('project_disk_usage')->insert([
                'timestamp' => '2026-10-07 09:15:00', 'username' => $username, 'files_bytes' => $files, 'docker_bytes' => $docker,
            ]);
        }
        $token = Admin::rootAccount()->createToken('admin', ['*'])->plainTextToken;

        $projects = collect($this->withToken($token)->getJson('/api/projects/all')->assertOk()->json('data'))->keyBy('username');

        $this->assertSame([
            'files_bytes' => 1000,
            'docker_bytes' => 5000,
            'total_bytes' => 6000,
            'limit_bytes' => 2048 * 1024 * 1024,
            'measured_at' => '2026-10-07T09:15:00Z',
        ], $projects['shop']['disk']);
        $this->assertSame(-1, $projects['blog']['disk']['limit_bytes'], '-1 is unlimited, never scaled');
        $this->assertNull($projects['blog']['disk']['docker_bytes']);
        $this->assertSame(7, $projects['blog']['disk']['total_bytes']);
        $this->assertNull($projects['fresh']['disk'], 'not measured yet');
    }

    public function test_an_engine_not_migrated_yet_still_serves_its_projects(): void
    {
        $this->project('shop');
        DB::statement('DROP TABLE project_disk_usage');
        $token = Admin::rootAccount()->createToken('admin', ['*'])->plainTextToken;

        $this->assertNull(ProjectDisk::latest('shop'));
        $this->withToken($token)->getJson('/api/projects/all')->assertOk()->assertJsonPath('data.0.disk', null);
    }

    public function test_prune_drops_measurements_past_the_retention(): void
    {
        foreach (['2026-08-01 00:00:00', '2026-10-06 00:00:00'] as $at) {
            DB::table('project_disk_usage')->insert(['timestamp' => $at, 'username' => 'shop', 'files_bytes' => 1, 'docker_bytes' => 1]);
        }
        $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'UTC'));

        $this->artisan('metrics:prune')->assertSuccessful();

        $this->assertSame(['2026-10-06 00:00:00'], DB::table('project_disk_usage')->pluck('timestamp')->all());
    }

    /** @param array<string, mixed> $details */
    private function project(string $username, array $details = []): User
    {
        $user = new User();
        $user->forceFill([
            'username' => $username,
            'domain' => $username . '.example.com',
            'details' => $details,
            'status' => 'active',
        ])->save();

        return $user;
    }
}
