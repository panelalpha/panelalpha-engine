<?php

namespace Tests\Unit\Console;

use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use App\Lib\Limits\ResourceLimit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `project:limit:set` / `:get` drive the same ResourceLimit table the model
 * stores against; these pin the behaviour the three hand-written copies had.
 */
class ProjectLimitCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_set_requires_a_project_or_all(): void
    {
        $this->artisan('project:limit:set --memory-limit=512')
            ->expectsOutputToContain('One of following options is required')
            ->assertExitCode(1);
    }

    public function test_set_requires_at_least_one_limit_and_names_them_all(): void
    {
        $this->makeUser('alice');

        $this->assertSame(1, Artisan::call('project:limit:set', ['--project' => 'alice']));

        $output = Artisan::output();
        $this->assertStringContainsString('At least one of following options is required', $output);
        foreach (ResourceLimit::all() as $limit) {
            $this->assertStringContainsString("`--{$limit->option}={$limit->argument}`", $output);
        }
    }

    public function test_set_rejects_an_unknown_project(): void
    {
        $this->artisan('project:limit:set --project=nobody --memory-limit=512')
            ->expectsOutputToContain('Invalid username')
            ->assertExitCode(1);
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $this->makeUser('alice', ['memory_limit' => 256]);

        $this->artisan('project:limit:set --project=alice --memory-limit=512')
            ->expectsConfirmation('Do you wish to continue?', 'no')
            ->assertExitCode(0);

        $this->assertSame(256, User::findByUsername('alice')->getMemoryLimit());
    }

    public function test_set_writes_every_selected_limit(): void
    {
        $this->makeUser('alice');

        $this->artisan(
            'project:limit:set --project=alice --memory-limit=512 --cpu-limit=1.5'
            . ' --disk-space-limit=1024 --inodes-limit=900 --mysql-databases-limit=5'
        )
            ->expectsConfirmation('Do you wish to continue?', 'yes')
            ->expectsOutputToContain('Limits have been updated.')
            ->assertExitCode(0);

        $user = User::findByUsername('alice');
        $this->assertSame(512, $user->getMemoryLimit());
        $this->assertSame(1.5, $user->getCpuLimit());
        $this->assertSame(1024, $user->getDiskSpaceLimit());
        $this->assertSame(900, $user->getInodesLimit());
        $this->assertSame(5, $user->getMysqlDatabasesLimit());
    }

    public function test_an_unmentioned_limit_is_left_alone(): void
    {
        $this->makeUser('alice', ['memory_limit' => 256, 'inodes_limit' => 900]);

        $this->artisan('project:limit:set --project=alice --memory-limit=512')
            ->expectsConfirmation('Do you wish to continue?', 'yes')
            ->assertExitCode(0);

        $user = User::findByUsername('alice');
        $this->assertSame(512, $user->getMemoryLimit());
        $this->assertSame(900, $user->getInodesLimit(), 'inodes was not mentioned and must not move');
    }

    public function test_minus_one_clears_a_limit_but_disk_space_keeps_it(): void
    {
        $this->makeUser('alice', ['bandwidth_limit' => 50, 'disk_space_limit' => 1024]);

        $this->artisan('project:limit:set --project=alice --bandwidth-limit=-1 --disk-space-limit=-1')
            ->expectsConfirmation('Do you wish to continue?', 'yes')
            ->assertExitCode(0);

        $user = User::findByUsername('alice');
        $this->assertNull($user->getBandwidthLimit());
        $this->assertSame(-1, $user->getDiskSpaceLimit());
    }

    /** Every project has a memory limit, so -1 is refused rather than stored. */
    public function test_memory_cannot_be_cleared(): void
    {
        $this->makeUser('alice', ['memory_limit' => 256]);

        $this->assertSame(1, Artisan::call('project:limit:set', [
            '--project' => 'alice',
            '--memory-limit' => '-1',
        ]));
        $this->assertStringContainsString(
            'The memory limit is in MB and must be a positive number.',
            Artisan::output()
        );
        $this->assertSame(256, User::findByUsername('alice')->getMemoryLimit());
    }

    /** The ceiling is the host's RAM less the engine's share; what is free does not matter. */
    public function test_memory_above_the_host_ceiling_is_refused(): void
    {
        HostMemoryProbe::fake(new HostMemory(3809));
        $this->makeUser('alice', ['memory_limit' => 256]);

        try {
            $this->assertSame(1, Artisan::call('project:limit:set', ['--project' => 'alice', '--memory-limit' => '3298']));
            $this->assertStringContainsString('greater than 3297 MB', Artisan::output());
            $this->assertSame(256, User::findByUsername('alice')->getMemoryLimit());
        } finally {
            HostMemoryProbe::fake(null);
        }
    }

    /** An unset memory limit reads back as the default the account runs with. */
    public function test_get_reports_the_default_for_an_unset_memory_limit(): void
    {
        $this->makeUser('alice');

        $this->assertSame(0, Artisan::call('project:limit:get', ['--project' => 'alice']));

        $this->assertStringContainsString(
            '  memory_limit:     not set, runs with the default (',
            Artisan::output()
        );
    }

    public function test_a_baked_in_limit_warns_about_the_rebuild(): void
    {
        $this->makeUser('alice');

        $this->artisan('project:limit:set --project=alice --inodes-limit=900')
            ->expectsConfirmation('Do you wish to continue?', 'yes')
            ->expectsOutputToContain('Changes will take effect after rebuild')
            ->assertExitCode(0);
    }

    public function test_a_limit_the_runtime_does_not_bake_in_does_not_warn(): void
    {
        $this->makeUser('alice');

        $this->artisan('project:limit:set --project=alice --bandwidth-limit=50')
            ->expectsConfirmation('Do you wish to continue?', 'yes')
            ->doesntExpectOutputToContain('Changes will take effect after rebuild')
            ->assertExitCode(0);
    }

    public function test_all_applies_to_every_project(): void
    {
        $this->makeUser('alice');
        $this->makeUser('bob');

        $this->artisan('project:limit:set --all --memory-limit=512')
            ->expectsConfirmation('Do you wish to continue?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(512, User::findByUsername('alice')->getMemoryLimit());
        $this->assertSame(512, User::findByUsername('bob')->getMemoryLimit());
    }

    public function test_get_prints_every_limit_with_its_unit(): void
    {
        $this->makeUser('alice', [
            'disk_space_limit' => 1024,
            'memory_limit' => 512,
            'cpu_limit' => 1.5,
            'device_read_bps' => 1000,
            'mysql_databases_limit' => 5,
        ]);

        $this->assertSame(0, Artisan::call('project:limit:get', ['--project' => 'alice']));

        $output = Artisan::output();
        $this->assertStringContainsString('User `alice`:', $output);
        // The labels exactly as this command has always printed them.
        $this->assertStringContainsString("  disk_space_limit: 1024 MB\n", $output);
        $this->assertStringContainsString("  memory_limit:     512 MB\n", $output);
        $this->assertStringContainsString("  cpu_limit:        1.5 CPUs\n", $output);
        $this->assertStringContainsString("  device_read_bps:  1000 bps\n", $output);
        $this->assertStringContainsString("  device_write_bps: no limit\n", $output);
        $this->assertStringContainsString("  bandwidth_limit:  no limit\n", $output);
        $this->assertStringContainsString("  mysql_databases_limit: 5\n", $output);
        $this->assertStringContainsString("  ftp_accounts_limit: no limit\n", $output);
        $this->assertStringContainsString("  sftp_accounts_limit: no limit\n", $output);
        $this->assertStringContainsString("  addon_domains_limit: no limit\n", $output);
        $this->assertStringContainsString("  subdomains_limit: no limit\n", $output);
        $this->assertStringContainsString("  inodes_limit:    no limit\n", $output);

        foreach (ResourceLimit::all() as $limit) {
            $this->assertStringContainsString($limit->key . ':', $output);
        }
    }

    /** Only disk space stores -1 for "no limit"; anywhere else a -1 reads back as stored. */
    public function test_get_prints_a_stored_minus_one_as_stored_outside_disk_space(): void
    {
        $this->makeUser('alice', ['disk_space_limit' => -1, 'cpu_limit' => -1]);

        $this->assertSame(0, Artisan::call('project:limit:get', ['--project' => 'alice']));

        $output = Artisan::output();
        $this->assertStringContainsString("  disk_space_limit: no limit\n", $output);
        $this->assertStringContainsString("  cpu_limit:        -1 CPUs\n", $output);
    }

    public function test_set_checks_its_options_before_it_looks_the_project_up(): void
    {
        $this->artisan('project:limit:set --project=nobody')
            ->expectsOutputToContain('At least one of following options is required')
            ->doesntExpectOutputToContain('Invalid username')
            ->assertExitCode(1);

        $this->artisan('project:limit:set --project=nobody --memory-limit=0')
            ->expectsOutputToContain('The memory limit is in MB and must be a positive number.')
            ->doesntExpectOutputToContain('Invalid username')
            ->assertExitCode(1);
    }

    public function test_set_announces_with_the_padding_it_always_used(): void
    {
        $this->makeUser('alice');

        $this->artisan('project:limit:set --project=alice --inodes-limit=900')
            ->expectsOutput('Following limits will be set for user `alice`:')
            ->expectsOutput('  inodes_limit:     900')
            ->expectsConfirmation('Do you wish to continue?', 'no')
            ->assertExitCode(0);

        $this->artisan('project:limit:set --all --inodes-limit=900')
            ->expectsOutput('Following limits will be set for all (1) users:')
            ->expectsOutput('  inodes_limit: 900')
            ->expectsConfirmation('Do you wish to continue?', 'no')
            ->assertExitCode(0);
    }

    public function test_set_on_an_empty_fleet_asks_for_no_rebuild(): void
    {
        $this->artisan('project:limit:set --all --inodes-limit=900')
            ->expectsConfirmation('Do you wish to continue?', 'yes')
            ->expectsOutputToContain('Limits have been updated.')
            ->doesntExpectOutputToContain('Changes will take effect after rebuild')
            ->assertExitCode(0);
    }

    public function test_get_round_trips_what_set_wrote(): void
    {
        $this->makeUser('alice');

        $this->artisan('project:limit:set --project=alice --cpu-limit=2.5 --subdomains-limit=7')
            ->expectsConfirmation('Do you wish to continue?', 'yes')
            ->assertExitCode(0);

        $this->artisan('project:limit:get --project=alice')
            ->expectsOutputToContain('2.5 CPUs')
            ->expectsOutputToContain('subdomains_limit:')
            ->assertExitCode(0);
    }
}
