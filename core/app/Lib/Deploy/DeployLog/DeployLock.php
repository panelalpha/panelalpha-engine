<?php

namespace App\Lib\Deploy\DeployLog;

use App\Exceptions\DeployAlreadyRunningException;
use RuntimeException;

/**
 * One deploy at a time per account: an advisory lock on a file in the account's
 * log directory, released by the kernel if the process dies.
 */
final class DeployLock
{
    /** @var resource|null */
    private $handle = null;

    /** acquireFor() made the account's directory just for this lock; release() removes it. */
    private bool $ownsDirectory = false;

    public function __construct(private readonly DeployLogPaths $paths)
    {
    }

    /**
     * Take an account's lock for work that writes no deploy log: a delete, or
     * the rebuild of a project on a plain template.
     *
     * @throws DeployAlreadyRunningException
     */
    public static function acquireFor(string $username): self
    {
        $paths = new DeployLogPaths($username);
        $created = !is_dir($paths->directory());
        LogStorage::ensureDirectory(DeployLogPaths::base());
        LogStorage::ensureDirectory($paths->directory());

        $lock = new self($paths);
        $lock->acquire();
        $lock->ownsDirectory = $created;

        return $lock;
    }

    /**
     * @throws DeployAlreadyRunningException when another deploy holds it
     */
    public function acquire(): void
    {
        $handle = @fopen($this->paths->lock(), 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not open deploy lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new DeployAlreadyRunningException('A deployment is already running for this user.');
        }

        $this->handle = $handle;
    }

    /**
     * Whether some process holds the lock right now, without taking it. A
     * deploy that died without reaching finish() left its status `running`,
     * but the kernel dropped its lock with it -- this is what tells the two
     * apart.
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

        return !$free;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            if ($this->ownsDirectory) {
                // An account that writes no deploy log keeps no directory for one.
                @unlink($this->paths->lock());
                @rmdir($this->paths->directory());
            }
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }
}
