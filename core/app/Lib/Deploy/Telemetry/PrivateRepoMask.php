<?php

namespace App\Lib\Deploy\Telemetry;

/**
 * A private repository's path and branch, masked wherever they turn up in text, such as the clone line and git's
 * own errors. The mask is the same everywhere, so a failure still groups across installs.
 */
final class PrivateRepoMask
{
    public const REPO = '<repo>';

    public const BRANCH = '<branch>';

    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $branches
     */
    private function __construct(
        private readonly array $patterns,
        private readonly array $branches,
    ) {}

    /**
     * Null when there is nothing to hide: a public repository, or none at all. Takes every URL the report knows,
     * since the account record and the checkout's remote can disagree.
     *
     * @param  list<?string>  $urls
     * @param  list<?string>  $branches
     */
    public static function for(bool $private, array $urls, array $branches = []): ?self
    {
        if (! $private) {
            return null;
        }

        $patterns = [];
        foreach (array_unique(array_filter($urls, 'is_string')) as $url) {
            $parts = DeployReport::parseRepoUrl($url);
            if ($parts === null || $parts['path'] === '') {
                continue;
            }

            $patterns = [...$patterns, ...self::patternsFor($parts)];
        }

        if ($patterns === []) {
            return null;
        }

        return new self(array_values(array_unique($patterns)), array_values(array_unique(self::branchNames($branches))));
    }

    /**
     * host/path in any URL or scp form, and the bare path only when it has an owner: a lone `app` would
     * mask every word `app` in the log.
     *
     * @param  array{host: string, path: string}  $parts
     * @return list<string>
     */
    private static function patternsFor(array $parts): array
    {
        $host = preg_quote($parts['host'], '#');
        $path = preg_quote($parts['path'], '#');
        $tail = '(?:\.git)?(?![\w-])';

        $patterns = ['#(?<='.$host.'[/:])'.$path.$tail.'#i'];
        if (str_contains($parts['path'], '/')) {
            $patterns[] = '#(?<![\w.-])'.$path.$tail.'#i';
        }

        return $patterns;
    }

    /**
     * @param  list<?string>  $branches
     * @return list<string>
     */
    private static function branchNames(array $branches): array
    {
        $names = [];
        foreach ($branches as $branch) {
            if (is_string($branch) && trim($branch) !== '') {
                $names[] = trim($branch);
            }
        }

        return $names;
    }

    /** The repository everywhere, a branch only where the text says it is a branch: `main` is also a word. */
    public function apply(string $line): string
    {
        foreach ($this->patterns as $pattern) {
            $line = (string) preg_replace($pattern, self::REPO, $line);
        }

        foreach ($this->branches as $branch) {
            $line = (string) preg_replace(
                '#(\bbranch\b[\'"]?\s*[:=]?\s*[\'"]?)'.preg_quote($branch, '#').'(?![\w./-])#i',
                '$1'.self::BRANCH,
                $line
            );
        }

        return $line;
    }
}
