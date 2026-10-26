<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Deploy\Source\GitRepoInput;
use PHPUnit\Framework\TestCase;

/**
 * Inspect has to see the same tree the deploy will.
 *
 * The deploy path calls `initSubmodules()`; the inspect clone did not. For a
 * meta repository whose application lives in a submodule that is the whole
 * difference: inspect cloned empty directories, found no composer.json and no
 * .php anywhere, and answered
 *
 *   "deployable": false,
 *   "issue": "Runtime 'php' is required but could not be resolved for this project"
 *
 * while the very next deploy of the same URL with the same recipe reached
 * serving: ok (ESMira). The recipe was found either way — inspect knew which
 * recipe applied and still concluded the runtime it names could not exist.
 *
 * Local-path submodules need `protocol.file.allow`, which git has refused by
 * default since 2.38 and which the production command deliberately does not
 * relax. The fixture checkout sets it on itself; only the fixture is local.
 */
class SourceResolverSubmodulesTest extends TestCase
{
    private string $tmpDir = '';

    private ?string $home = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git is not on PATH');
        }

        $this->tmpDir = sys_get_temp_dir() . '/inspect-sub-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0700, true);

        // `protocol.file.allow` is read by the child clone git spawns, before
        // that repository exists -- so the superproject's own config cannot
        // carry it and it has to come from a global one. The production
        // command does not relax this and must not; only the fixture is local.
        mkdir($this->tmpDir . '/home', 0700, true);
        file_put_contents(
            $this->tmpDir . '/home/.gitconfig',
            "[protocol \"file\"]\n\tallow = always\n[user]\n\tname = t\n\temail = t@example.com\n"
        );
        $this->home = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $_ENV['HOME'] = $this->tmpDir . '/home';

    }

    protected function tearDown(): void
    {
        if ($this->home !== null) {
            $_SERVER['HOME'] = $_ENV['HOME'] = $this->home;
        }
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function git(string $cwd, string $args): void
    {
        exec(
            'cd ' . escapeshellarg($cwd) . ' && git -c protocol.file.allow=always '
            . '-c user.name=t -c user.email=t@example.com ' . $args . ' 2>&1',
            $output,
            $status
        );
        $this->assertSame(0, $status, "git {$args}\n" . implode("\n", $output));
    }

    /** A meta repository: four files of its own, the application in a submodule. */
    private function metaRepository(): string
    {
        $sub = $this->tmpDir . '/sub';
        mkdir($sub, 0700, true);
        $this->git($sub, 'init -q --initial-branch=main');
        file_put_contents($sub . '/index.php', "<?php echo 'esmira';\n");
        file_put_contents($sub . '/composer.json', '{"name":"acme/web"}');
        $this->git($sub, 'add -A && git -c user.name=t -c user.email=t@example.com commit -qm web');

        $meta = $this->tmpDir . '/meta';
        mkdir($meta, 0700, true);
        $this->git($meta, 'init -q --initial-branch=main');
        file_put_contents($meta . '/README.md', "a meta repository\n");
        $this->git($meta, 'add -A && git -c user.name=t -c user.email=t@example.com commit -qm meta');
        $this->git($meta, 'submodule add -q ' . escapeshellarg('file://' . $sub) . ' web');
        $this->git($meta, 'commit -qm submodule');

        return $meta;
    }

    /**
     * Straight at the step, by reflection.
     *
     * `fromGit()` cannot be driven end to end here: it only accepts remote
     * URLs (a local path normalises to `https://tmp/...`), and the shallow
     * clone it does rules out the dumb-HTTP server a test could stand up. So
     * the fetch is exercised directly and the wiring is pinned separately,
     * below -- the defect was the call being absent, not the step being wrong.
     */
    private function initSubmodules(string $checkout, array $protocols = GitRepoInput::SCHEMES): void
    {
        $method = new \ReflectionMethod(SourceResolver::class, 'initSubmodules');
        $method->invoke(new SourceResolver($this->tmpDir . '/workspace', 60, null, $protocols), $checkout, null);
    }

    public function test_the_application_inside_a_submodule_is_fetched(): void
    {
        $file = ['https', 'http', 'file'];
        $meta = $this->metaRepository();
        $checkout = $this->tmpDir . '/checkout';
        $this->git($this->tmpDir, 'clone -q ' . escapeshellarg($meta) . ' ' . escapeshellarg($checkout));

        // What inspect saw before: the directory is there and it is empty.
        $this->assertFileDoesNotExist($checkout . '/web/composer.json');

        $this->initSubmodules($checkout, $file);

        $this->assertFileExists($checkout . '/web/composer.json', 'the submodule was not fetched');
        $this->assertFileExists($checkout . '/web/index.php');
    }

    /**
     * The engine clones over http and https only, and a submodule's child
     * clone reads the same restriction: a transport the engine does not speak
     * is left unfetched rather than handed to git.
     */
    public function test_a_submodule_on_a_transport_the_engine_does_not_fetch_is_left_alone(): void
    {
        $meta = $this->metaRepository();
        $checkout = $this->tmpDir . '/checkout';
        $this->git($this->tmpDir, 'clone -q ' . escapeshellarg($meta) . ' ' . escapeshellarg($checkout));

        $this->initSubmodules($checkout);

        $this->assertFileDoesNotExist($checkout . '/web/composer.json', 'a file transport was fetched');
    }

    /** A repository with no submodules pays one stat and is untouched. */
    public function test_a_repository_without_submodules_is_left_alone(): void
    {
        $plain = $this->tmpDir . '/plain';
        mkdir($plain, 0700, true);
        $this->git($plain, 'init -q --initial-branch=main');
        file_put_contents($plain . '/index.php', "<?php\n");
        $this->git($plain, 'add -A && git -c user.name=t -c user.email=t@example.com commit -qm one');

        $this->initSubmodules($plain);

        $this->assertFileExists($plain . '/index.php');
        $this->assertFileDoesNotExist($plain . '/.gitmodules');
    }

    /**
     * Best effort. A submodule this request has no credential for must leave
     * the parent checkout usable rather than turn a readable repository into
     * a failed inspect.
     */
    public function test_a_submodule_that_cannot_be_fetched_is_not_an_error(): void
    {
        $meta = $this->tmpDir . '/unreachable';
        mkdir($meta, 0700, true);
        $this->git($meta, 'init -q --initial-branch=main');
        file_put_contents($meta . '/index.php', "<?php\n");
        file_put_contents(
            $meta . '/.gitmodules',
            "[submodule \"web\"]\n\tpath = web\n\turl = https://127.0.0.1:1/nope.git\n"
        );
        $this->git($meta, 'add -A && git -c user.name=t -c user.email=t@example.com commit -qm meta');

        $this->initSubmodules($meta);

        $this->assertFileExists($meta . '/index.php');
    }

    /**
     * Best effort covers a slow git too: a config read that runs out of time
     * skips the submodules instead of failing the whole inspect.
     */
    public function test_a_config_read_that_times_out_is_not_an_error(): void
    {
        foreach (['url read' => '*--local*--get-regexp*', 'nested path read' => '*--file*--get-regexp*'] as $case => $hanging) {
            $meta = $this->metaRepository();
            $checkout = $this->tmpDir . '/checkout';
            $this->git($this->tmpDir, 'clone -q ' . escapeshellarg($meta) . ' ' . escapeshellarg($checkout));

            $this->withConfigReadsHanging($hanging, function () use ($checkout): void {
                $method = new \ReflectionMethod(SourceResolver::class, 'initSubmodules');
                $method->invoke(new SourceResolver($this->tmpDir . '/workspace', 2), $checkout, null);
            });

            $this->assertFileExists($checkout . '/README.md', $case);
            foreach (['checkout', 'sub', 'meta', 'bin'] as $dir) {
                $this->removeDir($this->tmpDir . '/' . $dir);
            }
        }
    }

    /** Runs $run with a `git` first on PATH that hangs when its arguments match $hanging and is git otherwise. */
    private function withConfigReadsHanging(string $hanging, callable $run): void
    {
        $realGit = trim((string) shell_exec('command -v git'));
        $bin = $this->tmpDir . '/bin';
        mkdir($bin, 0700, true);
        file_put_contents($bin . '/git', "#!/bin/sh\ncase \"\$*\" in {$hanging}) exec sleep 10;; esac\nexec "
            . escapeshellarg($realGit) . " \"\$@\"\n");
        chmod($bin . '/git', 0755);

        $path = (string) getenv('PATH');
        putenv('PATH=' . $bin . ':' . $path);
        $_SERVER['PATH'] = $_ENV['PATH'] = $bin . ':' . $path;
        try {
            $run();
        } finally {
            putenv('PATH=' . $path);
            $_SERVER['PATH'] = $_ENV['PATH'] = $path;
        }
    }

    /** A gitlink plus its .gitmodules entry, written as given; the commit is never fetched. */
    private function addGitlink(string $repo, string $name, string $gitmodulesEntry): void
    {
        file_put_contents($repo . '/.gitmodules', "[submodule \"{$name}\"]\n\tpath = {$name}\n{$gitmodulesEntry}\n", FILE_APPEND);
        $this->git($repo, 'update-index --add --cacheinfo 160000,' . str_repeat('1', 40) . ',' . $name);
        $this->git($repo, 'add .gitmodules && git -c user.name=t -c user.email=t@example.com commit -qm ' . $name);
    }

    /**
     * Every host git opens an ssh connection to, recorded by a stand-in ssh
     * that never connects.
     *
     * @return list<string>
     */
    private function sshContacts(callable $run): array
    {
        $log = $this->tmpDir . '/ssh.log';
        $ssh = $this->tmpDir . '/ssh.sh';
        file_put_contents($ssh, "#!/bin/sh\necho \"\$*\" >> " . escapeshellarg($log) . "\nexit 1\n");
        chmod($ssh, 0755);
        putenv('GIT_SSH_COMMAND=' . $ssh);
        $_SERVER['GIT_SSH_COMMAND'] = $_ENV['GIT_SSH_COMMAND'] = $ssh;
        try {
            $run();
        } finally {
            putenv('GIT_SSH_COMMAND');
            unset($_SERVER['GIT_SSH_COMMAND'], $_ENV['GIT_SSH_COMMAND']);
        }

        return is_file($log) ? (array) file($log, FILE_IGNORE_NEW_LINES) : [];
    }

    /**
     * What .gitmodules says is not what git fetches: a quoted URL is
     * unquoted, and a relative one is resolved against the parent remote and
     * can climb past its host. Both are checked as git resolves them.
     */
    public function test_a_quoted_or_relative_internal_url_is_not_contacted(): void
    {
        $cases = [
            'quoted' => ["\turl = \"ssh://git@127.0.0.1/x.git\"", 'https://example.com/owner/repo.git'],
            'relative' => ["\turl = ../../../127.0.0.1/x.git", 'ssh://git@example.com/owner/repo.git'],
        ];
        foreach ($cases as $case => [$entry, $origin]) {
            $meta = $this->metaRepository();
            $this->addGitlink($meta, 'evil', $entry);
            $checkout = $this->tmpDir . '/checkout-' . $case;
            $this->git($this->tmpDir, 'clone -q ' . escapeshellarg($meta) . ' ' . escapeshellarg($checkout));
            $this->git($checkout, 'remote set-url origin ' . escapeshellarg($origin));

            $contacts = $this->sshContacts(fn () => $this->initSubmodules($checkout));

            $this->assertSame([], preg_grep('/127\.0\.0\.1/', $contacts), "{$case}: git connected to an internal host");
            @unlink($this->tmpDir . '/ssh.log');
            $this->removeDir($this->tmpDir . '/sub');
            $this->removeDir($this->tmpDir . '/meta');
        }
    }

    /** A nested .gitmodules is checked before its level is fetched, not left to --recursive. */
    public function test_a_nested_internal_submodule_is_not_contacted(): void
    {
        $file = ['https', 'http', 'file'];
        $meta = $this->metaRepository();
        $sub = $this->tmpDir . '/sub';
        $this->addGitlink($sub, 'evil', "\turl = ssh://git@127.0.0.1/x.git");
        $this->git($meta . '/web', 'pull -q origin main');
        $this->git($meta, 'add web && git -c user.name=t -c user.email=t@example.com commit -qm bump');

        $checkout = $this->tmpDir . '/checkout';
        $this->git($this->tmpDir, 'clone -q ' . escapeshellarg($meta) . ' ' . escapeshellarg($checkout));

        $contacts = $this->sshContacts(fn () => $this->initSubmodules($checkout, $file));

        $this->assertFileExists($checkout . '/web/composer.json', 'the first level was not fetched');
        $this->assertSame([], preg_grep('/127\.0\.0\.1/', $contacts), 'a nested internal submodule was contacted');
    }

    /**
     * The defect itself: the deploy path fetched submodules and the inspect
     * path did not, so the two disagreed about the same URL. This pins the
     * call into the clone, which is the line that was missing.
     */
    public function test_the_clone_path_runs_it(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../../../core/app/Lib/Deploy/Inspect/SourceResolver.php'
        );
        $this->assertIsString($source);

        $this->assertStringContainsString('$this->cloneRepository(', $this->methodBody($source, 'public function fromGit'));
        $this->assertStringContainsString('$this->initSubmodules(', $this->methodBody($source, 'private function cloneRepository'));
    }

    private function methodBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        $this->assertNotFalse($start, $signature);
        $method = substr($source, $start);

        return substr($method, 0, (int) strpos($method, "\n    }\n"));
    }
}
