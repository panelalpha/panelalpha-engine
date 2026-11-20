<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployFinishLine;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployStatus;
use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Finding;
use App\System\Project\Dind\AppHealth;
use Tests\TestCase;

class DeployLoggerPartialTest extends TestCase
{
    private ?string $username = null;

    protected function tearDown(): void
    {
        if ($this->username !== null) {
            DeployLogger::deleteUserLogs($this->username);
        }
        parent::tearDown();
    }

    private function logger(): DeployLogger
    {
        $this->username = 'partial-'.bin2hex(random_bytes(6));

        return DeployLogger::start($this->username);
    }

    public function test_a_partial_deploy_keeps_its_warnings_and_no_error(): void
    {
        $logger = $this->logger();
        $logger->finishPartial([
            new Finding(AppHealth::CODE_NOT_ANSWERING, AppHealth::NOT_ANSWERING),
            new Finding(PublicUrl::CERTIFICATE_REFUSED, 'Refused.', ['domain' => 'shop.example.com']),
        ]);

        $latest = $logger->readLatest();
        $this->assertSame(DeployLogger::STATUS_PARTIAL, $latest['status']);
        $this->assertNull($latest['error']);
        $this->assertSame([
            ['code' => AppHealth::CODE_NOT_ANSWERING, 'message' => AppHealth::NOT_ANSWERING],
            ['code' => PublicUrl::CERTIFICATE_REFUSED, 'message' => 'Refused.', 'domain' => 'shop.example.com'],
        ], $latest['warnings']);

        $last = end($logger->read()['lines']);
        $this->assertSame(DeployLogger::LEVEL_WARN, $last['level']);
        $this->assertSame('Deploy finished with warnings: '.AppHealth::NOT_ANSWERING.' | Refused.', $last['msg']);
    }

    public function test_a_finished_deploy_that_warned_about_nothing_has_an_empty_list(): void
    {
        $logger = $this->logger();
        $logger->finish(DeployLogger::STATUS_SUCCESS);

        $this->assertSame([], $logger->readLatest()['warnings']);
        $this->assertSame(DeployLogger::LEVEL_OK, end($logger->read()['lines'])['level']);
    }

    public function test_a_cancel_drops_the_warnings_with_the_verdict(): void
    {
        $logger = $this->logger();
        DeployLogger::requestCancel($this->username);
        $logger->finishPartial([new Finding(AppHealth::CODE_NOT_ANSWERING, AppHealth::NOT_ANSWERING)]);

        $latest = $logger->readLatest();
        $this->assertSame(DeployLogger::STATUS_CANCELLED, $latest['status']);
        $this->assertSame([], $latest['warnings']);
    }

    public function test_a_failed_deploy_keeps_its_code_beside_the_sentence(): void
    {
        $logger = $this->logger();
        $logger->finishFailed(new Finding('out-of-memory', 'The build ran out of memory.'));

        $latest = $logger->readLatest();
        $this->assertSame('The build ran out of memory.', $latest['error']);
        $this->assertSame(['code' => 'out-of-memory', 'message' => 'The build ran out of memory.'], $latest['failure']);
        $this->assertEquals(new Finding('out-of-memory', 'The build ran out of memory.'), DeployStatus::failureOf($latest));
    }

    public function test_a_failure_nobody_named_is_a_deploy_failure_and_success_has_none(): void
    {
        $failed = $this->logger();
        $failed->finish(DeployLogger::STATUS_FAILED, 'Something broke.');
        $this->assertSame(DeployFailureExplainer::DEPLOY_FAILED, $failed->readLatest()['failure']['code']);

        $ok = $this->logger();
        $ok->finish(DeployLogger::STATUS_SUCCESS);
        $this->assertNull($ok->readLatest()['failure']);
        $this->assertNull(DeployStatus::failureOf($ok->readLatest()));
    }

    public function test_a_failure_recorded_before_failures_had_codes_is_unclassified(): void
    {
        $this->assertEquals(
            new Finding(Finding::UNCLASSIFIED, 'Boom.'),
            DeployStatus::failureOf(['status' => DeployLogger::STATUS_FAILED, 'error' => 'Boom.']),
        );
    }

    public function test_the_finish_line_gives_the_verdict_back_at_either_level(): void
    {
        $line = static fn (string $level): array => [['ts' => 1, 'level' => $level, 'msg' => 'Deploy finished with warnings: One. | Two.']];

        foreach ([DeployLogger::LEVEL_WARN, DeployLogger::LEVEL_ERROR] as $level) {
            $verdict = DeployFinishLine::find($line($level));
            $this->assertSame(DeployLogger::STATUS_PARTIAL, $verdict['status'], $level);
            $this->assertNull($verdict['error'], $level);
            $this->assertEquals([new Finding(Finding::UNCLASSIFIED, 'One.'), new Finding(Finding::UNCLASSIFIED, 'Two.')], $verdict['warnings'], $level);
        }

        $this->assertNull(DeployFinishLine::find([['ts' => 1, 'level' => DeployLogger::LEVEL_WARN, 'msg' => 'Deploy failed: not a verdict at warn']]));
    }

    public function test_a_partial_deploy_recorded_before_warnings_had_codes_reads_as_warnings(): void
    {
        $legacy = ['status' => DeployLogger::STATUS_PARTIAL, 'error' => 'One. | Two.'];

        $this->assertNull(DeployStatus::errorOf($legacy));
        $this->assertSame(['One.', 'Two.'], array_map(static fn (Finding $f): string => $f->message, DeployStatus::warningsOf($legacy)));

        $failed = ['status' => DeployLogger::STATUS_FAILED, 'error' => 'Boom.', 'warnings' => []];
        $this->assertSame('Boom.', DeployStatus::errorOf($failed));
        $this->assertSame([], DeployStatus::warningsOf($failed));
    }
}
