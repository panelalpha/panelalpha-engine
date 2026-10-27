<?php

namespace App\System\Project\Dind;

use App\Models\User;
use App\System\Services\Mysql;

/**
 * An application database a push provisions on a target that had none,
 * recorded before {@see AppDatabase::provision()} runs, so a failed push takes
 * away what it added (the database, its user and grants, their rows and the
 * stored password) and keeps whatever the target already had.
 */
final class ProvisionedAppDatabase
{
    private function __construct(
        private Mysql $mysql,
        public readonly string $name,
        private bool $hadDatabase,
        private bool $hadUser,
        private bool $hadUserRow,
        private bool $hadPassword,
    ) {}

    /** A database already on the server under the name is kept, as the provision keeps it. */
    public static function before(User $user, Mysql $mysql): self
    {
        return self::record($user, $mysql, $mysql->databases()->databaseExists(AppDatabase::nameFor($user)));
    }

    /** The push drops any database under the name for the source's data, so a rollback drops it too. */
    public static function beforeReplacing(User $user, Mysql $mysql): self
    {
        return self::record($user, $mysql, false);
    }

    public function remove(User $user): void
    {
        if (! $this->hadDatabase && $this->mysql->databases()->databaseExists($this->name)) {
            $this->mysql->databases()->deleteDatabase($this->name);
        }
        $user->mysqlDatabases()->getQuery()->where('database', $this->name)->delete();
        $this->removeUser($user);
        if (! $this->hadPassword) {
            $user->setDetails([AppDatabase::PASSWORD_DETAIL => null]);
            $user->save();
        }
    }

    private static function record(User $user, Mysql $mysql, bool $hadDatabase): self
    {
        $name = AppDatabase::nameFor($user);

        return new self(
            $mysql,
            $name,
            $hadDatabase,
            $mysql->users()->userExists($name),
            $user->mysqlUsers()->getQuery()->where('user', $name)->exists(),
            AppDatabase::credentialsOf($user) !== null,
        );
    }

    /**
     * A user the target already had keeps existing, without the grant the push
     * gave it on the database (one created later under that name would get it).
     */
    private function removeUser(User $user): void
    {
        if ($this->hadUser) {
            $this->mysql->privileges()->revokeIfExists($this->name, $this->name);
        } elseif ($this->mysql->users()->userExists($this->name)) {
            $this->mysql->users()->deleteUser($this->name);
        }
        if (! $this->hadUserRow) {
            $user->mysqlUsers()->getQuery()->where('user', $this->name)->delete();
        }
    }
}
