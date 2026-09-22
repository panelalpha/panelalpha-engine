<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\SourceResolver;
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
 * serving: ok in 120 s (ESMira, supported-apps#1216). The recipe was found
 * either way — inspect knew which recipe applied and still concluded the
 * runtime it names could not exist.
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
        $this->git($meta, 'submodule add -q ' . escapeshellarg($sub) . ' web');
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
    private function initSubmodules(string $checkout): void
    {
        $method = new \ReflectionMethod(SourceResolver::class, 'initSubmodules');
        $method->invoke(new SourceResolver($this->tmpDir . '/workspace', 60), $checkout, null);
    }

    public function test_the_application_inside_a_submodule_is_fetched(): void
    {
        $meta = $this->metaRepository();
        $checkout = $this->tmpDir . '/checkout';
        $this->git($this->tmpDir, 'clone -q ' . escapeshellarg($meta) . ' ' . escapeshellarg($checkout));

        // What inspect saw before: the directory is there and it is empty.
        $this->assertFileDoesNotExist($checkout . '/web/composer.json');

        $this->initSubmodules($checkout);

        $this->assertFileExists($checkout . '/web/composer.json', 'the submodule was not fetched');
        $this->assertFileExists($checkout . '/web/index.php');
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
     * The defect itself: the deploy path fetched submodules and the inspect
     * path did not, so the two disagreed about the same URL. This pins the
     * call into the clone, which is the line that was missing.
     */
    public function test_the_clone_path_runs_it(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../../app/Lib/Deploy/Inspect/SourceResolver.php'
        );
        $this->assertIsString($source);

        $fromGit = substr($source, (int) strpos($source, 'public function fromGit'));
        $fromGit = substr($fromGit, 0, (int) strpos($fromGit, 'return new ResolvedSource'));

        $this->assertStringContainsString('$this->initSubmodules(', $fromGit);
    }
}
