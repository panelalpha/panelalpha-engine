<?php

namespace Tests\Unit\Cron;

use App\Exceptions\ProblemException;
use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Cron;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A classic project's crontab is written by its first deploy. Before that
 * deploy ran, the list read the missing file with `sudo cp` and answered 500.
 */
class CronBeforeFirstDeployTest extends TestCase
{
    private const JOB = [
        'command' => 'echo mcp',
        'minute' => '*',
        'hour' => '*',
        'day_of_month' => '*',
        'month' => '*',
        'day_of_week' => '*',
    ];

    private string $root;

    private bool $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-cron-first-deploy-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/engine/users/alice', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        if ($this->usesDatabase) {
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    public function test_a_project_without_a_crontab_lists_no_jobs_and_reads_nothing(): void
    {
        $system = $this->system();

        $cron = $system->project($this->user())->cron();

        $this->assertSame([], $cron->list());
        $this->assertFalse($cron->exists('abc'));
        $this->assertSame([], $system->reads);
    }

    public function test_creating_a_job_before_the_first_deploy_is_a_conflict_that_writes_nothing(): void
    {
        $system = $this->system();

        try {
            $system->project($this->user())->cron()->create(self::JOB);
            $this->fail('expected the create to be refused');
        } catch (ProblemException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('project_not_deployed', $e->problems[0]['code']);
            $this->assertSame('project', $e->problems[0]['field']);
        }

        $this->assertSame([], $system->calls);
        $this->assertFileDoesNotExist($this->crontab());
    }

    public function test_a_deployed_project_that_lost_its_crontab_gets_it_back_owned_by_the_account(): void
    {
        file_put_contents($this->root . '/engine/users/alice/docker-compose.yml', '');
        mkdir(dirname($this->crontab()));
        $system = $this->system();
        $user = $this->user();
        $user->setDetails(['UID' => 1500, 'GID' => 1500]);

        $created = $system->project($user)->cron()->create(self::JOB);

        $this->assertSame([
            ['sudo', 'touch', $this->crontab()],
            ['sudo', 'chown', '1500:1500', $this->crontab()],
            ['sudo', 'chmod', '600', $this->crontab()],
        ], $system->calls);
        $this->assertSame([$created], $system->project($user)->cron()->list());
    }

    public function test_the_api_lists_none_refuses_a_create_and_finds_no_job_to_change(): void
    {
        $this->withDatabase();
        $this->user()->save();

        $this->getJson('/api/projects/alice/cron-jobs')
            ->assertOk()
            ->assertExactJson(['data' => []]);
        $this->postJson('/api/projects/alice/cron-jobs', self::JOB)
            ->assertStatus(409)
            ->assertJsonPath('problems.0.code', 'project_not_deployed');
        $this->putJson('/api/projects/alice/cron-jobs/abc', self::JOB)->assertNotFound();
        $this->deleteJson('/api/projects/alice/cron-jobs/abc')->assertNotFound();
    }

    private function withDatabase(): void
    {
        $this->usesDatabase = true;
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();
        $this->withoutMiddleware(Authenticate::class);
    }

    private function crontab(): string
    {
        return $this->root . '/engine/users/alice/crontabs/www-data';
    }

    private function user(): User
    {
        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.test';
        $user->password = 'secret';
        $user->setDetails(['template' => 'default']);

        return $user;
    }

    /**
     * The engine dir under a temp root; `sudo touch` creates the file, and a
     * read through the filesystem is recorded, as `sudo cp` would run it.
     */
    private function system(): System
    {
        return new class ($this->root) extends System {
            /** @var list<string|array<int, string>> */
            public array $calls = [];

            /** @var list<string> */
            public array $reads = [];

            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root . '/engine';
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->calls[] = $cmd;
                if (is_array($cmd) && $cmd[1] === 'touch') {
                    touch($cmd[2]);
                }

                return '';
            }

            public function filesystem(): Filesystem
            {
                $system = $this;

                return new class ($system) extends Filesystem {
                    public function __construct(private System $recorder)
                    {
                        parent::__construct($recorder);
                    }

                    public function fileGetContents(string $path): string
                    {
                        $this->recorder->reads[] = $path;

                        return (string) file_get_contents($path);
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        file_put_contents($path, $contents);
                    }
                };
            }
        };
    }
}
