<?php

namespace Tests\Unit\System\Services;

use App\Lib\Modsec\AuditLogFiles;
use App\System;
use Tests\TestCase;

/**
 * logrotate compresses rotated audit logs (`audit-<date>.log.gz`). `tail` read
 * the last 50 KB as plain text and json_decoded gzip bytes, so a rotated file
 * returned nothing at all and a recent block could not be reached.
 */
class ModsecAuditLogTailTest extends TestCase
{
    private string $root;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-modsec-logs-' . bin2hex(random_bytes(4));
        $this->dir = $this->root . '/logs/modsecurity';
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        @rmdir($this->root . '/logs');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_a_rotated_gzip_audit_log_is_read(): void
    {
        $entries = [
            ['transaction' => ['unique_id' => 'a']],
            ['transaction' => ['unique_id' => 'b']],
            ['transaction' => ['unique_id' => 'c']],
        ];
        $plain = implode("\n", array_map(fn (array $entry): string => json_encode($entry), $entries)) . "\n";
        file_put_contents($this->dir . '/audit-2026-10-01.log.gz', gzencode($plain));

        $tail = $this->files()->tail('audit-2026-10-01.log.gz');

        $this->assertNotNull($tail);
        $this->assertCount(3, $tail);
        // Newest first.
        $this->assertSame('c', $tail[0]->transaction->unique_id);
        $this->assertSame('a', $tail[2]->transaction->unique_id);
    }

    /** A file shorter than the window is not seeked past its start. */
    public function test_a_short_plain_log_is_read(): void
    {
        file_put_contents($this->dir . '/audit.log', "{\"transaction\":{\"unique_id\":\"x\"}}\n");

        $tail = $this->files()->tail('audit.log');

        $this->assertCount(1, $tail);
        $this->assertSame('x', $tail[0]->transaction->unique_id);
    }

    public function test_a_broken_gzip_file_reads_as_nothing_rather_than_error(): void
    {
        file_put_contents($this->dir . '/audit-2026-10-02.log.gz', 'not gzip at all');

        // zlib's stream may still yield garbage; what matters is that a decode
        // that yields no JSON object returns an empty list, not a fatal.
        $this->assertSame([], $this->files()->tail('audit-2026-10-02.log.gz'));
    }

    public function test_a_missing_file_is_null(): void
    {
        $this->assertNull($this->files()->tail('audit-1999-01-01.log.gz'));
    }

    private function files(): AuditLogFiles
    {
        $system = new class ($this->root) extends System {
            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }
        };

        return new AuditLogFiles($system);
    }
}
