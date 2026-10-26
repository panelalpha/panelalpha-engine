<?php

namespace App\System\Project\Dind;

use App\Models\MysqlDatabase;
use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DatabaseCopyResult;
use App\System\Services\Mysql\DefinerMap;

/**
 * The application database a container project's deploy provisioned
 * ({@see AppDatabase}), carried into a staging, clone or push.
 *
 * It lives on the account MySQL server rather than in a volume, so copying
 * the account's home and volumes brings none of it along: the copy needs a
 * database of its own, under its own prefix, holding the source's data.
 */
final class AppDatabaseCopy
{
    public function __construct(private Mysql $mysql) {}

    /**
     * $dest's own application database, with the source's data when $withData.
     * Null when the source has none.
     */
    public function intoCopy(User $source, User $dest, bool $withData): ?DatabaseCopyResult
    {
        if (! AppDatabase::isOwnedBy($source)) {
            return null;
        }
        if (! $withData) {
            AppDatabase::provision($dest, $this->mysql);

            return new DatabaseCopyResult;
        }

        $from = AppDatabase::nameFor($source);
        $to = AppDatabase::nameFor($dest);
        if (! AppDatabase::isOwnedBy($dest)) {
            MysqlDatabase::create(['user_id' => $dest->id, 'database' => $to]);
        }
        $this->copier()->copyDatabase($from, $to, DefinerMap::map($source->getMysqlPrefix(), $dest->getMysqlPrefix(), [$from], [$to]));
        AppDatabase::provision($dest, $this->mysql);

        return new DatabaseCopyResult([$from => $to]);
    }

    /**
     * The target's application database replaced with the source's. One the
     * target already had is dumped into $rollbackDir first and recorded in
     * $rollbacks; one it lacked is provisioned and recorded in $created, so a
     * failed push drops it again.
     *
     * @param  array<string, string>  $rollbacks  destination database => rollback dump
     * @param  list<string>  $created
     */
    public function intoTarget(User $from, User $to, string $rollbackDir, array &$rollbacks, array &$created): DatabaseCopyResult
    {
        $source = AppDatabase::nameFor($from);
        $dest = AppDatabase::nameFor($to);
        $copier = $this->copier();

        if (AppDatabase::isOwnedBy($to)) {
            $rollbacks[$dest] = $copier->dumpRollback($dest, $rollbackDir);
            $copier->replaceDatabase($source, $dest, $rollbacks[$dest], $to, DefinerMap::between($from, $to));
        } else {
            $created[] = $dest;
            AppDatabase::provision($to, $this->mysql);
            $this->mysql->databases()->deleteDatabase($dest);
            $copier->copyDatabase($source, $dest, DefinerMap::between($from, $to));
        }

        return new DatabaseCopyResult([$source => $dest]);
    }

    /**
     * A target the source's run file is pushed into without its data still
     * gets a database of its own to point that file at.
     */
    public function ensureTargetHasOne(User $to): void
    {
        if (! AppDatabase::isOwnedBy($to)) {
            AppDatabase::provision($to, $this->mysql);
        }
    }

    private function copier(): DatabaseCopier
    {
        return new DatabaseCopier($this->mysql);
    }
}
