<?php

namespace App\Lib\Host;

/**
 * The host disk projects live on: every account keeps its files and inner Docker store under /home/<user>,
 * which core mounts from the host, so this disk fills up rather than core's overlay root.
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
