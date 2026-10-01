<?php

namespace App\Lib\Deploy;

use App\Lib\Deploy\Env\ComposeEnvFiles;
use App\Lib\Deploy\Env\EnvExampleCopies;

/**
 * Reading, merging and writing a project's `.env`.
 *
 * Deliberately tolerant and round-trip oriented — the same rules the panel's
 * own parser follows — because the file belongs to the customer: comments,
 * blank lines and the order of the keys all survive a merge, and only the
 * values the engine was asked to set are touched.
 *
 * Which `.env` files a checkout is *missing* is a different question, and
 * lives in {@see EnvExampleCopies}.
 */
class EnvFile
{
    /**
     * An env file's text as UTF-8: a UTF-8 BOM dropped, UTF-16 with a BOM
     * converted (DumbPad ships its .env.example as UTF-16LE). Null when NUL
     * bytes remain, since Compose refuses such a file whatever is done to it.
     */
    public static function asUtf8(string $raw): ?string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $from = $raw[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE';
            $raw = (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', $from);
        }

        return str_contains($raw, "\0") ? null : $raw;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function parse(string $contents): array
    {
        $rows = [];
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $lastIndex = count($lines) - 1;
        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '') {
                if ($index === $lastIndex) {
                    continue;
                }
                $rows[] = ['type' => 'blank'];
                continue;
            }
            if (str_starts_with($trimmed, '#')) {
                $rows[] = ['type' => 'comment', 'text' => $line];
                continue;
            }
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/s', $line, $m)) {
                $rows[] = [
                    'type' => 'variable',
                    'key' => $m[1],
                    'value' => self::unquote($m[2]),
                ];
                continue;
            }
            $rows[] = ['type' => 'comment', 'text' => $line];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    public static function serialise(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            switch ($row['type'] ?? null) {
                case 'variable':
                    $key = (string) ($row['key'] ?? '');
                    if ($key === '') {
                        continue 2;
                    }
                    $lines[] = $key . '=' . self::quote((string) ($row['value'] ?? ''));
                    break;
                case 'comment':
                    $lines[] = (string) ($row['text'] ?? '');
                    break;
                case 'blank':
                    $lines[] = '';
                    break;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Override existing keys (or append missing ones) with the given map.
     * Only keys present in $overrides are changed; empty override values are skipped.
     *
     * @param array<string, string> $overrides
     */
    public static function merge(string $baseContents, array $overrides): string
    {
        $filtered = [];
        foreach ($overrides as $key => $value) {
            if (!is_string($key) || $key === '' || !is_string($value)) {
                continue;
            }
            $filtered[$key] = $value;
        }
        if ($filtered === []) {
            return $baseContents === '' ? '' : (str_ends_with($baseContents, "\n") ? $baseContents : $baseContents . "\n");
        }

        $rows = self::parse($baseContents);
        $seen = [];
        foreach ($rows as $i => $row) {
            if (($row['type'] ?? null) !== 'variable') {
                continue;
            }
            $key = $row['key'] ?? '';
            if ($key !== '' && array_key_exists($key, $filtered)) {
                $rows[$i]['value'] = $filtered[$key];
                $seen[$key] = true;
            }
        }
        foreach ($filtered as $key => $value) {
            if (!isset($seen[$key])) {
                $rows[] = ['type' => 'variable', 'key' => $key, 'value' => $value];
            }
        }

        return self::serialise($rows);
    }

    /**
     * Comment out the lines Docker Compose's dotenv reader refuses. A
     * `.env.example` written to be `source`d (saltcorn: `unset DATABASE_URL …`)
     * otherwise stops `docker compose up` with "key cannot contain a space".
     *
     * @return array{0: string, 1: list<int>} contents, the 1-based lines commented out
     */
    public static function withoutComposeRejectedLines(string $contents): array
    {
        $lines = explode("\n", $contents);
        $rejected = [];
        $openQuote = null;
        foreach ($lines as $i => $line) {
            if ($openQuote !== null) {
                // Inside a multi-line quoted value: Compose reads it as value, not keys.
                if (preg_match('/(?<!\\\\)' . preg_quote($openQuote, '/') . '/', $line) === 1) {
                    $openQuote = null;
                }
                continue;
            }
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $statement = (string) preg_replace('/^export\s+/', '', $trimmed);
            $parts = preg_split('/[=:]/', $statement, 2) ?: [$statement];
            // Compose's own key alphabet: letters, digits, `_ . - [ ]`.
            if (preg_match('/^[\p{L}\p{N}_.\-\[\]]+$/u', rtrim($parts[0])) !== 1) {
                $lines[$i] = '# ' . $line;
                $rejected[] = $i + 1;
                continue;
            }
            $value = ltrim($parts[1] ?? '');
            $quote = $value[0] ?? '';
            if (($quote === '"' || $quote === "'")
                && preg_match('/(?<!\\\\)' . preg_quote($quote, '/') . '/', substr($value, 1)) !== 1) {
                $openQuote = $quote;
            }
        }

        return $rejected === [] ? [$contents, []] : [implode("\n", $lines), $rejected];
    }

    private static function unquote(string $raw): string
    {
        $value = $raw;
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                if ($first === '"') {
                    $value = str_replace(
                        ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                        ["\n", "\r", "\t", '"', '\\'],
                        $value,
                    );
                }

                return $value;
            }
        }
        $hashPos = strpos($value, ' #');
        if ($hashPos !== false) {
            $value = substr($value, 0, $hashPos);
        }

        return rtrim($value);
    }

    private static function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $needsQuoting = preg_match('/[\s"\'#\\\\]/', $value) === 1
            || $value[0] === '"' || $value[0] === "'";
        if (!$needsQuoting) {
            return $value;
        }
        $escaped = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $value);

        return '"' . $escaped . '"';
    }

    /**
     * Missing `.env` files that Docker Compose will refuse to start without.
     *
     * @return list<array{example: string, dest: string, relative: string}>
     */
    public static function nestedEnvExampleCopies(string $projectDir): array
    {
        return EnvExampleCopies::for($projectDir);
    }

    /**
     * @return array<string, array{example: string, dest: string, relative: string}>
     */
    public static function envLocalCopies(string $projectDir): array
    {
        return EnvExampleCopies::local($projectDir);
    }

    public static function projectMentionsEnvLocal(string $projectDir): bool
    {
        return EnvExampleCopies::mentionsEnvLocal($projectDir);
    }

    /**
     * Relative `env_file:` paths Docker Compose will look for. Used by the
     * deploy pipeline, which reads the compose file via sudo.
     *
     * @return list<string>
     */
    public static function composeEnvFileRelativePathsFromYaml(string $raw): array
    {
        return ComposeEnvFiles::declaredIn($raw);
    }

    /**
     * DB_* from `.env`, falling back to `.env.example`.
     *
     * Pass `$read` to go through the account's own file layer: a 0600 `.env`
     * is unreadable to the engine's user and would silently lose to the
     * example (engine#186).
     *
     * @param (callable(string): ?string)|null $read path => contents, null when absent
     * @return array{connection: string, host: string, port: string, database: string, username: string, password: string}
     */
    public static function databaseSettings(string $projectDir, ?callable $read = null): array
    {
        $settings = [
            'connection' => '',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => '',
            'username' => '',
            'password' => '',
        ];
        $map = [
            'DB_CONNECTION' => 'connection',
            'DB_HOST' => 'host',
            'DB_PORT' => 'port',
            'DB_DATABASE' => 'database',
            'DB_USERNAME' => 'username',
            'DB_PASSWORD' => 'password',
        ];
        $projectDir = rtrim($projectDir, '/');
        $read ??= self::readHostFile(...);
        foreach (['.env', '.env.example'] as $name) {
            $contents = $read($projectDir . '/' . $name);
            if (!is_string($contents) || $contents === '') {
                continue;
            }
            foreach (self::parse($contents) as $row) {
                if (($row['type'] ?? '') !== 'variable') {
                    continue;
                }
                $key = $row['key'] ?? '';
                if ($key !== '' && isset($map[$key])) {
                    $settings[$map[$key]] = (string) ($row['value'] ?? '');
                }
            }
            break;
        }

        return $settings;
    }

    /** A file on the engine's own filesystem, or null when absent or unreadable. */
    public static function readHostFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }
}
