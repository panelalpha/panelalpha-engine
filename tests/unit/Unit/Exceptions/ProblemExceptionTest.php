<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ProblemException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The contract two audiences depend on: `errors` unchanged for the panel,
 * `problems` beside it for anything that has to decide what to do next.
 */
class ProblemExceptionTest extends TestCase
{
    public function test_it_is_a_validation_exception_so_existing_catches_still_work(): void
    {
        $this->assertInstanceOf(
            ValidationException::class,
            ProblemException::one('domain', 'domain_taken', 'Taken.')
        );
    }

    public function test_errors_keeps_the_shape_every_client_already_reads(): void
    {
        $e = ProblemException::of([
            ['field' => 'username', 'code' => 'name_taken', 'message' => 'User already exists.'],
            ['field' => 'template', 'code' => 'template_not_found', 'message' => 'Template directory does not exist.'],
        ]);

        $this->assertSame([
            'username' => ['User already exists.'],
            'template' => ['Template directory does not exist.'],
        ], $e->errors());
    }

    /**
     * Two mistakes used to cost two round trips, because each was its own
     * throw. Reported together, they are one.
     */
    public function test_every_problem_survives_into_the_list(): void
    {
        $e = ProblemException::of([
            ['field' => 'username', 'code' => 'name_taken', 'message' => 'User already exists.'],
            ['field' => 'disk_space_limit', 'code' => 'invalid_value', 'message' => 'Invalid value.'],
        ]);

        $this->assertSame(['name_taken', 'invalid_value'], array_column($e->problems, 'code'));
        $this->assertSame(['username', 'disk_space_limit'], array_column($e->problems, 'field'));
    }

    public function test_one_carries_whatever_context_the_failure_had(): void
    {
        $e = ProblemException::one('deploy', 'php-version-mismatch', 'This project needs PHP 8.3.', [
            'stage' => 'cloning',
            'deploy_log_offset' => 0,
        ]);

        $this->assertSame([[
            'field' => 'deploy',
            'code' => 'php-version-mismatch',
            'message' => 'This project needs PHP 8.3.',
            'stage' => 'cloning',
            'deploy_log_offset' => 0,
        ]], $e->problems);

        $this->assertSame(['deploy' => ['This project needs PHP 8.3.']], $e->errors());
    }

    /**
     * Two problems on one field are two problems, not one overwriting the
     * other.
     */
    public function test_a_field_can_carry_more_than_one(): void
    {
        $e = ProblemException::of([
            ['field' => 'domain', 'code' => 'domain_taken', 'message' => 'Taken.'],
            ['field' => 'domain', 'code' => 'invalid_value', 'message' => 'Malformed.'],
        ]);

        $this->assertSame(['domain' => ['Taken.', 'Malformed.']], $e->errors());
        $this->assertCount(2, $e->problems);
    }
}
