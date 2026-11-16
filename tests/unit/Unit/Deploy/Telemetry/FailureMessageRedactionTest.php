<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Telemetry\DeployReport;
use PHPUnit\Framework\TestCase;

class FailureMessageRedactionTest extends TestCase
{
    public function test_an_explained_failure_message_names_no_account(): void
    {
        $failure = $this->failureOf(StepWatchdog::MARKER.': "env PWD=/home/acme7x/project docker compose --project-directory /home/acme7x/project up -d" printed nothing for 902s. Last output: "fetch https://mirror.example.test/APKINDEX.tar.gz"');

        $this->assertSame('build-stalled', $failure['rule']);
        $this->assertStringNotContainsString('acme7x', (string) $failure['message']);
        $this->assertStringContainsString('/home/<account>/project', (string) $failure['message']);
        $this->assertStringNotContainsString('acme7x', $failure['signature']);
    }

    public function test_an_unexplained_failure_still_has_no_message(): void
    {
        $failure = $this->failureOf('the frobnicator declined to frob in /home/acme7x/project');

        $this->assertFalse($failure['explained']);
        $this->assertNull($failure['message']);
    }

    /**
     * @return array{stage: ?string, rule: ?string, explained: bool, message: ?string, signature: string}
     */
    private function failureOf(string $error): array
    {
        $report = DeployReport::build([
            'id' => '01JGXR0000000000000000000B',
            'occurred_at' => 1756137600,
            'outcome' => DeployReport::OUTCOME_FAILED,
            'tier' => DeployReport::TIER_METADATA,
            'install_id' => 'install-abc',
            'username' => 'acme7x',
            'latest' => ['id' => '20260825-131200-a1b2c3', 'stage' => 'running'],
            'details' => ['deploy_strategy' => 'dockerfile'],
            'error' => $error,
            'signal' => null,
        ]);

        return $report['failure'];
    }
}
