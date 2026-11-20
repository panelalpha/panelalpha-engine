<?php

namespace Tests\Integration\System\Services;

use App\Lib\Modsec\AuditLogFiles;
use App\System;
use Tests\TestCase;

/** A plain audit log larger than the 50 KB window is read from its end, so only the newest entries come back. */
class ModsecAuditLogWindowTest extends TestCase
{
    private string $root;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-modsec-window-'.bin2hex(random_bytes(4));
        $this->dir = $this->root.'/logs/modsecurity';
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        @rmdir($this->root.'/logs');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_only_the_entries_inside_the_window_are_returned_newest_first(): void
    {
        $padding = str_repeat('x', 1000);
        $lines = array_map(
            static fn (int $i): string => json_encode(['transaction' => ['unique_id' => "id-{$i}", 'pad' => $padding]]),
            range(1, 100)
        );
        file_put_contents($this->dir.'/audit.log', implode("\n", $lines)."\n");

        $tail = (new AuditLogFiles($this->system()))->tail('audit.log');

        $ids = array_map(static fn (object $entry): string => $entry->transaction->unique_id, $tail);
        $this->assertSame('id-100', $ids[0]);
        $this->assertGreaterThan(40, count($ids));
        $this->assertLessThan(52, count($ids));
        $this->assertNotContains('id-1', $ids);
    }

    private function system(): System
    {
        return new class($this->root) extends System
        {
            public function __construct(private string $root) {}

            public function engineDirPath(): string
            {
                return $this->root;
            }
        };
    }
}
