<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\ProjectCache;
use PHPUnit\Framework\TestCase;

class ProjectCacheTest extends TestCase
{
    public function test_every_cache_lives_under_the_projects_own_directory(): void
    {
        $this->assertSame('/var/cache/panelalpha/projects/alice', ProjectCache::dirFor('alice'));

        foreach (ProjectCache::NAMES as $name) {
            $this->assertSame(
                '/var/cache/panelalpha/projects/alice/' . $name,
                ProjectCache::subdirFor('alice', $name)
            );
        }
    }

    public function test_projects_do_not_share_a_directory(): void
    {
        $this->assertNotSame(ProjectCache::dirFor('alice'), ProjectCache::dirFor('bob'));
        $this->assertStringStartsNotWith(ProjectCache::dirFor('alice'), ProjectCache::dirFor('bob'));
    }

    public function test_a_username_that_is_not_one_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProjectCache::dirFor('../../etc');
    }







    public function test_prune_invokes_the_script_in_the_hosts_namespace(): void
    {
        $argv = ProjectCache::pruneArgv(86400);

        $this->assertSame(
            ['sudo', 'nsenter', '--target', '1', '--all', 'sh', ProjectCache::PRUNE_SCRIPT, '86400'],
            $argv
        );
    }

    public function test_prune_passes_dry_run_and_the_skip_list_through(): void
    {
        $argv = ProjectCache::pruneArgv(3600, true, ['alice', 'bob']);

        $this->assertContains('--dry-run', $argv);
        $this->assertContains('--skip', $argv);
        $this->assertContains('alice,bob', $argv);
    }

    public function test_prune_refuses_an_unsafe_name_to_skip(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProjectCache::pruneArgv(86400, false, ['../../etc']);
    }

    public function test_prune_refuses_a_negative_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProjectCache::pruneArgv(-1);
    }

    public function test_the_scripts_report_is_parsed_and_junk_dropped(): void
    {
        $rows = ProjectCache::parseReport(
            "/var/cache/panelalpha/projects/alice\t227328000\t90000\tdeleted\n"
            . "rubbish\n"
            . "/var/cache/pa-composer/bob\tnotanumber\t1\tdeleted\n"
            . "/var/cache/panelalpha/js/carol\t65536\t172800\twould-delete\n"
        );

        $this->assertSame(
            [
                ['path' => '/var/cache/panelalpha/projects/alice', 'bytes' => 227328000, 'age' => 90000, 'action' => 'deleted'],
                ['path' => '/var/cache/panelalpha/js/carol', 'bytes' => 65536, 'age' => 172800, 'action' => 'would-delete'],
            ],
            $rows
        );
    }

    public function test_durations_are_parsed(): void
    {
        $this->assertSame(86400, ProjectCache::parseDuration('24h'));
        $this->assertSame(86400, ProjectCache::parseDuration('1d'));
        $this->assertSame(604800, ProjectCache::parseDuration('7d'));
        $this->assertSame(5400, ProjectCache::parseDuration('90m'));
        $this->assertSame(3600, ProjectCache::parseDuration('3600'));
        $this->assertNull(ProjectCache::parseDuration(null));
        $this->assertNull(ProjectCache::parseDuration('  '));
    }

    public function test_an_unparseable_duration_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProjectCache::parseDuration('soon');
    }

    public function test_prepare_never_names_another_projects_directory(): void
    {
        $script = ProjectCache::prepareScript('alice', '1004:1004');

        $this->assertStringNotContainsString(ProjectCache::dirFor('bob'), $script);
        // The shared root as an argument of its own would chown every project.
        $this->assertStringNotContainsString("'" . ProjectCache::ROOT . "'", $script);
    }
}
