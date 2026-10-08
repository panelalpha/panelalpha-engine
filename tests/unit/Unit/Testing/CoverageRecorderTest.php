<?php

namespace Tests\Unit\Testing;

use App\Lib\Testing\CoverageRecorder;
use App\System\EnginePaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CoverageRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CoverageRecorder::reset();
        config(['app.debug' => true]);
    }

    protected function tearDown(): void
    {
        CoverageRecorder::reset();
        parent::tearDown();
    }

    public function test_coverage_files_live_beside_core_in_the_engine_tests_directory(): void
    {
        $dir = dirname(base_path()) . '/tests/coverage';

        $this->assertSame($dir . '/900001/recording', CoverageRecorder::recordingPath(900001));
        $this->assertSame($dir . '/900001/dumps', CoverageRecorder::dumpsDir(900001));
        $this->assertSame(EnginePaths::ENGINE_DIR . '/tests/coverage', config('testing.coverage_host_dir'));
    }

    public function test_is_recording_follows_recording_file(): void
    {
        $tokenId = 900001;
        $path = CoverageRecorder::recordingPath($tokenId);
        $this->assertFalse(CoverageRecorder::isRecording($tokenId));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
        try {
            $this->assertTrue(CoverageRecorder::isRecording($tokenId));
        } finally {
            File::delete($path);
            @rmdir(dirname($path));
        }
    }

    public function test_can_record_requires_debug_xdebug_and_recording_file(): void
    {
        $this->assertFalse(CoverageRecorder::canRecord(null));

        config(['app.debug' => false]);
        $this->assertFalse(CoverageRecorder::canRecord(1));

        config(['app.debug' => true]);
        if (!extension_loaded('xdebug')) {
            $this->assertFalse(CoverageRecorder::canRecord(1));
            return;
        }

        $this->assertFalse(CoverageRecorder::canRecord(900002));
    }

    public function test_around_without_recording_just_runs_work(): void
    {
        $called = false;
        $result = CoverageRecorder::around(null, function () use (&$called) {
            $called = true;
            return 7;
        });

        $this->assertTrue($called);
        $this->assertSame(7, $result);
        $this->assertFalse(CoverageRecorder::isActive());
    }

    public function test_nested_around_is_reentrant_when_recording(): void
    {
        if (!extension_loaded('xdebug')) {
            $this->markTestSkipped('xdebug required');
        }

        $tokenId = 900003;
        $rec = CoverageRecorder::recordingPath($tokenId);
        $dumps = CoverageRecorder::dumpsDir($tokenId);
        File::ensureDirectoryExists($dumps);
        File::put($rec, '');

        try {
            $depths = [];
            CoverageRecorder::around($tokenId, function () use (&$depths, $tokenId) {
                $depths[] = CoverageRecorder::isActive();
                CoverageRecorder::around($tokenId, function () use (&$depths) {
                    $depths[] = CoverageRecorder::isActive();
                    return null;
                }, 'inner');
                $depths[] = CoverageRecorder::isActive();
                return null;
            }, 'outer');

            $this->assertSame([true, true, true], $depths);
            $this->assertFalse(CoverageRecorder::isActive());

            $files = glob($dumps . '/*.rawcov') ?: [];
            $this->assertCount(1, $files, 'nested around must not write a second dump');
            $this->assertStringStartsWith('outer-', basename($files[0]));
        } finally {
            File::deleteDirectory(dirname($rec));
            CoverageRecorder::reset();
        }
    }
}
