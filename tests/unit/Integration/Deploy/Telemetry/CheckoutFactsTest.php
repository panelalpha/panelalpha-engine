<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\CheckoutFacts;
use PHPUnit\Framework\TestCase;
use Tests\Support\CheckoutFactsFixtures;

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
    use CheckoutFactsFixtures;

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
}
