<?php

namespace Tests\Unit\Models;

use App\Models\Backup;
use PHPUnit\Framework\TestCase;

/** Each operation's status and progress, kept side by side in one `async_status` document. */
class BackupAsyncStatusTest extends TestCase
{
    public function test_each_operation_keeps_its_own_status(): void
    {
        $backup = new Backup();
        $backup->setBackupStatus('completed');
        $backup->setRestoreStatus('running');
        $backup->setDeleteStatus('pending');

        $this->assertSame('completed', $backup->backupStatus());
        $this->assertSame('running', $backup->restoreStatus());
        $this->assertSame('pending', $backup->deleteStatus());

        $backup->setRestoreStatus(null);
        $this->assertNull($backup->restoreStatus());
        $this->assertSame(['backup' => 'completed', 'delete' => 'pending'], $backup->async_status);
    }

    public function test_a_status_that_is_not_text_reads_as_none(): void
    {
        $backup = new Backup();
        $backup->async_status = ['backup' => 3];

        $this->assertNull($backup->backupStatus());
    }

    public function test_each_operation_keeps_its_own_progress(): void
    {
        $backup = new Backup();
        $backup->setBackupProgress(100);
        $backup->setRestoreProgress(40);
        $backup->setDeleteProgress(5);

        $this->assertSame(100, $backup->backupProgress());
        $this->assertSame(40, $backup->restoreProgress());
        $this->assertSame(5, $backup->deleteProgress());

        $backup->setDeleteProgress(null);
        $this->assertNull($backup->deleteProgress());
        $this->assertArrayNotHasKey('delete_progress', $backup->async_status);
    }

    public function test_progress_written_as_text_is_read_as_a_number_and_anything_else_as_none(): void
    {
        $backup = new Backup();
        $backup->async_status = ['backup_progress' => '75', 'restore_progress' => 'half'];

        $this->assertSame(75, $backup->backupProgress());
        $this->assertNull($backup->restoreProgress());
        $this->assertNull($backup->deleteProgress());
    }

    public function test_contents_default_to_files_and_databases(): void
    {
        $backup = new Backup();
        $this->assertTrue($backup->includeFiles());
        $this->assertTrue($backup->includeDatabases());

        $backup->contents = ['files' => true, 'databases' => false];
        $this->assertTrue($backup->includeFiles());
        $this->assertFalse($backup->includeDatabases());
    }
}
