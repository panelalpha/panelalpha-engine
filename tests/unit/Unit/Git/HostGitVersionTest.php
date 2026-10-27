<?php

namespace Tests\Unit\Git;

use App\Lib\Git\HostGitVersion;
use App\Lib\Net\EgressRefusedException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * git before 2.37 ignores `http.curloptResolve` without an error, so the
 * host's version decides whether a pinned command really is pinned.
 */
class HostGitVersionTest extends TestCase
{
    /** @var list<list<string>> */
    private array $runs = [];

    private function hostGit(string|\Throwable $answer, ?Repository $cache = null): HostGitVersion
    {
        return new HostGitVersion(function (array $argv) use ($answer): string {
            $this->runs[] = $argv;
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        }, $cache ?? new Repository(new ArrayStore()));
    }

    public function test_the_version_is_read_from_git_itself(): void
    {
        $this->assertSame('2.34.1', $this->hostGit("git version 2.34.1\n")->version());
        $this->assertSame([['git', '--version']], $this->runs);
    }

    public function test_a_distribution_suffix_is_not_part_of_the_version(): void
    {
        $this->assertSame('2.39.5', HostGitVersion::parse("git version 2.39.5 (Apple Git-154)\n"));
        $this->assertSame('2.53.0', HostGitVersion::parse("git version 2.53.0.windows.1\n"));
        $this->assertNull(HostGitVersion::parse("sudo: git: command not found\n"));
    }

    public function test_git_older_than_2_37_does_not_pin(): void
    {
        $this->assertFalse($this->hostGit("git version 2.34.1\n")->pinsAddresses());
        $this->assertFalse($this->hostGit("git version 2.36.6\n")->pinsAddresses());
    }

    public function test_git_2_37_and_newer_pins(): void
    {
        $this->assertTrue($this->hostGit("git version 2.37.0\n")->pinsAddresses());
        $this->assertTrue($this->hostGit("git version 2.43.0\n")->pinsAddresses());
        $this->assertTrue($this->hostGit("git version 3.0.1\n")->pinsAddresses());
    }

    public function test_the_version_is_read_once_and_cached(): void
    {
        $cache = new Repository(new ArrayStore());

        $this->hostGit("git version 2.34.1\n", $cache)->pinsAddresses();
        $this->hostGit("git version 2.34.1\n", $cache)->pinsAddresses();
        $this->hostGit("git version 2.34.1\n", $cache)->version();

        $this->assertCount(1, $this->runs);
    }

    public function test_a_git_that_does_not_answer_is_asked_again_and_not_refused(): void
    {
        $cache = new Repository(new ArrayStore());

        $this->assertTrue($this->hostGit(new \RuntimeException('nsenter failed'), $cache)->pinsAddresses());
        $this->assertSame('2.34.1', $this->hostGit("git version 2.34.1\n", $cache)->version());
        $this->assertCount(2, $this->runs);
    }

    public function test_an_old_git_refuses_with_the_version_it_needs(): void
    {
        try {
            $this->hostGit("git version 2.34.1\n")->assertPins('github.com');
            $this->fail('An old git was not refused.');
        } catch (EgressRefusedException $e) {
            $this->assertStringContainsString('git is 2.34.1', $e->getMessage());
            $this->assertStringContainsString('git 2.37 or newer is required', $e->getMessage());
            $this->assertStringContainsString('add github.com to EGRESS_ALLOW_HOSTS', $e->getMessage());
            $this->assertSame('host_refused', $e->problem()['code']);
        }
    }

    public function test_a_new_git_is_not_refused(): void
    {
        $this->hostGit("git version 2.43.0\n")->assertPins('github.com');

        $this->assertCount(1, $this->runs);
    }
}
