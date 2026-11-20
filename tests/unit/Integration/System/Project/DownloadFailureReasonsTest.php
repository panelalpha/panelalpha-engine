<?php

namespace Tests\Integration\System\Project;

use App\System\Project\DownloadFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A failed download says why in a short sentence of its own, from curl's exit code, and never repeats curl. */
class DownloadFailureReasonsTest extends TestCase
{
    private const URL = 'https://files.example.com/site.zip';

    /** @return iterable<string, array{int, string, string}> */
    public static function reasons(): iterable
    {
        yield 'unresolved host' => [6, 'resolve', 'files.example.com could not be resolved.'];
        yield 'unresolved proxy' => [5, 'resolve', 'files.example.com could not be resolved.'];
        yield 'refused connection' => [7, 'connect', 'Could not connect to files.example.com.'];
        yield 'timeout' => [28, 'timeout', 'The download from files.example.com timed out.'];
        yield 'certificate' => [60, 'tls', 'Could not set up a secure connection to files.example.com: its certificate or TLS setup was refused.'];
        yield 'empty reply' => [52, 'interrupted', 'The connection to files.example.com broke off before the file arrived.'];
        yield 'anything else' => [3, 'other', 'Could not download '.self::URL.' (curl exit code 3).'];
    }

    #[DataProvider('reasons')]
    public function test_each_exit_code_is_reported_on_the_url_with_its_reason(int $exitCode, string $reason, string $message): void
    {
        $problem = DownloadFailure::problem($exitCode, self::URL, 0);

        $this->assertSame([[
            'field' => 'url',
            'code' => DownloadFailure::CODE,
            'message' => $message,
            'reason' => $reason,
        ]], $problem->problems);
    }

    public function test_an_http_failure_carries_the_status_the_server_answered(): void
    {
        $problem = DownloadFailure::problem(22, self::URL, 404);

        $this->assertSame([[
            'field' => 'url',
            'code' => DownloadFailure::CODE,
            'message' => self::URL.' answered HTTP 404.',
            'reason' => 'http',
            'http_status' => 404,
        ]], $problem->problems);
    }

    public function test_a_file_that_could_not_be_written_is_reported_on_the_path(): void
    {
        $problem = DownloadFailure::problem(23, self::URL, 200);

        $this->assertSame([[
            'field' => 'path',
            'code' => DownloadFailure::WRITE_FAILED,
            'message' => "The downloaded file could not be written into the folder: the project's account cannot write there, or the disk is full.",
        ]], $problem->problems);
    }
}
