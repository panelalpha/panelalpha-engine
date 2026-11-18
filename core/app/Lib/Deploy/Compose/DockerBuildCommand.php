<?php

namespace App\Lib\Deploy\Compose;

/**
 * The `docker build` commands a README, Makefile or script spells out, read for the tag each gives and the context
 * and Dockerfile it builds from (`docker build -f docker/Dockerfile -t altmount:dev .`).
 */
final class DockerBuildCommand
{
    /** Options that take a value: their value is never the context. */
    private const VALUED = [
        '--add-host', '--allow', '--annotation', '--attest', '--build-arg', '--build-context', '--builder',
        '--cache-from', '--cache-to', '--call', '--cgroup-parent', '--cpu-period', '--cpu-quota', '--cpu-shares',
        '--cpuset-cpus', '--cpuset-mems', '--iidfile', '--isolation', '--label', '--memory', '--memory-swap',
        '--metadata-file', '--network', '--output', '--platform', '--progress', '--secret', '--shm-size', '--ssh',
        '--ulimit', '-m', '-o',
    ];

    /** Where a shell command stops. */
    private const SEPARATORS = [';', '&', '|', '`', '>', '<'];

    /**
     * Every build in $text that tags $name with $tag, or with a tag a variable supplies.
     *
     * @return list<array{context: string, dockerfile: ?string, target: ?string, line: string}>
     */
    public static function buildsOf(string $text, string $name, string $tag): array
    {
        $builds = [];
        foreach (self::commandLines($text) as $line) {
            $words = self::words($line);
            $build = self::parse($words);
            if ($build !== null && self::tagsImage($build['tags'], $name, $tag)) {
                unset($build['tags']);
                $builds[] = $build + ['line' => implode(' ', $words)];
            }
        }

        return $builds;
    }

    /**
     * Each `docker build` / `docker buildx build` from its start to the end of its shell command, continuations joined.
     *
     * @return list<string>
     */
    private static function commandLines(string $text): array
    {
        $joined = (string) preg_replace('/\\\\\r?\n\s*/', ' ', $text);
        preg_match_all('/\bdocker\s+(?:buildx\s+)?build\b[^\r\n]*/', $joined, $matches);

        return array_map(static fn (string $line): string => trim($line), $matches[0]);
    }

    /**
     * @param  list<string>  $words
     * @return array{context: string, dockerfile: ?string, target: ?string, tags: list<string>}|null
     */
    private static function parse(array $words): ?array
    {
        $words = array_slice($words, $words[1] === 'buildx' ? 3 : 2);
        $parsed = ['context' => null, 'dockerfile' => null, 'target' => null, 'tags' => []];
        for ($i = 0; $i < count($words); $i++) {
            [$option, $value] = self::option($words[$i]);
            if ($option === null) {
                $parsed['context'] ??= $words[$i];

                continue;
            }
            if ($value === null && self::takesValue($option)) {
                $value = $words[++$i] ?? null;
            }
            $parsed = self::withOption($parsed, $option, $value);
        }

        return is_string($parsed['context']) && self::isLiteralPath($parsed['context'])
            && ($parsed['dockerfile'] === null || self::isLiteralPath($parsed['dockerfile']))
            ? $parsed
            : null;
    }

    /**
     * The command's words up to the shell separator that ends it, quotes removed.
     *
     * @return list<string>
     */
    private static function words(string $line): array
    {
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $line, $matches, PREG_SET_ORDER);
        $words = [];
        foreach ($matches as $match) {
            $quoted = ($match[1] ?? '') !== '' || ($match[2] ?? '') !== '';
            $word = $match[3] ?? '';
            if (! $quoted && self::endsCommand($word)) {
                $cut = self::beforeSeparator($word);
                if ($cut !== '') {
                    $words[] = $cut;
                }
                break;
            }
            $words[] = $quoted ? ($match[1] !== '' ? $match[1] : $match[2]) : $word;
        }

        return $words;
    }

    private static function endsCommand(string $word): bool
    {
        return self::beforeSeparator($word) !== $word;
    }

    private static function beforeSeparator(string $word): string
    {
        $cut = strlen($word);
        foreach (self::SEPARATORS as $separator) {
            $at = strpos($word, $separator);
            if ($at !== false) {
                $cut = min($cut, $at);
            }
        }

        return substr($word, 0, $cut);
    }

    /** @return array{0: ?string, 1: ?string} the option and an `=` value, or no option for a positional word */
    private static function option(string $word): array
    {
        if ($word === '-' || ! str_starts_with($word, '-')) {
            return [null, null];
        }
        if (str_starts_with($word, '--') && str_contains($word, '=')) {
            [$option, $value] = explode('=', $word, 2);

            return [$option, $value];
        }
        if (preg_match('/^-([tf])(.+)$/', $word, $m) === 1) {
            return ['-'.$m[1], $m[2]];
        }

        return [$word, null];
    }

    private static function takesValue(string $option): bool
    {
        return in_array($option, ['-t', '--tag', '-f', '--file', '--target'], true) || in_array($option, self::VALUED, true);
    }

    /**
     * @param  array{context: ?string, dockerfile: ?string, target: ?string, tags: list<string>}  $parsed
     * @return array{context: ?string, dockerfile: ?string, target: ?string, tags: list<string>}
     */
    private static function withOption(array $parsed, string $option, ?string $value): array
    {
        if ($value === null) {
            return $parsed;
        }

        return match ($option) {
            '-t', '--tag' => [...$parsed, 'tags' => [...$parsed['tags'], $value]],
            '-f', '--file' => [...$parsed, 'dockerfile' => $value],
            '--target' => [...$parsed, 'target' => $value],
            default => $parsed,
        };
    }

    /** A path the command names as it stands: not stdin, a URL or a variable. */
    private static function isLiteralPath(string $path): bool
    {
        return $path !== '' && $path !== '-' && ! str_contains($path, '$') && ! str_contains($path, '://')
            && ! str_starts_with($path, '/') && ! str_starts_with($path, '~') && ! str_starts_with($path, 'git@');
    }

    /** @param  list<string>  $tags */
    private static function tagsImage(array $tags, string $name, string $tag): bool
    {
        foreach ($tags as $given) {
            $colon = strrpos($given, ':');
            $slash = strrpos($given, '/');
            $hasTag = $colon !== false && ($slash === false || $colon > $slash);
            $givenName = $hasTag ? substr($given, 0, $colon) : $given;
            $givenTag = $hasTag ? substr($given, $colon + 1) : 'latest';
            if (strcasecmp($givenName, $name) === 0 && (strcasecmp($givenTag, $tag) === 0 || str_contains($givenTag, '$'))) {
                return true;
            }
        }

        return false;
    }
}
