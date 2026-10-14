<?php

namespace Tests\Unit\System\Services;

use App\Lib\Modsec\AuditLogFiles;
use App\System;
use App\System\Services\Modsec;
use Tests\TestCase;

/**
 * The download endpoint serves AuditLogFiles::readablePath(); a listed log
 * core cannot read must come back null, as tail() does, so both answer 404.
 */
class ModsecAuditLogReadablePathTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-modsec-readable-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/audit.log', "{\"transaction\":{\"unique_id\":\"x\"}}\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/audit.log');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_readable_log_gives_its_path(): void
    {
        $this->assertSame($this->dir . '/audit.log', $this->files()->readablePath('audit.log'));
    }

    public function test_a_listed_log_that_cannot_be_read_is_null_for_download_and_tail(): void
    {
        $files = $this->files();

        $this->assertNotNull($files->path('audit-unreadable.log'));
        $this->assertNull($files->readablePath('audit-unreadable.log'));
        $this->assertNull($files->tail('audit-unreadable.log'));
    }

    public function test_an_unlisted_log_is_null(): void
    {
        $this->assertNull($this->files()->readablePath('audit-1999-01-01.log'));
    }

    private function files(): AuditLogFiles
    {
        $system = new class ($this->dir) extends System {
            public function __construct(private string $dir)
            {
            }

            public function modsec(): Modsec
            {
                return new class ($this, $this->dir) extends Modsec {
                    public function __construct(System $system, private string $dir)
                    {
                        parent::__construct($system);
                    }

                    public function listAuditLogFiles(): array
                    {
                        return [
                            ['file' => 'audit.log', 'path' => $this->dir . '/audit.log', 'mtime' => 0, 'size' => 0],
                            ['file' => 'audit-unreadable.log', 'path' => $this->dir . '/missing/audit-unreadable.log', 'mtime' => 0, 'size' => 0],
                        ];
                    }
                };
            }
        };

        return new AuditLogFiles($system);
    }
}
