<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A console command that calls the `Log` facade without importing it resolves
 * the name inside its own namespace (`App\Console\Commands\Projects\Log`),
 * which only blows up on the path where the call runs. PHPUnit never executes
 * that path, so nothing local catches it -- `pae project:staging` did it on a
 * failed cleanup (engine#795).
 */
class ConsoleCommandLogImportTest extends TestCase
{
    public function test_a_console_command_that_logs_imports_the_log_facade(): void
    {
        $root = dirname(__DIR__, 4) . '/core/app/Console';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $checked = 0;
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // The Log facade, not `McpActivityLog::query()` or `SomethingLog::`.
            if (preg_match('/(?<![A-Za-z0-9_\\\\])Log::/', $source) !== 1) {
                continue;
            }
            $checked++;
            $this->assertMatchesRegularExpression(
                '/^use Illuminate\\\\Support\\\\Facades\\\\Log;/m',
                $source,
                $file->getPathname() . ' calls Log:: without importing the facade'
            );
        }

        $this->assertGreaterThan(0, $checked, 'the guard found no command that logs');
    }
}
