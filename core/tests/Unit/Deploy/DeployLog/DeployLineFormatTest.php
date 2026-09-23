<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLineFormat;
use App\Lib\Deploy\DeployLog\DeployLogger;
use PHPUnit\Framework\TestCase;

class DeployLineFormatTest extends TestCase
{
    private const START = 1_000_000;

    public function test_a_line_carries_its_elapsed_time_and_message(): void
    {
        $rendered = DeployLineFormat::line(
            ['ts' => self::START + 75, 'level' => DeployLogger::LEVEL_INFO, 'msg' => 'Using strategy: static'],
            self::START
        );

        $this->assertNotNull($rendered);
        $this->assertStringContainsString('1:15', $rendered);
        $this->assertStringContainsString('Using strategy: static', $rendered);
    }

    public function test_dim_lines_are_the_subprocess_firehose_and_need_verbose(): void
    {
        $line = ['ts' => self::START, 'level' => DeployLogger::LEVEL_DIM, 'msg' => 'Container project-app-1 Created'];

        $this->assertNull(DeployLineFormat::line($line, self::START));
        $this->assertNotNull(DeployLineFormat::line($line, self::START, true));
    }

    public function test_an_empty_message_is_not_a_line(): void
    {
        $this->assertNull(DeployLineFormat::line(
            ['ts' => self::START, 'level' => DeployLogger::LEVEL_INFO, 'msg' => '   '],
            self::START
        ));
    }

    public function test_angle_brackets_from_a_build_are_not_read_as_style_tags(): void
    {
        $rendered = (string) DeployLineFormat::line(
            ['ts' => self::START, 'level' => DeployLogger::LEVEL_ERROR, 'msg' => 'cannot find <stdio.h>'],
            self::START
        );

        $this->assertStringContainsString('\\<stdio.h\\>', $rendered);
    }

    public function test_elapsed_time_never_goes_backwards(): void
    {
        // A line written before the first one we saw, and a deploy whose start
        // is unknown: both print a placeholder rather than a negative clock.
        $this->assertSame('  -:--', DeployLineFormat::elapsed(self::START - 5, self::START));
        $this->assertSame('  -:--', DeployLineFormat::elapsed(self::START, 0));
        $this->assertSame('  0:09', DeployLineFormat::elapsed(self::START + 9, self::START));
        $this->assertSame(' 12:30', DeployLineFormat::elapsed(self::START + 750, self::START));
    }

    public function test_a_stage_heading_names_the_stage(): void
    {
        $this->assertStringContainsString(
            'Building',
            DeployLineFormat::stage('building', self::START + 30, self::START)
        );
    }

    public function test_the_opening_stage_line_becomes_the_heading(): void
    {
        $rendered = (string) DeployLineFormat::line(
            ['ts' => self::START + 8, 'level' => DeployLogger::LEVEL_INFO, 'msg' => 'Starting stage: cloning'],
            self::START
        );

        $this->assertStringContainsString('Cloning', $rendered);
        $this->assertStringNotContainsString('Starting stage', $rendered);
        $this->assertStringContainsString('0:08', $rendered);
    }

    public function test_the_closing_stage_line_is_dropped(): void
    {
        $this->assertNull(DeployLineFormat::line(
            ['ts' => self::START, 'level' => DeployLogger::LEVEL_OK, 'msg' => "Stage 'cloning' finished"],
            self::START
        ));
    }

    public function test_only_a_finished_deploy_is_terminal(): void
    {
        $this->assertFalse(DeployLineFormat::isTerminal(DeployLogger::STATUS_RUNNING));
        $this->assertFalse(DeployLineFormat::isTerminal(null));
        foreach ([
            DeployLogger::STATUS_SUCCESS,
            DeployLogger::STATUS_PARTIAL,
            DeployLogger::STATUS_FAILED,
            DeployLogger::STATUS_CANCELLED,
        ] as $status) {
            $this->assertTrue(DeployLineFormat::isTerminal($status), $status);
        }
    }
}
