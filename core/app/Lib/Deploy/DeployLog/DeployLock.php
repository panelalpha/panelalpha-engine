<?php

namespace App\Lib\Deploy\DeployLog;

use App\Exceptions\DeployAlreadyRunningException;
use App\Lib\DeployHook\Coalescing;
use RuntimeException;

/**
 * One deploy at a time per account: an advisory lock on a file in the account's
 * log directory, released by the kernel if the process dies.
 */
final class DeployLock
{
    /** What a delete writes into the lock file, so a refusal can say the project is going. */
    public const HOLDER_DELETE = 'delete';

    /** What a backup and a restore write, so a refusal started without a task can still name them. */
    public const HOLDER_BACKUP = 'backup';

    public const HOLDER_RESTORE = 'restore';

    /** What an env var apply writes, so a refusal without a task can still name who holds the lock. */
    public const HOLDER_ENV_VARS = 'env_vars';

    /** @var resource|null */
    private $handle = null;

    /** Accounts whose lock this very process holds: flock refuses a second open of the same file. */
    private static array $heldByThisProcess = [];

    /** acquireFor() made the account's directory just for this lock; release() removes it. */
    private bool $ownsDirectory = false;

    public function __construct(private readonly DeployLogPaths $paths) {}

    /**
     * Take an account's lock for work that writes no deploy log: a delete, or
     * the rebuild of a project on a plain template.
     *
     * @throws DeployAlreadyRunningException
     */
    public static function acquireFor(string $username, ?string $holder = null): self
    {
        $paths = new DeployLogPaths($username);
        $created = ! is_dir($paths->directory());
        LogStorage::ensureDirectory(DeployLogPaths::base());
        LogStorage::ensureDirectory($paths->directory());

        $lock = new self($paths);
        $lock->acquire($holder);
        $lock->ownsDirectory = $created;

        return $lock;
    }

    /**
     * @throws DeployAlreadyRunningException when another deploy holds it
     */
    public function acquire(?string $holder = null): void
    {
        $handle = @fopen($this->paths->lock(), 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not open deploy lock file.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new DeployAlreadyRunningException(self::busyMessage($this->paths->username));
        }

        self::writeHolder($handle, $holder);
        $this->handle = $handle;
        self::$heldByThisProcess[$this->paths->username] = true;
    }

    /**
     * Every holder rewrites the file, so what a dead holder wrote never outlives it.
     *
     * @param  resource  $handle
     */
    private static function writeHolder($handle, ?string $holder): void
    {
        ftruncate($handle, 0);
        if ($holder !== null) {
            fwrite($handle, $holder);
        }
    }

    /**
     * Whether this process already holds the account's lock. flock refuses a second open of the
     * same file, so a nested repair cannot tell our own lock from another process's.
     */
    public static function heldByThisProcess(string $username): bool
    {
        return self::$heldByThisProcess[$username] ?? false;
    }

    /** What to tell a second writer: the holder's own name when the lock file carries one. */
    private static function busyMessage(string $username): string
    {
        return match (self::heldBy($username)) {
            self::HOLDER_BACKUP => 'A backup is already running for this user.',
            self::HOLDER_RESTORE => 'A restore is already running for this user.',
            self::HOLDER_ENV_VARS => 'The environment variables of this user are being applied.',
            default => 'A deployment is already running for this user.',
        };
    }

    /** What the account's current holder wrote into the lock; null when nobody holds it or it said nothing. */
    public static function heldBy(string $username): ?string
    {
        $lock = new self(new DeployLogPaths($username));
        if (! $lock->isHeld()) {
            return null;
        }
        $holder = @file_get_contents($lock->paths->lock());

        return is_string($holder) && $holder !== '' ? $holder : null;
    }

    /**
     * Whether some process holds the lock, without taking it: a deploy that died before finish() is still `running`,
     * but the kernel dropped its lock with it, so this tells the two apart.
     */
    public function isHeld(): bool
    {
        if (is_resource($this->handle)) {
            return true;
        }

        $handle = @fopen($this->paths->lock(), 'c');
        if ($handle === false) {
            return false;
        }

        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $free;
    }

    /**
     * Free the lock, then run the pushes {@see Coalescing} parked on it while it was held: whatever held it (a deploy, a
     * backup, the served tree's settle), a delivery it turned away is due the moment it is free.
     */
    public function release(): void
    {
        if ($this->unlock()) {
            Coalescing::runPendingFor($this->paths->username);
        }
    }

    /**
     * Free the lock without running what is parked on it: for a logger's destructor, which can run as the process shuts
     * down; a deploy it left unfinished is closed, and its parked pushes run, by {@see DeployLogger::settleOrphaned()}.
     */
    public function releaseQuietly(): void
    {
        $this->unlock();
    }

    /** Whether this lock was held and has been released. */
    private function unlock(): bool
    {
        if (! is_resource($this->handle)) {
            $this->handle = null;

            return false;
        }
        if ($this->ownsDirectory) {
            $this->removeLockDirectory();
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        unset(self::$heldByThisProcess[$this->paths->username]);
        $this->handle = null;

        return true;
    }

    /**
     * An account that writes no deploy log keeps no directory for one.
     */
    private function removeLockDirectory(): void
    {
        @unlink($this->paths->lock());
        @rmdir($this->paths->directory());
    }
}
