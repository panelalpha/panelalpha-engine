<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitProbeResult;
use PHPUnit\Framework\TestCase;
use Tests\Support\GitRemoteProbeOutcomeFixtures;
use Tests\Support\TestNetwork;

/**
 * How the probe reads each answer ls-remote can give, with `run()` faked: the outcome,
 * the problem it reports, how often it asks, and that a credential helper never outlives it.
 */
class GitRemoteProbeOutcomeTest extends TestCase
{
    use GitRemoteProbeOutcomeFixtures;

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
}
