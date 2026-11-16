<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitProbeResult;
use App\Lib\Deploy\Source\GitRefsUnavailableException;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Http\OutboundUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * How the probe reads each answer ls-remote can give, with `run()` faked: the outcome,
 * the problem it reports, how often it asks, and that a credential helper never outlives it.
 */
class GitRemoteProbeOutcomeTest extends TestCase
{
    private const URL = 'https://example.test/o/r.git';

    protected function setUp(): void
    {
        parent::setUp();
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(4)]);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        parent::tearDown();
    }

    /**
     * @param array{timedOut: bool, ok: bool, stderr: string}|\Throwable $answer
     */
    private function probe(array|\Throwable $answer, int $attempts = 2): object
    {
        return new class ($answer, $attempts) extends GitRemoteProbe {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @var list<?string> */
            public array $askPassSeen = [];

            public function __construct(private array|\Throwable $answer, int $attempts)
            {
                parent::__construct(7, $attempts);
            }

            protected function run(array $command): array
            {
                $this->commands[] = $command;
                $askPass = null;
                foreach ($command as $arg) {
                    if (str_starts_with($arg, 'GIT_ASKPASS=')) {
                        $askPass = substr($arg, strlen('GIT_ASKPASS='));
                    }
                }
                $this->askPassSeen[] = $askPass !== null && is_file($askPass) ? $askPass : null;
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
    }

    /**
     * @param array{timedOut: bool, ok: bool, stderr: string} $answer
     * @param ?array<string, mixed> $problem
     */
    #[DataProvider('answers')]
    public function test_each_answer_is_read_the_same_way(
        array $answer,
        ?string $token,
        int $attempts,
        string $outcome,
        int $runs,
        ?array $problem
    ): void {
        $probe = $this->probe($answer, $attempts);

        $result = $probe->check('git_repo', self::URL, $token);

        $this->assertSame($outcome, $result->outcome);
        $this->assertSame($problem, $result->problem);
        $this->assertCount($runs, $probe->commands);
    }

    public static function answers(): array
    {
        $timedOut = ['timedOut' => true, 'ok' => false, 'stderr' => ''];
        $refused = static fn (string $stderr): array => ['timedOut' => false, 'ok' => false, 'stderr' => $stderr];

        return [
            'timed out twice' => [$timedOut, null, 2, 'unchecked', 2, [
                'field' => 'git_repo',
                'code' => 'git_repo_timeout',
                'message' => 'example.test did not answer within 7s (2 attempts). This says nothing about whether the repository exists or is public: it was not checked, so nothing was created. Retry; if it keeps timing out, the host is down or slow to reach from this engine.',
                'retryable' => true,
            ]],
            'timed out once' => [$timedOut, null, 1, 'unchecked', 1, [
                'field' => 'git_repo',
                'code' => 'git_repo_timeout',
                'message' => 'example.test did not answer within 7s. This says nothing about whether the repository exists or is public: it was not checked, so nothing was created. Retry; if it keeps timing out, the host is down or slow to reach from this engine.',
                'retryable' => true,
            ]],
            'answered' => [['timedOut' => false, 'ok' => true, 'stderr' => ''], null, 2, 'verified', 1, null],
            'unreachable' => [$refused('fatal: unable to access: Could not resolve host: example.test'), null, 2, 'unchecked', 1, [
                'field' => 'git_repo',
                'code' => 'git_repo_unreachable',
                'message' => 'Could not reach example.test. The engine must be able to open an HTTPS connection to it.',
                'retryable' => true,
            ]],
            'auth with token' => [$refused('remote: HTTP Basic: Access denied'), 'tok', 2, 'refused', 1, [
                'field' => 'git_token',
                'code' => 'git_token_rejected',
                'message' => 'The token was refused by example.test. Check it has not expired and that it grants read access to this repository.',
            ]],
            'auth without token' => [$refused('fatal: could not read Username for https://example.test'), null, 2, 'refused', 1, [
                'field' => 'git_repo',
                'code' => 'git_repo_requires_token',
                'message' => 'This repository is private, or does not exist. Send a token that can read it, or check the address.',
            ]],
            'missing without token' => [$refused('remote: Repository not found.'), null, 2, 'refused', 1, [
                'field' => 'git_repo',
                'code' => 'git_repo_requires_token',
                'message' => 'This repository is private, or does not exist. Send a token that can read it, or check the address.',
            ]],
            'missing with token' => [$refused('remote: Repository not found.'), 'tok', 2, 'refused', 1, [
                'field' => 'git_repo',
                'code' => 'git_repo_not_found',
                'message' => 'No such repository on example.test, or the token cannot see it.',
            ]],
            'anything else' => [$refused("fatal: something odd\nsecond line"), null, 2, 'refused', 1, [
                'field' => 'git_repo',
                'code' => 'git_repo_unreadable',
                'message' => 'The repository could not be read: fatal: something odd',
            ]],
            'nothing said' => [$refused(''), null, 2, 'refused', 1, [
                'field' => 'git_repo',
                'code' => 'git_repo_unreadable',
                'message' => 'The repository could not be read: no output from git',
            ]],
        ];
    }

    public function test_a_branch_is_asked_for_in_the_same_ls_remote_and_checked_against_its_output(): void
    {
        $probe = $this->probe(['timedOut' => false, 'ok' => true, 'stderr' => '']);

        $problem = $probe->problem('git_repo', self::URL, null, 'git_token', ' main ', 'git_branch');

        $this->assertSame([
            'field' => 'git_branch',
            'code' => 'git_branch_not_found',
            'message' => 'example.test lists no branches or tags for this repository, so \'main\' cannot be checked out.',
            'expected' => 'the name of a branch or tag in the repository',
        ], $problem);
        $this->assertSame([
            'git',
            '-c',
            'http.curloptResolve=example.test:443:' . TestNetwork::routable(4),
            '-c',
            'http.followRedirects=false',
            '-c',
            'credential.helper=',
            '-c',
            'core.askpass=',
            'ls-remote',
            '--symref',
            '--',
            self::URL,
            'HEAD',
            'refs/heads/*',
            'refs/tags/*',
        ], $probe->commands[0]);
    }

    public function test_an_engine_side_failure_is_unchecked_and_says_nothing(): void
    {
        $this->assertNull($this->probe(new \RuntimeException('boom'))->problem('git_repo', self::URL, 'tok'));
        $this->assertSame(
            GitProbeResult::unchecked()->outcome,
            $this->probe(new \RuntimeException('boom'))->check('git_repo', self::URL, null)->outcome
        );
    }

    public function test_an_egress_refusal_is_reported_against_the_repository(): void
    {
        $this->assertSame(
            ['field' => 'git_repo', 'code' => 'host_refused', 'message' => 'nope'],
            $this->probe(new \InvalidArgumentException('nope'))->problem('git_repo', self::URL, null)
        );
    }

    public function test_an_empty_address_is_not_probed(): void
    {
        $probe = $this->probe(['timedOut' => false, 'ok' => true, 'stderr' => '']);

        $this->assertNull($probe->problem('git_repo', '  ', null));
        $this->assertSame([], $probe->commands);
    }

    /**
     * @return list<array{0: array{timedOut: bool, ok: bool, stderr: string}|\Throwable}>
     */
    public static function tokenRuns(): array
    {
        return [
            'answered' => [['timedOut' => false, 'ok' => true, 'stderr' => '']],
            'refused' => [['timedOut' => false, 'ok' => false, 'stderr' => 'remote: HTTP Basic: Access denied']],
            'engine failure' => [new \RuntimeException('boom')],
            'egress refusal' => [new \InvalidArgumentException('nope')],
        ];
    }

    /**
     * @param array{timedOut: bool, ok: bool, stderr: string}|\Throwable $answer
     */
    #[DataProvider('tokenRuns')]
    public function test_the_credential_helper_exists_while_git_runs_and_is_gone_after(array|\Throwable $answer): void
    {
        $probe = $this->probe($answer);

        $probe->check('git_repo', self::URL, 'tok');

        $this->assertCount(1, $probe->askPassSeen);
        $askPass = $probe->askPassSeen[0];
        $this->assertNotNull($askPass, 'git ran without the credential helper on disk');
        $this->assertFileDoesNotExist($askPass);
        $this->assertDirectoryDoesNotExist(dirname($askPass));
    }

    /** A script left on disk by a write that stopped partway, or whose mode could not be set, goes with its directory. */
    public function test_a_credential_helper_that_was_not_prepared_leaves_nothing_behind(): void
    {
        $probe = $this->probeWithAnUnpreparedAskPass();

        $this->assertSame(GitProbeResult::UNCHECKED, $probe->check('git_repo', self::URL, 'tok')->outcome);
        try {
            $probe->refs(self::URL, 'tok');
            $this->fail('The ref listing ran without its credential helper');
        } catch (GitRefsUnavailableException) {
        }

        $this->assertCount(2, $probe->workspaces);
        foreach ($probe->workspaces as $workspace) {
            $this->assertDirectoryDoesNotExist($workspace);
        }
        $this->assertSame(0, $probe->runs);
    }

    private function probeWithAnUnpreparedAskPass(): object
    {
        return new class (7, 1) extends GitRemoteProbe {
            /** @var list<string> */
            public array $workspaces = [];

            public int $runs = 0;

            protected function writeAskPass(string $workspace, string $token, string $repoUrl): ?string
            {
                $this->workspaces[] = $workspace;
                parent::writeAskPass($workspace, $token, $repoUrl);

                return null;
            }

            protected function run(array $command): array
            {
                $this->runs++;

                return ['timedOut' => false, 'ok' => true, 'stderr' => ''];
            }
        };
    }
}
