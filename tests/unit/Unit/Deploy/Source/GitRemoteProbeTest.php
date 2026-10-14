<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRemoteProbe;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The probe against real remotes. Grouped `network` so a run without egress
 * can skip it: what it asserts is how the engine reads a forge's answers, and
 * a mocked forge would only assert that the fixtures match the matcher.
 */
#[Group('network')]
class GitRemoteProbeTest extends TestCase
{
    public function test_a_public_repository_answers(): void
    {
        $problem = (new GitRemoteProbe())->problem(
            'git_repo',
            'https://github.com/octocat/Hello-World.git',
            null
        );

        $this->assertNull($problem, 'a public repository should need no credentials');
    }

    /**
     * A forge hides a private repo from an unauthenticated caller, so
     * "private" and "typo" are one answer and must read as one.
     */
    public function test_a_private_or_missing_repository_asks_for_a_token(): void
    {
        $problem = (new GitRemoteProbe())->problem(
            'git_repo',
            'https://github.com/vvolv/definitely-private-xyz.git',
            null
        );

        $this->assertNotNull($problem);
        $this->assertSame('git_repo_requires_token', $problem['code']);
        $this->assertStringContainsString('git_token', $problem['message']);
        $this->assertArrayNotHasKey('retryable', $problem);
    }

    /** A token that is not a token is refused by the forge, not by us. */
    public function test_a_rejected_token_is_reported_against_the_token(): void
    {
        $problem = (new GitRemoteProbe())->problem(
            'git_repo',
            'https://github.com/vvolv/definitely-private-xyz.git',
            'ghp_' . str_repeat('a', 36)
        );

        $this->assertNotNull($problem);
        $this->assertSame('git_token_rejected', $problem['code']);
        $this->assertSame('git_token', $problem['field']);
    }

    public function test_a_host_that_does_not_resolve_is_unreachable_and_retryable(): void
    {
        $problem = (new GitRemoteProbe())->problem(
            'git_repo',
            'https://nonexistent-host-' . bin2hex(random_bytes(6)) . '.invalid/o/r.git',
            null
        );

        $this->assertNotNull($problem);
        $this->assertSame('git_repo_unreachable', $problem['code']);
        $this->assertTrue($problem['retryable']);
    }

    /**
     * A socket that is never accept()ed: the kernel completes the handshake,
     * so git waits on a TLS reply that never comes. An unroutable address
     * fails fast on some hosts and would exercise the wrong branch.
     *
     * Both bounds matter -- the lower one tells a timeout apart from a
     * connection refused in milliseconds, and proves the retry happened.
     */
    public function test_a_host_that_never_answers_times_out_after_a_retry(): void
    {
        $budget = 2;
        [$problem, $elapsed] = $this->probeSilentListener(new GitRemoteProbe($budget, 2));

        $this->assertNotNull($problem);
        // Its own code: a timeout is not a refused connection, nor a private repository.
        $this->assertSame('git_repo_timeout', $problem['code']);
        $this->assertTrue($problem['retryable'], 'a timeout is not the caller\'s mistake to fix');
        $this->assertStringContainsString("within {$budget}s (2 attempts)", $problem['message']);
        $this->assertStringContainsString('nothing was created', $problem['message']);
        $this->assertStringContainsString('says nothing about whether', $problem['message']);
        $this->assertStringNotContainsStringIgnoringCase('private', $problem['message']);

        $this->assertGreaterThanOrEqual(2 * $budget - 0.2, $elapsed, 'the timeout was not retried');
        $this->assertLessThan(2 * $budget + 5.0, $elapsed, 'the probe ran past its own timeout');
    }

    public function test_a_single_attempt_probe_does_not_retry(): void
    {
        $budget = 2;
        [$problem, $elapsed] = $this->probeSilentListener(new GitRemoteProbe($budget, 1));

        $this->assertNotNull($problem);
        $this->assertSame('git_repo_timeout', $problem['code']);
        $this->assertStringContainsString("within {$budget}s.", $problem['message']);
        $this->assertGreaterThanOrEqual($budget - 0.2, $elapsed);
        $this->assertLessThan(2 * $budget - 0.2, $elapsed, 'a single-attempt probe retried');
    }

    /**
     * A refusal is an answer: retrying it would only double the wait.
     */
    public function test_an_unresolvable_host_is_not_retried(): void
    {
        $started = microtime(true);
        $problem = (new GitRemoteProbe(30, 2))->problem(
            'git_repo',
            'https://nonexistent-host-' . bin2hex(random_bytes(6)) . '.invalid/o/r.git',
            null
        );

        $this->assertSame('git_repo_unreachable', $problem['code'] ?? null);
        $this->assertLessThan(30.0, microtime(true) - $started);
    }

    /** @return array{0: ?array<string, mixed>, 1: float} */
    private function probeSilentListener(GitRemoteProbe $probe): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "could not open a listener: {$errstr}");

        try {
            $name = stream_socket_get_name($server, false);
            $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

            $started = microtime(true);
            $problem = $probe->problem('git_repo', "https://127.0.0.1:{$port}/o/r.git", null);

            return [$problem, microtime(true) - $started];
        } finally {
            fclose($server);
        }
    }

    /** Nothing named, nothing probed -- and no network call made. */
    public function test_an_empty_repository_is_not_probed(): void
    {
        $this->assertNull((new GitRemoteProbe())->problem('git_repo', '', null));
        $this->assertNull((new GitRemoteProbe())->problem('git_repo', '   ', null));
    }

    /** A probe failure must never put the credential in the response. */
    public function test_the_token_never_reaches_the_problem(): void
    {
        $token = 'ghp_' . str_repeat('z', 36);
        $problem = (new GitRemoteProbe())->problem(
            'git_repo',
            'https://github.com/vvolv/definitely-private-xyz.git',
            $token
        );

        $this->assertNotNull($problem);
        $this->assertStringNotContainsString($token, json_encode($problem) ?: '');
    }

    // ---- A branch the remote does not have ---------------------------------

    /** Output of `git ls-remote --symref -- <url> HEAD refs/heads/* refs/tags/*`. */
    private const REFS = "ref: refs/heads/main\tHEAD\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\tHEAD\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/heads/feature/nested\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/heads/main\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/tags/v1.0\n"
        . "0dfb2685d04204d4232f1075904de619b900fcb0\trefs/tags/v2.0\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/tags/v2.0^{}\n";

    public function test_a_listed_branch_or_tag_is_not_a_problem(): void
    {
        foreach (['main', 'feature/nested', 'v1.0', 'v2.0'] as $ref) {
            $this->assertNull(GitRemoteProbe::branchProblem('git_branch', $ref, self::REFS, 'github.com'), $ref);
        }
    }

    public function test_a_missing_branch_names_the_default_and_the_closest(): void
    {
        $problem = GitRemoteProbe::branchProblem('git_branch', 'mian', self::REFS, 'github.com');

        $this->assertSame('git_branch', $problem['field']);
        $this->assertSame('git_branch_not_found', $problem['code']);
        $this->assertSame('main', $problem['suggestion']);
        $this->assertSame(['main', 'feature/nested'], $problem['examples']);
        $this->assertStringContainsString("no branch or tag named 'mian'", $problem['message']);
        $this->assertStringContainsString("The default branch is 'main'", $problem['message']);
        $this->assertArrayNotHasKey('retryable', $problem);
    }

    public function test_nothing_close_is_not_guessed(): void
    {
        $problem = GitRemoteProbe::branchProblem('git_branch', 'no-such-branch-xyz', self::REFS, 'github.com');

        $this->assertArrayNotHasKey('suggestion', $problem);
        $this->assertSame(['main', 'feature/nested'], $problem['examples']);
    }

    /** Through the real probe and a real git, against a local remote. */
    public function test_the_probe_asks_about_the_branch_in_the_same_ls_remote(): void
    {
        $dir = sys_get_temp_dir() . '/pa-branch-probe-' . bin2hex(random_bytes(4));
        $git = static fn (string ...$args): string => (string) shell_exec(
            'git -C ' . escapeshellarg($dir) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1'
        );
        mkdir($dir);

        try {
            $git('init', '-q', '-b', 'main');
            $git('-c', 'user.email=t@e.st', '-c', 'user.name=t', 'commit', '-q', '--allow-empty', '-m', 'x');
            $git('tag', 'v1.0');

            $probe = new GitRemoteProbe();
            $this->assertNull($probe->problem('git_repo', 'file://' . $dir, null, 'git_token', 'main'));
            $this->assertNull($probe->problem('git_repo', 'file://' . $dir, null, 'git_token', 'v1.0'));
            $this->assertNull($probe->problem('git_repo', 'file://' . $dir, null), 'no branch, no branch check');

            $problem = $probe->problem('git_repo', 'file://' . $dir, null, 'git_token', 'no-such-branch-xyz');
            $this->assertSame('git_branch_not_found', $problem['code'] ?? null);
            $this->assertSame(['main'], $problem['examples']);
        } finally {
            shell_exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
