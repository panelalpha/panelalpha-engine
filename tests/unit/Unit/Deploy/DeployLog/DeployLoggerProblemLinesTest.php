<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogger;
use Tests\TestCase;

/**
 * A known problem whose cause is blank writes no `Why:` heading, and one without a version writes no "fixed in" line.
 */
class DeployLoggerProblemLinesTest extends TestCase
{
    private string $username = '';

    protected function tearDown(): void
    {
        gc_collect_cycles();
        if ($this->username !== '') {
            DeployLogger::deleteUserLogs($this->username);
        }
        parent::tearDown();
    }

    public function test_a_section_with_no_text_is_left_out(): void
    {
        $this->username = 'problem-'.bin2hex(random_bytes(6));
        $logger = DeployLogger::start($this->username);
        $write = new \ReflectionMethod(DeployLogger::class, 'writeProblem');
        $write->invoke($logger, [
            'title' => null,
            'body_why' => "  \n\n ",
            'body_fix' => "First line\n  second line  ",
        ], DeployLogger::STAGE_RUNNING);

        $lines = $logger->read()['lines'];
        $this->assertSame([
            'Known problem: PanelAlpha has a fix for this failure',
            'How to fix:',
            'First line',
            'second line',
        ], array_column($lines, 'msg'));
        $this->assertSame([DeployLogger::LEVEL_WARN, DeployLogger::LEVEL_INFO, DeployLogger::LEVEL_INFO, DeployLogger::LEVEL_INFO], array_column($lines, 'level'));
        $this->assertSame([DeployLogger::STAGE_RUNNING], array_values(array_unique(array_column($lines, 'stage'))));

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }
}
