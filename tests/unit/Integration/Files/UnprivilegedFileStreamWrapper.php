<?php

namespace Tests\Integration\Files;

use App\Lib\Helpers\FileStreamWrapper;
use Tests\Support\LocalRoot;

/** rootfile:// with its helper run by pa-root-local as this process instead of the root broker; counts the helper starts. */
class UnprivilegedFileStreamWrapper extends FileStreamWrapper
{
    public static int $helperStarts = 0;

    public static function install(): void
    {
        if (in_array('rootfile', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('rootfile');
        }
        stream_wrapper_register('rootfile', self::class);
        self::$helperStarts = 0;
    }

    public static function uninstall(): void
    {
        stream_wrapper_unregister('rootfile');
        FileStreamWrapper::unconfine();
    }

    /** @return list<string>|null */
    protected function helperCommand(): ?array
    {
        $command = parent::helperCommand();
        if ($command === null) {
            return null;
        }
        self::$helperStarts++;

        return LocalRoot::argv($command);
    }
}
