<?php

namespace Tests\Unit\Mcp;

use Tests\TestCase;

/**
 * Artisan lives in the core container; the operator is on the host, where only
 * `pae` / `pae-artisan` exist. A printed `php artisan …` cannot be pasted.
 */
class HostCommandHintsTest extends TestCase
{
    private const COMMANDS = [
        'app/Console/Commands/Api/McpTokensCreateCommand.php',
        'app/Console/Commands/Api/McpConnectCommand.php',
        'app/Console/Commands/Mcp/CheckCommand.php',
    ];

    public function test_operator_hints_use_the_host_wrapper(): void
    {
        foreach (self::COMMANDS as $file) {
            $source = (string) file_get_contents(base_path($file));

            $this->assertStringNotContainsString(
                'php artisan',
                $source,
                "{$file} must not tell the operator to run artisan directly"
            );
        }
    }

    public function test_token_create_points_at_the_verification_command(): void
    {
        $source = (string) file_get_contents(base_path(self::COMMANDS[0]));

        $this->assertStringContainsString('pae mcp:check', $source);
    }
}
