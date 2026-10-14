<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * Parsed views over one deploy's JSON-lines log.
 *
 * The panel pages forward from an offset it remembers; telemetry wants the
 * other end and does not know the total; the build breakdown needs the whole
 * thing, because a `#N [x/y] <cmd>` and its `#N DONE <s>` can be thousands of
 * lines apart and a window that split the two would report the step as
 * costing nothing.
 */
final class DeployLogReader
{
    public const MAX_READ_LINES = 2000;

    /** Bytes from the end of a log that last() reads: room for the finish line's lookback. */
    public const LAST_WINDOW = 262144;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Every line, timestamped, in order.
     *
     * @return list<array{ts: int, level: string, msg: string}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->decodedLines() as $decoded) {
            if (isset($decoded['msg'])) {
                $entries[] = [
                    'ts' => (int) ($decoded['ts'] ?? 0),
                    'level' => (string) ($decoded['level'] ?? DeployLogger::LEVEL_DIM),
                    'msg' => (string) $decoded['msg'],
                ];
            }
        }

        return $entries;
    }

    /**
     * Lines from `$offset` on, at most `$limit` of them and, when given, about
     * `$maxBytes` of log. `next_offset` is where the next page starts, so a
     * caller that pages until `more` is false reads every line exactly once.
     *
     * A single line longer than the whole budget is still returned, cut to
     * it, so a page never comes back empty while lines remain.
     *
     * @return array{lines: list<array{ts: int, stage: ?string, level: string, msg: string}>, next_offset: int, more: bool}
     */
    public function page(int $offset = 0, int $limit = self::MAX_READ_LINES, ?int $maxBytes = null): array
    {
        $raw = $this->rawLines();
        $offset = max(0, $offset);

        $taken = [];
        $bytes = 0;
        foreach (array_slice($raw, $offset, $limit) as $line) {
            if ($maxBytes !== null && $taken !== [] && $bytes + strlen($line) > $maxBytes) {
                break;
            }
            $taken[] = $line;
            $bytes += strlen($line);
        }

        $next = min(count($raw), $offset + count($taken));

        return [
            'lines' => $this->parse($taken, $maxBytes),
            'next_offset' => $next,
            'more' => $next < count($raw),
        ];
    }

    /**
     * @return list<array{ts: int, stage: ?string, level: string, msg: string}>
     */
    public function tail(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return $this->parse(array_slice($this->rawLines(), -$limit));
    }

    /**
     * The first `$limit` lines, read without loading the rest of the file.
     *
     * @return list<array{ts: int, stage: ?string, level: string, msg: string}>
     */
    public function head(int $limit): array
    {
        $handle = $limit > 0 && is_file($this->path) ? @fopen($this->path, 'rb') : false;
        if ($handle === false) {
            return [];
        }

        $raw = [];
        while (count($raw) < $limit && ($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '') {
                $raw[] = $line;
            }
        }
        fclose($handle);

        return $this->parse($raw);
    }

    /**
     * The last `$limit` lines that fit in the last `$window` bytes, read
     * without loading the rest of the file.
     *
     * @return list<array{ts: int, stage: ?string, level: string, msg: string}>
     */
    public function last(int $limit, int $window = self::LAST_WINDOW): array
    {
        $size = $limit > 0 && is_file($this->path) ? (int) @filesize($this->path) : 0;
        $chunk = $size > 0 ? @file_get_contents($this->path, false, null, max(0, $size - $window)) : false;
        if (!is_string($chunk) || $chunk === '') {
            return [];
        }

        $raw = array_values(array_filter(explode("\n", $chunk), static fn (string $l): bool => $l !== ''));
        if ($size > $window) {
            array_shift($raw);
        }

        return $this->parse(array_slice($raw, -$limit));
    }

    /**
     * @param list<string> $raw
     * @return list<array{ts: int, stage: ?string, level: string, msg: string}>
     */
    private function parse(array $raw, ?int $maxBytes = null): array
    {
        $lines = [];
        foreach ($raw as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = [
                    'ts' => (int) ($decoded['ts'] ?? 0),
                    'stage' => $decoded['stage'] ?? null,
                    'level' => (string) ($decoded['level'] ?? DeployLogger::LEVEL_DIM),
                    'msg' => self::cut((string) ($decoded['msg'] ?? ''), $maxBytes),
                ];
            }
        }

        return $lines;
    }

    private static function cut(string $msg, ?int $maxBytes): string
    {
        if ($maxBytes === null || strlen($msg) <= $maxBytes) {
            return $msg;
        }

        return mb_strcut($msg, 0, $maxBytes) . ' [... ' . (strlen($msg) - $maxBytes) . ' more bytes cut]';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodedLines(): array
    {
        $decoded = [];
        foreach ($this->rawLines() as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $decoded[] = $entry;
            }
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function rawLines(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        return file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }
}
