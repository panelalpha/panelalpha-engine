<?php

namespace App\System\Project;

use App\Exceptions\ProblemException;

/**
 * Why a URL download failed, from curl's exit code: a short reason on `url`
 * (or on `path` when the file could not be written), never curl's own output.
 */
final class DownloadFailure
{
    public const CODE = 'download_failed';

    public const WRITE_FAILED = 'write_failed';

    /** curl exit codes, by the reason they are reported as. */
    private const REASONS = [
        'resolve' => [5, 6],
        'connect' => [7],
        'timeout' => [28],
        'http' => [22],
        'tls' => [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91],
        'interrupted' => [16, 18, 52, 55, 56, 92],
    ];

    private const CURL_WRITE_ERROR = 23;

    public static function problem(int $exitCode, string $url, int $httpStatus): ProblemException
    {
        if ($exitCode === self::CURL_WRITE_ERROR) {
            return ProblemException::one('path', self::WRITE_FAILED, 'The downloaded file could not be written into the folder: '
                ."the project's account cannot write there, or the disk is full.");
        }
        $reason = self::reason($exitCode);
        $extra = $reason === 'http' ? ['reason' => $reason, 'http_status' => $httpStatus] : ['reason' => $reason];

        return ProblemException::one('url', self::CODE, self::message($reason, $url, $exitCode, $httpStatus), $extra);
    }

    private static function reason(int $exitCode): string
    {
        foreach (self::REASONS as $reason => $codes) {
            if (in_array($exitCode, $codes, true)) {
                return $reason;
            }
        }

        return 'other';
    }

    private static function message(string $reason, string $url, int $exitCode, int $httpStatus): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return match ($reason) {
            'resolve' => "{$host} could not be resolved.",
            'connect' => "Could not connect to {$host}.",
            'timeout' => "The download from {$host} timed out.",
            'http' => "{$url} answered HTTP {$httpStatus}.",
            'tls' => "Could not set up a secure connection to {$host}: its certificate or TLS setup was refused.",
            'interrupted' => "The connection to {$host} broke off before the file arrived.",
            default => "Could not download {$url} (curl exit code {$exitCode}).",
        };
    }
}
