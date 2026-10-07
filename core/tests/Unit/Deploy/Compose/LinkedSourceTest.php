<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\LinkedSource;
use PHPUnit\Framework\TestCase;

/**
 * A committed symlink as a bind source: the account's dockerd follows it, so
 * where it leads is what gets mounted, whatever the source's text says.
 */
class LinkedSourceTest extends TestCase
{
    private string $root;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/linked-source-' . bin2hex(random_bytes(4));
        $home = $this->root . '/acct';
        $this->project = $home . '/project';
        foreach (['project/real', 'project/dir', '.panelalpha/deep', 'docker/sub'] as $dir) {
            mkdir($home . '/' . $dir, 0755, true);
        }
        $links = [
            'project/data' => '/var/run',
            'project/ok' => 'real',
            'project/pa' => '../.panelalpha/deep',
            'project/abs' => '/home/acct/.panelalpha/deep',
            'project/other' => '/home/other/x',
            'project/home' => '..',
            'project/dk' => '../docker',
            'project/dksub' => '../docker/sub',
            'project/dir/etc' => '/etc',
            'project/hop' => 'dir/../data',
            'project/loop1' => 'loop2',
            'project/loop2' => 'loop1',
            '.panelalpha/deep/back' => '../../project/data',
        ];
        foreach ($links as $link => $target) {
            symlink($target, $home . '/' . $link);
        }
    }

    protected function tearDown(): void
    {
        @chmod($this->project . '/locked', 0755);
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_link_out_of_the_home_is_refused_wherever_it_sits_in_the_path(): void
    {
        foreach (['./data', 'data', './data/docker.sock', './dir/etc/passwd', './other', './hop', './pa/back'] as $source) {
            $this->assertTrue(LinkedSource::escapes($source, $this->project), $source);
        }
    }

    public function test_a_link_that_stays_in_the_project_or_panelalpha_is_kept(): void
    {
        foreach (['./ok', './ok/file', './pa', './pa/new/dir', './abs', './real', './dir'] as $source) {
            $this->assertFalse(LinkedSource::escapes($source, $this->project), $source);
        }
    }

    public function test_a_link_to_the_rest_of_the_home_is_refused(): void
    {
        // The home holds the inner daemon's data-root; a link is not how a
        // project reaches it, even where `../` written out is allowed.
        foreach (['./home', './home/docker', './dk', './dksub'] as $source) {
            $this->assertTrue(LinkedSource::escapes($source, $this->project), $source);
        }
    }

    public function test_dot_dot_after_a_link_climbs_from_its_target(): void
    {
        // Kernel order: dksub is ~/docker/sub, so `dksub/..` is ~/docker, not ~/project.
        $this->assertTrue(LinkedSource::escapes('./dksub/..', $this->project));
        $this->assertTrue(LinkedSource::escapes('./dksub/../x', $this->project));
        // And back into the project is where it ends, link or not.
        $this->assertFalse(LinkedSource::escapes('./dksub/../../project/real', $this->project));
    }

    public function test_a_path_that_does_not_exist_yet_is_taken_as_written(): void
    {
        $this->assertFalse(LinkedSource::escapes('./nothing/yet', $this->project));
        $this->assertFalse(LinkedSource::escapes('../.panelalpha/new', $this->project));
        // Back out of the missing part and the real tree is read again.
        $this->assertTrue(LinkedSource::escapes('./nothing/../data', $this->project));
    }

    public function test_text_alone_decides_when_no_link_is_followed(): void
    {
        $this->assertFalse(LinkedSource::escapes('../', $this->project));
        $this->assertFalse(LinkedSource::escapes('../docker', $this->project));
        // Above the home is outside the account's tree altogether.
        $this->assertTrue(LinkedSource::escapes('../../etc', $this->project));
    }

    public function test_absolute_sources_in_the_home_are_followed_too(): void
    {
        $this->assertTrue(LinkedSource::escapes('/home/acct/project/data', $this->project));
        $this->assertTrue(LinkedSource::escapes('/home/acct/.panelalpha/deep/back', $this->project));
        $this->assertFalse(LinkedSource::escapes('/home/acct/.panelalpha/deep', $this->project));
        $this->assertFalse(LinkedSource::escapes('/srv/data', $this->project));
        // `..` in an absolute path cannot be walked from core's side of it.
        $this->assertTrue(LinkedSource::escapes('/home/other/../acct/project/data', $this->project));
    }

    public function test_a_link_loop_is_refused(): void
    {
        $this->assertTrue(LinkedSource::escapes('./loop1', $this->project));
    }

    public function test_a_directory_that_cannot_be_searched_is_refused(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root searches every directory');
        }
        mkdir($this->project . '/locked', 0000);

        $this->assertFalse(LinkedSource::escapes('./locked', $this->project));
        $this->assertTrue(LinkedSource::escapes('./locked/x', $this->project));
    }
}
