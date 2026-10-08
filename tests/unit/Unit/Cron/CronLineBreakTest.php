<?php

namespace Tests\Unit\Cron;

use App\Http\Middleware\Authenticate;
use App\Http\Requests\CronJobStoreRequest;
use App\Http\Requests\CronJobUpdateRequest;
use App\Models\User;
use Dsc\Cron\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A crontab entry is one line. A line break in the command made the cron
 * library throw after validation had passed (a 500), and one in a schedule
 * field was written through and split the entry into two crontab lines.
 */
class CronLineBreakTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function brokenFields(): array
    {
        return [
            'newline in command' => ['command', "echo a\necho b"],
            'carriage return in command' => ['command', "echo a\recho b"],
            'crlf in command' => ['command', "echo a\r\n* * * * * echo b"],
            'nul in command' => ['command', "echo a\0b"],
            'newline in a minute list' => ['minute', "0,\n5"],
            'newline inside hour' => ['hour', "0\n1"],
            'newline in day_of_month' => ['day_of_month', "*\n/2"],
            'newline in month' => ['month', "1,\n2"],
            'carriage return in day_of_week' => ['day_of_week', "1\r,2"],
        ];
    }

    #[DataProvider('brokenFields')]
    public function test_a_line_break_in_any_field_is_refused(string $field, string $value): void
    {
        foreach ([new CronJobStoreRequest(), new CronJobUpdateRequest()] as $request) {
            $validator = Validator::make([$field => $value] + $this->job(), $request->rules(), $request->messages());

            $this->assertTrue($validator->fails(), $request::class);
            $this->assertSame([$field], $validator->errors()->keys(), $request::class);
        }
    }

    public function test_a_single_line_job_still_passes(): void
    {
        $job = $this->job(['command' => "php artisan schedule:run >> /tmp/out.log 2>&1\t# tab ok"]);

        foreach ([new CronJobStoreRequest(), new CronJobUpdateRequest()] as $request) {
            $this->assertFalse(Validator::make($job, $request->rules())->fails(), $request::class);
        }
    }

    public function test_the_library_throws_on_a_multi_line_command(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Job())->setCommand("echo a\necho b");
    }

    public function test_create_and_update_answer_422_on_command_before_anything_is_written(): void
    {
        $this->user();
        // Only an inner line break survives: the ends are trimmed first.
        $job = $this->job(['command' => "echo a\n* * * * * echo b"]);

        $this->postJson('/api/projects/alice/cron-jobs', $job)
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');
        $this->putJson('/api/projects/alice/cron-jobs/abc', $job)
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');
    }

    /** @return array<string, array{string, string, string}> */
    public static function badSchedules(): array
    {
        return [
            'spaced hour list' => ['hour', '9 , 17', 'Whitespace is not allowed inside a field'],
            'minute out of range' => ['minute', '61', 'Value 61 out of bounds (0-59)'],
        ];
    }

    /**
     * Schedule errors are keyed by field like every other validation error,
     * not a bare list of "<field>: <message>" strings.
     */
    #[DataProvider('badSchedules')]
    public function test_a_bad_schedule_answers_422_under_its_field(string $field, string $value, string $message): void
    {
        $this->user();
        $job = $this->job([$field => $value]);

        foreach ([
            $this->postJson('/api/projects/alice/cron-jobs', $job),
            $this->putJson('/api/projects/alice/cron-jobs/abc', $job),
        ] as $response) {
            $response->assertStatus(422)
                ->assertJsonValidationErrors([$field => $message])
                ->assertJsonPath('errors', [$field => [$message]]);
        }
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function job(array $overrides = []): array
    {
        return $overrides + [
            'command' => 'echo mcp',
            'minute' => '*',
            'hour' => '*',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
        ];
    }

    private function user(): User
    {
        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.test';
        $user->password = 'secret';
        $user->setDetails(['template' => 'default']);
        $user->save();

        return $user;
    }
}
