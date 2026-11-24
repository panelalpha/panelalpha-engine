<?php

namespace App\System\Project\Dind;

use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DatabaseCopyResult;

/**
 * The application database a container project's deploy provisioned ({@see AppDatabase}), carried into a staging,
 * clone or push: it lives on the account MySQL server, so copying the home and volumes brings none of it along.
 */
final class AppDatabaseCopy
{
    public function __construct(private Mysql $mysql) {}

    /**
     * $dest's own application database, with the source's data when $withData, loaded as $dest's own account.
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
        AppDatabase::provision($dest, $this->mysql);
        $this->copier()->copyInto($from, $to, $this->mysql->importAccount($dest, $to));

        return new DatabaseCopyResult([$from => $to]);
    }

    /**
     * The target's application database replaced with the source's: an existing one is dumped into $rollbackDir
     * and recorded in $rollbacks first; a missing one is provisioned and recorded in $provisioned for a failed push.
     *
     * @param  array<string, string>  $rollbacks  destination database => rollback dump
     */
    public function intoTarget(
        User $from,
        User $to,
        string $rollbackDir,
        array &$rollbacks,
        ?ProvisionedAppDatabase &$provisioned,
    ): DatabaseCopyResult {
        $source = AppDatabase::nameFor($from);
        $dest = AppDatabase::nameFor($to);
        $copier = $this->copier();

        if (AppDatabase::isOwnedBy($to)) {
            $account = $this->mysql->importAccount($to, $dest);
            $rollbacks[$dest] = $copier->dumpRollback($dest, $rollbackDir);
            $copier->replaceDatabase($source, $dest, $rollbacks[$dest], $to, $account);
        } else {
            $this->provisionRecorded($to, ProvisionedAppDatabase::beforeReplacing($to, $this->mysql), $provisioned);
            $this->mysql->databases()->deleteDatabase($dest);
            $copier->copyDatabase($source, $dest, $this->mysql->importAccount($to, $dest));
        }

        return new DatabaseCopyResult([$source => $dest]);
    }

    /**
     * A target pushed the source's run file without its data still gets a database of its own to point it at,
     * recorded in $provisioned like one {@see intoTarget()} provisions.
     */
    public function ensureTargetHasOne(User $to, ?ProvisionedAppDatabase &$provisioned): void
    {
        if (! AppDatabase::isOwnedBy($to)) {
            $this->provisionRecorded($to, ProvisionedAppDatabase::before($to, $this->mysql), $provisioned);
        }
    }

    /**
     * Recorded before provisioning starts, so a provision that fails halfway
     * is removed too.
     */
    private function provisionRecorded(User $to, ProvisionedAppDatabase $record, ?ProvisionedAppDatabase &$provisioned): void
    {
        $provisioned = $record;
        AppDatabase::provision($to, $this->mysql);
    }

    private function copier(): DatabaseCopier
    {
        return new DatabaseCopier($this->mysql);
    }
}
