<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\SourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which of the four sources a caller named, and the guards around reading it.
 *
 * classify() is the part worth pinning down: the endpoint takes one string and
 * has to tell a repository URL from a path from a hosting username without
 * asking. Everything it gets wrong is either a clone of something local or a
 * 404 on a project that was really a URL.
 */
class SourceResolverTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/source-resolver-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function sources(): array
    {
        return [
            'https url' => ['https://github.com/owner/repo', SourceResolver::TYPE_GIT],
            'https url with .git' => ['https://github.com/owner/repo.git', SourceResolver::TYPE_GIT],
            'ssh url' => ['ssh://git@github.com/owner/repo.git', SourceResolver::TYPE_GIT],
            'scp syntax' => ['git@github.com:owner/repo.git', SourceResolver::TYPE_GIT],
            'schemeless host' => ['github.com/owner/repo', SourceResolver::TYPE_GIT],
            'absolute path' => ['/home/johndoe/project', SourceResolver::TYPE_PATH],
            'project username' => ['johndoe', SourceResolver::TYPE_PROJECT],
            'relative path' => ['../elsewhere', null],
            'empty' => ['', null],
            'nonsense' => ['not a source', null],
        ];
    }

    #[DataProvider('sources')]
    public function test_it_classifies_a_source(string $source, ?string $expected): void
    {
        $this->assertSame($expected, SourceResolver::classify($source));
    }

    public function test_it_adds_the_scheme_a_pasted_url_left_out(): void
    {
        $this->assertSame('https://github.com/owner/repo', SourceResolver::normaliseGitUrl('github.com/owner/repo'));
        $this->assertSame('https://github.com/owner/repo', SourceResolver::normaliseGitUrl('https://github.com/owner/repo'));
        $this->assertSame('git@github.com:owner/repo.git', SourceResolver::normaliseGitUrl('git@github.com:owner/repo.git'));
    }

    public function test_it_refuses_to_clone_a_scheme_that_is_not_a_remote(): void
    {
        $this->expectException(InspectException::class);
        SourceResolver::assertCloneable('file:///etc');
    }

    public function test_a_subdirectory_may_not_climb_out_of_the_source(): void
    {
        mkdir($this->tmpDir . '/apps/web', 0777, true);

        $this->assertSame(
            realpath($this->tmpDir . '/apps/web'),
            SourceResolver::descend($this->tmpDir, 'apps/web')
        );
        $this->assertSame($this->tmpDir, SourceResolver::descend($this->tmpDir, null));

        $this->expectException(InspectException::class);
        SourceResolver::descend($this->tmpDir, '../..');
    }

    public function test_a_project_directory_is_project_else_the_document_root(): void
    {
        $home = $this->tmpDir . '/home';
        mkdir($home . '/site.test/public_html', 0777, true);

        $this->assertSame(
            realpath($home . '/site.test/public_html'),
            SourceResolver::projectDirectory($home, '/site.test/public_html', null)
        );
        $this->assertSame($home . '/project', SourceResolver::projectDirectory($home, null, null));

        mkdir($home . '/project');
        $this->assertSame(realpath($home . '/project'), SourceResolver::projectDirectory($home, '/site.test/public_html', null));
        $this->assertSame(realpath($home . '/site.test'), SourceResolver::projectDirectory($home, null, $home . '/site.test'));
    }

    public function test_a_document_root_linked_out_of_the_home_is_not_inspected(): void
    {
        $home = $this->tmpDir . '/home';
        mkdir($this->tmpDir . '/elsewhere');
        mkdir($home . '/site.test', 0777, true);
        symlink($this->tmpDir . '/elsewhere', $home . '/site.test/public_html');

        try {
            $this->assertSame($home . '/project', SourceResolver::projectDirectory($home, '/site.test/public_html', null));
        } finally {
            unlink($home . '/site.test/public_html');
        }
    }

    public function test_a_project_directory_linked_out_of_the_home_is_never_read(): void
    {
        $home = $this->tmpDir . '/home';
        mkdir($this->tmpDir . '/elsewhere');
        mkdir($home . '/site.test/public_html', 0777, true);
        symlink($this->tmpDir . '/elsewhere', $home . '/project');

        try {
            // With a document root and without one: refused, never followed and never swapped for another directory.
            foreach (['/site.test/public_html', null] as $documentRoot) {
                try {
                    SourceResolver::projectDirectory($home, $documentRoot, null);
                    $this->fail('~/project linked out of the home was not refused');
                } catch (InspectException $e) {
                    $this->assertSame('The requested directory must stay inside the source.', $e->getMessage());
                }
            }
        } finally {
            unlink($home . '/project');
        }
    }

    public function test_a_directory_swapped_for_a_link_out_since_the_last_look_is_refused(): void
    {
        $home = $this->tmpDir . '/home';
        mkdir($home . '/project', 0777, true);
        mkdir($this->tmpDir . '/elsewhere');
        $this->assertSame(realpath($home . '/project'), SourceResolver::projectDirectory($home, null, null));

        // Swapped by another process, as the account would: PHP's own rmdir()
        // and symlink() would drop the cached entry themselves.
        exec('rmdir ' . escapeshellarg($home . '/project') . ' && ln -s '
            . escapeshellarg($this->tmpDir . '/elsewhere') . ' ' . escapeshellarg($home . '/project'));
        try {
            SourceResolver::projectDirectory($home, null, null);
            $this->fail('the cached directory was trusted');
        } catch (InspectException $e) {
            $this->assertSame('The requested directory must stay inside the source.', $e->getMessage());
        } finally {
            unlink($home . '/project');
        }
    }

    public function test_a_path_under_a_root_may_be_given_absolute_or_relative(): void
    {
        $home = '/home/johndoe';

        $this->assertSame('project', SourceResolver::underRoot($home, 'project'));
        $this->assertSame('project/apps/api', SourceResolver::underRoot($home, '/home/johndoe/project/apps/api'));
        $this->assertSame('', SourceResolver::underRoot($home, '/home/johndoe'));
        $this->assertSame('public_html', SourceResolver::underRoot($home, '/home/johndoe/public_html/'));
    }

    public function test_an_absolute_path_outside_the_root_is_refused(): void
    {
        $this->expectException(InspectException::class);
        // Not a prefix match on the string: a sibling account whose name
        // starts the same way is still a different account.
        SourceResolver::underRoot('/home/johndoe', '/home/johndoe2/project');
    }

    public function test_it_expresses_a_directory_relative_to_a_root(): void
    {
        $this->assertSame('project', SourceResolver::relativeTo('/home/johndoe', '/home/johndoe/project'));
        $this->assertNull(SourceResolver::relativeTo('/home/johndoe', '/home/johndoe'));
        $this->assertNull(SourceResolver::relativeTo('/home/johndoe', '/srv/elsewhere'));
    }

    public function test_a_subdirectory_that_is_not_there_is_an_error(): void
    {
        $this->expectException(InspectException::class);
        SourceResolver::descend($this->tmpDir, 'apps/web');
    }

    public function test_a_directory_source_reports_its_git_metadata_when_it_has_any(): void
    {
        file_put_contents($this->tmpDir . '/index.html', '<h1>hi</h1>');

        $resolved = (new SourceResolver($this->tmpDir . '/tmp'))
            ->fromDirectory(SourceResolver::TYPE_PATH, $this->tmpDir, $this->tmpDir);

        $this->assertSame(realpath($this->tmpDir), $resolved->dir);
        $this->assertNull($resolved->meta['commit'], 'A plain directory has no commit');
        $this->assertFalse($resolved->isEmpty());
        // Nothing was cloned, so releasing must not delete the caller's files.
        $resolved->release();
        $this->assertFileExists($this->tmpDir . '/index.html');
    }

    public function test_a_missing_directory_is_an_error(): void
    {
        $this->expectException(InspectException::class);
        (new SourceResolver($this->tmpDir . '/tmp'))
            ->fromDirectory(SourceResolver::TYPE_PATH, '/no/such', '/no/such');
    }

    public function test_it_sweeps_a_workspace_a_dead_request_left_behind(): void
    {
        $workspace = $this->tmpDir . '/tmp';
        mkdir($workspace, 0700, true);
        $stale = $workspace . '/inspect-deadbeefdeadbeef';
        mkdir($stale . '/repo', 0700, true);
        file_put_contents($stale . '/repo/README.md', 'left over');
        touch($stale, time() - 7 * 3600);

        $fresh = $workspace . '/inspect-cafecafecafecafe';
        mkdir($fresh, 0700, true);

        // Any clone attempt sweeps first; this one fails immediately.
        try {
            (new SourceResolver($workspace, 15))->fromGit('https://127.0.0.1:1/nope.git');
        } catch (InspectException $e) {
            // expected
        }

        $this->assertDirectoryDoesNotExist($stale, 'A stale workspace was left behind');
        $this->assertDirectoryExists($fresh, 'A workspace from a live request was swept');
    }

    /**
     * A clone that cannot start must not leave its workspace behind. The
     * address is a closed port on this host, so this needs no network and
     * fails immediately rather than on the timeout.
     */
    public function test_a_failed_clone_cleans_up_after_itself(): void
    {
        $workspace = $this->tmpDir . '/tmp';
        $resolver = new SourceResolver($workspace, 15);

        try {
            $resolver->fromGit('https://127.0.0.1:1/nope.git');
            $this->fail('Cloning from a closed port should fail');
        } catch (InspectException $e) {
            $this->assertStringContainsString('Could not clone the repository', $e->getMessage());
        }

        $left = array_values(array_diff(scandir($workspace) ?: [], ['.', '..']));
        $this->assertSame([], $left, 'A failed clone left its temp directory behind');
    }
}
