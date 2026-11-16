<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;

/**
 * An application that failed but answered its own error page with a 2xx (Roundcube's "Internal Error"): matched on the
 * whole `<title>` of a page small enough to be the whole body, never on words in it.
 */
final class ErrorPageCheck
{
    public const ID = 'app-error-page';

    /** The word `no-server-error` already sets for a 5xx: the same failure, answered with another status. */
    public const SERVING = 'error_page';

    /** Whole titles, compared lowercased with whitespace collapsed and trailing punctuation dropped. */
    private const TITLES = [
        'internal error',
        'internal server error',
        'server error',
        'application error',
        'unexpected error',
    ];

    /** Whoops' own titles, and a 500 named as an error by its code: "500px" or "500 Startups" are not one. */
    private const TITLE_PATTERN = '/^(?:whoops(?:[!,]|$)|(?:http\s+)?(?:error\s+)?500$|500\b.*\berror\b|(?:http\s+)?error\s+500\b)/u';

    /**
     * @param  array{serving: string, checks: list<array<string, mixed>>}  $verdict
     * @return array{serving: string, checks: list<array<string, mixed>>}
     */
    public static function apply(array $verdict, ProbedResponse $front): array
    {
        $title = self::errorTitle($front);
        if ($title === null) {
            return $verdict;
        }
        $outranked = AppHealth::hasFailedError($verdict['checks']);
        $verdict['checks'][] = self::failedCheck($front, $title);
        if (! $outranked) {
            $verdict['serving'] = self::SERVING;
        }

        return $verdict;
    }

    /** The page's title when it is an error page's, as the page wrote it. */
    public static function errorTitle(ProbedResponse $front): ?string
    {
        if (! self::isWholeSuccessfulPage($front)) {
            return null;
        }
        $title = self::titleOf($front->body);

        return $title !== null && self::isErrorTitle($title) ? $title : null;
    }

    private static function isWholeSuccessfulPage(ProbedResponse $front): bool
    {
        return $front->status >= 200 && $front->status < 300
            && strlen($front->body) < ProbedResponse::SAMPLE_BYTES;
    }

    private static function titleOf(string $body): ?string
    {
        if (preg_match('~<title\b[^>]*>(.*?)</title>~is', $body, $match) !== 1) {
            return null;
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $title === '' ? null : $title;
    }

    private static function isErrorTitle(string $title): bool
    {
        $normalised = rtrim(mb_strtolower($title), " .!:\u{2026}");

        return in_array($normalised, self::TITLES, true) || preg_match(self::TITLE_PATTERN, $normalised) === 1;
    }

    /** @return array<string, mixed> */
    private static function failedCheck(ProbedResponse $front, string $title): array
    {
        return [
            'id' => self::ID,
            'group' => CheckRegistry::BASELINE,
            'status' => CheckResult::STATUS_FAIL,
            'severity' => HealthCheck::SEVERITY_ERROR,
            'title' => "The front page answered {$front->status} with the application's own error page.",
            'detail' => "{$front->url} is titled \"{$title}\".",
            'fix' => 'The application failed and showed its error page instead of the site. Read the container '
                .'output, or the application\'s error log, to see why.',
            'evidence' => ['url' => $front->url, 'http_code' => $front->status, 'title' => $title],
        ];
    }
}
