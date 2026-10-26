<?php

namespace App\Lib\Host;

/**
 * The host disk projects live on. Every account keeps its files and its whole
 * inner Docker store under /home/<user>, and core mounts the host's /home, so
 * this is the disk that fills up -- not core's own root, which is an overlay.
 */
final class DataDisk
{
    public const PATH = '/home';

    /**
     * @return ?array{total: int, free: int, used: int, percent: float}
     */
    public static function usage(string $path = self::PATH): ?array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        $used = $total - $free;

        return [
            'total' => (int) $total,
            'free' => (int) $free,
            'used' => (int) $used,
            'percent' => round($used / $total * 100, 2),
        ];
    }
}
