<?php

namespace Tests\Integration\Deploy\Inspect;

use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Deploy\Inspect\TreeUnavailable;
use App\Lib\Http\OutboundUrl;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The resolver's less travelled paths: a token on a clone, a remote that will not hand out single blobs, a host
 * that stops resolving between the check and the clone, and the guards on directories and workspaces.
 */
class SourceResolverEdgesTest extends TestCase
{
    private const URL = 'https://gitlab.com/owner/repo';

    private string $tmp = '';

    private string $origin = '';

    private ?string $home = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git is not on PATH');
        }
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(4)]);
        $this->tmp = sys_get_temp_dir().'/resolver-edges-'.bin2hex(random_bytes(6));
        $this->origin = $this->tmp.'/origin';
        mkdir($this->origin, 0700, true);
        mkdir($this->tmp.'/home', 0700, true);
        $this->git('init -q -b main');
        $this->git('config uploadpack.allowFilter true');
        $this->gitConfig('');
        $this->home = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $_ENV['HOME'] = $this->tmp.'/home';
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        if ($this->home !== null) {
            $_SERVER['HOME'] = $_ENV['HOME'] = $this->home;
        }
        ResolvedSource::removeTree($this->tmp);
        parent::tearDown();
    }

    private function gitConfig(string $extra): void
    {
        file_put_contents(
            $this->tmp.'/home/.gitconfig',
            "[url \"file://{$this->origin}\"]\n\tinsteadOf = ".self::URL."\n".$extra
        );
    }

    private function git(string $args): string
    {
        return trim((string) shell_exec(
            'git -C '.escapeshellarg($this->origin).' -c user.name=t -c user.email=t@example.com '.$args.' 2>&1'
        ));
    }

    /**
     * @param  array<string, string>  $files
     */
    private function commit(array $files): string
    {
        foreach ($files as $path => $contents) {
            file_put_contents($this->origin.'/'.$path, $contents);
        }
        $this->git('add -A');
        $this->git('commit -qm change');

        return $this->git('rev-parse HEAD');
    }

    private function resolver(int $timeout = 60): SourceResolver
    {
        return new SourceResolver($this->tmp.'/work', $timeout, null, ['https', 'http', 'file']);
    }

    /** @return list<string> */
    private function workspaces(): array
    {
        return glob($this->tmp.'/work/inspect-*') ?: [];
    }

    private function runGit(SourceResolver $resolver, array $command, ?string $redact = null): InspectException
    {
        try {
            (new \ReflectionMethod(SourceResolver::class, 'run'))->invoke($resolver, $command, 'Could not clone the repository', $redact, false);
        } catch (InspectException $e) {
            return $e;
        }

        $this->fail('the failure was not raised');
    }

    public function test_a_token_goes_with_the_partial_clone_and_its_helper_is_removed(): void
    {
        $sha = $this->commit(['composer.json' => '{}', 'index.php' => '<?php echo 1;']);

        $resolved = $this->resolver()->fromGit(self::URL, null, 'secret-token');
        try {
            $this->assertSame(SourceResolver::METHOD_PARTIAL, $resolved->meta['method']);
            $this->assertSame($sha, $resolved->meta['commit']);
            $this->assertSame('{}', file_get_contents($resolved->dir.'/composer.json'));
            $this->assertFileDoesNotExist(dirname($resolved->dir).'/askpass.sh');
        } finally {
            $resolved->release();
        }
    }

    public function test_a_token_goes_with_the_full_clone_a_submodule_needs(): void
    {
        $sha = $this->commit(['composer.json' => '{}']);
        $this->git('update-index --add --cacheinfo 160000,'.$sha.',vendored');
        $this->git('commit -qm submodule');

        $resolved = $this->resolver()->fromGit(self::URL, null, 'secret-token');
        try {
            $this->assertSame(SourceResolver::METHOD_CLONE, $resolved->meta['method']);
            $this->assertSame('main', $resolved->meta['branch']);
            $this->assertFileDoesNotExist(dirname($resolved->dir).'/askpass.sh');
        } finally {
            $resolved->release();
        }
    }

    public function test_a_remote_that_refuses_single_blobs_is_cloned_in_full(): void
    {
        $this->gitConfig("[protocol]\n\tversion = 0\n");
        $this->commit(['composer.json' => '{"name":"app"}', 'index.php' => '<?php echo 1;']);

        $resolved = $this->resolver()->fromGit(self::URL);
        try {
            $this->assertSame(SourceResolver::METHOD_CLONE, $resolved->meta['method']);
            $this->assertSame('<?php echo 1;', file_get_contents($resolved->dir.'/index.php'));
        } finally {
            $resolved->release();
        }
        $this->assertSame([], $this->workspaces());
    }

    public function test_a_token_refused_by_a_missing_repository_is_not_retried_another_way(): void
    {
        ResolvedSource::removeTree($this->origin);

        try {
            $this->resolver()->fromGit(self::URL, null, 'secret-token');
            $this->fail('A missing repository was resolved.');
        } catch (InspectException $e) {
            $this->assertSame(
                'Could not clone the repository: there is no repository at this address, or the token cannot see it. '
                .'Check the address and that the token can read this repository.',
                $e->getMessage()
            );
        }
        $this->assertSame([], $this->workspaces());
    }

    public function test_a_host_that_stops_resolving_after_the_check_fails_the_clone_cleanly(): void
    {
        $calls = 0;
        OutboundUrl::setResolverForTests(static function (string $host) use (&$calls): array {
            return $calls++ === 0 ? [TestNetwork::routable(4)] : [];
        });

        try {
            $this->resolver()->fromGit(self::URL);
            $this->fail('A host that no longer resolves was cloned.');
        } catch (InspectException $e) {
            $this->assertStringStartsWith('Could not clone the repository: ', $e->getMessage());
        }
        $this->assertSame([], $this->workspaces());
    }

    public function test_a_full_clone_whose_host_no_longer_resolves_releases_its_workspace(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => []);

        try {
            (new \ReflectionMethod(SourceResolver::class, 'cloneRepository'))->invoke($this->resolver(), self::URL, null, null);
            $this->fail('A host that does not resolve was cloned.');
        } catch (InspectException $e) {
            $this->assertStringStartsWith('Could not clone the repository: ', $e->getMessage());
        }
        $this->assertSame([], $this->workspaces());
    }

    public function test_submodules_are_fetched_with_the_clones_credential_helper(): void
    {
        $this->gitConfig("[protocol \"file\"]\n\tallow = always\n");
        $sub = $this->tmp.'/sub';
        mkdir($sub, 0700, true);
        shell_exec('git -C '.escapeshellarg($sub).' init -q -b main 2>&1');
        file_put_contents($sub.'/composer.json', '{"name":"acme/web"}');
        shell_exec('git -C '.escapeshellarg($sub).' -c user.name=t -c user.email=t@example.com add -A 2>&1');
        shell_exec('git -C '.escapeshellarg($sub).' -c user.name=t -c user.email=t@example.com commit -qm web 2>&1');
        $this->commit(['README.md' => 'meta']);
        $this->git('-c protocol.file.allow=always submodule add -q '.escapeshellarg('file://'.$sub).' web');
        $this->git('commit -qm submodule');
        $checkout = $this->tmp.'/checkout';
        shell_exec('git clone -q '.escapeshellarg($this->origin).' '.escapeshellarg($checkout).' 2>&1');
        $askPass = $this->tmp.'/askpass.sh';
        file_put_contents($askPass, "#!/bin/sh\necho token\n");
        chmod($askPass, 0700);

        (new \ReflectionMethod(SourceResolver::class, 'initSubmodules'))->invoke($this->resolver(), $checkout, $askPass);

        $this->assertSame('{"name":"acme/web"}', file_get_contents($checkout.'/web/composer.json'));
    }

    public function test_a_workspace_root_that_cannot_be_made_refuses_the_inspection(): void
    {
        file_put_contents($this->tmp.'/blocker', 'not a directory');
        $resolver = new SourceResolver($this->tmp.'/blocker/work', 60, null, ['https', 'http', 'file']);

        $this->expectExceptionObject(new InspectException('Could not create the inspection workspace.'));

        $resolver->fromGit(self::URL);
    }

    public function test_the_sweep_leaves_a_file_and_a_link_that_only_look_like_workspaces(): void
    {
        mkdir($this->tmp.'/work', 0700, true);
        file_put_contents($this->tmp.'/work/inspect-file', 'x');
        mkdir($this->tmp.'/elsewhere', 0700, true);
        touch($this->tmp.'/elsewhere', time() - 7 * 3600);
        symlink($this->tmp.'/elsewhere', $this->tmp.'/work/inspect-link');
        $this->commit(['composer.json' => '{}']);

        $this->resolver()->fromGit(self::URL)->release();

        $this->assertFileExists($this->tmp.'/work/inspect-file');
        $this->assertTrue(is_link($this->tmp.'/work/inspect-link'));
        $this->assertDirectoryExists($this->tmp.'/elsewhere');
    }

    public function test_the_file_list_is_not_read_without_a_reader(): void
    {
        $this->expectExceptionObject(new TreeUnavailable('Not a github.com repository URL.'));

        $this->resolver()->fromGitHubTree('https://github.com/owner/repo');
    }

    public function test_a_file_is_not_a_directory_source(): void
    {
        file_put_contents($this->tmp.'/file.txt', 'x');

        $this->expectExceptionObject(new InspectException('No such directory: '.$this->tmp.'/file.txt'));

        $this->resolver()->fromDirectory(SourceResolver::TYPE_PATH, $this->tmp.'/file.txt', $this->tmp.'/file.txt');
    }

    public function test_a_directory_whose_git_metadata_is_unreadable_reports_none(): void
    {
        mkdir($this->tmp.'/plain', 0700, true);
        file_put_contents($this->tmp.'/plain/.git', "gitdir: /nowhere\n");

        $resolved = $this->resolver()->fromDirectory(SourceResolver::TYPE_PATH, $this->tmp.'/plain', $this->tmp.'/plain');

        $this->assertSame(['repository' => null, 'branch' => null, 'commit' => null], $resolved->meta);
    }

    public function test_a_subdirectory_naming_only_the_source_itself_is_the_source(): void
    {
        $this->assertSame($this->tmp, SourceResolver::descend($this->tmp, '././.'));
        mkdir($this->tmp.'/a/b', 0700, true);
        $this->assertSame(realpath($this->tmp.'/a/b'), SourceResolver::descend($this->tmp, 'a/./b'));
    }

    public function test_a_subdirectory_with_a_nul_byte_is_refused(): void
    {
        $this->expectExceptionObject(new InspectException('Invalid directory.'));

        SourceResolver::descend($this->tmp, "a\0b");
    }

    public function test_git_saying_nothing_is_a_bare_failure(): void
    {
        $e = $this->runGit($this->resolver(), ['sh', '-c', 'exit 1']);

        $this->assertSame('Could not clone the repository.', $e->getMessage());
    }

    public function test_without_a_fatal_line_the_last_line_is_quoted_with_the_workspace_hidden(): void
    {
        $workspace = $this->tmp.'/work/inspect-x';
        $e = $this->runGit($this->resolver(), ['sh', '-c', 'echo "Cloning into repo" >&2; echo "error: cannot write '.$workspace.'/repo" >&2; exit 1'], $workspace);

        $this->assertSame('Could not clone the repository: error: cannot write <workspace>/repo', $e->getMessage());
    }

    public function test_a_git_that_outlives_its_timeout_is_a_failure(): void
    {
        $e = $this->runGit($this->resolver(1), ['sh', '-c', 'sleep 5']);

        $this->assertStringStartsWith('Could not clone the repository: ', $e->getMessage());
        $this->assertStringContainsString('timeout', $e->getMessage());
    }
}
