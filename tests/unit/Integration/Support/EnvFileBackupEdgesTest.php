<?php

namespace Tests\Integration\Support;

use App\Support\EnvFile;
use RuntimeException;
use Tests\TestCase;

/**
 * A single-quoted value, a backup that has to take the file's group, and a backup that cannot
 * be written, which must leave the file itself untouched.
 */
class EnvFileBackupEdgesTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pae-env-edges-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->path = $this->dir.'/.env';
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_single_quoted_value_reads_without_its_quotes(): void
    {
        file_put_contents($this->path, "APP_NAME='Panel Alpha'\nEMPTY=''\n");

        $file = new EnvFile($this->path);

        $this->assertSame('Panel Alpha', $file->get('APP_NAME'));
        $this->assertSame('', $file->get('EMPTY'));
    }

    public function test_the_backup_takes_the_files_group(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('only root can give the file a group it is not in');
        }
        file_put_contents($this->path, "A=1\n");
        chgrp($this->path, 4321);

        (new EnvFile($this->path))->set(['A' => '2']);

        clearstatcache();
        $this->assertSame(4321, filegroup($this->path.EnvFile::BACKUP_SUFFIX));
        $this->assertSame("A=1\n", file_get_contents($this->path.EnvFile::BACKUP_SUFFIX));
    }

    public function test_a_backup_that_cannot_be_written_stops_the_edit(): void
    {
        file_put_contents($this->path, "A=1\n");
        mkdir($this->path.EnvFile::BACKUP_SUFFIX);

        try {
            (new EnvFile($this->path))->set(['A' => '2']);
            $this->fail('an edit without a backup must not go ahead');
        } catch (RuntimeException $e) {
            $this->assertSame(
                "Could not write {$this->path}".EnvFile::BACKUP_SUFFIX.'. Run this as the user that owns the file.',
                $e->getMessage()
            );
        }

        $this->assertSame("A=1\n", file_get_contents($this->path));
    }
}
