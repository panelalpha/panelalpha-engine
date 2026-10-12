<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Exceptions\DeployAlreadyRunningException;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\DeployStatus;
use Tests\TestCase;

/**
 * One deploy at a time per account.
 *
 * An advisory lock on a file rather than a flag in latest.json, because the
 * kernel releases it when the process dies: a deploy killed by an OOM or a
 * restarted php-fpm leaves a status flag saying "running" forever, and the
 * account can never deploy again without someone clearing it by hand.
 */
class DeployLockTest extends TestCase
{
    private string $username = '';

    private DeployLogPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'lock-' . bin2hex(random_bytes(6));
        $this->paths = new DeployLogPaths($this->username);
        // The lock file lives in the account's log directory, which the
        // first deploy would have created.
        (new DeployStatus($this->paths))->write([]);
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_a_lock_can_be_taken(): void
    {
        $lock = new DeployLock($this->paths);
        $lock->acquire();

        $this->assertFileExists($this->paths->lock());

        $lock->release();
    }

    public function test_a_second_deploy_for_the_same_account_is_refused(): void
    {
        $first = new DeployLock($this->paths);
        $first->acquire();

        try {
            $this->expectException(DeployAlreadyRunningException::class);
            (new DeployLock($this->paths))->acquire();
        } finally {
            $first->release();
        }
    }

    public function test_releasing_lets_the_next_deploy_through(): void
    {
        $first = new DeployLock($this->paths);
        $first->acquire();
        $first->release();

        $second = new DeployLock($this->paths);
        $second->acquire();

        $this->addToAssertionCount(1);
        $second->release();
    }

    public function test_another_account_is_not_blocked(): void
    {
        // The lock is per account, or one slow deploy would stop the host.
        $mine = new DeployLock($this->paths);
        $mine->acquire();

        $otherName = 'lock-' . bin2hex(random_bytes(6));
        $otherPaths = new DeployLogPaths($otherName);
        (new DeployStatus($otherPaths))->write([]);
        $theirs = new DeployLock($otherPaths);

        try {
            $theirs->acquire();
            $this->addToAssertionCount(1);
        } finally {
            $theirs->release();
            $mine->release();
            DeployLogger::deleteUserLogs($otherName);
        }
    }

    public function test_is_held_reports_a_lock_another_handle_holds_without_taking_it(): void
    {
        $observer = new DeployLock($this->paths);
        $this->assertFalse($observer->isHeld());

        $holder = new DeployLock($this->paths);
        $holder->acquire();

        try {
            $this->assertTrue($observer->isHeld());
            $this->assertTrue($holder->isHeld());
        } finally {
            $holder->release();
        }

        $this->assertFalse($observer->isHeld(), 'asking must not have taken the lock');
        (new DeployLock($this->paths))->acquire();
        $this->addToAssertionCount(1);
    }

    public function test_is_held_is_false_for_an_account_with_no_log_directory(): void
    {
        $this->assertFalse((new DeployLock(new DeployLogPaths('lock-' . bin2hex(random_bytes(6)))))->isHeld());
    }

    public function test_the_holder_is_named_only_while_it_holds_the_lock(): void
    {
        $this->assertNull(DeployLock::heldBy($this->username));

        $delete = DeployLock::acquireFor($this->username, DeployLock::HOLDER_DELETE);
        $this->assertSame(DeployLock::HOLDER_DELETE, DeployLock::heldBy($this->username));
        $delete->release();
        $this->assertNull(DeployLock::heldBy($this->username));

        // A holder that died left its name in the file; the next one must not inherit it.
        file_put_contents($this->paths->lock(), DeployLock::HOLDER_DELETE);
        $deploy = new DeployLock($this->paths);
        $deploy->acquire();
        try {
            $this->assertNull(DeployLock::heldBy($this->username));
        } finally {
            $deploy->release();
        }
    }

    public function test_a_backup_and_a_restore_leave_their_own_names(): void
    {
        foreach ([DeployLock::HOLDER_BACKUP, DeployLock::HOLDER_RESTORE] as $holder) {
            $lock = DeployLock::acquireFor($this->username, $holder);
            $this->assertSame($holder, DeployLock::heldBy($this->username));
            $lock->release();
            $this->assertNull(DeployLock::heldBy($this->username));
        }
    }

    public function test_a_refused_second_lock_names_the_backup_that_holds_it(): void
    {
        $backup = DeployLock::acquireFor($this->username, DeployLock::HOLDER_BACKUP);

        try {
            $this->expectException(DeployAlreadyRunningException::class);
            $this->expectExceptionMessage('A backup is already running for this user.');
            (new DeployLock($this->paths))->acquire();
        } finally {
            $backup->release();
        }
    }

    public function test_a_refused_second_lock_names_the_restore_that_holds_it(): void
    {
        $restore = DeployLock::acquireFor($this->username, DeployLock::HOLDER_RESTORE);

        try {
            $this->expectException(DeployAlreadyRunningException::class);
            $this->expectExceptionMessage('A restore is already running for this user.');
            (new DeployLock($this->paths))->acquire();
        } finally {
            $restore->release();
        }
    }

    public function test_releasing_a_lock_never_taken_is_harmless(): void
    {
        (new DeployLock($this->paths))->release();

        $this->addToAssertionCount(1);
    }

    public function test_it_reports_a_lock_this_process_holds_and_forgets_it_on_release(): void
    {
        $this->assertFalse(DeployLock::heldByThisProcess($this->username));

        $lock = new DeployLock($this->paths);
        $lock->acquire();
        $this->assertTrue(DeployLock::heldByThisProcess($this->username));

        $lock->release();
        $this->assertFalse(DeployLock::heldByThisProcess($this->username));
    }

    public function test_anothers_lock_is_not_reported_as_this_processes(): void
    {
        $held = fopen($this->paths->lock(), 'c');
        flock($held, LOCK_EX | LOCK_NB);

        try {
            $this->assertFalse(DeployLock::heldByThisProcess($this->username));
            $this->assertTrue((new DeployLock($this->paths))->isHeld());
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }

    public function test_releasing_twice_is_harmless(): void
    {
        $lock = new DeployLock($this->paths);
        $lock->acquire();
        $lock->release();
        $lock->release();

        $this->addToAssertionCount(1);
    }
}
