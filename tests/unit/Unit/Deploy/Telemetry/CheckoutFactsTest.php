<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\CheckoutFacts;
use PHPUnit\Framework\TestCase;

/**
 * Reading the repository off the disk instead of out of the account record.
 *
 * Two halves, both worth having. The scripted runner pins what the class does
 * with each answer git can give -- including the ones a broken checkout gives,
 * which is the case this runs in. The real-repository test pins the answers
 * themselves: every assertion below about `--is-shallow-repository` or
 * `%H %ct` is a guess about git's output format until an actual git prints it.
 */
class CheckoutFactsTest extends TestCase
{
    private const DIR = '/home/acme7x/project';

    /**
     * A runner that answers from a table of git subcommands.
     *
     * @param array<string, ?string> $answers keyed by the arguments after `-C <dir>`
     * @return callable(list<string>): ?string
     */
    private function runner(array $answers, ?array &$seen = null): callable
    {
        $seen = [];

        return static function (array $argv) use ($answers, &$seen): ?string {
            $index = array_search('-C', $argv, true);
            $key = implode(' ', array_slice($argv, (int) $index + 2));
            $seen[] = $key;

            return $answers[$key] ?? null;
        };
    }

    /**
     * @return array<string, ?string>
     */
    private function repository(array $overrides = []): array
    {
        return array_replace([
            'rev-parse --is-inside-work-tree' => "true\n",
            'config --get remote.origin.url' => "https://github.com/vercel/next.js.git\n",
            'log -1 --format=%H %ct' => "0d2f3a4b5c6d7e8f90a1b2c3d4e5f60718293a4b 1756000000\n",
            'rev-parse --abbrev-ref HEAD' => "main\n",
            'rev-parse --is-shallow-repository' => "true\n",
        ], $overrides);
    }

    public function test_a_checkout_reports_what_the_build_had_to_work_with(): void
    {
        $facts = CheckoutFacts::read(self::DIR, $this->runner($this->repository()));

        $this->assertTrue($facts['present']);
        $this->assertSame('https://github.com/vercel/next.js.git', $facts['remote']);
        $this->assertSame('main', $facts['branch']);
        $this->assertSame('0d2f3a4b5c6d7e8f90a1b2c3d4e5f60718293a4b', $facts['commit']);
        $this->assertSame(1756000000, $facts['committed_at']);
        $this->assertTrue($facts['shallow']);
        $this->assertFalse($facts['detached']);
    }

    public function test_a_directory_that_is_not_a_repository_answers_once_and_stops(): void
    {
        // The common case -- an archive upload -- so it must cost one process,
        // not five against a tree that has no .git in it.
        $facts = CheckoutFacts::read(self::DIR, $this->runner([], $seen));

        $this->assertSame(['present' => false], $facts);
        $this->assertSame(['rev-parse --is-inside-work-tree'], $seen);
    }

    public function test_no_directory_is_not_a_repository_either(): void
    {
        $ran = false;
        $facts = CheckoutFacts::read('  ', function () use (&$ran) {
            $ran = true;

            return null;
        });

        $this->assertSame(['present' => false], $facts);
        $this->assertFalse($ran, 'nothing may be executed for an empty path');
    }

    public function test_every_read_runs_inside_the_directory_it_was_given(): void
    {
        $argvs = [];
        CheckoutFacts::read(self::DIR, function (array $argv) use (&$argvs): ?string {
            $argvs[] = $argv;

            return $argv[5] === '--is-inside-work-tree' ? 'true' : null;
        });

        foreach ($argvs as $argv) {
            $this->assertSame('git', $argv[0]);
            // safe.directory: the tree belongs to the account user and this
            // does not run as them. Without it git refuses every read and
            // every field comes back null on a real install.
            $this->assertSame('-c', $argv[1]);
            $this->assertSame('safe.directory=' . self::DIR, $argv[2]);
            $this->assertSame(['-C', self::DIR], [$argv[3], $argv[4]]);
        }
    }

    public function test_a_detached_head_reports_no_branch_and_says_why(): void
    {
        $facts = CheckoutFacts::read(self::DIR, $this->runner($this->repository([
            'rev-parse --abbrev-ref HEAD' => "HEAD\n",
        ])));

        $this->assertNull($facts['branch']);
        $this->assertTrue($facts['detached']);
    }

    /**
     * A `git init` nothing was ever committed into: HEAD does not resolve, so
     * `log -1` fails outright and takes both fields with it.
     */
    public function test_an_unborn_head_loses_the_commit_but_not_the_repository(): void
    {
        $facts = CheckoutFacts::read(self::DIR, $this->runner($this->repository([
            'log -1 --format=%H %ct' => null,
            'rev-parse --abbrev-ref HEAD' => "HEAD\n",
        ])));

        $this->assertTrue($facts['present']);
        $this->assertNull($facts['commit']);
        $this->assertNull($facts['committed_at']);
        // Nothing is checked out because nothing was ever committed, which is
        // not the same thing as a deploy that resolved a tag.
        $this->assertFalse($facts['detached']);
    }

    public function test_a_commit_line_git_did_not_write_is_refused_rather_than_parsed(): void
    {
        foreach (['deadbeef 1756000000', 'not a sha at all', '', '0d2f3a4b5c6d7e8f90a1b2c3d4e5f60718293a4b'] as $line) {
            $facts = CheckoutFacts::read(self::DIR, $this->runner($this->repository([
                'log -1 --format=%H %ct' => $line,
            ])));

            $this->assertNull($facts['commit'], "accepted: {$line}");
            $this->assertNull($facts['committed_at'], "accepted: {$line}");
        }
    }

    /**
     * The engine clones through GIT_ASKPASS, so its own checkouts have a clean
     * remote. One somebody pushed or restored themselves may not, and a token
     * written into .git/config must not survive being read out of it.
     */
    public function test_a_credential_in_the_remote_does_not_leave_the_checkout(): void
    {
        $facts = CheckoutFacts::read(self::DIR, $this->runner($this->repository([
            'config --get remote.origin.url' => "https://acme:ghp_secrettoken@github.com/acme/portal.git\n",
        ])));

        $this->assertSame('https://github.com/acme/portal.git', $facts['remote']);
        $this->assertStringNotContainsString('ghp_secrettoken', (string) json_encode($facts));
    }

    public function test_a_repository_with_no_remote_is_still_a_repository(): void
    {
        $facts = CheckoutFacts::read(self::DIR, $this->runner($this->repository([
            'config --get remote.origin.url' => "\n",
        ])));

        $this->assertTrue($facts['present']);
        $this->assertNull($facts['remote']);
    }

    public function test_a_branch_name_nobody_could_have_typed_is_truncated(): void
    {
        $facts = CheckoutFacts::read(self::DIR, $this->runner($this->repository([
            'rev-parse --abbrev-ref HEAD' => str_repeat('b', 400),
        ])));

        $this->assertSame(CheckoutFacts::MAX_BRANCH_BYTES, strlen((string) $facts['branch']));
    }

    /**
     * The whole point of the lfs field: a clone whose large files arrived as
     * pointer stubs deploys green and serves nothing, and no other field in a
     * report explains it.
     */
    public function test_submodules_and_lfs_are_read_from_the_tree(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/.gitmodules', "[submodule \"theme\"]\n\tpath = theme\n");
        file_put_contents($dir . '/.gitattributes', "*.psd filter=lfs diff=lfs merge=lfs -text\n");

        $facts = CheckoutFacts::read($dir, $this->runner($this->repository()));

        $this->assertTrue($facts['submodules']);
        $this->assertTrue($facts['lfs']);
    }

    public function test_gitattributes_that_says_nothing_about_lfs_is_not_lfs(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/.gitattributes', "* text=auto eol=lf\n");

        $facts = CheckoutFacts::read($dir, $this->runner($this->repository()));

        $this->assertFalse($facts['lfs']);
        $this->assertFalse($facts['submodules']);
    }

    /**
     * Against a real git, because everything above is an assertion about what
     * git prints and the rest of these tests take that on trust.
     */
    public function test_a_real_repository_reads_the_way_the_scripted_one_does(): void
    {
        $git = trim((string) shell_exec('command -v git 2>/dev/null'));
        if ($git === '') {
            $this->markTestSkipped('git is not installed');
        }

        $dir = $this->tempDir();
        $run = function (array $argv): ?string {
            $command = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>/dev/null';
            $out = shell_exec($command);

            return is_string($out) && trim($out) !== '' ? $out : null;
        };

        $this->assertSame(['present' => false], CheckoutFacts::read($dir, $run));

        foreach ([
            ['git', '-C', $dir, 'init', '--initial-branch=trunk'],
            ['git', '-C', $dir, 'config', 'user.email', 'nobody@example.com'],
            ['git', '-C', $dir, 'config', 'user.name', 'Nobody'],
            ['git', '-C', $dir, 'remote', 'add', 'origin', 'https://github.com/acme/widget.git'],
        ] as $argv) {
            $run($argv);
        }
        file_put_contents($dir . '/README.md', "hello\n");
        $run(['git', '-C', $dir, 'add', '.']);
        $run(['git', '-C', $dir, 'commit', '-m', 'first']);

        $facts = CheckoutFacts::read($dir, $run);

        $this->assertTrue($facts['present']);
        $this->assertSame('https://github.com/acme/widget.git', $facts['remote']);
        $this->assertSame('trunk', $facts['branch']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $facts['commit']);
        $this->assertGreaterThan(1700000000, $facts['committed_at']);
        $this->assertFalse($facts['shallow']);
        $this->assertFalse($facts['detached']);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/checkout-facts-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->cleanup[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->cleanup = [];
        parent::tearDown();
    }
}
