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
     * connection refused in milliseconds.
     */
    public function test_a_host_that_never_answers_times_out_within_its_budget(): void
    {
        $budget = 3;
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "could not open a listener: {$errstr}");

        try {
            $name = stream_socket_get_name($server, false);
            $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

            $started = microtime(true);
            $problem = (new GitRemoteProbe($budget))->problem(
                'git_repo',
                "https://127.0.0.1:{$port}/o/r.git",
                null
            );
            $elapsed = microtime(true) - $started;
        } finally {
            fclose($server);
        }

        $this->assertNotNull($problem);
        $this->assertSame('git_repo_unreachable', $problem['code']);
        $this->assertTrue($problem['retryable'], 'a timeout is not the caller\'s mistake to fix');
        $this->assertStringContainsString("within {$budget}s", $problem['message']);
        $this->assertStringContainsString('nothing was created', $problem['message']);

        $this->assertGreaterThanOrEqual(
            $budget - 0.2,
            $elapsed,
            'returned before the budget, so this was not the timeout path'
        );
        $this->assertLessThan(
            $budget + 5.0,
            $elapsed,
            'the probe ran past its own timeout'
        );
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
}
