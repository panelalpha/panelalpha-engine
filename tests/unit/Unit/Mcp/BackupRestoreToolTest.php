<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\Backups\BackupRestoreTool;
use Tests\TestCase;

/**
 * `only` and `exclude` were bare objects, so a client guessed `{"databases": true}` and got a 422: the tool must give
 * the shape the request takes, and say that the two cannot be combined.
 */
class BackupRestoreToolTest extends TestCase
{
    public function test_only_and_exclude_give_the_keys_a_restore_takes(): void
    {
        $properties = (array) (new BackupRestoreTool)->toArray()['inputSchema']['properties'];

        foreach (['only', 'exclude'] as $filter) {
            $keys = (array) ($properties[$filter]['properties'] ?? []);
            $this->assertSame('object', $properties[$filter]['type'], $filter);
            $this->assertSame('boolean', $keys['files']['type'] ?? null, "{$filter}.files");
            $this->assertSame('array', $keys['databases']['type'] ?? null, "{$filter}.databases");
            $this->assertSame('array', $keys['volumes']['type'] ?? null, "{$filter}.volumes");
        }
    }

    public function test_the_tool_says_only_and_exclude_cannot_be_combined(): void
    {
        $properties = (array) (new BackupRestoreTool)->toArray()['inputSchema']['properties'];

        $this->assertStringContainsString('`exclude`', (string) ($properties['only']['description'] ?? ''));
        $this->assertStringContainsString('`only`', (string) ($properties['exclude']['description'] ?? ''));
    }
}
