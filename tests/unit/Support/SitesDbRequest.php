<?php

namespace Tests\Support;

use App\System\EnginePaths;
use App\System\Privileged;
use App\System\Privileged\RootEngineComposeLines;
use App\System\Services\Mysql\ImportAccount;

/** The broker request a copy or a table drop in sites-db is asked for with. */
final class SitesDbRequest
{
    public static function copy(string $from, string $to, ?string $table, ImportAccount $account): string
    {
        return self::lines()->sitesDbCopy($from, $to, $table, $account->user);
    }

    public static function drop(string $database, string $table): string
    {
        return self::lines()->sitesDbDropTable($database, $table);
    }

    private static function lines(): RootEngineComposeLines
    {
        return Privileged::engineComposeLines(EnginePaths::ENGINE_DIR);
    }
}
