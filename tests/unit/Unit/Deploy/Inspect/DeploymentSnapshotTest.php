<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\DeploymentSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The deployed snapshot next to the current files.
 *
 * `drift` is the field an operator reads first, so what does and does not
 * count as drift is worth pinning down: a project that has never deployed has
 * none, a port frozen as a string is not drift against the same port as an
 * integer, and a strategy that changed is.
 */
class DeploymentSnapshotTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'deployment_status' => 'success',
            'deploy_source' => 'git',
            'deploy_strategy' => 'nextjs',
            'deploy_label' => 'Next.js',
            'deploy_runtime' => 'node',
            'deploy_port' => 3000,
            'app_port' => 3000,
            'git_repo' => 'https://github.com/owner/repo',
            'git_branch' => 'main',
            'git_commit' => 'aaaaaaaa',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(array $application = [], array $ports = []): array
    {
        return [
            'application' => array_merge(['strategy' => 'nextjs', 'runtime' => 'node'], $application),
            'ports' => array_merge(['primary' => 3000], $ports),
        ];
    }

    public function test_it_describes_what_the_last_deploy_decided(): void
    {
        $deployment = DeploymentSnapshot::describe($this->details());

        $this->assertSame('success', $deployment['status']);
        $this->assertSame('git', $deployment['source']);
        $this->assertSame('nextjs', $deployment['strategy']);
        $this->assertSame(3000, $deployment['port']);
        $this->assertSame('https://github.com/owner/repo', $deployment['repository']);
        $this->assertSame('aaaaaaaa', $deployment['commit']);
        $this->assertSame([], $deployment['warnings']);
    }

    public function test_an_account_that_never_deployed_reads_as_unknown(): void
    {
        $deployment = DeploymentSnapshot::describe([]);

        $this->assertSame('unknown', $deployment['status']);
        $this->assertNull($deployment['strategy']);
        $this->assertNull($deployment['commit']);
    }

    public function test_matching_files_are_not_drift(): void
    {
        $this->assertSame([], DeploymentSnapshot::drift($this->details(), $this->report(), 'aaaaaaaa'));
    }

    public function test_a_port_frozen_as_a_string_is_the_same_port(): void
    {
        $drift = DeploymentSnapshot::drift($this->details(['deploy_port' => '3000']), $this->report(), 'aaaaaaaa');

        $this->assertSame([], $drift);
    }

    public function test_it_reports_a_strategy_that_moved_on(): void
    {
        $drift = DeploymentSnapshot::drift(
            $this->details(['deploy_strategy' => 'fallback']),
            $this->report(),
            'aaaaaaaa'
        );

        $this->assertSame([
            ['field' => 'strategy', 'deployed' => 'fallback', 'detected' => 'nextjs'],
        ], $drift);
    }

    public function test_it_reports_a_checkout_ahead_of_the_deployed_commit(): void
    {
        $drift = DeploymentSnapshot::drift($this->details(), $this->report(), 'bbbbbbbb');

        $this->assertSame([
            ['field' => 'commit', 'deployed' => 'aaaaaaaa', 'detected' => 'bbbbbbbb'],
        ], $drift);
    }

    public function test_a_project_that_never_deployed_has_no_drift(): void
    {
        $this->assertSame([], DeploymentSnapshot::drift([], $this->report(), 'aaaaaaaa'));
    }

    public function test_an_unreadable_detection_is_not_reported_as_drift(): void
    {
        // Detection could not name a port; that is not the same as the port
        // having changed, and reporting it would be noise on every static site.
        $drift = DeploymentSnapshot::drift($this->details(), $this->report(ports: ['primary' => null]), 'aaaaaaaa');

        $this->assertSame([], $drift);
    }
}
