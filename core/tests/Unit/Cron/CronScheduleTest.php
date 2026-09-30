<?php

namespace Tests\Unit\Cron;

use App\Lib\Helpers\CronSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether five fields describe a schedule crond will accept.
 *
 * The validator had no tests while it lived inside a controller. What it gets
 * wrong is not visible as an error: a schedule crond silently refuses is a
 * cron job that never runs and never says why.
 */
class CronScheduleTest extends TestCase
{
    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function schedule(array $overrides = []): array
    {
        return $overrides + [
            'minute' => '0',
            'hour' => '0',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
        ];
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function validSchedules(): array
    {
        return [
            'every minute' => [['minute' => '*']],
            'step' => [['minute' => '*/5']],
            'stepped range' => [['minute' => '10-50/5']],
            'list' => [['hour' => '0,6,12,18']],
            'numeric range' => [['hour' => '9-17']],
            'month names' => [['month' => 'jan,feb,dec']],
            'weekday names' => [['day_of_week' => 'mon-fri']],
            'sunday as seven' => [['day_of_week' => '7']],
            'sunday as zero' => [['day_of_week' => '0']],
            'upper case names' => [['month' => 'JAN']],
            'bounds' => [['minute' => '59', 'hour' => '23', 'day_of_month' => '31', 'month' => '12']],
        ];
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('validSchedules')]
    public function test_it_accepts_what_crond_accepts(array $overrides): void
    {
        $this->assertSame([], CronSchedule::errors($this->schedule($overrides)));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidSchedules(): array
    {
        return [
            'minute above range' => [['minute' => '60'], 'minute: value 60 out of bounds (0-59)'],
            'hour above range' => [['hour' => '24'], 'hour: value 24 out of bounds (0-23)'],
            'day zero' => [['day_of_month' => '0'], 'day_of_month: value 0 out of bounds (1-31)'],
            'weekday above seven' => [['day_of_week' => '8'], 'day_of_week: value 8 out of bounds (0-7)'],
            'backwards range' => [['hour' => '17-9'], 'hour: range start greater than end'],
            'range out of bounds' => [['minute' => '10-70'], 'minute: range values out of bounds'],
            'open range' => [['minute' => '5-'], "minute: invalid range '5-'"],
            'zero step' => [['minute' => '*/0'], 'minute: invalid step value'],
            'non numeric step' => [['minute' => '1-5/x'], 'minute: invalid step value'],
            'unknown name' => [['month' => 'smarch'], "month: invalid token 'smarch'"],
            'name in the wrong field' => [['minute' => 'mon'], "minute: invalid token 'mon'"],
            'empty field' => [['hour' => ''], 'hour: empty value'],
            'empty list element' => [['hour' => '1,,2'], 'hour: empty list element'],
            'unsupported nickname' => [['minute' => '@daily'], "minute: invalid token '@daily'"],
            // Whitespace ends a crontab field, so `9 , 17` would be three fields.
            'space around a comma' => [['hour' => '9 , 17'], 'hour: whitespace is not allowed'],
            'surrounding space' => [['hour' => ' 9 '], 'hour: whitespace is not allowed'],
            'space inside a range' => [['minute' => '1 - 5'], 'minute: whitespace is not allowed'],
            'tab in a list' => [['day_of_week' => "1,\t2"], 'day_of_week: whitespace is not allowed'],
        ];
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('invalidSchedules')]
    public function test_it_rejects_what_crond_would_refuse(array $overrides, string $expected): void
    {
        $errors = CronSchedule::errors($this->schedule($overrides));

        $this->assertNotEmpty($errors, 'expected a rejection');
        $this->assertStringContainsString($expected, implode(' | ', $errors));
    }

    public function test_it_reports_every_bad_field_at_once(): void
    {
        // Someone fixing an expression should see everything wrong with it in
        // one round trip, not discover the next problem after fixing the first.
        $errors = CronSchedule::errors([
            'minute' => '90',
            'hour' => '5-1',
            'day_of_month' => '',
            'month' => 'xxx',
            'day_of_week' => '1,,2',
        ]);

        $this->assertCount(5, $errors);
    }

    public function test_a_missing_field_is_an_empty_one(): void
    {
        // The API can be called without a field at all; that is the same
        // problem as sending it blank, and gets the same message.
        $this->assertSame(['minute: empty value'], CronSchedule::errors([
            'hour' => '0', 'day_of_month' => '*', 'month' => '*', 'day_of_week' => '*',
        ]));
    }

    public function test_a_step_narrows_the_range_before_it(): void
    {
        // The base is validated as though the step were not there, so an
        // out-of-bounds base is still caught.
        $this->assertSame([], CronSchedule::errors($this->schedule(['minute' => '0-59/15'])));
        $this->assertNotEmpty(CronSchedule::errors($this->schedule(['minute' => '0-70/15'])));
    }

    public function test_the_field_order_is_crons_own(): void
    {
        $this->assertSame(
            ['minute', 'hour', 'day_of_month', 'month', 'day_of_week'],
            CronSchedule::fieldNames()
        );
    }
}
