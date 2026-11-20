<?php

namespace Tests\Unit\Cron;

use App\Lib\Helpers\CronSchedule;
use PHPUnit\Framework\TestCase;

/** A range whose ends are not values of the field, and a field the schedule does not have. */
class CronScheduleRangeTokensTest extends TestCase
{
    public function test_an_unknown_field_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown cron field 'second'");

        CronSchedule::fieldErrors('second', '*');
    }

    public function test_each_end_of_a_range_must_be_a_value_of_the_field(): void
    {
        $this->assertSame(["Invalid token 'x' in range 'x-5'"], CronSchedule::fieldErrors('hour', 'x-5'));
        $this->assertSame(["Invalid token 'y' in range '1-y'"], CronSchedule::fieldErrors('hour', '1-y'));
    }
}
