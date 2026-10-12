<?php

namespace App\Lib\Modsec;

use App\Exceptions\NotFoundException;
use App\System;

/** The ModSecurity audit logs, as the API and the CLI both read them. */
final class AuditLogFiles
{
    private const TAIL_BYTES = 1024 * 50;

    public function __construct(private readonly System $system = new System())
    {
    }

    /**
     * @return array<array{file: string, path: string, mtime: int, size: int}>
     */
    public function list(): array
    {
        return $this->system->modsec()->listAuditLogFiles();
    }

    public function path(string $filename): ?string
    {
        foreach ($this->list() as $file) {
            if ($file['file'] == $filename) {
                return $file['path'];
            }
        }

        return null;
    }

    /** The file's path when core can read it, so an unreadable log answers like a missing one. */
    public function readablePath(string $filename): ?string
    {
        $path = $this->path($filename);

        return $path !== null && is_readable($path) ? $path : null;
    }

    public function pathOrFail(string $filename): string
    {
        return $this->path($filename) ?? throw self::notFound($filename);
    }

    /**
     * The JSON lines in the file's last 50 KB, newest first, or null when
     * there is no such file or it cannot be read. A rotated `.gz` log is read through zlib.
     *
     * @return list<mixed>|null
     */
    public function tail(string $filename): ?array
    {
        $path = $this->readablePath($filename);
        if ($path === null) {
            return null;
        }

        $data = str_ends_with($path, '.gz') ? self::tailCompressed($path) : self::tailPlain($path);
        if ($data === null) {
            return null;
        }

        return self::decodedNewestFirst($data);
    }

    /** @return list<mixed> */
    private static function decodedNewestFirst(string $data): array
    {
        $logs = [];
        $lines = explode("\n", $data);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $log = @json_decode($lines[$i]);
            if ($log) {
                $logs[] = $log;
            }
        }

        return $logs;
    }

    /** The last self::TAIL_BYTES of a plain file, or null when it cannot be read. */
    private static function tailPlain(string $path): ?string
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            return null;
        }

        try {
            $size = fstat($file)['size'] ?? 0;
            if ($size > self::TAIL_BYTES) {
                fseek($file, -self::TAIL_BYTES, SEEK_END);
            }

            $data = stream_get_contents($file);
        } finally {
            fclose($file);
        }

        return $data === false ? null : $data;
    }

    /** The zlib wrapper cannot seek from the end, so the whole file is streamed. */
    private static function tailCompressed(string $path): ?string
    {
        $file = @fopen('compress.zlib://' . $path, 'rb');
        if ($file === false) {
            return null;
        }

        $tail = '';
        try {
            while (!feof($file)) {
                $chunk = fread($file, 65536);
                if ($chunk === false) {
                    return null;
                }
                $tail = substr($tail . $chunk, -self::TAIL_BYTES);
            }
        } finally {
            fclose($file);
        }

        return $tail;
    }

    /** @return list<mixed> */
    public function tailOrFail(string $filename): array
    {
        return $this->tail($filename) ?? throw self::notFound($filename);
    }

    private static function notFound(string $filename): NotFoundException
    {
        return new NotFoundException("Audit log file '{$filename}' not found.");
    }
}
