<?php

namespace Tests\Unit\Files;

use App\Lib\Helpers\FileStreamWrapper;

/** sudophp:// with its helper run as this process instead of through sudo; counts the helper starts. */
class UnprivilegedFileStreamWrapper extends FileStreamWrapper
{
    public static int $helperStarts = 0;

    public static function install(): void
    {
        if (in_array('sudophp', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('sudophp');
        }
        stream_wrapper_register('sudophp', self::class);
        self::$helperStarts = 0;
    }

    public static function uninstall(): void
    {
        stream_wrapper_unregister('sudophp');
        FileStreamWrapper::confineTo(null);
    }

    /** @return list<string> */
    protected function helperCommand(): array
    {
        self::$helperStarts++;

        return [PHP_BINARY, dirname(__DIR__, 3) . '/app/Lib/Helpers/file_stream.php'];
    }
}
