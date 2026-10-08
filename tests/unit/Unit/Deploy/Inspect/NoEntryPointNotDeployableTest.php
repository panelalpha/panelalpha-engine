<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\Report\ApplicationReport;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * StackStorm's inspect answered `deployable: true, issue: null` in the same
 * response whose start command reads "PanelAlpha found no Python entry point
 * in this repository, so nothing is running" -- two answers to one question,
 * and the field a caller branches on was the wrong one.
 *
 * The placeholder itself stays: an account that answers is easier to diagnose
 * than one that crash-loops. What changes is that inspect says so.
 */
class NoEntryPointNotDeployableTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-noentry-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
        file_put_contents($this->dir . '/requirements.txt', "flask\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $decision */
    private function report(array $decision): array
    {
        $context = ProjectContext::make($this->dir, ['requirements.txt' => true]);

        return (new ApplicationReport($this->dir, $context, $decision, null, null))->build();
    }

    public function test_a_placeholder_start_command_is_not_deployable(): void
    {
        $report = $this->report([
            'strategy' => 'python',
            'label' => 'Python',
            'start_command' => 'mkdir -p ' . PythonRuntime::PLACEHOLDER_DIR
                . " && printf '%s' '<h1>This application did not start</h1>' > "
                . PythonRuntime::PLACEHOLDER_DIR . '/index.html'
                . ' && .venv/bin/python -m http.server 8000 --directory ' . PythonRuntime::PLACEHOLDER_DIR,
        ]);

        $this->assertFalse($report['deployable']);
        $this->assertIsString($report['issue']);
        $this->assertStringContainsString('entry point', $report['issue']);
    }

    /** A project with a real entry point is untouched. */
    public function test_a_real_start_command_stays_deployable(): void
    {
        $report = $this->report([
            'strategy' => 'python',
            'label' => 'Python',
            'start_command' => '.venv/bin/gunicorn --bind 0.0.0.0:8000 wsgi:application',
        ]);

        $this->assertTrue($report['deployable']);
        $this->assertNull($report['issue']);
    }

    /** No start command at all is a different question, and not this one. */
    public function test_no_start_command_is_not_reported_as_missing_entry_point(): void
    {
        $report = $this->report(['strategy' => 'python', 'label' => 'Python']);

        $this->assertNull($report['issue']);
    }
}
