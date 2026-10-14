<?php

namespace Tests\Unit\Models;

use App\Lib\Project\ApiAccess;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A queue worker keeps the settings table in a static for the life of the
 * process, and a setting written by the CLI or the API is written by another
 * one. Every job has to start from what is stored now.
 */
class SettingFreshPerJobTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_job_reads_a_setting_written_after_the_worker_read_the_table(): void
    {
        Setting::set(ApiAccess::SETTING, 'as the worker first saw it');
        $this->assertSame('as the worker first saw it', Setting::get(ApiAccess::SETTING));

        DB::table('settings')->where('name', ApiAccess::SETTING)->update(['value' => 'written by another process']);

        ReadingJob::$seen = null;
        dispatch(new ReadingJob());

        $this->assertSame('written by another process', ReadingJob::$seen);
    }

    public function test_the_first_read_of_a_job_does_not_see_what_the_last_one_cleared(): void
    {
        Setting::clearRuntimeSettings();
        Setting::set(ApiAccess::SETTING, 'first');

        ReadingJob::$seen = null;
        dispatch(new ReadingJob());
        $this->assertSame('first', ReadingJob::$seen);

        DB::table('settings')->where('name', ApiAccess::SETTING)->update(['value' => 'second']);

        ReadingJob::$seen = null;
        dispatch(new ReadingJob());
        $this->assertSame('second', ReadingJob::$seen);
    }
}

/** Reads the setting the way a job does, and keeps what it saw. */
class ReadingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public static ?string $seen = null;

    public function handle(): void
    {
        self::$seen = Setting::get(ApiAccess::SETTING);
    }
}
