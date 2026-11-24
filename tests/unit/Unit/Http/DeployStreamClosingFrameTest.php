<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\UserController;
use App\Lib\Deploy\DeployLog\DeployLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The last NDJSON frame of a streamed create: a create that still has to receive its files is not a finished deploy, or the
 * wizard would treat the placeholder as the project.
 */
class DeployStreamClosingFrameTest extends TestCase
{
    public function test_a_create_waiting_for_its_files_continues(): void
    {
        $stages = [['name' => DeployLogger::STAGE_PREPARING, 'started_at' => 1, 'finished_at' => 2]];

        $frame = $this->closingFrame(['status' => DeployLogger::STATUS_WAITING, 'stage' => DeployLogger::STAGE_PREPARING, 'stages' => $stages]);

        $this->assertSame([
            'type' => 'continue',
            'status' => DeployLogger::STATUS_WAITING,
            'stage' => DeployLogger::STAGE_PREPARING,
            'stages' => $stages,
        ], $frame);
    }

    public function test_a_create_still_running_continues(): void
    {
        $frame = $this->closingFrame(['status' => DeployLogger::STATUS_RUNNING, 'stage' => DeployLogger::STAGE_RUNNING, 'stages' => []]);

        $this->assertSame('continue', $frame['type']);
    }

    /**
     * @param  array<string, mixed>  $latest
     * @return array<string, mixed>
     */
    private function closingFrame(array $latest): array
    {
        return (new ReflectionMethod(UserController::class, 'closingFrame'))->invoke(null, $latest, 'empty', null);
    }
}
