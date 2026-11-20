<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\HostScript;
use App\Lib\Deploy\Platform\PlatformStage;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostScriptFixtures;

/**
 * Host-side stages: what runs in the account's shell rather than a container.
 */
class HostScriptTest extends TestCase
{
    use HostScriptFixtures;

    /**
     * A heredoc terminator has to sit at column 0. Indenting a borrowed body
     * for readability breaks every app config that writes a config file with one
     * — two of the shipped pages do.
     */
    public function test_a_borrowed_body_with_a_heredoc_stays_valid_shell(): void
    {
        $body = "cat > .env <<'EOF'\nAPP_KEY=abc\nEOF\necho done";

        $script = HostScript::render(null, PlatformStage::PREPARE, null, [], ['app-config-setup' => $body]);

        $this->assertStringContainsString("\nEOF\n", $script);
        $this->assertStringNotContainsString("\n  EOF\n", $script);

        $tmp = tempnam(sys_get_temp_dir(), 'hostscript');
        file_put_contents($tmp, $script);
        exec('bash -n ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        unlink($tmp);

        $this->assertSame(0, $code, 'generated script is not valid bash: ' . implode("\n", $output));
    }

    /**
     * A folded multi-line description must be commented on every line, or its
     * later lines land in the host script as bare shell.
     */
    public function test_a_multiline_description_is_fully_commented(): void
    {
        $script = HostScript::render(
            $this->manifest([[
                'id' => 'prep',
                'stage' => 'prepare',
                'run' => 'echo prep',
                'description' => "Prepare the account.\n\nRuns once per deploy.",
            ]]),
            PlatformStage::PREPARE
        );

        $this->assertStringContainsString("# Prepare the account.\n# \n# Runs once per deploy.", $script);

        $tmp = tempnam(sys_get_temp_dir(), 'hostscript');
        file_put_contents($tmp, $script);
        exec('bash -n ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        unlink($tmp);

        $this->assertSame(0, $code, 'generated script is not valid bash: ' . implode("\n", $output));
    }
}
