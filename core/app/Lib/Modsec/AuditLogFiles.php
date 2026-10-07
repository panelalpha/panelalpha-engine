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

    public function pathOrFail(string $filename): string
    {
        return $this->path($filename) ?? throw self::notFound($filename);
    }

    /**
     * The JSON lines in the file's last 50 KB, newest first, or null when
     * there is no such file.
     *
     * @return list<mixed>|null
     */
    public function tail(string $filename): ?array
    {
        $path = $this->path($filename);
        if ($path === null) {
            return null;
        }

        $file = fopen($path, 'r');
        fseek($file, -self::TAIL_BYTES, SEEK_END);
        $data = fread($file, self::TAIL_BYTES);
        fclose($file);

        $logs = [];
        $lines = explode("\n", $data);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            /** @var mixed */
            $log = @json_decode($lines[$i]);
            if ($log) {
                $logs[] = $log;
            }
        }

        return $logs;
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
