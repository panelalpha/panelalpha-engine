<?php

namespace Tests\Support;

use App\System\Privileged;
use App\System\Privileged\AccountFileManager;

/** The file manager's scripts as the root broker runs them. */
final class BrokerScripts
{
    public static function confine(): string
    {
        return LocalRoot::script(self::files()->confine('/home/u', []));
    }

    public static function entryType(): string
    {
        return LocalRoot::script(self::files()->entryType('/home/u/x'));
    }

    public static function resolvedFile(): string
    {
        return LocalRoot::script(self::files()->resolvedFile('/home/u/x'));
    }

    private static function files(): AccountFileManager
    {
        return Privileged::accountFileManager(1001, 1001);
    }
}
