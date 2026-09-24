<?php

namespace App\Lib\Deploy\Telemetry;

/**
 * A private repository's path and branch, masked wherever they turn up in text.
 *
 * The report hashes the repository in its `repo` block, but the same URL is in
 * the clone line and in git's own errors -- "Authentication failed for
 * 'https://github.com/owner/repo/'" is the signature of every rejected token.
 * The mask is the same everywhere, so a failure still groups across installs.
 *
 * No Laravel dependencies — unit-testable.
 */
final class PrivateRepoMask
{
    public const REPO = '<repo>';
    public const BRANCH = '<branch>';

    /**
     * @param list<string> $patterns
     * @param list<string> $branches
     */
    private function __construct(
        private readonly array $patterns,
        private readonly array $branches,
    ) {
    }

    /**
     * Null when there is nothing to hide: a public repository, or none at all.
     * Takes every URL the report knows, since the account record and the
     * checkout's remote can disagree.
     *
     * @param list<?string> $urls
     * @param list<?string> $branches
     */
    public static function for(bool $private, array $urls, array $branches = []): ?self
    {
        if (!$private) {
            return null;
        }

        $patterns = [];
        foreach (array_unique(array_filter($urls, 'is_string')) as $url) {
            $parts = DeployReport::parseRepoUrl($url);
            if ($parts === null || $parts['path'] === '') {
                continue;
            }

            $host = preg_quote($parts['host'], '#');
            $path = preg_quote($parts['path'], '#');
            $tail = '(?:\.git)?(?![\w-])';

            // host/path in any URL or scp form. The bare path only when it has
            // an owner: a lone `app` would mask every word `app` in the log.
            $patterns[] = '#(?<=' . $host . '[/:])' . $path . $tail . '#i';
            if (str_contains($parts['path'], '/')) {
                $patterns[] = '#(?<![\w.-])' . $path . $tail . '#i';
            }
        }

        if ($patterns === []) {
            return null;
        }

        $names = [];
        foreach ($branches as $branch) {
            if (is_string($branch) && trim($branch) !== '') {
                $names[] = trim($branch);
            }
        }

        return new self(array_values(array_unique($patterns)), array_values(array_unique($names)));
    }

    public function apply(string $line): string
    {
        foreach ($this->patterns as $pattern) {
            $line = (string) preg_replace($pattern, self::REPO, $line);
        }

        // Only where the text says it is a branch: `main` is also a word.
        foreach ($this->branches as $branch) {
            $line = (string) preg_replace(
                '#(\bbranch\b[\'"]?\s*[:=]?\s*[\'"]?)' . preg_quote($branch, '#') . '(?![\w./-])#i',
                '$1' . self::BRANCH,
                $line
            );
        }

        return $line;
    }
}
